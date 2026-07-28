<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;

final class PluginIsolationTest extends TestCase
{
    public function testWooCommerceEntryPointHasIndependentIdentityAndDependency(): void
    {
        $entry = file_get_contents(
            dirname(__DIR__, 2)
            . '/plugins/commerce-documents-woocommerce/commerce-documents-woocommerce.php'
        );

        self::assertStringContainsString('Plugin Name: Commerce Documents for WooCommerce', $entry);
        self::assertStringContainsString('Requires Plugins: woocommerce', $entry);
        self::assertStringContainsString('Text Domain: commerce-documents-woocommerce', $entry);
        self::assertStringNotContainsString('LDC_', $entry);
        self::assertStringNotContainsString('ldc_', $entry);
    }

    public function testManualLegacyRuntimeIsNotPresentInRepository(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/ldc-invoice-generator.php');
        self::assertDirectoryDoesNotExist(
            dirname(__DIR__, 2) . '/plugins/commerce-documents-woocommerce/assets/legacy'
        );
    }
}
