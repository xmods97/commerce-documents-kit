<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class AuditEventHash
{
    public static function next(
        string $key,
        string $previousHash,
        string $eventName,
        string $documentId,
        string $contextJson,
        string $createdAt
    ): string {
        if (strlen($key) !== 32 || ($previousHash !== '' && !preg_match('/^[a-f0-9]{64}$/D', $previousHash))) {
            throw new InvalidArgumentException('Audit event hash input is invalid.');
        }
        return hash_hmac('sha256', implode("\n", [
            $previousHash,
            $eventName,
            $documentId,
            $contextJson,
            $createdAt,
        ]), $key);
    }
}
