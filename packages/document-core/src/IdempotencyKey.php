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
        ?string $legacyPolicy = null,
        ?int $legacyVersion = null
    ): self {
        if (trim($sourceType) === '' || trim($sourceId) === '') {
            throw new InvalidArgumentException('Idempotency source is required.');
        }

        // Policy changes must never allocate another document for the same source.
        // The optional legacy arguments preserve the v0.2 public call signature.
        return new self(hash('sha256', implode("\n", [
            trim($sourceType),
            trim($sourceId),
            $documentType->value(),
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
