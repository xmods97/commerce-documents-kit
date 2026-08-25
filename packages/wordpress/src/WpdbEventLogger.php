<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;
use Xmods\CommerceDocuments\Contracts\EventLogger;
use Xmods\CommerceDocuments\AuditEventHash;

/**
 * Append-only audit log with a per-document HMAC chain.
 *
 * Concurrency is handled by the database rather than by a read-then-write lock:
 * `UNIQUE KEY chain_position (document_id, prev_event_hash)` makes it impossible
 * for two events of the same document to claim the same predecessor. A writer
 * that loses the race fails the insert and retries against the new tip, so the
 * chain stays linear without needing an explicit transaction or GET_LOCK — both
 * of which would be unreliable across the storage engines WordPress permits.
 *
 * The chain is per-document on purpose: a global chain makes one missing row
 * invalidate verification for every later event of every other document.
 */
final class WpdbEventLogger implements EventLogger
{
    private const MAX_ATTEMPTS = 5;

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
        if (trim($documentId) === '') {
            throw new RuntimeException('Audit events require a document id.');
        }
        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $previousHash = $this->tip($documentId);
            $createdAt = gmdate('Y-m-d H:i:s');
            $eventHash = AuditEventHash::next(
                $this->key,
                $previousHash,
                $event,
                $documentId,
                $contextJson,
                $createdAt
            );

            $result = $this->wpdb->insert($this->table, [
                'document_id' => $documentId,
                'event_name' => $event,
                'context' => $contextJson,
                'prev_event_hash' => $previousHash,
                'event_hash' => $eventHash,
                'created_at' => $createdAt,
            ], ['%s', '%s', '%s', '%s', '%s', '%s']);

            if ($result !== false) {
                return;
            }
            // A concurrent writer took this chain position; re-read the tip and retry.
        }

        throw new RuntimeException('Document event persistence failed after contention.');
    }

    private function tip(string $documentId): string
    {
        $hash = (string) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT event_hash FROM {$this->table}
             WHERE document_id = %s ORDER BY id DESC LIMIT 1",
            $documentId
        ));
        return preg_match('/^[a-f0-9]{64}$/D', $hash) === 1 ? $hash : '';
    }
}
