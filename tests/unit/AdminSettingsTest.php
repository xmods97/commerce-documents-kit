<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\WooCommerce\AdminSettings;
use Xmods\CommerceDocuments\WooCommerce\PaidOrderPolicy;

final class AdminSettingsTest extends TestCase
{
    public function testSanitizesSettingsAndRejectsUnknownStatuses(): void
    {
        $settings = AdminSettings::sanitize([
            'seller' => [
                'name' => ' Seller ',
                'email' => ' seller@example.invalid ',
                'address' => [
                    'line1' => ' Street 1 ',
                    'postal_code' => '00-001',
                    'city' => 'Warsaw',
                    'country_code' => 'pl',
                ],
            ],
            'language' => 'pl-PL',
            'paid_statuses' => ['processing', 'unknown'],
            'policy_name' => 'injected-policy',
            'policy_version' => 99,
        ], ['pending', 'processing', 'completed']);

        self::assertSame('Seller', $settings['seller']['name']);
        self::assertSame('PL', $settings['seller']['address']['country_code']);
        self::assertSame(['processing'], $settings['order_confirmation_statuses']);
        self::assertSame(['processing'], $settings['payment_confirmation_statuses']);
        self::assertSame('payment-confirmation', $settings['policy_name']);
        self::assertSame(1, $settings['policy_version']);
        self::assertTrue(AdminSettings::isComplete($settings));
    }

    public function testProformaAndInvoiceStatusMatricesAreNoLongerAccepted(): void
    {
        $settings = AdminSettings::sanitize([
            'proforma_statuses' => ['pending'],
            'invoice_statuses' => ['completed'],
        ], ['pending', 'completed']);

        self::assertArrayNotHasKey('proforma_statuses', $settings);
        self::assertArrayNotHasKey('invoice_statuses', $settings);
    }

    public function testCashOnDeliveryDefaultsToNeverPaidAndFiltersGatewayIds(): void
    {
        $settings = AdminSettings::sanitize([], []);
        self::assertSame(PaidOrderPolicy::COD_POLICY_NEVER, $settings['cod_policy']);
        self::assertSame([], $settings['cod_offline_methods']);

        $enrolled = AdminSettings::sanitize([
            'cod_policy' => PaidOrderPolicy::COD_POLICY_STATUS_ONLY,
            'cod_offline_methods' => 'COD, bacs  cheque, not valid!',
        ], []);
        self::assertSame(PaidOrderPolicy::COD_POLICY_STATUS_ONLY, $enrolled['cod_policy']);
        self::assertSame(['cod', 'bacs', 'cheque'], $enrolled['cod_offline_methods']);

        $bogus = AdminSettings::sanitize(['cod_policy' => 'anything'], []);
        self::assertSame(PaidOrderPolicy::COD_POLICY_NEVER, $bogus['cod_policy']);
    }

    public function testIncompleteSettingsCannotGenerate(): void
    {
        $settings = AdminSettings::sanitize([
            'seller' => ['name' => 'Seller', 'address' => []],
            'paid_statuses' => [],
        ], ['pending']);

        self::assertFalse(AdminSettings::isComplete($settings));
    }

    public function testUnknownLanguageFallsBackToPolish(): void
    {
        $settings = AdminSettings::sanitize(['language' => 'de'], []);
        self::assertSame('pl-PL', $settings['language']);
    }

    public function testResolvesSellerFromWooCommerceWithoutGuessingTaxIdentifier(): void
    {
        $settings = AdminSettings::sanitize([
            'seller_source' => 'woocommerce',
            'seller' => ['tax_identifier' => 'PL123'],
        ], []);
        $options = [
            'blogname' => 'Example Store',
            'admin_email' => 'store@example.invalid',
            'woocommerce_store_address' => 'Main Street 1',
            'woocommerce_store_address_2' => '',
            'woocommerce_store_postcode' => '00-001',
            'woocommerce_store_city' => 'Warsaw',
            'woocommerce_default_country' => 'PL:MZ',
        ];
        $seller = AdminSettings::resolveSeller(
            $settings,
            static function (string $name, $default) use ($options) {
                return $options[$name] ?? $default;
            }
        );

        self::assertSame('Example Store', $seller['name']);
        self::assertSame('PL123', $seller['tax_identifier']);
        self::assertSame('PL', $seller['address']['country_code']);
        self::assertSame('MZ', $seller['address']['region']);
    }
}
