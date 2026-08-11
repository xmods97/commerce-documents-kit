<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\TaxRate;
use Xmods\CommerceDocuments\WooCommerce\ConfigurableStatusPolicy;
use Xmods\CommerceDocuments\WooCommerce\OrderData;
use Xmods\CommerceDocuments\WooCommerce\OrderMapper;

final class WooCommerceOrderMapperTest extends TestCase
{
    public function testMapsConfiguredProformaAndInvoiceStatuses(): void
    {
        $policy = new ConfigurableStatusPolicy(['pending'], ['processing', 'completed'], 'test', 1);
        $mapper = new OrderMapper();

        $proforma = $mapper->map($this->order('pending', '', 'cod'), $policy, '2026-07-28T12:00:00+00:00');
        $invoice = $mapper->map(
            $this->order('processing', '2026-07-28T11:00:00+00:00'),
            $policy,
            '2026-07-28T12:00:00+00:00'
        );

        self::assertSame('order_confirmation', $proforma->type->value());
        self::assertSame('2026-07-28T12:00:00+00:00', $proforma->issuedAt);
        self::assertSame('order_confirmation', $invoice->type->value());
        self::assertSame('2026-07-28T11:00:00+00:00', $invoice->issuedAt);
        self::assertSame('42', $invoice->sourceId);
    }

    public function testDoesNotHardcodeUnconfiguredStatus(): void
    {
        $this->expectException(RuntimeException::class);
        (new OrderMapper())->map(
            $this->order('custom-status', ''),
            new ConfigurableStatusPolicy([], [], 'test', 1),
            '2026-07-28T12:00:00+00:00'
        );
    }

    private function order(string $status, string $paidAt, string $paymentMethod = ''): OrderData
    {
        $currency = Currency::fromCode('EUR');
        $address = Address::create('1 Test Street', '', '00-001', 'Test City', '', 'PL');
        return new OrderData(
            '42',
            $status,
            '2026-07-28T10:00:00+00:00',
            $paidAt,
            $currency,
            Language::fromTag('en'),
            Party::create('Seller', 'TEST', '', $address),
            Party::create('Buyer', 'TEST', '', $address),
            [
                DocumentItem::create(
                    'Item',
                    Quantity::one(),
                    'unit',
                    Money::fromMinorUnits(1000, $currency),
                    TaxRate::zero()
                ),
            ],
            $paymentMethod
        );
    }
}
