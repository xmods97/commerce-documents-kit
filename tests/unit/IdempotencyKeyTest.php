<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\IdempotencyKey;

final class IdempotencyKeyTest extends TestCase
{
    public function testIsDeterministicAndIgnoresPolicyChanges(): void
    {
        $type = DocumentType::fromString(DocumentType::INVOICE);
        $first = IdempotencyKey::forSource('woocommerce_order', '42', $type, 'default', 1);
        $same = IdempotencyKey::forSource('woocommerce_order', '42', $type, 'default', 1);
        $changed = IdempotencyKey::forSource('woocommerce_order', '42', $type, 'default', 2);

        self::assertSame($first->value(), $same->value());
        self::assertSame($first->value(), $changed->value());
        self::assertSame(64, strlen($first->value()));
        self::assertSame($first->value(), IdempotencyKey::fromString($first->value())->value());
    }

    public function testRejectsInvalidSerializedKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IdempotencyKey::fromString('invalid');
    }
}
