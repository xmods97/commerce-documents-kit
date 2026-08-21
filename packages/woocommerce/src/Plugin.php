<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use Throwable;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Application\GenerateDocument;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\WordPress\WpdbDocumentRepository;
use Xmods\CommerceDocuments\WordPress\WpdbEventLogger;
use Xmods\CommerceDocuments\WordPress\WpdbNumberGenerator;
use Xmods\CommerceDocuments\WordPress\ConfigKeyProvider;
use Xmods\CommerceDocuments\WordPress\EncryptedSnapshotCodec;
use Xmods\CommerceDocuments\WordPress\OpenSslAesGcmCipher;

final class Plugin
{
    private const VERSION = '0.2.2';

    private function __construct()
    {
    }

    public static function boot(): void
    {
        add_action('init', [self::class, 'loadTranslations']);
        add_action('woocommerce_checkout_order_processed', [self::class, 'observeCheckoutOrder'], 20, 3);
        add_action('woocommerce_order_status_changed', [self::class, 'observeOrderStatus'], 10, 4);
        CustomerController::boot();
        if (is_admin()) {
            AdminController::boot();
        }
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

        $orderConfirmationEnabled = (string) get_option(
            'commerce_documents_wc_order_confirmation_enabled',
            '0'
        ) === '1';
        $paymentConfirmationEnabled = (string) get_option(
            'commerce_documents_wc_payment_confirmation_enabled',
            '0'
        ) === '1';
        if (!$orderConfirmationEnabled && !$paymentConfirmationEnabled) {
            return;
        }

        // Gateways such as BACS set on-hold after checkout_order_processed.
        // Re-evaluate the unpaid policy here as well; source/type idempotency
        // makes the checkout and status hooks safe to run in either order.
        if ($orderConfirmationEnabled) {
            try {
                self::generateOrderConfirmationForOrder($order);
            } catch (OrderNotEligibleException $expected) {
                // A selected status can be valid for the other document type;
                // non-qualification is normal control flow, not an error.
            } catch (Throwable $error) {
                error_log('Commerce Documents order confirmation failed: ' . $error->getMessage());
                do_action('commerce_documents_generation_failed', $orderId, $error);
            }
        }

        if ($paymentConfirmationEnabled) {
            try {
                self::generatePaymentConfirmationForOrder($order);
            } catch (OrderNotEligibleException $expected) {
                // Payment confirmation also requires the gateway payment date.
            } catch (Throwable $error) {
                error_log('Commerce Documents payment confirmation failed: ' . $error->getMessage());
                do_action('commerce_documents_generation_failed', $orderId, $error);
            }
        }
    }

    /** @param mixed $postedData @param mixed $order */
    public static function observeCheckoutOrder(int $orderId, $postedData, $order): void
    {
        if ((string) get_option('commerce_documents_wc_order_confirmation_enabled', '0') !== '1') {
            return;
        }
        if (!is_object($order) && function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
        }
        if (!is_object($order)) {
            return;
        }
        try {
            self::generateOrderConfirmationForOrder($order);
        } catch (OrderNotEligibleException $expected) {
            return;
        } catch (Throwable $error) {
            error_log('Commerce Documents checkout confirmation failed: ' . $error->getMessage());
            do_action('commerce_documents_generation_failed', $orderId, $error);
        }
    }

    public static function generateForOrder($order): DocumentSnapshot
    {
        return self::generatePaymentConfirmationForOrder($order);
    }

    public static function generateOrderConfirmationForOrder($order): DocumentSnapshot
    {
        return self::generateWithPolicy($order, self::orderConfirmationPolicy());
    }

    public static function generatePaymentConfirmationForOrder($order): DocumentSnapshot
    {
        return self::generateWithPolicy($order, self::paidPolicy());
    }

    public static function generateCodForOrder($order): DocumentSnapshot
    {
        $settings = get_option('commerce_documents_wc_settings', []);
        $methods = is_array($settings) ? (array) ($settings['cod_offline_methods'] ?? []) : [];
        if ($methods === []) {
            $methods = ['cod'];
        }
        return self::generateWithPolicy(
            $order,
            new CodOrderPolicy(CodOrderPolicy::DEFAULT_STATUSES, $methods)
        );
    }

    private static function generateWithPolicy($order, \Xmods\CommerceDocuments\WooCommerce\Contracts\OrderGenerationPolicy $policy): DocumentSnapshot
    {
        global $wpdb;
        $settings = get_option('commerce_documents_wc_settings', []);
        if (!is_array($settings)) {
            throw new \RuntimeException('Complete Commerce Documents settings before generating documents.');
        }

        $seller = AdminSettings::resolveSeller(
            $settings,
            static function (string $name, $default) {
                return get_option($name, $default);
            }
        );
        $resolvedSettings = $settings;
        $resolvedSettings['seller'] = $seller;
        $orderConfirmationEnabled = (string) get_option(
            'commerce_documents_wc_order_confirmation_enabled',
            '0'
        ) === '1';
        $paymentConfirmationEnabled = (string) get_option(
            'commerce_documents_wc_payment_confirmation_enabled',
            '0'
        ) === '1';
        if (!AdminSettings::isComplete($resolvedSettings, $orderConfirmationEnabled, $paymentConfirmationEnabled)) {
            throw new \RuntimeException('Complete Commerce Documents settings before generating documents.');
        }
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
            Language::fromTag((string) ($settings['language'] ?? 'pl-PL')),
            function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2
        );
        $request = (new OrderMapper())->map($adapter->map($order), $policy, gmdate(DATE_ATOM));

        $prefix = $wpdb->prefix;
        $service = new GenerateDocument(
            new WpdbDocumentRepository(
                $wpdb,
                $prefix . 'commerce_documents',
                new EncryptedSnapshotCodec(new OpenSslAesGcmCipher(ConfigKeyProvider::encryptionKey()))
            ),
            new WpdbNumberGenerator($wpdb, $prefix . 'commerce_document_sequences'),
            new WpdbEventLogger($wpdb, $prefix . 'commerce_document_events', ConfigKeyProvider::auditKey())
        );
        $snapshot = $service->execute($request);

        do_action(
            'commerce_documents_shadow_snapshot_ready',
            $snapshot->toArray()['document_id'],
            $order
        );
        return $snapshot;
    }

    private static function paidPolicy(): PaidOrderPolicy
    {
        $settings = get_option('commerce_documents_wc_settings', []);
        $settings = is_array($settings) ? $settings : [];
        return new PaidOrderPolicy(
            (array) (
                $settings['payment_confirmation_statuses']
                    ?? $settings['paid_statuses']
                    ?? PaidOrderPolicy::DEFAULT_PAID_STATUSES
            ),
            // COD is never an automatic paid document. Its separate manual
            // action uses CodOrderPolicy and stamps the unpaid notice.
            PaidOrderPolicy::COD_POLICY_NEVER,
            [],
            'payment-confirmation',
            (int) ($settings['policy_version'] ?? 1)
        );
    }

    private static function orderConfirmationPolicy(): OrderConfirmationPolicy
    {
        $settings = get_option('commerce_documents_wc_settings', []);
        $settings = is_array($settings) ? $settings : [];
        return new OrderConfirmationPolicy(
            (array) (
                $settings['order_confirmation_statuses']
                    ?? $settings['paid_statuses']
                    ?? ['pending', 'on-hold', 'processing']
            )
        );
    }
}
