<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

/**
 * Legacy types remain constructible so historical rows stay readable, but only
 * the issuable set may be used to create a new document — see M10 in
 * review/claude/security-findings.md. Removing the legacy constants belongs with
 * the schema-5 legacy cleanup; refusing to issue them does not have to wait for
 * it, and is what actually prevents a second generator of fiscal documents
 * appearing alongside Fakturownia.
 */
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

    /** Types a new document may be created with. */
    public static function issuableValues(): array
    {
        return [self::ORDER_CONFIRMATION, self::CORRECTION];
    }

    public function isIssuable(): bool
    {
        return in_array($this->value, self::issuableValues(), true);
    }

    public function assertIssuable(): void
    {
        if (!$this->isIssuable()) {
            throw new InvalidArgumentException(
                'Document type "' . $this->value . '" is retained for reading historical documents '
                . 'and cannot be issued.'
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
