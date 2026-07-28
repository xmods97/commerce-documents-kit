<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use Throwable;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Application\GenerateDocument;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\WordPress\WpdbDocumentRepository;
use Xmods\CommerceDocuments\WordPress\WpdbEventLogger;
use Xmods\CommerceDocuments\WordPress\WpdbNumberGenerator;

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

        if (get_option('commerce_documents_wc_shadow_enabled', false) !== true) {
            return;
        }

        try {
            self::generateShadowSnapshot($order);
        } catch (Throwable $error) {
            error_log('Commerce Documents shadow generation failed: ' . $error->getMessage());
            do_action('commerce_documents_generation_failed', $orderId, $error);
        }
    }

    private static function generateShadowSnapshot($order): void
    {
        global $wpdb;
        $settings = get_option('commerce_documents_wc_settings', []);
        if (!is_array($settings)) {
            return;
        }
        $required = ['seller', 'language', 'proforma_statuses', 'invoice_statuses', 'policy_name'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $settings)) {
                return;
            }
        }

        $seller = (array) $settings['seller'];
        $address = (array) ($seller['address'] ?? []);
        $sellerParty = Party::create(
            (string) ($seller['name'] ?? ''),
            (string) ($seller['tax_identifier'] ?? ''),
            (string) ($seller['email'] ?? ''),
            Address::create(
                (string) ($address['line1'] ?? ''),
                (string) ($address['line2'] ?? ''),
                (string) ($address['postal_code'] ?? ''),
                (string) ($address['city'] ?? ''),
                (string) ($address['region'] ?? ''),
                (string) ($address['country_code'] ?? '')
            )
        );

        $adapter = new NativeOrderAdapter(
            $sellerParty,
            Language::fromTag((string) $settings['language']),
            function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2
        );
        $policy = new ConfigurableStatusPolicy(
            (array) $settings['proforma_statuses'],
            (array) $settings['invoice_statuses'],
            (string) $settings['policy_name'],
            (int) ($settings['policy_version'] ?? 1)
        );
        $request = (new OrderMapper())->map($adapter->map($order), $policy, gmdate(DATE_ATOM));

        $prefix = $wpdb->prefix;
        $service = new GenerateDocument(
            new WpdbDocumentRepository($wpdb, $prefix . 'commerce_documents'),
            new WpdbNumberGenerator($wpdb, $prefix . 'commerce_document_sequences'),
            new WpdbEventLogger($wpdb, $prefix . 'commerce_document_events')
        );
        $snapshot = $service->execute($request);

        do_action(
            'commerce_documents_shadow_snapshot_ready',
            $snapshot->toArray()['document_id'],
            $order
        );
    }
}
