<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;

final class Installer
{
    public const SCHEMA_VERSION = 1;
    private const SCHEMA_VERSION_OPTION = 'commerce_documents_schema_version';

    public static function activate(): void
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new RuntimeException('WordPress database is unavailable.');
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (SchemaDefinition::sql($wpdb->prefix, $wpdb->get_charset_collate()) as $sql) {
            dbDelta($sql);
        }
        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);
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
