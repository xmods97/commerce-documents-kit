<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;

final class BuildConfigurationTest extends TestCase
{
    public function testBuildIsLocalOnlyAndHasNoReleasePublishing(): void
    {
        $root = dirname(__DIR__, 2);
        $script = file_get_contents($root . '/tools/build-woocommerce.ps1');
        $workflow = file_get_contents($root . '/.github/workflows/ci.yml');

        self::assertStringContainsString('commerce-documents-woocommerce.zip', $script);
        self::assertStringContainsString('SHA256', $script);
        self::assertStringNotContainsString('gh release', $script);
        self::assertStringNotContainsString('action-gh-release', $workflow);
        self::assertStringContainsString('contents: read', $workflow);
    }
}
