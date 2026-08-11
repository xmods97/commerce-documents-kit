<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use RuntimeException;
use Xmods\CommerceDocuments\Application\GenerationRequest;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\WooCommerce\Contracts\OrderGenerationPolicy;

final class OrderMapper
{
    public function map(
        OrderData $order,
        OrderGenerationPolicy $policy,
        string $generatedAt
    ): GenerationRequest {
        $type = $policy->documentTypeFor($order);
        if ($type === null) {
            throw new RuntimeException('The current order status does not trigger a document.');
        }

        $issuedAt = $order->paidAt !== ''
            ? $order->paidAt
            : $generatedAt;

        return new GenerationRequest(
            $type,
            DocumentStatus::fromString(DocumentStatus::ISSUED),
            'woocommerce_order',
            $order->orderId,
            $policy->name(),
            $policy->version(),
            $order->currency,
            $order->language,
            $order->seller,
            $order->buyer,
            $order->items,
            $generatedAt,
            $issuedAt
        );
    }
}
