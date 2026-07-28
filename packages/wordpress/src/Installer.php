<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;

final class Installer
{
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
    }
}
