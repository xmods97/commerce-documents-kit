<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;
use Xmods\CommerceDocuments\Contracts\EventLogger;

final class WpdbEventLogger implements EventLogger
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

    public function record(string $event, string $documentId, array $context = []): void
    {
        $result = $this->wpdb->insert($this->table, [
            'document_id' => $documentId,
            'event_name' => $event,
            'context' => json_encode($context, JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ], ['%s', '%s', '%s', '%s']);

        if ($result === false) {
            throw new RuntimeException('Document event persistence failed.');
        }
    }
}
