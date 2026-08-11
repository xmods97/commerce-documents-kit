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
                snapshot_cipher longtext NULL,
                encryption_version smallint unsigned NOT NULL DEFAULT 0,
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
                prev_event_hash char(64) NOT NULL DEFAULT '',
                event_hash char(64) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY document_id (document_id)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}commerce_document_sequences (
                series_key varchar(96) NOT NULL,
                current_value bigint unsigned NOT NULL,
                PRIMARY KEY (series_key)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}commerce_document_links (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                document_id varchar(64) NOT NULL,
                parent_document_id varchar(64) NOT NULL,
                relationship varchar(32) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY document_relationship (document_id, relationship),
                KEY parent_document (parent_document_id)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}commerce_document_deliveries (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                delivery_id varchar(64) NOT NULL,
                document_id varchar(64) NOT NULL,
                channel varchar(32) NOT NULL,
                recipient_hmac char(64) NOT NULL,
                delivery_status varchar(32) NOT NULL,
                attempts int unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY delivery_id (delivery_id),
                KEY document_delivery (document_id),
                KEY recipient_hmac (recipient_hmac)
            ) {$charsetCollate};",
            "CREATE TABLE {$prefix}commerce_document_migrations (
                version int unsigned NOT NULL,
                applied_at datetime NOT NULL,
                PRIMARY KEY (version)
            ) {$charsetCollate};",
        ];
    }
}
