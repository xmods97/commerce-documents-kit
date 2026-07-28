<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class DocumentStatus
{
    public const DRAFT = 'draft';
    public const ISSUED = 'issued';
    public const SENT = 'sent';
    public const PAID = 'paid';
    public const CANCELLED = 'cancelled';
    public const REPLACED = 'replaced';

    /** @var string */
    private $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::values(), true)) {
            throw new InvalidArgumentException('Unsupported document status.');
        }
        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function values(): array
    {
        return [
            self::DRAFT,
            self::ISSUED,
            self::SENT,
            self::PAID,
            self::CANCELLED,
            self::REPLACED,
        ];
    }

    public function value(): string
    {
        return $this->value;
    }
}
