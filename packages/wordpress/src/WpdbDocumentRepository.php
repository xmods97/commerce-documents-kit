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

    public function __construct($wpdb, string $table)
    {
        $this->wpdb = $wpdb;
        $this->table = $table;
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?DocumentSnapshot
    {
        $sql = $this->wpdb->prepare(
            "SELECT snapshot FROM {$this->table} WHERE idempotency_key = %s LIMIT 1",
            $key->value()
        );
        $json = $this->wpdb->get_var($sql);
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
        $data = $snapshot->toArray();
        $inserted = $this->wpdb->insert(
            $this->table,
            [
                'idempotency_key' => $key->value(),
                'document_id' => $data['document_id'],
                'document_type' => $data['document_type'],
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'],
                'snapshot' => $snapshot->toJson(),
                'content_hash' => $snapshot->contentHash(),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
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
