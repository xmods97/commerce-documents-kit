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
        self::assertStringContainsString("add_action('plugins_loaded'", $entry);
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

    public function testAdminActionsRequireCapabilityAndNonces(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/packages/woocommerce/src/AdminController.php'
        );

        self::assertStringContainsString("current_user_can('manage_woocommerce')", $controller);
        self::assertStringContainsString('check_admin_referer', $controller);
        self::assertStringContainsString('wp_nonce_url', $controller);
        self::assertStringNotContainsString('wp_mail(', $controller);
        self::assertStringNotContainsString('FiscalizationGateway', $controller);
    }
}
