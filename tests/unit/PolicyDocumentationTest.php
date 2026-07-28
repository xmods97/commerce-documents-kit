<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;

final class PolicyDocumentationTest extends TestCase
{
    public function testPolandPolicyDoesNotDefineProductionDefaultsOrSecrets(): void
    {
        $policy = file_get_contents(dirname(__DIR__, 2) . '/docs/policies/poland.md');

        self::assertStringContainsString('proforma', $policy);
        self::assertStringContainsString('not submitted to KSeF', $policy);
        self::assertStringContainsString('FA(3)', $policy);
        self::assertStringContainsString('Deliberately not implemented yet', $policy);
        self::assertStringNotContainsString('default VAT rate:', $policy);
        self::assertStringNotContainsString('api_key', strtolower($policy));
    }
}
