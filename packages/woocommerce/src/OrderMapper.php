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
            throw new RuntimeException('This order does not qualify for a document under the active policy.');
        }

        // Prefer the gateway's payment date so the document carries the moment the
        // money was confirmed, not the moment the request happened to run.
        $issuedAt = $order->paidAt !== '' ? $order->paidAt : $generatedAt;

        if ($policy instanceof PaidOrderPolicy || $policy instanceof CodOrderPolicy || $policy instanceof OrderConfirmationPolicy) {
            $metadata = $policy->decision($order);
        } else {
            $metadata = [
                'payment_method' => $order->paymentMethod,
                'payment_confirmed' => $order->paidAt !== '' ? 'yes' : 'no',
                'payment_status' => $order->paidAt !== '' ? 'paid' : 'unpaid',
            ];
        }
        $metadata['order_number'] = $order->orderId;
        $metadata['order_status'] = $order->status;

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
            $issuedAt,
            $metadata
        );
    }
}
