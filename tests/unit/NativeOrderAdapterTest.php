<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\WooCommerce\NativeOrderAdapter;

final class NativeOrderAdapterTest extends TestCase
{
    public function testMapsExactWooCommerceSnapshotAmountsAndMetadata(): void
    {
        $seller = Party::create(
            'Seller',
            'SELLER-TEST',
            '',
            Address::create('Seller Street', '', '00-001', 'City', '', 'PL')
        );
        $mapped = (new NativeOrderAdapter($seller, Language::fromTag('en'), 2))
            ->map(new FakeOrder());

        self::assertSame('42', $mapped->orderId);
        self::assertSame('processing', $mapped->status);
        self::assertSame('pl-PL', $mapped->language->tag());
        self::assertSame('Example Buyer', $mapped->buyer->toArray()['name']);
        self::assertCount(2, $mapped->items);
        self::assertSame(998, $mapped->items[0]->net()->minorUnits());
        self::assertSame(199, $mapped->items[0]->tax()->minorUnits());
        self::assertSame(333, $mapped->items[0]->unitNet()->minorUnits());
        self::assertSame(250, $mapped->items[1]->net()->minorUnits());
        self::assertSame('2026-07-28T11:00:00+00:00', $mapped->paidAt);
    }

    public function testPluginUsesSeparateCheckoutAndPaymentConfirmationPolicies(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/packages/woocommerce/src/Plugin.php'
        );

        self::assertStringContainsString(
            "add_action('woocommerce_checkout_order_processed', [self::class, 'observeCheckoutOrder']",
            $source
        );
        self::assertStringContainsString("'commerce_documents_wc_order_confirmation_enabled'", $source);
        self::assertStringContainsString("'commerce_documents_wc_payment_confirmation_enabled'", $source);
        self::assertStringContainsString('generateOrderConfirmationForOrder', $source);
        self::assertStringContainsString('generatePaymentConfirmationForOrder', $source);
        self::assertStringContainsString("'proforma_statuses'", $source);
        self::assertStringContainsString("'invoice_statuses'", $source);
        self::assertStringNotContainsString('wp_mail(', $source);
        self::assertStringNotContainsString('KSeF', $source);
    }

    public function testRejectsAmountThatWouldOverflowEffectiveTaxCalculation(): void
    {
        $adapter = new NativeOrderAdapter(
            Party::create(
                'Seller',
                '',
                '',
                Address::create('Street', '', '00-001', 'City', '', 'PL')
            ),
            Language::fromTag('en'),
            2
        );
        $method = new ReflectionMethod($adapter, 'effectiveRate');
        $method->setAccessible(true);
        $currency = Currency::fromCode('EUR');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the supported tax calculation range');
        $method->invoke(
            $adapter,
            Money::fromMinorUnits(PHP_INT_MIN, $currency),
            Money::fromMinorUnits(1, $currency)
        );
    }
}

final class FakeOrder
{
    public function get_id(): int { return 42; }
    public function get_status(): string { return 'processing'; }
    public function get_currency(): string { return 'EUR'; }
    public function get_date_created(): FakeOrderDate
    {
        return new FakeOrderDate('2026-07-28T10:00:00+00:00');
    }
    public function get_date_paid(): FakeOrderDate
    {
        return new FakeOrderDate('2026-07-28T11:00:00+00:00');
    }
    public function get_items(string $type): array
    {
        return $type === 'line_item' ? [new FakeOrderItem()] : [];
    }
    public function get_shipping_total(): string { return '2.50'; }
    public function get_shipping_tax(): string { return '0.50'; }
    public function get_meta(string $key, bool $single): string
    {
        if ($key === '_commerce_documents_language') {
            return 'pl-PL';
        }
        if ($key === '_billing_vat_id') {
            return 'BUYER-TEST';
        }
        return '';
    }
    public function get_billing_company(): string { return 'Example Buyer'; }
    public function get_billing_first_name(): string { return ''; }
    public function get_billing_last_name(): string { return ''; }
    public function get_billing_email(): string { return 'buyer@example.invalid'; }
    public function get_billing_address_1(): string { return 'Buyer Street'; }
    public function get_billing_address_2(): string { return ''; }
    public function get_billing_postcode(): string { return '00-002'; }
    public function get_billing_city(): string { return 'City'; }
    public function get_billing_state(): string { return ''; }
    public function get_billing_country(): string { return 'PL'; }
}

final class FakeOrderItem
{
    public function get_name(): string { return 'Discounted product'; }
    public function get_quantity(): int { return 3; }
    public function get_total(): string { return '9.98'; }
    public function get_total_tax(): string { return '1.99'; }
    public function get_meta(string $key, bool $single): string { return 'piece'; }
}

final class FakeOrderDate
{
    private $value;
    public function __construct(string $value) { $this->value = $value; }
    public function date(string $format): string { return $this->value; }
}
