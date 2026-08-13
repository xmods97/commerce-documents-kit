<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\WooCommerce\Contracts\OrderGenerationPolicy;

/** Creates the first internal confirmation immediately after checkout. */
final class OrderConfirmationPolicy implements OrderGenerationPolicy
{
    /** @var string[] */
    private $statuses;

    /** @param string[] $statuses */
    public function __construct(array $statuses, string $name = 'order-created-confirmation', int $version = 1)
    {
        $this->statuses = array_values(array_unique(array_map('strval', $statuses)));
        if ($this->statuses === [] || trim($name) === '' || $version < 1) {
            throw new \InvalidArgumentException('Order confirmation statuses and policy identity are required.');
        }
        $this->name = trim($name);
        $this->version = $version;
    }

    /** @var string */
    private $name;
    /** @var int */
    private $version;

    public function documentTypeFor(OrderData $order): ?DocumentType
    {
        // Some gateways confirm payment inside the checkout request before this
        // hook runs. Do not backdate an immutable "unpaid" document then; the
        // payment-confirmation policy owns that case.
        return $order->paidAt === ''
            && in_array($order->status, $this->statuses, true)
            ? DocumentType::fromString(DocumentType::ORDER_CONFIRMATION)
            : null;
    }

    /** @return array<string, scalar|null> */
    public function decision(OrderData $order): array
    {
        return [
            'policy' => $this->name,
            'policy_version' => $this->version,
            'payment_method' => $order->paymentMethod,
            'payment_confirmed' => 'no',
            'payment_status' => 'order_created_unpaid',
            'payment_badge' => 'unpaid',
            'payment_notice' => 'NIEOPŁACONE — płatność niepotwierdzona',
        ];
    }

    public function name(): string { return $this->name; }
    public function version(): int { return $this->version; }
}
