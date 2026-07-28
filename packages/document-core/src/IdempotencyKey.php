<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class IdempotencyKey
{
    /** @var string */
    private $value;

    private function __construct(string $value)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $value)) {
            throw new InvalidArgumentException('Idempotency key must be a SHA-256 hex value.');
        }
        $this->value = $value;
    }

    public static function forSource(
        string $sourceType,
        string $sourceId,
        DocumentType $documentType,
        string $policy,
        int $version
    ): self {
        if ($version < 1 || trim($sourceType) === '' || trim($sourceId) === '' || trim($policy) === '') {
            throw new InvalidArgumentException('Idempotency source, policy, and version are required.');
        }
        return new self(hash('sha256', implode("\n", [
            trim($sourceType),
            trim($sourceId),
            $documentType->value(),
            trim($policy),
            (string) $version,
        ])));
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
