<?php
/**
 * Plugin Name: Commerce Documents for WooCommerce
 * Description: Issues internal order-confirmation documents for paid WooCommerce orders. Fiscal invoices are not generated automatically.
 * Version: 0.3.2
 * Update URI: https://github.com/xmods97/commerce-documents-kit
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

\Xmods\CommerceDocuments\WordPress\GitHubReleaseUpdater::boot(__FILE__);

register_activation_hook(
    __FILE__,
    [\Xmods\CommerceDocuments\WordPress\Installer::class, 'activate']
);

/*
 * High-Performance Order Storage. All order access goes through the WooCommerce
 * CRUD API (wc_get_order, $order->get_*), never through post meta or wp_posts,
 * so the plugin is compatible with both storage backends. Without this
 * declaration WooCommerce marks the plugin incompatible and blocks HPOS.
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Commerce Documents requires WooCommerce.', 'commerce-documents-woocommerce')
                . '</p></div>';
        });
        return;
    }

    \Xmods\CommerceDocuments\WooCommerce\Plugin::boot();
});
