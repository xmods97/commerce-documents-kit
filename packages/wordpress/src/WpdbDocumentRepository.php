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
            "SELECT document_id, snapshot, snapshot_cipher FROM {$this->table} WHERE idempotency_key = %s LIMIT 1",
            $key->value()
        );
        $row = $this->wpdb->get_row($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (is_array($row) && $this->codec !== null && (string) ($row['snapshot_cipher'] ?? '') !== '') {
            return $this->codec->decrypt(
                (string) $row['snapshot_cipher'],
                (string) ($row['document_id'] ?? '')
            );
        }
        $json = is_array($row) ? ($row['snapshot'] ?? '') : $this->wpdb->get_var($sql);
        if (!is_string($json) || $json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Stored document snapshot is invalid.');
        }
        return DocumentSnapshot::fromArray($data);
    }

    public function save(
        IdempotencyKey $key,
        DocumentSnapshot $snapshot
    ): RepositorySaveResult {
        if ($this->codec === null) {
            throw new RuntimeException('Encrypted document storage is not configured.');
        }
        $data = $snapshot->toArray();
        $inserted = $this->wpdb->insert(
            $this->table,
            [
                'idempotency_key' => $key->value(),
                'document_id' => $data['document_id'],
                'document_type' => $data['document_type'],
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'],
                'snapshot' => '',
                'snapshot_cipher' => $this->codec->encrypt($snapshot),
                'encryption_version' => 1,
                'content_hash' => $snapshot->contentHash(),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

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
