<?php
/**
 * Focused runtime checks for the review fixes. No network, no database,
 * no WordPress bootstrap, no email transport. Evidence goes to evidence/.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $map = [
        'Xmods\\CommerceDocuments\\WooCommerce\\' => $root . '/packages/woocommerce/src/',
        'Xmods\\CommerceDocuments\\WordPress\\'   => $root . '/packages/wordpress/src/',
        'Xmods\\CommerceDocuments\\'              => $root . '/packages/document-core/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strpos($class, $prefix) !== 0) {
            continue;
        }
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($file)) {
            require $file;
        }
        return;
    }
});

use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\AuditEventHash;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\IdempotencyKey;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\Rendering\BasicPdfRenderer;
use Xmods\CommerceDocuments\Rendering\PdfTextEncoding;
use Xmods\CommerceDocuments\TaxRate;
use Xmods\CommerceDocuments\WooCommerce\OrderData;
use Xmods\CommerceDocuments\WooCommerce\PaidOrderPolicy;
use Xmods\CommerceDocuments\WordPress\AuditChainVerifier;
use Xmods\CommerceDocuments\WordPress\EncryptedSnapshotCodec;
use Xmods\CommerceDocuments\WordPress\Installer;
use Xmods\CommerceDocuments\WordPress\OpenSslAesGcmCipher;
use Xmods\CommerceDocuments\WordPress\SandboxMailer;
use Xmods\CommerceDocuments\WordPress\SchemaDefinition;
use Xmods\CommerceDocuments\WordPress\WpdbDocumentRepository;

$evidence = __DIR__ . '/evidence';
if (!is_dir($evidence)) {
    mkdir($evidence, 0700, true);
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
}
function section(string $title): void
{
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
}

/** wpdb stand-in that applies $formats the way WordPress does. */
final class FormatApplyingWpdb
{
    public $rows = [];
    private $key = '';

    public function prepare(string $sql, ...$args): string
    {
        $this->key = (string) ($args[0] ?? '');
        return $sql;
    }

    public function get_row(string $sql, $output)
    {
        return $this->rows[$this->key] ?? null;
    }

    public function get_var(string $sql)
    {
        return null;
    }

    public function insert(string $table, array $data, array $formats)
    {
        if (count($data) !== count($formats)) {
            return false;
        }
        $out = [];
        $i = 0;
        foreach ($data as $column => $value) {
            $out[$column] = $formats[$i] === '%d' ? (int) $value : (string) $value;
            $i++;
        }
        if (isset($this->rows[$out['idempotency_key']])) {
            return false;
        }
        $this->rows[$out['idempotency_key']] = $out;
        return 1;
    }
}

function snapshot(string $buyer, array $metadata = []): DocumentSnapshot
{
    $currency = Currency::fromCode('PLN');
    $address = Address::create('ul. Grzybowska 87', '', '00-844', 'Warszawa', '', 'PL');
    return DocumentSnapshot::create(
        'doc_review_fix',
        'ORDER_CONFIRMATION/2026/000001',
        DocumentType::fromString(DocumentType::ORDER_CONFIRMATION),
        DocumentStatus::fromString(DocumentStatus::ISSUED),
        'woocommerce_order',
        '209',
        $currency,
        Language::fromTag('pl-PL'),
        Party::create('GEWARD', '1234567890', 'shop@example.invalid', $address),
        Party::create($buyer, '', 'buyer@example.invalid', $address),
        [
            DocumentItem::create('Blat granitowy błyszczący', Quantity::one(), 'szt', Money::fromMinorUnits(10000, $currency), TaxRate::zero()),
            DocumentItem::create('Transport', Quantity::one(), 'usł', Money::fromMinorUnits(2300, $currency), TaxRate::zero()),
        ],
        '2026-08-11T10:00:00+00:00',
        '2026-08-11T10:00:00+00:00',
        1,
        $metadata
    );
}

function order(string $status, string $paidAt, string $method): OrderData
{
    $address = Address::create('ul. Grzybowska 87', '', '00-844', 'Warszawa', '', 'PL');
    return new OrderData(
        '209',
        $status,
        '2026-08-11T09:00:00+00:00',
        $paidAt,
        Currency::fromCode('PLN'),
        Language::fromTag('pl-PL'),
        Party::create('GEWARD', '', '', $address),
        Party::create('Buyer', '', '', $address),
        [],
        $method
    );
}

// -------------------------------------------------------------------------
section('C1 — encrypted snapshot survives wpdb column formats');

$wpdb = new FormatApplyingWpdb();
$codec = new EncryptedSnapshotCodec(new OpenSslAesGcmCipher(str_repeat('k', 32)));
$repository = new WpdbDocumentRepository($wpdb, 'wp_commerce_documents', $codec);
$key = IdempotencyKey::forSource('woocommerce_order', '209', DocumentType::fromString(DocumentType::ORDER_CONFIRMATION));
$repository->save($key, snapshot('Jan Kowalski'));
$stored = $wpdb->rows[$key->value()];
check('snapshot_cipher is not collapsed to 0', $stored['snapshot_cipher'] !== '0' && $stored['snapshot_cipher'] !== 0, 'length=' . strlen((string) $stored['snapshot_cipher']));
check('encryption_version is an integer 1', $stored['encryption_version'] === 1);
$restored = $repository->findByIdempotencyKey($key);
check('round-trips back to the same document', $restored !== null && $restored->contentHash() === snapshot('Jan Kowalski')->contentHash());

// -------------------------------------------------------------------------
section('H1 — legacy plaintext fallback is authenticated');

$json = snapshot('Jan Kowalski')->toJson();
$ok = WpdbDocumentRepository::hydrate([
    'document_id' => 'doc_review_fix', 'snapshot' => $json,
    'snapshot_cipher' => '', 'content_hash' => hash('sha256', $json),
], null);
check('valid legacy row is accepted', $ok !== null);

foreach ([
    ['tampered body rejected', str_replace('Jan Kowalski', 'Attacker XXXX', $json), hash('sha256', $json)],
    ['missing content_hash rejected', $json, ''],
    ['garbage content_hash rejected', $json, str_repeat('f', 64)],
] as $case) {
    try {
        WpdbDocumentRepository::hydrate([
            'document_id' => 'doc_review_fix', 'snapshot' => $case[1],
            'snapshot_cipher' => '', 'content_hash' => $case[2],
        ], null);
        check($case[0], false, 'accepted');
    } catch (Throwable $e) {
        check($case[0], true, $e->getMessage());
    }
}
check(
    'removal boundary is declared',
    WpdbDocumentRepository::LEGACY_PLAINTEXT_REMOVED_IN_SCHEMA > Installer::SCHEMA_VERSION,
    'removed in schema ' . WpdbDocumentRepository::LEGACY_PLAINTEXT_REMOVED_IN_SCHEMA
        . ', current ' . Installer::SCHEMA_VERSION
);

// -------------------------------------------------------------------------
section('H2/H3 — order confirmation only, and only when paid');

$default = new PaidOrderPolicy();
foreach ([
    ['pending', '', 'bacs'], ['cancelled', '', 'bacs'], ['failed', '', 'bacs'],
    ['refunded', '', 'stripe'], ['checkout-draft', '', 'stripe'], ['processing', '', 'cod'],
] as $case) {
    check(
        sprintf('no document for %s/%s%s', $case[0], $case[2], $case[1] === '' ? ' (unpaid)' : ''),
        $default->documentTypeFor(order($case[0], $case[1], $case[2])) === null
    );
}
$paidType = $default->documentTypeFor(order('processing', '2026-08-11T10:00:00+00:00', 'stripe'));
check('paid order yields payment_confirmation', $paidType !== null && $paidType->value() === DocumentType::PAYMENT_CONFIRMATION, $paidType ? $paidType->value() : 'null');
check('invoice/proforma are never produced', $paidType === null || !in_array($paidType->value(), [DocumentType::INVOICE, DocumentType::PROFORMA], true));

$enrolled = new PaidOrderPolicy(PaidOrderPolicy::DEFAULT_PAID_STATUSES, PaidOrderPolicy::COD_POLICY_STATUS_ONLY, ['cod']);
check('COD counts only once explicitly enrolled', $enrolled->documentTypeFor(order('processing', '', 'cod')) !== null);
check('COD still excluded on an unpaid status', $enrolled->documentTypeFor(order('pending', '', 'cod')) === null);
check('decision is recorded for audit', $enrolled->decision(order('processing', '', 'cod'))['cod_policy'] === PaidOrderPolicy::COD_POLICY_STATUS_ONLY);

$pluginSource = file_get_contents($root . '/packages/woocommerce/src/Plugin.php');
check('runtime no longer constructs ConfigurableStatusPolicy', strpos($pluginSource, 'new ConfigurableStatusPolicy') === false);

// -------------------------------------------------------------------------
section('H5 — audit chain');

$auditKey = str_repeat('a', 32);
$rows = [];
$previous = '';
foreach (['document.generated', 'document.sent', 'document.replaced'] as $i => $event) {
    $createdAt = sprintf('2026-08-11 10:00:%02d', $i);
    $hash = AuditEventHash::next($auditKey, $previous, $event, 'doc_a', '{}', $createdAt);
    $rows[] = ['event_name' => $event, 'context' => '{}', 'prev_event_hash' => $previous, 'event_hash' => $hash, 'created_at' => $createdAt];
    $previous = $hash;
}
check('intact chain verifies', AuditChainVerifier::verifyRows('doc_a', $rows, $auditKey)['valid']);

$tampered = $rows;
$tampered[1]['event_name'] = 'document.deleted';
$r = AuditChainVerifier::verifyRows('doc_a', $tampered, $auditKey);
check('altered event detected', !$r['valid'] && $r['broken_at'] === 2, $r['reason']);

$removed = $rows;
array_splice($removed, 1, 1);
$r = AuditChainVerifier::verifyRows('doc_a', $removed, $auditKey);
check('deleted event detected', !$r['valid'], $r['reason']);

$schema = implode("\n", SchemaDefinition::sql('wp_'));
check('chain_position unique index present', strpos($schema, 'UNIQUE KEY chain_position (document_id, prev_event_hash)') !== false);
$loggerSource = file_get_contents($root . '/packages/wordpress/src/WpdbEventLogger.php');
check('chain tip is read per document', strpos($loggerSource, 'WHERE document_id = %s ORDER BY id DESC') !== false);
check('contention is retried', strpos($loggerSource, 'MAX_ATTEMPTS') !== false);

// -------------------------------------------------------------------------
section('H6 / M9 — supersession and migration safety');

check('superseded_by column present', strpos($schema, "superseded_by varchar(64) NOT NULL DEFAULT ''") !== false);
check('superseded_at column present', strpos($schema, 'superseded_at datetime NULL') !== false);
$adminSource = file_get_contents($root . '/packages/woocommerce/src/AdminController.php');
check('correction requires an idempotency token', strpos($adminSource, 'correction_token') !== false);
check('supersession is claimed conditionally', strpos($adminSource, "AND superseded_by = ''") !== false);
check('event recorded on the original', strpos($adminSource, "record('document.replaced'") !== false);
check('event recorded on the correction', strpos($adminSource, "record('document.corrected'") !== false);
$plan = Installer::rollbackPlan('wp_');
check('rollback plan is emitted, not executed', count($plan) >= 5 && strpos($plan[1], 'DROP INDEX chain_position') !== false);
try {
    Installer::rollbackPlan('bad prefix;');
    check('rollback plan validates the prefix', false, 'accepted');
} catch (Throwable $e) {
    check('rollback plan validates the prefix', true);
}

// -------------------------------------------------------------------------
section('H4 — PDF content');

$pdf = (new BasicPdfRenderer(2))->render(snapshot('Zażółć gęślą jaźń', [
    'payment_method' => 'cod', 'payment_confirmed' => 'no', 'order_number' => '209',
]));
file_put_contents($evidence . '/fixed-renderer-sample.pdf', $pdf);
check('valid PDF header and trailer', strpos($pdf, '%PDF-1.4') === 0 && strpos($pdf, '%%EOF') !== false, strlen($pdf) . ' bytes');
check('custom encoding declared', strpos($pdf, '/Differences [') !== false && strpos($pdf, '/aogonek') !== false);
check('Polish text is not replaced by "?"', strpos($pdf, 'Za???') === false);
check('all line items rendered', strpos($pdf, 'Blat granitowy') !== false && strpos($pdf, 'Transport') !== false);
check('totals rendered', strpos($pdf, '123,00') !== false);
check('unpaid COD notice rendered', strpos($pdf, 'NIEOP') !== false);

preg_match('/xref\s+0 (\d+)\s+(.*?)trailer/s', $pdf, $table);
preg_match_all('/^(\d{10}) 00000 n $/m', $table[2] ?? '', $entries);
$offsetsOk = ((int) ($table[1] ?? 0) - 1) === count($entries[1]);
foreach ($entries[1] as $index => $offset) {
    $expect = ($index + 1) . ' 0 obj';
    if (substr($pdf, (int) $offset, strlen($expect)) !== $expect) {
        $offsetsOk = false;
    }
}
check('every xref offset resolves', $offsetsOk, count($entries[1]) . ' objects');
preg_match('/startxref\s+(\d+)/', $pdf, $start);
check('startxref points at the table', substr($pdf, (int) ($start[1] ?? 0), 4) === 'xref');
// A very long Polish name must be cut without producing invalid UTF-8 and
// without requiring ext-mbstring, which the package does not declare.
$longPdf = (new BasicPdfRenderer(2))->render(snapshot(str_repeat('Zażółć ', 20)));
check('long multibyte name truncated safely', strpos($longPdf, '%%EOF') !== false && strpos($longPdf, '...') !== false);
check('renderer needs no ext-mbstring', preg_match('/\bmb_[a-z_]+\s*\(/', file_get_contents($root . '/packages/document-core/src/Rendering/BasicPdfRenderer.php')) !== 1);
check('delimiters escaped', PdfTextEncoding::encode('(x) \\ y') === '\\(x\\) \\\\ y', PdfTextEncoding::encode('(x) \\ y'));
check('control bytes neutralised', strpos(PdfTextEncoding::encode("a\nb"), "\n") === false);

// -------------------------------------------------------------------------
section('M4-M7 — sandbox mailer');

$mailDir = $evidence . '/mail-' . bin2hex(random_bytes(4));
$mailer = new SandboxMailer($mailDir, 'shop@example.invalid');
$mailer->send(snapshot('Jan Kowalski'), 'buyer@example.invalid', 'Zamówienie', "Linia 1\nLinia 2", $pdf);
$mailer->send(snapshot('Jan Kowalski'), 'buyer@example.invalid', 'Zamówienie', 'Linia 1', $pdf);
$files = glob($mailDir . '/*.eml');
check('two sends in the same second leave two files', count($files) === 2, count($files) . ' file(s)');
$eml = (string) file_get_contents($files[0]);
check('MIME-Version present', strpos($eml, 'MIME-Version: 1.0') !== false);
check('From present', strpos($eml, 'From: shop@example.invalid') !== false);
check('Message-ID present', strpos($eml, 'Message-ID:') !== false);
check('non-ASCII subject encoded', strpos($eml, '=?UTF-8?B?') !== false);
check('multi-line body accepted', count($files) === 2);
$mode = substr(sprintf('%o', fileperms($files[0])), -4);
if (DIRECTORY_SEPARATOR === '\\') {
    // PHP's chmod() on Windows only toggles the read-only bit, so POSIX modes are
    // not observable here. Assert the call is made and record the platform caveat.
    check(
        'restrictive mode requested (POSIX-only; not observable on Windows)',
        strpos(file_get_contents($root . '/packages/wordpress/src/SandboxMailer.php'), 'chmod($path, 0600)') !== false,
        'reported mode=' . $mode . ' on Windows'
    );
} else {
    check('file mode restricted to 0600', $mode === '0600', 'mode=' . $mode);
}

$rejected = 0;
foreach ([
    ['buyer@example.invalid', "Subject\r\nBcc: evil@example.invalid"],
    ["buyer@example.invalid\r\nBcc: evil@example.invalid", 'Subject'],
    ['not-an-email', 'Subject'],
] as $case) {
    try {
        $mailer->send(snapshot('X'), $case[0], $case[1], 'body', 'x');
    } catch (Throwable $e) {
        $rejected++;
    }
}
check('header injection and bad recipients rejected', $rejected === 3, $rejected . '/3');
$mailerSource = file_get_contents($root . '/packages/wordpress/src/SandboxMailer.php');
// Match invocations, not the sentence in the class docblock that says it never
// calls wp_mail.
$transportCalls = preg_match('/\b(wp_mail|fsockopen|stream_socket_client|curl_exec|mail)\s*\(/', $mailerSource) === 1;
check('no network transport is invoked', !$transportCalls);
check('exclusive create used', strpos($mailerSource, "'xb'") !== false);

// -------------------------------------------------------------------------
section('M8 — HPOS declaration');

$bootstrap = file_get_contents($root . '/plugins/commerce-documents-woocommerce/commerce-documents-woocommerce.php');
check('declares custom_order_tables compatibility', strpos($bootstrap, 'custom_order_tables') !== false);
check('uses before_woocommerce_init', strpos($bootstrap, 'before_woocommerce_init') !== false);
check('plugin description no longer advertises invoices', stripos($bootstrap, 'proforma and invoice generation') === false);

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo sprintf('PASS %d   FAIL %d', $pass, $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
