<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use TypeError;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\Money;

final class MoneyTest extends TestCase
{
    private function currency(string $code = 'EUR'): Currency
    {
        return Currency::fromCode($code);
    }

    public function testCreatesZeroPositiveAndNegativeValues(): void
    {
        $currency = $this->currency();

        self::assertSame(0, Money::zero($currency)->minorUnits());
        self::assertSame(125, Money::fromMinorUnits(125, $currency)->minorUnits());
        self::assertSame(-125, Money::fromMinorUnits(-125, $currency)->minorUnits());
    }

    public function testArithmeticReturnsNewValuesWithoutChangingOperands(): void
    {
        $currency = $this->currency();
        $left = Money::fromMinorUnits(120, $currency);
        $right = Money::fromMinorUnits(30, $currency);

        self::assertSame(150, $left->add($right)->minorUnits());
        self::assertSame(90, $left->subtract($right)->minorUnits());
        self::assertSame(360, $left->multiply(3)->minorUnits());
        self::assertSame(120, $left->minorUnits());
        self::assertSame(30, $right->minorUnits());
    }

    public function testComparesValues(): void
    {
        $currency = $this->currency();
        $base = Money::fromMinorUnits(100, $currency);

        self::assertSame(-1, $base->compare(Money::fromMinorUnits(101, $currency)));
        self::assertSame(0, $base->compare(Money::fromMinorUnits(100, $currency)));
        self::assertSame(1, $base->compare(Money::fromMinorUnits(99, $currency)));
    }

    /**
     * @dataProvider crossCurrencyOperations
     */
    public function testRejectsCrossCurrencyOperations(string $operation): void
    {
        $left = Money::fromMinorUnits(100, $this->currency());
        $right = Money::fromMinorUnits(100, $this->currency('GBP'));

        $this->expectException(InvalidArgumentException::class);
        $left->{$operation}($right);
    }

    public function crossCurrencyOperations(): array
    {
        return [
            'add' => ['add'],
            'subtract' => ['subtract'],
            'compare' => ['compare'],
        ];
    }

    public function testRejectsFloatingPointInput(): void
    {
        $this->expectException(TypeError::class);
        Money::fromMinorUnits(1.5, $this->currency());
    }

    public function testSupportsIntegerBoundaries(): void
    {
        $currency = $this->currency();

        self::assertSame(PHP_INT_MAX, Money::fromMinorUnits(PHP_INT_MAX, $currency)->minorUnits());
        self::assertSame(PHP_INT_MIN, Money::fromMinorUnits(PHP_INT_MIN, $currency)->minorUnits());
    }

    public function testDetectsAdditionOverflow(): void
    {
        $currency = $this->currency();
        $this->expectException(OverflowException::class);
        Money::fromMinorUnits(PHP_INT_MAX, $currency)
            ->add(Money::fromMinorUnits(1, $currency));
    }

    public function testDetectsSubtractionOverflow(): void
    {
        $currency = $this->currency();
        $this->expectException(OverflowException::class);
        Money::fromMinorUnits(PHP_INT_MIN, $currency)
            ->subtract(Money::fromMinorUnits(1, $currency));
    }

    public function testDetectsMultiplicationOverflow(): void
    {
        $this->expectException(OverflowException::class);
        Money::fromMinorUnits(PHP_INT_MAX, $this->currency())->multiply(2);
    }
}
