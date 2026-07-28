<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\WooCommerce\AdminSettings;

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
            'proforma_statuses' => ['pending', 'unknown'],
            'invoice_statuses' => ['completed'],
            'policy_name' => 'injected-policy',
            'policy_version' => 99,
        ], ['pending', 'processing', 'completed']);

        self::assertSame('Seller', $settings['seller']['name']);
        self::assertSame('PL', $settings['seller']['address']['country_code']);
        self::assertSame(['pending'], $settings['proforma_statuses']);
        self::assertSame(['completed'], $settings['invoice_statuses']);
        self::assertSame('woocommerce-status-policy', $settings['policy_name']);
        self::assertSame(1, $settings['policy_version']);
        self::assertTrue(AdminSettings::isComplete($settings));
    }

    public function testIncompleteSettingsCannotGenerate(): void
    {
        $settings = AdminSettings::sanitize([
            'seller' => ['name' => 'Seller', 'address' => []],
            'proforma_statuses' => [],
            'invoice_statuses' => [],
        ], ['pending']);

        self::assertFalse(AdminSettings::isComplete($settings));
    }

    public function testUnknownLanguageFallsBackToPolish(): void
    {
        $settings = AdminSettings::sanitize(['language' => 'de'], []);
        self::assertSame('pl-PL', $settings['language']);
    }
}
