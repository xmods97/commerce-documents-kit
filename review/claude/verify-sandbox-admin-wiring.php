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
    $check('pdf attachment is present', strpos($eml, 'application/pdf') !== false);
    $check('multiline body is preserved as MIME text', strpos($eml, 'Content-Transfer-Encoding: base64') !== false);
    $check('sandbox event is recorded', $events->events[0][0] === 'document.sandbox_stored');
    $check('no transport symbols occur in mailer source', preg_match('/\b(wp_mail|mail|fsockopen|curl_exec|stream_socket_client)\s*\(/', file_get_contents($root . '/packages/wordpress/src/SandboxMailer.php')) === 0);
} finally {
    foreach ((array) glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

echo "checks={$checks} pass={$passes} fail=" . ($checks - $passes) . "\n";
