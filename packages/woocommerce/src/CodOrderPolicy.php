<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use InvalidArgumentException;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\WooCommerce\Contracts\OrderGenerationPolicy;

/**
 * Explicit, operator-triggered policy for an unpaid cash-on-delivery order.
 *
 * It is intentionally separate from PaidOrderPolicy: COD is never a confirmed
 * payment and must not become an automatic document merely because its order
 * status is processing. The resulting order confirmation carries the explicit
 * unpaid notice in its immutable metadata.
 */
final class CodOrderPolicy implements OrderGenerationPolicy
{
    // A completed COD order has normally been collected by the carrier. WooCommerce
    // does not reliably set paid_at for offline gateways, so it must not receive an
    // immutable "unpaid" notice merely because that date is empty.
    public const DEFAULT_STATUSES = ['pending', 'on-hold', 'processing'];

    /** @var string[] */
    private $statuses;
    /** @var string[] */
    private $methods;
    /** @var string */
    private $name;
    /** @var int */
    private $version;

    /**
     * @param string[] $statuses
     * @param string[] $methods
     */
    public function __construct(
        array $statuses = self::DEFAULT_STATUSES,
        array $methods = ['cod'],
        string $name = 'cod-order-confirmation',
        int $version = 1
    ) {
        if (trim($name) === '' || $version < 1) {
            throw new InvalidArgumentException('Policy name and positive version are required.');
        }
        $this->statuses = array_values(array_unique(array_map('strval', $statuses)));
        $this->methods = array_values(array_unique(array_filter(array_map(
            static function ($method): string {
                return strtolower(trim((string) $method));
            },
            $methods
        ), static function (string $method): bool {
            return $method !== '';
        })));
        $this->name = trim($name);
        $this->version = $version;
    }

    public function documentTypeFor(OrderData $order): ?DocumentType
    {
        return $this->qualifies($order)
            ? DocumentType::fromString(DocumentType::ORDER_CONFIRMATION)
            : null;
    }

    public function qualifies(OrderData $order): bool
    {
        return in_array($order->status, $this->statuses, true)
            && $order->paidAt === ''
            && in_array(strtolower($order->paymentMethod), $this->methods, true);
    }

    /** @return array<string, scalar|null> */
    public function decision(OrderData $order): array
    {
        return [
            'policy' => $this->name,
            'policy_version' => $this->version,
            'payment_method' => $order->paymentMethod,
            'payment_confirmed' => 'no',
            'payment_status' => 'cash_on_delivery_unpaid',
            'payment_notice' => 'Nieopłacone — płatność przy odbiorze',
        ];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): int
    {
        return $this->version;
    }
}
