<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

/**
 * Reports how many rows still depend on the legacy plaintext `snapshot` column.
 *
 * The plaintext read branch in WpdbDocumentRepository is removed in schema
 * version WpdbDocumentRepository::LEGACY_PLAINTEXT_REMOVED_IN_SCHEMA. This audit
 * is the gate: the branch may only be deleted once `remaining` reaches zero.
 * Read-only — it never rewrites rows.
 */
final class LegacySnapshotAudit
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

    /**
     * @return array{total:int,encrypted:int,remaining:int,unverifiable:int,removal_schema:int,ready:bool}
     */
    public function report(): array
    {
        $total = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        $encrypted = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE snapshot_cipher IS NOT NULL AND snapshot_cipher <> ''"
        );
        // Legacy rows whose content_hash cannot authenticate the plaintext column:
        // these need manual review, not an automated re-encrypt.
        $unverifiable = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE (snapshot_cipher IS NULL OR snapshot_cipher = '')
               AND (content_hash IS NULL OR content_hash = '' OR SHA2(snapshot, 256) <> content_hash)"
        );
        $remaining = $total - $encrypted;

        return [
            'total' => $total,
            'encrypted' => $encrypted,
            'remaining' => $remaining,
            'unverifiable' => $unverifiable,
            'removal_schema' => WpdbDocumentRepository::LEGACY_PLAINTEXT_REMOVED_IN_SCHEMA,
            'ready' => $remaining === 0,
        ];
    }
}
