<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use InvalidArgumentException;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Party;

final class OrderData
{
    /** @var string */
    public $orderId;
    /** @var string */
    public $status;
    /** @var string */
    public $createdAt;
    /** @var string */
    public $paidAt;
    /** @var Currency */
    public $currency;
    /** @var Language */
    public $language;
    /** @var Party */
    public $seller;
    /** @var Party */
    public $buyer;
    /** @var array */
    public $items;

    public function __construct(
        string $orderId,
        string $status,
        string $createdAt,
        string $paidAt,
        Currency $currency,
        Language $language,
        Party $seller,
        Party $buyer,
        array $items
    ) {
        if (trim($orderId) === '' || trim($status) === '') {
            throw new InvalidArgumentException('Order ID and status are required.');
        }

        $this->orderId = trim($orderId);
        $this->status = trim($status);
        $this->createdAt = $createdAt;
        $this->paidAt = $paidAt;
        $this->currency = $currency;
        $this->language = $language;
        $this->seller = $seller;
        $this->buyer = $buyer;
        $this->items = $items;
    }
}
