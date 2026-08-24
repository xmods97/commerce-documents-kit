<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;

final class GitHubReleaseUpdaterTest extends TestCase
{
    public function testUpdaterUsesExpectedAssetsAndServerOnlyToken(): void
    {
        $root = dirname(__DIR__, 2);
        $updater = file_get_contents($root . '/packages/wordpress/src/GitHubReleaseUpdater.php');
        $entry = file_get_contents($root . '/plugins/commerce-documents-woocommerce/commerce-documents-woocommerce.php');

        self::assertStringContainsString("private const ZIP_ASSET = 'commerce-documents-woocommerce.zip'", $updater);
        self::assertStringContainsString("private const CHECKSUM_ASSET = 'commerce-documents-woocommerce.zip.sha256'", $updater);
        self::assertStringContainsString('COMMERCE_DOCUMENTS_GITHUB_TOKEN', $updater);
        self::assertStringContainsString("hash_equals(\$release['sha256'], hash('sha256', \$body))", $updater);
        self::assertStringContainsString('GitHubReleaseUpdater::boot(__FILE__)', $entry);
        self::assertStringNotContainsString("update_option('commerce_documents_github_token'", $updater);
    }
}
