<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/runtime-autoload.php';

use Xmods\CommerceDocuments\WordPress\SandboxMailer;

$checks = 0;
$passes = 0;
$check = static function (string $name, bool $condition) use (&$checks, &$passes): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $passes++;
    echo "PASS: {$name}\n";
};

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/packages/woocommerce/src/AdminController.php');
$mailerSource = (string) file_get_contents($root . '/packages/wordpress/src/SandboxMailer.php');
$check('admin sandbox path runs retention cleanup', strpos($controller, 'purgeExpired(self::sandboxRetentionDays())') !== false);
$check('retention has a bounded wp-config override', strpos($controller, 'COMMERCE_DOCUMENTS_SANDBOX_RETENTION_DAYS') !== false);
$check('retention scans only the local capture directory', strpos($mailerSource, 'new \\DirectoryIterator($this->directory)') !== false);
$check('retention allow-lists generated eml names', strpos($mailerSource, '\\.eml$/D') !== false);
$check('retention never follows symlinks', strpos($mailerSource, '$entry->isLink()') !== false);

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdk-retention-' . bin2hex(random_bytes(8));
mkdir($directory, 0700, true);

try {
    $mailer = new SandboxMailer($directory, 'sandbox@example.invalid');
    $expired = $directory . DIRECTORY_SEPARATOR . 'doc_old-20200101T000000-aaaaaaaaaaaa.eml';
    $recent = $directory . DIRECTORY_SEPARATOR . 'doc_recent-20200101T000000-bbbbbbbbbbbb.eml';
    $foreign = $directory . DIRECTORY_SEPARATOR . 'operator-note.eml';
    $pending = $directory . DIRECTORY_SEPARATOR . 'doc_pending-20200101T000000-cccccccccccc.pending';
    file_put_contents($expired, 'expired');
    file_put_contents($recent, 'recent');
    file_put_contents($foreign, 'foreign');
    file_put_contents($pending, 'pending');
    touch($expired, time() - (8 * 86400));
    touch($recent, time() - (2 * 86400));
    touch($foreign, time() - (30 * 86400));
    touch($pending, time() - (30 * 86400));

    $check('one expired generated eml is removed', $mailer->purgeExpired(7) === 1 && !file_exists($expired));
    $check('recent generated eml remains', file_exists($recent));
    $check('unrecognised eml remains untouched', file_exists($foreign));
    $check('pending artifacts are not removed by eml retention', file_exists($pending));

    $invalid = false;
    try {
        $mailer->purgeExpired(0);
    } catch (InvalidArgumentException $error) {
        $invalid = true;
    }
    $check('zero-day retention is refused', $invalid);
} finally {
    foreach ((array) glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

echo "checks={$checks} pass={$passes} fail=" . ($checks - $passes) . "\n";
exit($checks === $passes ? 0 : 1);
