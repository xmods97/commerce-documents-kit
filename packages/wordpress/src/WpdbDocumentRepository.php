<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;
use Xmods\CommerceDocuments\Contracts\DocumentRepository;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\IdempotencyKey;
use Xmods\CommerceDocuments\RepositorySaveResult;

final class WpdbDocumentRepository implements DocumentRepository
{
    /**
     * Rows written before encryption existed keep their snapshot in the plaintext
     * `snapshot` column. That column is read only when `snapshot_cipher` is empty,
     * and only after `content_hash` has been verified against the stored bytes.
     *
     * Removal boundary: the plaintext branch is deleted in schema version 5.
     * Before bumping to 5 every row must satisfy `snapshot_cipher <> ''`;
     * `LegacySnapshotAudit` reports the rows that still need re-encryption.
     */
    public const LEGACY_PLAINTEXT_REMOVED_IN_SCHEMA = 5;

    /** @var object */
    private $wpdb;
    /** @var string */
    private $table;
    /** @var EncryptedSnapshotCodec|null */
    private $codec;

    public function __construct($wpdb, string $table, ?EncryptedSnapshotCodec $codec = null)
    {
        $this->wpdb = $wpdb;
        $this->table = $table;
        $this->codec = $codec;
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?DocumentSnapshot
    {
        $sql = $this->wpdb->prepare(
            "SELECT document_id, snapshot, snapshot_cipher, content_hash
             FROM {$this->table} WHERE idempotency_key = %s LIMIT 1",
            $key->value()
        );
        $row = $this->wpdb->get_row($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($row)) {
            return null;
        }

        return self::hydrate($row, $this->codec);
    }

    /**
     * Rebuilds a snapshot from a stored row, choosing the encrypted branch when
     * available and verifying integrity on the legacy plaintext branch.
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row, ?EncryptedSnapshotCodec $codec): ?DocumentSnapshot
    {
        $documentId = (string) ($row['document_id'] ?? '');
        $cipher = (string) ($row['snapshot_cipher'] ?? '');

        if ($cipher !== '') {
            if ($codec === null) {
                throw new RuntimeException('Encrypted document storage is not configured.');
            }
            // AES-256-GCM authenticates the payload and binds it to the document id.
            return $codec->decrypt($cipher, $documentId);
        }

        $json = (string) ($row['snapshot'] ?? '');
        if ($json === '') {
            return null;
        }

        $storedHash = (string) ($row['content_hash'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/D', $storedHash)) {
            throw new RuntimeException('Legacy document snapshot has no usable content hash.');
        }
        // The plaintext column carries no MAC, so the stored hash is the only
        // integrity signal available. Compare against the raw bytes, not against a
        // rehydrated snapshot: rehydration upgrades schema v1 payloads to v2 and
        // would therefore never reproduce the hash recorded at write time.
        if (!hash_equals($storedHash, hash('sha256', $json))) {
            throw new RuntimeException('Legacy document snapshot failed integrity verification.');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Stored document snapshot is invalid.');
        }
        $snapshot = DocumentSnapshot::fromArray($data);
        if ($documentId !== '' && (string) $snapshot->toArray()['document_id'] !== $documentId) {
            throw new RuntimeException('Legacy document snapshot identity is invalid.');
        }
        return $snapshot;
    }

    public function save(
        IdempotencyKey $key,
        DocumentSnapshot $snapshot
    ): RepositorySaveResult {
        if ($this->codec === null) {
            throw new RuntimeException('Encrypted document storage is not configured.');
        }
        $data = $snapshot->toArray();
        // Column order and format order must stay aligned; `snapshot_cipher` is a
        // string payload and binding it as %d silently persists the integer 0.
        $columns = [
            'idempotency_key' => [$key->value(), '%s'],
            'document_id' => [$data['document_id'], '%s'],
            'document_type' => [$data['document_type'], '%s'],
            'source_type' => [$data['source_type'], '%s'],
            'source_id' => [$data['source_id'], '%s'],
            'snapshot' => ['', '%s'],
            'snapshot_cipher' => [$this->codec->encrypt($snapshot), '%s'],
            'encryption_version' => [1, '%d'],
            'content_hash' => [$snapshot->contentHash(), '%s'],
            'created_at' => [gmdate('Y-m-d H:i:s'), '%s'],
        ];
        $values = [];
        $formats = [];
        foreach ($columns as $column => $pair) {
            $values[$column] = $pair[0];
            $formats[] = $pair[1];
        }

        $inserted = $this->wpdb->insert($this->table, $values, $formats);

        if ($inserted !== false) {
            return RepositorySaveResult::created($snapshot);
        }

        $existing = $this->findByIdempotencyKey($key);
        if ($existing === null) {
            throw new RuntimeException('Document persistence failed.');
        }
        return RepositorySaveResult::existing($existing);
    }
}
