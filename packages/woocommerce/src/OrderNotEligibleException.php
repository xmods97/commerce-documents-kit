<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\WooCommerce;

use RuntimeException;

/** An expected policy decision: this order must not produce this document. */
final class OrderNotEligibleException extends RuntimeException
{
}
