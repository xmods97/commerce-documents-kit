<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

final class AdminSettings
{
    /**
     * @param array<string, mixed> $input
     * @param string[] $allowedStatuses
     * @return array<string, mixed>
     */
    public static function sanitize(array $input, array $allowedStatuses): array
    {
        $seller = (array) ($input['seller'] ?? []);
        $address = (array) ($seller['address'] ?? []);
        $cleanStatuses = static function ($values) use ($allowedStatuses): array {
            $values = array_map('strval', (array) $values);
            return array_values(array_intersect($allowedStatuses, array_unique($values)));
        };
        $language = (string) ($input['language'] ?? 'pl-PL');
        if (!in_array($language, ['pl-PL', 'en'], true)) {
            $language = 'pl-PL';
        }

        $codPolicy = (string) ($input['cod_policy'] ?? PaidOrderPolicy::COD_POLICY_NEVER);
        if (!in_array($codPolicy, [PaidOrderPolicy::COD_POLICY_NEVER, PaidOrderPolicy::COD_POLICY_STATUS_ONLY], true)) {
            $codPolicy = PaidOrderPolicy::COD_POLICY_NEVER;
        }
        $offlineMethods = array_values(array_filter(array_map(
            static function ($method): string {
                return strtolower(trim((string) $method));
            },
            preg_split('/[\s,]+/', (string) ($input['cod_offline_methods'] ?? '')) ?: []
        ), static function (string $method): bool {
            return $method !== '' && preg_match('/^[a-z0-9_\-]{1,64}$/D', $method) === 1;
        }));

        $orderConfirmationStatuses = array_key_exists('order_confirmation_statuses_present', $input)
            ? $cleanStatuses($input['order_confirmation_statuses'] ?? [])
            : $cleanStatuses(
                $input['order_confirmation_statuses']
                    ?? $input['paid_statuses']
                    ?? ['pending', 'on-hold', 'processing']
            );
        $paymentConfirmationStatuses = array_key_exists('payment_confirmation_statuses_present', $input)
            ? $cleanStatuses($input['payment_confirmation_statuses'] ?? [])
            : $cleanStatuses(
                $input['payment_confirmation_statuses']
                    ?? $input['paid_statuses']
                    ?? PaidOrderPolicy::DEFAULT_PAID_STATUSES
            );

        return [
            'seller_source' => ($input['seller_source'] ?? '') === 'woocommerce'
                ? 'woocommerce'
                : 'manual',
            'seller' => [
                'name' => trim((string) ($seller['name'] ?? '')),
                'tax_identifier' => trim((string) ($seller['tax_identifier'] ?? '')),
                'email' => trim((string) ($seller['email'] ?? '')),
                'address' => [
                    'line1' => trim((string) ($address['line1'] ?? '')),
                    'line2' => trim((string) ($address['line2'] ?? '')),
                    'postal_code' => trim((string) ($address['postal_code'] ?? '')),
                    'city' => trim((string) ($address['city'] ?? '')),
                    'region' => trim((string) ($address['region'] ?? '')),
                    'country_code' => strtoupper(trim((string) ($address['country_code'] ?? ''))),
                ],
            ],
            'language' => $language,
            // The first confirmation is issued at checkout; the second only
            // after WooCommerce has confirmed payment. Their status matrices
            // are intentionally independent.
            'order_confirmation_statuses' => $orderConfirmationStatuses,
            'payment_confirmation_statuses' => $paymentConfirmationStatuses,
            'cod_policy' => $codPolicy,
            'cod_offline_methods' => $offlineMethods,
            'policy_name' => 'payment-confirmation',
            'policy_version' => 1,
        ];
    }

    /**
     * @param callable(string, mixed): mixed $option
     * @return array<string, mixed>
     */
    public static function resolveSeller(array $settings, callable $option): array
    {
        $seller = (array) ($settings['seller'] ?? []);
        if (($settings['seller_source'] ?? 'manual') !== 'woocommerce') {
            return $seller;
        }

        $countryRegion = explode(':', (string) $option('woocommerce_default_country', ''), 2);
        return [
            'name' => trim((string) $option('blogname', '')),
            'tax_identifier' => trim((string) ($seller['tax_identifier'] ?? '')),
            'email' => trim((string) $option('admin_email', '')),
            'address' => [
                'line1' => trim((string) $option('woocommerce_store_address', '')),
                'line2' => trim((string) $option('woocommerce_store_address_2', '')),
                'postal_code' => trim((string) $option('woocommerce_store_postcode', '')),
                'city' => trim((string) $option('woocommerce_store_city', '')),
                'region' => trim((string) ($countryRegion[1] ?? '')),
                'country_code' => strtoupper(trim((string) ($countryRegion[0] ?? ''))),
            ],
        ];
    }

    public static function isComplete(
        array $settings,
        bool $orderConfirmationEnabled = true,
        bool $paymentConfirmationEnabled = true
    ): bool
    {
        $seller = (array) ($settings['seller'] ?? []);
        $address = (array) ($seller['address'] ?? []);
        return trim((string) ($seller['name'] ?? '')) !== ''
            && trim((string) ($address['line1'] ?? '')) !== ''
            && trim((string) ($address['postal_code'] ?? '')) !== ''
            && trim((string) ($address['city'] ?? '')) !== ''
            && preg_match('/^[A-Z]{2}$/D', (string) ($address['country_code'] ?? '')) === 1
            && in_array((string) ($settings['language'] ?? ''), ['pl-PL', 'en'], true)
            && trim((string) ($settings['policy_name'] ?? '')) !== ''
            && (int) ($settings['policy_version'] ?? 0) >= 1
            && (!$orderConfirmationEnabled || (array) (
                $settings['order_confirmation_statuses']
                    ?? $settings['paid_statuses']
                ?? []
            ) !== [])
            && (!$paymentConfirmationEnabled || (array) ($settings['payment_confirmation_statuses'] ?? []) !== []);
    }
}
