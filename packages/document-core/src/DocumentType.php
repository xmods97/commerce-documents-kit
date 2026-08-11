<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class DocumentType
{
    public const QUOTE = 'quote';
    public const PROFORMA = 'proforma';
    public const INVOICE = 'invoice';
    public const RECEIPT = 'receipt';
    public const CREDIT_NOTE = 'credit_note';
    public const ORDER_CONFIRMATION = 'order_confirmation';
    public const CORRECTION = 'correction';

    /** @var string */
    private $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::values(), true)) {
            throw new InvalidArgumentException('Unsupported document type.');
        }
        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function values(): array
    {
        return [self::QUOTE, self::PROFORMA, self::INVOICE, self::RECEIPT, self::CREDIT_NOTE, self::ORDER_CONFIRMATION, self::CORRECTION];
    }

    public function value(): string
    {
        return $this->value;
    }
}
