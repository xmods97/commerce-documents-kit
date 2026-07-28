<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use TypeError;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Quantity;

final class QuantityTest extends TestCase
{
    private function money(int $minorUnits): Money
    {
        return Money::fromMinorUnits($minorUnits, Currency::fromCode('EUR'));
    }

    public function testCreatesNormalizesAndComparesQuantities(): void
    {
        $quantity = Quantity::fromScaledUnits(2500, 3);

        self::assertSame(25, $quantity->scaledUnits());
        self::assertSame(1, $quantity->scale());
        self::assertTrue($quantity->equals(Quantity::fromScaledUnits(25, 1)));
        self::assertFalse($quantity->equals(Quantity::one()));
    }

    public function testSupportsZeroPositiveAndNegativeValues(): void
    {
        self::assertSame(0, Quantity::zero()->scaledUnits());
        self::assertSame(1, Quantity::one()->scaledUnits());
        self::assertSame(-25, Quantity::fromScaledUnits(-25, 1)->scaledUnits());
    }

    /**
     * @dataProvider multiplicationCases
     */
    public function testMultipliesMoneyWithoutFloat(
        int $minorUnits,
        int $scaledUnits,
        int $scale,
        int $expected
    ): void {
        $result = Quantity::fromScaledUnits($scaledUnits, $scale)
            ->multiplyMoney($this->money($minorUnits));

        self::assertSame($expected, $result->minorUnits());
    }

    public function multiplicationCases(): array
    {
        return [
            'whole' => [125, 3, 0, 375],
            'decimal' => [100, 25, 1, 250],
            'positive half' => [1, 5, 1, 1],
            'negative half' => [-1, 5, 1, -1],
            'negative quantity' => [100, -25, 1, -250],
        ];
    }

    /**
     * @dataProvider invalidQuantities
     */
    public function testRejectsInvalidQuantity(int $scaledUnits, int $scale): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::fromScaledUnits($scaledUnits, $scale);
    }

    public function invalidQuantities(): array
    {
        return [
            'negative scale' => [1, -1],
            'excessive scale' => [1, 7],
            'excessive units' => [1000000000001, 0],
        ];
    }

    public function testRejectsFloatingPointInput(): void
    {
        $this->expectException(TypeError::class);
        Quantity::fromScaledUnits(1.5, 1);
    }

    public function testDetectsMultiplicationOverflow(): void
    {
        $this->expectException(OverflowException::class);
        Quantity::fromScaledUnits(2, 0)->multiplyMoney($this->money(PHP_INT_MAX));
    }
}
