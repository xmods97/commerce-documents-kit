<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WordPress;

use RuntimeException;

final class ConfigKeyProvider
{
    public const ENCRYPTION_KEY_CONSTANT = 'COMMERCE_DOCUMENTS_ENCRYPTION_KEY';
    public const AUDIT_KEY_CONSTANT = 'COMMERCE_DOCUMENTS_AUDIT_HMAC_KEY';

    public static function encryptionKey(): string
    {
        if (!defined(self::ENCRYPTION_KEY_CONSTANT)) {
            throw new RuntimeException('Commerce Documents encryption key is not configured.');
        }
        $key = base64_decode((string) constant(self::ENCRYPTION_KEY_CONSTANT), true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('Commerce Documents encryption key must be base64-encoded 32 bytes.');
        }
        return $key;
    }

    public static function auditKey(): string
    {
        if (!defined(self::AUDIT_KEY_CONSTANT)) {
            throw new RuntimeException('Commerce Documents audit key is not configured.');
        }
        $key = base64_decode((string) constant(self::AUDIT_KEY_CONSTANT), true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('Commerce Documents audit key must be base64-encoded 32 bytes.');
        }
        return $key;
    }
}
