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

        return [
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
            'proforma_statuses' => $cleanStatuses($input['proforma_statuses'] ?? []),
            'invoice_statuses' => $cleanStatuses($input['invoice_statuses'] ?? []),
            'policy_name' => 'woocommerce-status-policy',
            'policy_version' => 1,
        ];
    }

    public static function isComplete(array $settings): bool
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
            && (
                (array) ($settings['proforma_statuses'] ?? []) !== []
                || (array) ($settings['invoice_statuses'] ?? []) !== []
            );
    }
}
