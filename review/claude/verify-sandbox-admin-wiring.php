<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/tools/runtime-autoload.php';

use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Application\DeliverDocument;
use Xmods\CommerceDocuments\Contracts\EventLogger;
use Xmods\CommerceDocuments\Contracts\PdfRenderer;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\TaxRate;
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
$plugin = (string) file_get_contents($root . '/packages/woocommerce/src/Plugin.php');
$check('admin action is registered', strpos($controller, "admin_post_commerce_documents_sandbox_email") !== false);
$check('plugin does not register sandbox delivery', strpos($plugin, 'sandboxEmail') === false);
$check('capability precedes nonce authorization', (bool) preg_match(
    '/function sandboxEmail\(\): void.*?self::authorize\(/s',
    $controller
));
$check('delivery uses a sandbox event', strpos($controller, "'document.sandbox_stored'") !== false);
$check('no transport call exists in controller', preg_match('/\b(wp_mail|mail|fsockopen|curl_\w+|wp_remote_\w+)\s*\(/', $controller) === 0);

final class RuntimeEvents implements EventLogger
{
    public $events = [];
    public function record(string $event, string $documentId, array $context = []): void
    {
        $this->events[] = [$event, $documentId, $context];
    }
}

final class RuntimePdf implements PdfRenderer
{
    public function render(DocumentSnapshot $snapshot): string
    {
        return '%PDF-sandbox-' . $snapshot->toArray()['document_id'];
    }
}

$currency = Currency::fromCode('PLN');
$address = Address::create('1 Test Street', '', '00-001', 'Test City', '', 'PL');
$snapshot = DocumentSnapshot::create(
    'doc_sandbox_check',
    'ORDER_CONFIRMATION/2026/000001',
    DocumentType::fromString(DocumentType::ORDER_CONFIRMATION),
    DocumentStatus::fromString(DocumentStatus::ISSUED),
    'woocommerce_order',
    '1',
    $currency,
    Language::fromTag('pl-PL'),
    Party::create('Seller', '', 'seller@example.invalid', $address),
    Party::create('Buyer', '', 'buyer@example.invalid', $address),
    [DocumentItem::create('Item', Quantity::one(), 'szt', Money::fromMinorUnits(100, $currency), TaxRate::zero())],
    '2026-08-12T10:00:00+00:00',
    '2026-08-12T10:00:00+00:00'
);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdk-admin-sandbox-' . bin2hex(random_bytes(8));
$events = new RuntimeEvents();
try {
    $delivery = new DeliverDocument(
        new RuntimePdf(),
        new SandboxMailer($directory, 'sandbox@example.invalid'),
        $events,
        'document.sandbox_stored'
    );
    $delivery->execute($snapshot, 'buyer@example.invalid', 'Sandbox preview', "Line 1\nLine 2");
    $files = glob($directory . DIRECTORY_SEPARATOR . '*.eml');
    $check('one local eml is created', is_array($files) && count($files) === 1);
    $eml = (string) file_get_contents($files[0]);

    // Decoding the parts rather than looking for header strings: a message can
    // carry `application/pdf` and `Content-Transfer-Encoding: base64` while the
    // body is empty or the attachment is truncated.
    preg_match('/boundary="([^"]+)"/', $eml, $boundaryMatch);
    $parts = $boundaryMatch === []
        ? []
        : array_slice(explode('--' . $boundaryMatch[1], $eml), 1, -1);
    $decoded = [];
    foreach ($parts as $part) {
        $split = explode("\r\n\r\n", ltrim($part, "\r\n"), 2);
        if (count($split) === 2) {
            $decoded[] = ['headers' => $split[0], 'body' => (string) base64_decode(trim($split[1]), true)];
        }
    }

    $textPart = null;
    $pdfPart = null;
    foreach ($decoded as $part) {
        if (strpos($part['headers'], 'text/plain') !== false) {
            $textPart = $part['body'];
        }
        if (strpos($part['headers'], 'application/pdf') !== false) {
            $pdfPart = $part['body'];
        }
    }

    $check('the pdf attachment decodes to exactly the rendered document',
        $pdfPart === (new RuntimePdf())->render($snapshot));
    $check('the attachment is declared as a filename-safe attachment',
        (bool) preg_match('/Content-Disposition: attachment; filename="[A-Za-z0-9_-]+\.pdf"/', $eml));
    $check('the multiline body decodes back to both of its lines',
        $textPart === "Line 1\r\nLine 2");
    $check('the recipient and sender headers are the expected local ones',
        strpos($eml, "\r\nTo: buyer@example.invalid\r\n") !== false
        && strpos($eml, "\r\nFrom: sandbox@example.invalid\r\n") !== false);
    $check('sandbox event is recorded', $events->events[0][0] === 'document.sandbox_stored');
    $check('the audit event carries a recipient hash, not the address',
        isset($events->events[0][2]['recipient_hash'])
        && $events->events[0][2]['recipient_hash'] === hash('sha256', 'buyer@example.invalid')
        && strpos(json_encode($events->events[0]), 'buyer@example.invalid') === false);
    $check('no transport symbols occur in mailer source', preg_match('/\b(wp_mail|mail|fsockopen|curl_exec|stream_socket_client)\s*\(/', file_get_contents($root . '/packages/wordpress/src/SandboxMailer.php')) === 0);

    // The recipient must come from the immutable snapshot, never from the
    // request. The admin action reads $data['buyer']['email'] and nothing else.
    $check('the recipient is read from the snapshot, not from the request',
        strpos($controller, "\$recipient = trim((string) ((\$data['buyer']['email'] ?? '')));") !== false
        && preg_match('/\$_(POST|GET|REQUEST)\[[\'"](recipient|email|to)[\'"]\]/', $controller) === 0);
    $check('an invalid buyer address stops the capture before anything is rendered',
        (bool) preg_match(
            '/filter_var\(\$recipient, FILTER_VALIDATE_EMAIL\) === false.*?self::redirect\(.*?new DeliverDocument/s',
            $controller
        ));

    // A capture holds the buyer's address and the whole document; inside the web
    // root it would be downloadable.
    $webroot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdk-sandbox-webroot-' . bin2hex(random_bytes(6));
    mkdir($webroot . DIRECTORY_SEPARATOR . 'wp-content', 0700, true);
    if (!defined('ABSPATH')) {
        define('ABSPATH', $webroot . DIRECTORY_SEPARATOR);
    }
    $refusedInside = false;
    try {
        new SandboxMailer($webroot . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'mail');
    } catch (Throwable $refusal) {
        $refusedInside = strpos($refusal->getMessage(), 'outside the web root') !== false;
    }
    $check('a capture directory inside the web root is refused', $refusedInside);

    $refusedTraversal = false;
    try {
        new SandboxMailer(
            $webroot . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'escaped'
        );
    } catch (Throwable $refusal) {
        $refusedTraversal = strpos($refusal->getMessage(), 'outside the web root') !== false;
    }
    $check('a traversal path that resolves back inside the web root is refused', $refusedTraversal);

    $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdk-sandbox-outside-' . bin2hex(random_bytes(6));
    $acceptedOutside = false;
    try {
        new SandboxMailer($outside);
        $acceptedOutside = true;
    } catch (Throwable $refusal) {
        $acceptedOutside = false;
    }
    $check('a directory outside the web root is still accepted', $acceptedOutside);
    @rmdir($outside);
    foreach ((array) glob($webroot . DIRECTORY_SEPARATOR . '*') as $entry) {
        is_dir($entry) ? @rmdir($entry) : @unlink($entry);
    }
    @rmdir($webroot);
} finally {
    foreach ((array) glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

echo "checks={$checks} pass={$passes} fail=" . ($checks - $passes) . "\n";
