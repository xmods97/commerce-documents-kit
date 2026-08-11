<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\TaxRate;

final class DocumentSnapshotTest extends TestCase
{
    private function snapshot(string $buyerName = 'Test Buyer'): DocumentSnapshot
    {
        $currency = Currency::fromCode('EUR');
        $address = Address::create('1 Test Street', '', '00-001', 'Test City', '', 'PL');

        return DocumentSnapshot::create(
            'doc_test_1',
            'TEST/1',
            DocumentType::fromString(DocumentType::INVOICE),
            DocumentStatus::fromString(DocumentStatus::ISSUED),
            'test_order',
            'order_1',
            $currency,
            Language::fromTag('en'),
            Party::create('Test Seller', 'SELLER-TEST', 'seller@example.invalid', $address),
            Party::create($buyerName, 'BUYER-TEST', 'buyer@example.invalid', $address),
            [
                DocumentItem::create(
                    'Test item',
                    Quantity::fromScaledUnits(25, 1),
                    'unit',
                    Money::fromMinorUnits(1000, $currency),
                    TaxRate::fromPartsPerMillion(200000)
                ),
            ],
            '2026-07-28T10:00:00+00:00',
            '2026-07-28T10:00:00+00:00'
        );
    }

    public function testSerializesAndRestoresDeterministically(): void
    {
        $original = $this->snapshot();
        $restored = DocumentSnapshot::fromArray($original->toArray());

        self::assertSame($original->toArray(), $restored->toArray());
        self::assertSame($original->toJson(), $restored->toJson());
        self::assertSame($original->contentHash(), $restored->contentHash());
        self::assertSame(3000, $original->toArray()['totals']['gross']);
    }

    public function testChangedSourceDataProducesDifferentSnapshotWithoutMutation(): void
    {
        $original = $this->snapshot('Original Buyer');
        $changed = $this->snapshot('Changed Buyer');

        self::assertNotSame($original->contentHash(), $changed->contentHash());
        self::assertSame('Original Buyer', $original->toArray()['buyer']['name']);
    }

    public function testRejectsUnsupportedSchema(): void
    {
        $data = $this->snapshot()->toArray();
        $data['schema_version'] = 3;

        $this->expectException(InvalidArgumentException::class);
        DocumentSnapshot::fromArray($data);
    }

    public function testRejectsNonAtomDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $data = $this->snapshot()->toArray();
        $data['created_at'] = '2026-07-28';
        DocumentSnapshot::fromArray($data);
    }
}
