<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use InvalidArgumentException;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\WooCommerce\Contracts\OrderGenerationPolicy;

final class ConfigurableStatusPolicy implements OrderGenerationPolicy
{
    /** @var string[] */
    private $proformaStatuses;
    /** @var string[] */
    private $invoiceStatuses;
    /** @var string */
    private $name;
    /** @var int */
    private $version;

    public function __construct(
        array $proformaStatuses,
        array $invoiceStatuses,
        string $name,
        int $version
    ) {
        if (trim($name) === '' || $version < 1) {
            throw new InvalidArgumentException('Policy name and positive version are required.');
        }
        $this->proformaStatuses = array_values(array_unique($proformaStatuses));
        $this->invoiceStatuses = array_values(array_unique($invoiceStatuses));
        $this->name = trim($name);
        $this->version = $version;
    }

    public function documentTypeFor(OrderData $order): ?DocumentType
    {
        if ($order->paidAt !== '' && in_array($order->status, $this->invoiceStatuses, true)) {
            return DocumentType::fromString(DocumentType::ORDER_CONFIRMATION);
        }
        if ($this->isCashOnDelivery($order) && in_array($order->status, $this->proformaStatuses, true)) {
            return DocumentType::fromString(DocumentType::ORDER_CONFIRMATION);
        }
        return null;
    }

    private function isCashOnDelivery(OrderData $order): bool
    {
        return in_array(strtolower($order->paymentMethod), ['cod', 'cash_on_delivery', 'przelewy24_cod'], true);
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
