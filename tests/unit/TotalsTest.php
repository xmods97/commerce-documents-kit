<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\TaxRate;
use Xmods\CommerceDocuments\Totals;

final class TotalsTest extends TestCase
{
    private function item(
        Currency $currency,
        int $unitNet,
        int $quantity,
        int $taxRate
    ): DocumentItem {
        return DocumentItem::create(
            'Item',
            Quantity::fromScaledUnits($quantity, 0),
            'unit',
            Money::fromMinorUnits($unitNet, $currency),
            TaxRate::fromPartsPerMillion($taxRate)
        );
    }

    public function testCalculatesEmptyTotals(): void
    {
        $totals = Totals::fromItems([], Currency::fromCode('EUR'));

        self::assertSame(0, $totals->net()->minorUnits());
        self::assertSame(0, $totals->tax()->minorUnits());
        self::assertSame(0, $totals->gross()->minorUnits());
        self::assertSame([], $totals->taxBreakdown()->amountsByPartsPerMillion());
    }

    public function testAggregatesTotalsAndMultipleTaxRates(): void
    {
        $currency = Currency::fromCode('EUR');
        $rateTwenty = TaxRate::fromPartsPerMillion(200000);
        $rateTen = TaxRate::fromPartsPerMillion(100000);
        $totals = Totals::fromItems([
            $this->item($currency, 1000, 2, 200000),
            $this->item($currency, 500, 1, 100000),
            $this->item($currency, -100, 1, 200000),
        ], $currency);

        self::assertSame(2400, $totals->net()->minorUnits());
        self::assertSame(430, $totals->tax()->minorUnits());
        self::assertSame(2830, $totals->gross()->minorUnits());
        self::assertSame(
            380,
            $totals->taxBreakdown()->amountFor($rateTwenty, $currency)->minorUnits()
        );
        self::assertSame(
            50,
            $totals->taxBreakdown()->amountFor($rateTen, $currency)->minorUnits()
        );
        self::assertSame(
            0,
            $totals->taxBreakdown()
                ->amountFor(TaxRate::zero(), $currency)
                ->minorUnits()
        );
    }

    public function testRejectsMixedCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Totals::fromItems([
            $this->item(Currency::fromCode('EUR'), 100, 1, 0),
            $this->item(Currency::fromCode('GBP'), 100, 1, 0),
        ], Currency::fromCode('EUR'));
    }
}
