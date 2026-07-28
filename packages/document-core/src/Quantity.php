<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments;

use InvalidArgumentException;
use OverflowException;

/**
 * Immutable decimal quantity represented by scaled integer units.
 */
final class Quantity
{
    private const MAX_SCALE = 6;
    private const MAX_ABSOLUTE_SCALED_UNITS = 1000000000000;

    /** @var int */
    private $scaledUnits;

    /** @var int */
    private $scale;

    private function __construct(int $scaledUnits, int $scale)
    {
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw new InvalidArgumentException('Quantity scale must be between 0 and 6.');
        }

        if (abs($scaledUnits) > self::MAX_ABSOLUTE_SCALED_UNITS) {
            throw new InvalidArgumentException('Quantity exceeds the supported scaled-unit range.');
        }

        while ($scale > 0 && $scaledUnits % 10 === 0) {
            $scaledUnits = intdiv($scaledUnits, 10);
            --$scale;
        }

        $this->scaledUnits = $scaledUnits;
        $this->scale = $scale;
    }

    public static function fromScaledUnits(int $scaledUnits, int $scale): self
    {
        return new self($scaledUnits, $scale);
    }

    public static function one(): self
    {
        return new self(1, 0);
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    public function scaledUnits(): int
    {
        return $this->scaledUnits;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function equals(self $other): bool
    {
        return $this->scaledUnits === $other->scaledUnits
            && $this->scale === $other->scale;
    }

    /**
     * Multiplies money by the quantity and rounds half away from zero.
     */
    public function multiplyMoney(Money $money): Money
    {
        $divisor = 10 ** $this->scale;
        $minorUnits = $money->minorUnits();
        $whole = self::multiplyExact(intdiv($minorUnits, $divisor), $this->scaledUnits);
        $fractionNumerator = ($minorUnits % $divisor) * $this->scaledUnits;
        $fraction = intdiv($fractionNumerator, $divisor);
        $remainder = $fractionNumerator % $divisor;

        if (abs($remainder) * 2 >= $divisor) {
            $fraction += $remainder > 0 ? 1 : -1;
        }

        if (
            ($fraction > 0 && $whole > PHP_INT_MAX - $fraction)
            || ($fraction < 0 && $whole < PHP_INT_MIN - $fraction)
        ) {
            throw new OverflowException('Quantity multiplication exceeds the supported integer range.');
        }

        return Money::fromMinorUnits($whole + $fraction, $money->currency());
    }

    private static function multiplyExact(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        $overflow = $left > 0
            ? ($right > 0
                ? $left > intdiv(PHP_INT_MAX, $right)
                : $right < intdiv(PHP_INT_MIN, $left))
            : ($right > 0
                ? $left < intdiv(PHP_INT_MIN, $right)
                : $left < intdiv(PHP_INT_MAX, $right));

        if ($overflow) {
            throw new OverflowException('Quantity multiplication exceeds the supported integer range.');
        }

        return $left * $right;
    }
}
