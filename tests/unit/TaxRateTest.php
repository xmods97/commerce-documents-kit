<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TypeError;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\TaxRate;

final class TaxRateTest extends TestCase
{
    private function money(int $minorUnits): Money
    {
        return Money::fromMinorUnits($minorUnits, Currency::fromCode('EUR'));
    }

    public function testCreatesZeroAndPositiveRate(): void
    {
        self::assertSame(0, TaxRate::zero()->partsPerMillion());
        self::assertSame(
            230000,
            TaxRate::fromPartsPerMillion(230000)->partsPerMillion()
        );
    }

    public function testCalculatesTaxAndPreservesCurrency(): void
    {
        $net = $this->money(10000);
        $tax = TaxRate::fromPartsPerMillion(230000)->calculateTax($net);

        self::assertSame(2300, $tax->minorUnits());
        self::assertSame($net->currency(), $tax->currency());
        self::assertSame(10000, $net->minorUnits());
    }

    /**
     * @dataProvider roundingCases
     */
    public function testRoundsHalfAwayFromZero(
        int $minorUnits,
        int $partsPerMillion,
        int $expected
    ): void {
        $actual = TaxRate::fromPartsPerMillion($partsPerMillion)
            ->calculateTax($this->money($minorUnits));

        self::assertSame($expected, $actual->minorUnits());
    }

    public function roundingCases(): array
    {
        return [
            'positive below half' => [1, 499999, 0],
            'positive half' => [1, 500000, 1],
            'negative below half' => [-1, 499999, 0],
            'negative half' => [-1, 500000, -1],
        ];
    }

    public function testHandlesIntegerBoundariesAtOneHundredPercent(): void
    {
        $rate = TaxRate::fromPartsPerMillion(1000000);

        self::assertSame(PHP_INT_MAX, $rate->calculateTax($this->money(PHP_INT_MAX))->minorUnits());
        self::assertSame(PHP_INT_MIN, $rate->calculateTax($this->money(PHP_INT_MIN))->minorUnits());
    }

    /**
     * @dataProvider invalidRates
     */
    public function testRejectsOutOfRangeRate(int $partsPerMillion): void
    {
        $this->expectException(InvalidArgumentException::class);
        TaxRate::fromPartsPerMillion($partsPerMillion);
    }

    public function invalidRates(): array
    {
        return [
            'negative' => [-1],
            'above maximum' => [1000001],
        ];
    }

    public function testRejectsFloatingPointRate(): void
    {
        $this->expectException(TypeError::class);
        TaxRate::fromPartsPerMillion(230000.5);
    }
}
