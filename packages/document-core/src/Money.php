<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;
use OverflowException;

final class Money
{
    /** @var int */
    private $minorUnits;

    /** @var Currency */
    private $currency;

    private function __construct(int $minorUnits, Currency $currency)
    {
        $this->minorUnits = $minorUnits;
        $this->currency = $currency;
    }

    public static function fromMinorUnits(int $minorUnits, Currency $currency): self
    {
        return new self($minorUnits, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        if (
            ($other->minorUnits > 0 && $this->minorUnits > PHP_INT_MAX - $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits < PHP_INT_MIN - $other->minorUnits)
        ) {
            throw new OverflowException('Money addition exceeds the supported integer range.');
        }

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if (
            ($other->minorUnits < 0 && $this->minorUnits > PHP_INT_MAX + $other->minorUnits)
            || ($other->minorUnits > 0 && $this->minorUnits < PHP_INT_MIN + $other->minorUnits)
        ) {
            throw new OverflowException('Money subtraction exceeds the supported integer range.');
        }

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multiply(int $multiplier): self
    {
        $overflow = false;

        if ($this->minorUnits > 0) {
            $overflow = $multiplier > 0
                ? $this->minorUnits > intdiv(PHP_INT_MAX, $multiplier)
                : ($multiplier < 0 && $multiplier < intdiv(PHP_INT_MIN, $this->minorUnits));
        } elseif ($this->minorUnits < 0) {
            $overflow = $multiplier > 0
                ? $this->minorUnits < intdiv(PHP_INT_MIN, $multiplier)
                : ($multiplier < 0 && $this->minorUnits < intdiv(PHP_INT_MAX, $multiplier));
        }

        if ($overflow) {
            throw new OverflowException('Money multiplication exceeds the supported integer range.');
        }

        return new self($this->minorUnits * $multiplier, $this->currency);
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw new InvalidArgumentException('Money values must use the same currency.');
        }
    }
}
