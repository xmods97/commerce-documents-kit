<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;

final class Installer
{
    public const SCHEMA_VERSION = 3;
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

    /** @return array{installed_version:int,target_version:int,upgrade_required:bool} */
    public static function preflight(): array
    {
        return [
            'installed_version' => self::installedSchemaVersion(),
            'target_version' => self::SCHEMA_VERSION,
            'upgrade_required' => self::upgradeRequired(),
        ];
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
