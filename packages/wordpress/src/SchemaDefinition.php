<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use InvalidArgumentException;

final class SchemaDefinition
{
    public static function sql(string $prefix, string $charsetCollate = ''): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
            throw new InvalidArgumentException('Database prefix is invalid.');
        }

        return [
            "CREATE TABLE {$prefix}commerce_documents (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                idempotency_key char(64) NOT NULL,
                document_id varchar(64) NOT NULL,
                document_type varchar(32) NOT NULL,
                source_type varchar(64) NOT NULL,
                source_id varchar(191) NOT NULL,
                snapshot longtext NOT NULL,
                content_hash char(64) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY idempotency_key (idempotency_key),
                UNIQUE KEY document_id (document_id),
                KEY source (source_type, source_id)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}commerce_document_events (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                document_id varchar(64) NOT NULL,
                event_name varchar(64) NOT NULL,
                context longtext NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY document_id (document_id)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}commerce_document_sequences (
                series_key varchar(96) NOT NULL,
                current_value bigint unsigned NOT NULL,
                PRIMARY KEY (series_key)
            ) {$charsetCollate};",
        ];
    }
}
