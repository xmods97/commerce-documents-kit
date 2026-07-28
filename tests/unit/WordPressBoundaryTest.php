<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xmods\CommerceDocuments\WordPress\NativeOptionStore;
use Xmods\CommerceDocuments\WordPress\NativeRequestAuthorizer;
use Xmods\CommerceDocuments\WordPress\Contracts\OptionStore;
use Xmods\CommerceDocuments\WordPress\Contracts\RequestAuthorizer;

final class WordPressBoundaryTest extends TestCase
{
    public function testNativeAdaptersImplementExplicitContracts(): void
    {
        self::assertInstanceOf(OptionStore::class, new NativeOptionStore());
        self::assertInstanceOf(RequestAuthorizer::class, new NativeRequestAuthorizer());
    }

    public function testOptionAdapterFailsClearlyOutsideWordPress(): void
    {
        $this->expectException(RuntimeException::class);
        (new NativeOptionStore())->get('test');
    }
}
