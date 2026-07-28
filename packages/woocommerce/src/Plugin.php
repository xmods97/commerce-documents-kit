<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

final class Plugin
{
    private const VERSION = '0.1.0';

    private function __construct()
    {
    }

    public static function boot(): void
    {
        add_action('init', [self::class, 'loadTranslations']);
        add_action('woocommerce_order_status_changed', [self::class, 'observeOrderStatus'], 10, 4);
    }

    public static function loadTranslations(): void
    {
        load_plugin_textdomain(
            'commerce-documents-woocommerce',
            false,
            'commerce-documents-woocommerce/languages'
        );
    }

    /**
     * Observation-only boundary until the persistent repository is installed.
     *
     * @param mixed $order
     */
    public static function observeOrderStatus(
        int $orderId,
        string $from,
        string $to,
        $order
    ): void {
        do_action(
            'commerce_documents_order_status_observed',
            $orderId,
            $from,
            $to,
            $order,
            self::VERSION
        );
    }
}
