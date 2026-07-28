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

final class DocumentItemTest extends TestCase
{
    private function item(): DocumentItem
    {
        return DocumentItem::create(
            'Universal service',
            Quantity::fromScaledUnits(25, 1),
            'unit',
            Money::fromMinorUnits(1000, Currency::fromCode('EUR')),
            TaxRate::fromPartsPerMillion(200000)
        );
    }

    public function testCreatesImmutableSnapshotAndCalculatesAmounts(): void
    {
        $item = $this->item();

        self::assertSame('Universal service', $item->description());
        self::assertSame('unit', $item->unit());
        self::assertSame(2500, $item->net()->minorUnits());
        self::assertSame(500, $item->tax()->minorUnits());
        self::assertSame(3000, $item->gross()->minorUnits());
    }

    /**
     * @dataProvider invalidText
     */
    public function testRejectsEmptyRequiredText(string $description, string $unit): void
    {
        $this->expectException(InvalidArgumentException::class);
        DocumentItem::create(
            $description,
            Quantity::one(),
            $unit,
            Money::fromMinorUnits(100, Currency::fromCode('EUR')),
            TaxRate::zero()
        );
    }

    public function invalidText(): array
    {
        return [
            'description' => ['', 'unit'],
            'unit' => ['Service', '  '],
        ];
    }

    public function testPreservesExplicitOrderLineAmountsAfterDiscounts(): void
    {
        $currency = Currency::fromCode('EUR');
        $item = DocumentItem::fromSnapshotAmounts(
            'Discounted item',
            Quantity::fromScaledUnits(3, 0),
            'unit',
            Money::fromMinorUnits(333, $currency),
            TaxRate::fromPartsPerMillion(200000),
            Money::fromMinorUnits(998, $currency),
            Money::fromMinorUnits(199, $currency)
        );

        self::assertSame(998, $item->net()->minorUnits());
        self::assertSame(199, $item->tax()->minorUnits());
        self::assertSame(1197, $item->gross()->minorUnits());
    }
}
