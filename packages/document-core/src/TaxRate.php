<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;

final class TaxRate
{
    private const SCALE = 1000000;

    /** @var int */
    private $partsPerMillion;

    private function __construct(int $partsPerMillion)
    {
        if ($partsPerMillion < 0 || $partsPerMillion > self::SCALE) {
            throw new InvalidArgumentException(
                'Tax rate must be between 0 and 1,000,000 parts per million.'
            );
        }

        $this->partsPerMillion = $partsPerMillion;
    }

    public static function fromPartsPerMillion(int $partsPerMillion): self
    {
        return new self($partsPerMillion);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function partsPerMillion(): int
    {
        return $this->partsPerMillion;
    }

    public function equals(self $other): bool
    {
        return $this->partsPerMillion === $other->partsPerMillion;
    }

    public function calculateTax(Money $net): Money
    {
        $minorUnits = $net->minorUnits();
        $whole = intdiv($minorUnits, self::SCALE) * $this->partsPerMillion;
        $fractionNumerator = ($minorUnits % self::SCALE) * $this->partsPerMillion;
        $fraction = intdiv($fractionNumerator, self::SCALE);
        $remainder = $fractionNumerator % self::SCALE;

        if (abs($remainder) * 2 >= self::SCALE) {
            $fraction += $remainder > 0 ? 1 : -1;
        }

        return Money::fromMinorUnits($whole + $fraction, $net->currency());
    }
}
