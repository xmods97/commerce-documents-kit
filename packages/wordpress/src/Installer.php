<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;

final class Installer
{
    public const SCHEMA_VERSION = 4;
    private const SCHEMA_VERSION_OPTION = 'commerce_documents_schema_version';

    public static function activate(): void
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new RuntimeException('WordPress database is unavailable.');
        }
        self::applySchema($wpdb);
        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    /**
     * Runs only when an administrator explicitly wires and authorizes it.
     * Plugin boot never invokes this method automatically.
     */
    public static function migrateToCurrentVersion(bool $backupConfirmed = false): void
    {
        if (!$backupConfirmed) {
            throw new RuntimeException('A verified database backup confirmation is required.');
        }
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new RuntimeException('WordPress database is unavailable.');
        }
        if (!self::upgradeRequired()) {
            return;
        }

        // Refuse to run while a known-blocking condition exists. dbDelta cannot add
        // a UNIQUE index over duplicate rows, and a partial failure there would
        // leave the schema half-applied.
        $preflight = self::preflight();
        if ($preflight['blockers'] !== []) {
            throw new RuntimeException(
                'Migration preflight failed: ' . implode('; ', $preflight['blockers'])
            );
        }

        self::applySchema($wpdb);
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'commerce_document_migrations',
            ['version' => self::SCHEMA_VERSION, 'applied_at' => gmdate('Y-m-d H:i:s')],
            ['%d', '%s']
        );
        if ($inserted === false && stripos((string) $wpdb->last_error, 'duplicate') === false) {
            throw new RuntimeException('Migration history could not be recorded.');
        }
        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);
    }

    /**
     * @return array{
     *     installed_version:int,
     *     target_version:int,
     *     upgrade_required:bool,
     *     blockers:string[],
     *     warnings:string[]
     * }
     */
    public static function preflight(): array
    {
        global $wpdb;
        $blockers = [];
        $warnings = [];

        if (is_object($wpdb)) {
            $events = $wpdb->prefix . 'commerce_document_events';
            $documents = $wpdb->prefix . 'commerce_documents';

            if (self::tableExists($wpdb, $events)) {
                // Schema 4 introduces UNIQUE KEY chain_position (document_id, prev_event_hash).
                // Events written under the old global chain can collide on that pair.
                $duplicates = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM (
                        SELECT document_id, prev_event_hash
                        FROM {$events}
                        GROUP BY document_id, prev_event_hash
                        HAVING COUNT(*) > 1
                    ) AS collisions"
                );
                if ($duplicates > 0) {
                    $blockers[] = sprintf(
                        '%d audit chain position(s) are duplicated; the chain_position unique index cannot be created until they are reconciled',
                        $duplicates
                    );
                }
            }

            if (self::tableExists($wpdb, $documents)) {
                $legacy = (new LegacySnapshotAudit($wpdb, $documents))->report();
                if ($legacy['remaining'] > 0) {
                    $warnings[] = sprintf(
                        '%d document(s) still store a plaintext snapshot; the legacy read branch is removed in schema %d',
                        $legacy['remaining'],
                        $legacy['removal_schema']
                    );
                }
                if ($legacy['unverifiable'] > 0) {
                    $blockers[] = sprintf(
                        '%d legacy document(s) cannot be authenticated against content_hash and need manual review',
                        $legacy['unverifiable']
                    );
                }
            }
        }

        return [
            'installed_version' => self::installedSchemaVersion(),
            'target_version' => self::SCHEMA_VERSION,
            'upgrade_required' => self::upgradeRequired(),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * Statements that reverse the schema-4 additions, for an operator to run
     * manually against a restored backup.
     *
     * This is deliberately not executable from the plugin: dropping columns and
     * indexes is destructive, dbDelta cannot express it, and an automated rollback
     * button next to a migration button is how the wrong one gets pressed.
     *
     * @return string[]
     */
    public static function rollbackPlan(string $prefix): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
            throw new RuntimeException('Database prefix is invalid.');
        }

        return [
            "-- Restore from backup first. These statements only undo the schema-4 additions.",
            "ALTER TABLE {$prefix}commerce_document_events DROP INDEX chain_position;",
            "ALTER TABLE {$prefix}commerce_documents DROP INDEX superseded_by;",
            "ALTER TABLE {$prefix}commerce_documents DROP COLUMN superseded_by;",
            "ALTER TABLE {$prefix}commerce_documents DROP COLUMN superseded_at;",
            "DELETE FROM {$prefix}commerce_document_migrations WHERE version = 4;",
            "-- Then set the option commerce_documents_schema_version back to 3.",
        ];
    }

    private static function tableExists($wpdb, string $table): bool
    {
        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        ) === $table;
    }

    private static function applySchema($wpdb): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (SchemaDefinition::sql($wpdb->prefix, $wpdb->get_charset_collate()) as $sql) {
            dbDelta($sql);
        }
    }

    public static function installedSchemaVersion(): int
    {
        return (int) get_option(self::SCHEMA_VERSION_OPTION, 0);
    }

    public static function upgradeRequired(): bool
    {
        return self::installedSchemaVersion() < self::SCHEMA_VERSION;
    }
}
