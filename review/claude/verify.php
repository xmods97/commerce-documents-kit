<?php
/**
 * Read-only verification harness for the Claude review.
 * Loads library classes without modifying them. No network, no DB, no email.
 * Writes evidence only into review/claude/evidence/.
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
use Xmods\CommerceDocuments\EncryptedPayload;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\Rendering\BasicPdfRenderer;
use Xmods\CommerceDocuments\TaxRate;
use Xmods\CommerceDocuments\WordPress\EncryptedSnapshotCodec;
use Xmods\CommerceDocuments\WordPress\OpenSslAesGcmCipher;
use Xmods\CommerceDocuments\WordPress\SandboxMailer;

$evidence = __DIR__ . '/evidence';
if (!is_dir($evidence)) {
    mkdir($evidence, 0700, true);
}

function line(string $s): void
{
    echo $s . PHP_EOL;
}

function snapshot(string $buyer, array $metadata = []): DocumentSnapshot
{
    $currency = Currency::fromCode('PLN');
    $address = Address::create('ul. Grzybowska 87', '', '00-844', 'Warszawa', '', 'PL');
    return DocumentSnapshot::create(
        'doc_review_0001',
        'ORDER_CONFIRMATION/2026/000001',
        DocumentType::fromString(DocumentType::ORDER_CONFIRMATION),
        DocumentStatus::fromString(DocumentStatus::ISSUED),
        'woocommerce_order',
        '209',
        $currency,
        Language::fromTag('pl-PL'),
        Party::create('GEWARD', '1234567890', 'shop@example.test', $address),
        Party::create($buyer, '', 'buyer@example.test', $address),
        [DocumentItem::create('Slab', Quantity::one(), 'szt', Money::fromMinorUnits(14429, $currency), TaxRate::zero())],
        '2026-07-28T10:00:00+00:00',
        '2026-07-28T10:00:00+00:00',
        1,
        $metadata
    );
}

line('=== T1: wpdb insert format misalignment (WpdbDocumentRepository::save) ===');
$codec = new EncryptedSnapshotCodec(new OpenSslAesGcmCipher(str_repeat('k', 32)));
$cipherJson = $codec->encrypt(snapshot('Jan Kowalski'));
line('cipher payload length: ' . strlen($cipherJson));
$asIfPercentD = sprintf('%d', $cipherJson);
line("value stored when the column is bound with %d: '" . $asIfPercentD . "'");
try {
    $codec->decrypt($asIfPercentD, 'doc_review_0001');
    line('RESULT: decrypt unexpectedly succeeded');
} catch (Throwable $e) {
    line('RESULT: decrypt of the persisted value fails -> ' . get_class($e) . ': ' . $e->getMessage());
}

line('');
line('=== T2: AES-GCM AAD binding and tamper detection ===');
try {
    $codec->decrypt($cipherJson, 'doc_other_id');
    line('AAD swap: NOT detected (bad)');
} catch (Throwable $e) {
    line('AAD swap: detected -> ' . $e->getMessage());
}
$p = json_decode($cipherJson, true);
$raw = base64_decode($p['ciphertext'], true);
$raw[0] = chr(ord($raw[0]) ^ 0x01);
$p['ciphertext'] = base64_encode($raw);
try {
    $codec->decrypt(json_encode($p), 'doc_review_0001');
    line('bit flip: NOT detected (bad)');
} catch (Throwable $e) {
    line('bit flip: detected -> ' . $e->getMessage());
}

line('');
line('=== T3: legacy plaintext fallback accepts unauthenticated JSON ===');
$plain = snapshot('Attacker Supplied')->toJson();
$decoded = json_decode($plain, true);
$restored = DocumentSnapshot::fromArray($decoded);
line('plaintext snapshot column round-trips with no MAC check: buyer=' . $restored->toArray()['buyer']['name']);
line('content_hash recomputed from data, not verified against stored column: ' . substr($restored->contentHash(), 0, 16) . '...');

line('');
line('=== T4: BasicPdfRenderer structural validity + Unicode ===');
$pdf = (new BasicPdfRenderer())->render(snapshot('Zażółć gęślą jaźń', ['payment_method' => 'cod', 'payment_confirmed' => 'no']));
file_put_contents($evidence . '/basic-renderer-sample.pdf', $pdf);
line('bytes: ' . strlen($pdf) . ' | header: ' . substr($pdf, 0, 8));
preg_match('/xref\s+0 (\d+)\s+(.*?)trailer/s', $pdf, $m);
$count = (int) ($m[1] ?? 0);
preg_match_all('/^(\d{10}) 00000 n $/m', $m[2] ?? '', $offs);
$ok = true;
foreach ($offs[1] as $i => $off) {
    $expect = ($i + 1) . ' 0 obj';
    $actual = substr($pdf, (int) $off, strlen($expect));
    if ($actual !== $expect) {
        $ok = false;
        line("  xref entry " . ($i + 1) . " offset {$off} points at '" . $actual . "' (expected '{$expect}')");
    }
}
line('xref size declared: ' . $count . ' | entries: ' . count($offs[1]) . ' | all offsets resolve: ' . ($ok ? 'yes' : 'NO'));
preg_match('/startxref\s+(\d+)/', $pdf, $sx);
line('startxref: ' . ($sx[1] ?? '?') . ' -> ' . substr($pdf, (int) ($sx[1] ?? 0), 4));
preg_match_all('/\((.*?)\) Tj/s', $pdf, $tj);
line('text shown in PDF:');
foreach ($tj[1] as $t) {
    line('  | ' . $t);
}
line('items rendered: ' . (strpos($pdf, 'Slab') !== false ? 'yes' : 'NO — line items are absent from the PDF'));

line('');
line('=== T5: AuditEventHash chain under concurrency ===');
$key = str_repeat('a', 32);
$h1 = AuditEventHash::next($key, '', 'document.generated', 'doc_a', '{}', '2026-08-11 10:00:00');
$h2 = AuditEventHash::next($key, $h1, 'document.sent', 'doc_a', '{}', '2026-08-11 10:00:01');
line('sequential chain ok: ' . (($h1 !== $h2) ? 'yes' : 'no'));
$fa = AuditEventHash::next($key, $h1, 'document.sent', 'doc_b', '{}', '2026-08-11 10:00:02');
$fb = AuditEventHash::next($key, $h1, 'document.sent', 'doc_c', '{}', '2026-08-11 10:00:02');
line('two writers reading the same tip produce two valid successors: ' . (($fa !== $fb) ? 'yes (chain forks silently)' : 'no'));

line('');
line('=== T6: SandboxMailer file handling ===');
$mailDir = $evidence . '/sandbox-mail-' . bin2hex(random_bytes(4));
$mailer = new SandboxMailer($mailDir);
try {
    $mailer->send(snapshot('Jan Kowalski'), 'buyer@example.test', 'Zamowienie', "Tresc\nwiadomosci", $pdf);
    line('multi-line body: ACCEPTED');
} catch (Throwable $e) {
    line('multi-line body: REJECTED -> ' . $e->getMessage() . ' (a normal email body cannot contain a newline)');
}
$mailer->send(snapshot('Jan Kowalski'), 'buyer@example.test', 'Zamowienie', 'Tresc wiadomosci', $pdf);
$written = glob($mailDir . '/*.eml');
foreach ($written as $f) {
    line('file: ' . basename($f) . ' | perms: ' . substr(sprintf('%o', fileperms($f)), -4) . ' | bytes: ' . filesize($f));
    $head = substr((string) file_get_contents($f), 0, 260);
    line('--- head ---');
    line($head);
}
line('MIME-Version header present: ' . (strpos((string) file_get_contents($written[0]), 'MIME-Version') !== false ? 'yes' : 'NO'));
line('From header present: ' . (strpos((string) file_get_contents($written[0]), 'From:') !== false ? 'yes' : 'NO'));

line('');
line('=== T7: header injection and traversal attempts (expect rejection) ===');
foreach ([
    ['a@b.test', "Subj\r\nBcc: evil@x.test", 'body'],
    ["a@b.test\r\nBcc: evil@x.test", 'Subj', 'body'],
    ['a@b.test', 'Subj', "body\r\n--boundary"],
] as $i => $case) {
    try {
        $mailer->send(snapshot('X'), $case[0], $case[1], $case[2], 'x');
        line('case ' . $i . ': ACCEPTED (bad)');
    } catch (Throwable $e) {
        line('case ' . $i . ': rejected -> ' . $e->getMessage());
    }
}

line('');
line('=== T8: filename collision within the same second ===');
$before = count(glob($mailDir . '/*.eml'));
$mailer->send(snapshot('Jan Kowalski'), 'buyer@example.test', 'A', 'a', $pdf);
$mailer->send(snapshot('Jan Kowalski'), 'buyer@example.test', 'B', 'b', $pdf);
$after = count(glob($mailDir . '/*.eml'));
line("files before=$before after=$after (two sends in the same second)");

line('');
line('=== T9: snapshot metadata validation ===');
foreach ([['Ok' => 1], ['bad key' => 1], ['payment_method' => ['a']], ['UPPER' => 1]] as $meta) {
    try {
        snapshot('X', $meta);
        line('accepted: ' . json_encode(array_keys($meta)));
    } catch (Throwable $e) {
        line('rejected: ' . json_encode(array_keys($meta)) . ' -> ' . $e->getMessage());
    }
}
