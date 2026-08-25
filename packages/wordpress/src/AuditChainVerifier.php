<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use Xmods\CommerceDocuments\AuditEventHash;

/**
 * Recomputes a document's audit chain and reports the first position that does
 * not reconcile. Read-only: it never repairs or rewrites events.
 */
final class AuditChainVerifier
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
        $this->key = $key;
    }

    /**
     * @return array{document_id:string,events:int,valid:bool,broken_at:int|null,reason:string}
     */
    public function verify(string $documentId): array
    {
        $rows = (array) $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, event_name, context, prev_event_hash, event_hash, created_at
             FROM {$this->table} WHERE document_id = %s ORDER BY id ASC",
            $documentId
        ), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');

        return self::verifyRows($documentId, $rows, $this->key);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{document_id:string,events:int,valid:bool,broken_at:int|null,reason:string}
     */
    public static function verifyRows(string $documentId, array $rows, string $key): array
    {
        $expectedPrevious = '';
        $position = 0;

        foreach ($rows as $row) {
            $position++;
            $storedPrevious = (string) ($row['prev_event_hash'] ?? '');
            $storedHash = (string) ($row['event_hash'] ?? '');

            if (!hash_equals($expectedPrevious, $storedPrevious)) {
                return self::fail($documentId, count($rows), $position, 'chain link does not match the previous event');
            }

            $recomputed = AuditEventHash::next(
                $key,
                $storedPrevious,
                (string) ($row['event_name'] ?? ''),
                $documentId,
                (string) ($row['context'] ?? ''),
                (string) ($row['created_at'] ?? '')
            );

            if (!hash_equals($recomputed, $storedHash)) {
                return self::fail($documentId, count($rows), $position, 'event contents do not match the recorded hash');
            }

            $expectedPrevious = $storedHash;
        }

        return [
            'document_id' => $documentId,
            'events' => count($rows),
            'valid' => true,
            'broken_at' => null,
            'reason' => '',
        ];
    }

    /**
     * @return array{document_id:string,events:int,valid:bool,broken_at:int|null,reason:string}
     */
    private static function fail(string $documentId, int $events, int $position, string $reason): array
    {
        return [
            'document_id' => $documentId,
            'events' => $events,
            'valid' => false,
            'broken_at' => $position,
            'reason' => $reason,
        ];
    }
}
