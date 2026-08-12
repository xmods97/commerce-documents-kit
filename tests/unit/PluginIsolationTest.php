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

    /**
     * The PDF renderer is now wired, to one admin preview and nowhere else. This
     * pins that shape: the invariant is no longer "nothing is wired" but "exactly
     * this is wired", which is the version worth defending.
     */
    public function testThePdfRendererIsWiredOnlyToTheAdminPreview(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/packages/woocommerce/src/AdminController.php');
        $plugin = (string) file_get_contents($root . '/packages/woocommerce/src/Plugin.php');

        // Exactly one construction, inside the preview factory.
        self::assertSame(1, substr_count($controller, 'new EmbeddedFontPdfRenderer('));
        self::assertMatchesRegularExpression(
            '/private static function pdfRenderer\(\).*?new EmbeddedFontPdfRenderer\(/s',
            $controller
        );
        self::assertStringContainsString('new WordPressLogoProvider()', $controller);

        // Registered on admin_post, unreachable from any order hook.
        self::assertStringContainsString(
            "add_action('admin_post_commerce_documents_preview_pdf', [self::class, 'previewPdf'])",
            $controller
        );
        self::assertStringContainsString(
            "add_action('admin_post_commerce_documents_sandbox_email', [self::class, 'sandboxEmail'])",
            $controller
        );
        self::assertStringNotContainsString('previewPdf', $plugin);
        self::assertStringNotContainsString('sandboxEmail', $plugin);

        // Capability, then a nonce bound to the requested document.
        self::assertMatchesRegularExpression(
            "/function previewPdf\(\): void\s*\{\s*if \(!current_user_can\('manage_woocommerce'\)\)/",
            $controller
        );
        self::assertStringContainsString(
            "check_admin_referer('commerce_documents_preview_pdf_' . \$documentId)",
            $controller
        );

        // A preview, not a delivery: no transport, no queue, no write.
        self::assertDoesNotMatchRegularExpression(
            '/\b(wp_mail|fsockopen|curl_\w+|wp_remote_\w+|file_put_contents|wp_schedule_)\w*\s*\(/',
            $controller
        );
    }

    public function testOnlyTheExplicitAdminSandboxActionConstructsLocalDelivery(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/packages/woocommerce/src/AdminController.php');
        $plugin = (string) file_get_contents($root . '/packages/woocommerce/src/Plugin.php');
        self::assertSame(1, substr_count($controller, 'new SandboxMailer('));
        self::assertSame(1, substr_count($controller, 'new DeliverDocument('));
        self::assertStringNotContainsString('new SandboxMailer(', $plugin);
        self::assertStringNotContainsString('new DeliverDocument(', $plugin);
        self::assertStringContainsString("'document.sandbox_stored'", $controller);
        self::assertStringNotContainsString('wp_mail(', $controller);
    }

    public function testSandboxDeliveryIsLocalAndUsesNoTransport(): void
    {
        $controller = (string) file_get_contents(
            dirname(__DIR__, 2) . '/packages/woocommerce/src/AdminController.php'
        );
        self::assertStringContainsString("new SandboxMailer(self::sandboxMailDirectory(), 'sandbox@example.invalid')", $controller);
        self::assertStringContainsString("'document.sandbox_stored'", $controller);
        self::assertStringContainsString('COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR', $controller);
        self::assertDoesNotMatchRegularExpression(
            '/\b(wp_mail|fsockopen|curl_\w+|wp_remote_\w+|wp_schedule_)\w*\s*\(/',
            $controller
        );
    }
}
