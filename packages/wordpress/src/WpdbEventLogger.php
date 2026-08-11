<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;
use Xmods\CommerceDocuments\Contracts\EventLogger;
use Xmods\CommerceDocuments\AuditEventHash;

final class WpdbEventLogger implements EventLogger
{
    /** @var object */
    private $wpdb;
    /** @var string */
    private $table;
    /** @var string */
    private $key;

    public function __construct($wpdb, string $table, string $key)
    {
        $this->wpdb = $wpdb;
        $this->table = $table;
        if (strlen($key) !== 32) {
            throw new RuntimeException('Audit event key must be 32 bytes.');
        }
        $this->key = $key;
    }

    public function record(string $event, string $documentId, array $context = []): void
    {
        $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $createdAt = gmdate('Y-m-d H:i:s');
        $previousHash = (string) $this->wpdb->get_var(
            "SELECT event_hash FROM {$this->table} ORDER BY id DESC LIMIT 1"
        );
        $eventHash = AuditEventHash::next($this->key, $previousHash, $event, $documentId, $contextJson, $createdAt);
        $result = $this->wpdb->insert($this->table, [
            'document_id' => $documentId,
            'event_name' => $event,
            'context' => $contextJson,
            'prev_event_hash' => $previousHash,
            'event_hash' => $eventHash,
            'created_at' => $createdAt,
        ], ['%s', '%s', '%s', '%s', '%s', '%s']);

        if ($result === false) {
            throw new RuntimeException('Document event persistence failed.');
        }
    }
}
