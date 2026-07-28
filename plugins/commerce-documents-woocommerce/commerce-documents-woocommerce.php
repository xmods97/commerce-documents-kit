<?php
/**
 * Plugin Name: Commerce Documents for WooCommerce
 * Description: Universal proforma and invoice generation foundation for WooCommerce orders.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: xmods97
 * Text Domain: commerce-documents-woocommerce
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$cdkAutoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];

foreach ($cdkAutoloadCandidates as $cdkAutoload) {
    if (is_readable($cdkAutoload)) {
        require_once $cdkAutoload;
        break;
    }
}

if (!class_exists(\Xmods\CommerceDocuments\WooCommerce\Plugin::class)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Commerce Documents could not load its runtime.', 'commerce-documents-woocommerce')
            . '</p></div>';
    });
    return;
}

if (!class_exists('WooCommerce')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Commerce Documents requires WooCommerce.', 'commerce-documents-woocommerce')
            . '</p></div>';
    });
    return;
}

register_activation_hook(
    __FILE__,
    [\Xmods\CommerceDocuments\WordPress\Installer::class, 'activate']
);

\Xmods\CommerceDocuments\WooCommerce\Plugin::boot();
