<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce\Contracts;

use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\WooCommerce\OrderData;

interface OrderGenerationPolicy
{
    public function documentTypeFor(OrderData $order): ?DocumentType;

    public function name(): string;

    public function version(): int;
}
