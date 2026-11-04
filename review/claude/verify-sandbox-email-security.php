<?php

declare(strict_types=1);

/**
 * Independent security harness for the local sandbox .eml delivery path.
 *
 * Complements review/claude/verify-sandbox-admin-wiring.php rather than repeating
 * it: this file concentrates on hostile snapshot input, MIME robustness, overwrite
 * and failure behaviour, immutability under repeated capture, and on pinning the
 * defects found by review so they stay reproducible.
 *
 * It never prints a buyer address, a rendered document or the body of a capture.
 * Every temporary directory it creates is removed again.
 */

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
$failures = [];
$check = static function (string $name, bool $condition) use (&$checks, &$passes, &$failures): void {
    $checks++;
    if ($condition) {
        $passes++;
        echo "PASS: {$name}\n";
        return;
    }
    $failures[] = $name;
    echo "FAIL: {$name}\n";
};

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/packages/woocommerce/src/AdminController.php');
$mailerSource = (string) file_get_contents($root . '/packages/wordpress/src/SandboxMailer.php');
$deliverSource = (string) file_get_contents($root . '/packages/document-core/src/Application/DeliverDocument.php');

$scratch = [];
$makeDirectory = static function (string $label) use (&$scratch): string {
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'cdk-sec-' . $label . '-' . bin2hex(random_bytes(6));
    mkdir($path, 0700, true);
    $scratch[] = $path;
    return $path;
};

/** @return string[] */
$captures = static function (string $directory, string $extension = 'eml'): array {
    $found = glob($directory . DIRECTORY_SEPARATOR . '*.' . $extension);
    return is_array($found) ? $found : [];
};

/** @return string[] */
$everything = static function (string $directory): array {
    $found = glob($directory . DIRECTORY_SEPARATOR . '*');
    return is_array($found) ? $found : [];
};

final class CollectingEvents implements EventLogger
{
    /** @var array<int, array{0:string,1:string,2:array}> */
    public $events = [];

    public function record(string $event, string $documentId, array $context = []): void
    {
        $this->events[] = [$event, $documentId, $context];
    }
}

final class BinaryPdf implements PdfRenderer
{
    /** Deliberately contains NUL, CR, LF and high bytes: base64 must survive all of them. */
    public const BYTES = "%PDF-1.4\x00\x0d\x0a\xff\xfe--boundary-lookalike--\x0d\x0a\x00trailer";

    public function render(DocumentSnapshot $snapshot): string
    {
        return self::BYTES;
    }
}

final class ThrowingPdf implements PdfRenderer
{
    public function render(DocumentSnapshot $snapshot): string
    {
        throw new RuntimeException('simulated renderer failure');
    }
}

$snapshotFor = static function (string $documentId, string $number = 'ORDER_CONFIRMATION/2026/000009'): DocumentSnapshot {
    $currency = Currency::fromCode('PLN');
    $address = Address::create('1 Test Street', '', '00-001', 'Test City', '', 'PL');

    return DocumentSnapshot::create(
        $documentId,
        $number,
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
};

$snapshot = $snapshotFor('doc_sec_check');

try {
// ---------------------------------------------------------------------------
// 1. Endpoint wiring: capability, document-bound nonce, no anonymous entry.
// ---------------------------------------------------------------------------

$check(
    'the sandbox nonce is bound to the document id, not to a shared action name',
    strpos($controller, "self::authorize('commerce_documents_sandbox_email_' . \$documentId);") !== false
);
$check(
    'the rendered form mints the same per-document nonce',
    strpos($controller, "wp_nonce_field('commerce_documents_sandbox_email_' . \$document['document_id']") !== false
);
$check(
    'authorize() checks the capability before the nonce and dies on failure',
    (bool) preg_match(
        '/private static function authorize\(string \$nonce\): void\s*\{\s*if \(!current_user_can\(\'manage_woocommerce\'\)\)\s*\{\s*wp_die\(.*?check_admin_referer\(\$nonce\);/s',
        $controller
    )
);

$registrationSources = '';
foreach (['packages', 'plugins'] as $tree) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $tree));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower((string) $file->getExtension()) === 'php') {
            $registrationSources .= (string) file_get_contents((string) $file->getPathname());
        }
    }
}
$check('no anonymous admin_post_nopriv hook exists in runtime code', strpos($registrationSources, 'admin_post_nopriv') === false);
$check('no ajax entry point exists in runtime code', strpos($registrationSources, 'wp_ajax') === false);
$check('no rest route is registered in runtime code', strpos($registrationSources, 'register_rest_route') === false);
$check('no scheduler is wired in runtime code', preg_match('/\bwp_(schedule|cron|next_scheduled)\w*\s*\(/', $registrationSources) === 0);

// ---------------------------------------------------------------------------
// 2. Source of truth: snapshot only, verified chain first.
// ---------------------------------------------------------------------------

preg_match('/public static function sandboxEmail\(\): void.*?\n    \}/s', $controller, $bodyMatch);
$sandboxBody = $bodyMatch[0] ?? '';
$check('the sandboxEmail body was located for order-sensitive assertions', $sandboxBody !== '');
$check(
    'the audit chain is verified before a mailer or delivery service is constructed',
    strpos($sandboxBody, 'AuditChainVerifier') !== false
    && strpos($sandboxBody, 'AuditChainVerifier') < strpos($sandboxBody, 'new DeliverDocument')
    && strpos($sandboxBody, "!\$verification['valid']") < strpos($sandboxBody, 'new SandboxMailer')
);
$check(
    'the action never reads live order data',
    strpos($sandboxBody, 'wc_get_order') === false && strpos($sandboxBody, 'get_billing') === false
);
preg_match_all('/\$_(POST|GET|REQUEST)\[[^\]]+\]/', $sandboxBody, $requestReads);
$check(
    'the action reads no request field other than the document id',
    $requestReads[0] !== []
    && array_values(array_unique($requestReads[0])) === ["\$_POST['document_id']"]
);
$check(
    'the failure branch does not echo the internal exception message to the browser',
    strpos($sandboxBody, "self::redirect('failed', 'Sandbox email could not be created.')") !== false
    && strpos($sandboxBody, "self::redirect('failed', \$error->getMessage())") === false
);
$check(
    'delivery renders the pdf from the same snapshot object it mails',
    (bool) preg_match('/\$binary = \$this->pdf->render\(\$snapshot\);/', $deliverSource)
    && (bool) preg_match('/->stage\(\s*\$snapshot,\s*\$recipient/s', $deliverSource)
);

// ---------------------------------------------------------------------------
// 3. Hostile snapshot values cannot escape the filename or the headers.
// ---------------------------------------------------------------------------

$directory = $makeDirectory('hostile');
$mailer = new SandboxMailer($directory, 'sandbox@example.invalid');

$hostileIds = [
    'path traversal in the document id' => '../../../etc/passwd',
    'windows traversal in the document id' => '..\\..\\windows\\system32\\config',
    'header break in the document id' => "doc_ok\r\nBcc: attacker@example.invalid",
    'quote break out of the filename parameter' => 'doc"; filename="evil.html',
    'null byte in the document id' => "doc_ok\x00.html",
    'space in the document id' => 'doc ok',
];
foreach ($hostileIds as $label => $hostileId) {
    $refused = false;
    try {
        $mailer->stage($snapshotFor($hostileId), 'buyer@example.invalid', 'Subject', 'Body', 'pdf');
    } catch (Throwable $error) {
        $refused = $error instanceof InvalidArgumentException;
    }
    $check($label . ' is refused', $refused);
}
$check('no file was produced by any refused document id', $everything($directory) === []);

$hostileSubjects = [
    'crlf injected subject' => "Subject\r\nBcc: attacker@example.invalid",
    'lone lf injected subject' => "Subject\nBcc: attacker@example.invalid",
    'lone cr injected subject' => "Subject\rBcc: attacker@example.invalid",
];
foreach ($hostileSubjects as $label => $hostileSubject) {
    $refused = false;
    try {
        $mailer->stage($snapshot, 'buyer@example.invalid', $hostileSubject, 'Body', 'pdf');
    } catch (Throwable $error) {
        $refused = $error instanceof InvalidArgumentException;
    }
    $check($label . ' is refused', $refused);
}

$hostileRecipients = [
    'empty recipient' => '',
    'non-address recipient' => 'not-an-address',
    'crlf injected recipient' => "buyer@example.invalid\r\nBcc: attacker@example.invalid",
    'bare header continuation recipient' => "buyer@example.invalid\n Bcc: attacker@example.invalid",
];
foreach ($hostileRecipients as $label => $hostileRecipient) {
    $refused = false;
    try {
        $mailer->stage($snapshot, $hostileRecipient, 'Subject', 'Body', 'pdf');
    } catch (Throwable $error) {
        $refused = $error instanceof InvalidArgumentException;
    }
    $check($label . ' is refused', $refused);
}
$check('no file was produced by any refused header value', $everything($directory) === []);

$senderRefused = false;
try {
    new SandboxMailer($directory, "sandbox@example.invalid\r\nBcc: attacker@example.invalid");
} catch (Throwable $error) {
    $senderRefused = $error instanceof InvalidArgumentException;
}
$check('a sender address carrying a header break is refused at construction', $senderRefused);

// A snapshot value that is legitimate but not ASCII must be encoded, not passed through.
$unicodeSubject = 'Sandbox preview: Zamówienie ąćęłńóśźż';
$pending = $mailer->stage($snapshot, 'buyer@example.invalid', $unicodeSubject, 'Body', 'pdf');
$staged = (string) file_get_contents($pending);
preg_match('/^Subject: (.*)$/m', $staged, $subjectMatch);
$subjectHeader = rtrim($subjectMatch[1] ?? '', "\r");
$check(
    'a non-ascii subject is rfc 2047 encoded rather than emitted raw',
    strpos($subjectHeader, '=?UTF-8?B?') === 0
    && base64_decode(substr($subjectHeader, 10, -2), true) === $unicodeSubject
);
$headerBlock = explode("\r\n\r\n", $staged, 2)[0];
$check(
    'every header line is a single well formed field',
    count(array_filter(
        explode("\r\n", $headerBlock),
        static function (string $line): bool {
            return preg_match('/^[A-Za-z-]+: /', $line) !== 1;
        }
    )) === 0
);
$mailer->discard($pending);
$check('a discarded staged artifact leaves nothing behind', $everything($directory) === []);

// ---------------------------------------------------------------------------
// 4. MIME correctness with binary content, and no active content.
// ---------------------------------------------------------------------------

$binaryDirectory = $makeDirectory('binary');
$binaryEvents = new CollectingEvents();
(new DeliverDocument(
    new BinaryPdf(),
    new SandboxMailer($binaryDirectory, 'sandbox@example.invalid'),
    $binaryEvents,
    'document.sandbox_stored'
))->execute($snapshot, 'buyer@example.invalid', 'Sandbox preview', "Line 1\nLine 2");

$files = $captures($binaryDirectory);
$check('exactly one capture is produced', count($files) === 1);
$eml = (string) file_get_contents($files[0]);

preg_match('/boundary="([^"]+)"/', $eml, $boundaryMatch);
$boundary = $boundaryMatch[1] ?? '';
$check('the boundary is unguessable random hex', (bool) preg_match('/^cdk-[a-f0-9]{24}$/D', $boundary));
// Once in the Content-Type header, twice as a part separator, once as the closing
// delimiter. Any further occurrence would mean encoded content collided with it.
$check('the boundary occurs only where the structure requires it', substr_count($eml, $boundary) === 4);
$check('the message is terminated by a closing boundary', substr($eml, -strlen('--' . $boundary . "--\r\n")) === '--' . $boundary . "--\r\n");

$segments = array_slice(explode('--' . $boundary, $eml), 1, -1);
$parts = [];
foreach ($segments as $segment) {
    $split = explode("\r\n\r\n", ltrim($segment, "\r\n"), 2);
    if (count($split) === 2) {
        $parts[] = ['headers' => $split[0], 'body' => base64_decode(trim($split[1]), true)];
    }
}
$check('the message has exactly two parts', count($parts) === 2);

$pdfPart = null;
$textPart = null;
foreach ($parts as $part) {
    if (strpos($part['headers'], 'application/pdf') !== false) {
        $pdfPart = $part['body'];
    }
    if (strpos($part['headers'], 'text/plain') !== false) {
        $textPart = $part['body'];
    }
}
$check('the pdf attachment decodes byte for byte, including nul, cr, lf and high bytes', $pdfPart === BinaryPdf::BYTES);
$check('base64 decoding is strict and produced no false positive', $pdfPart !== false && $textPart !== false);
$check('the text part decodes back to its original lines with crlf endings', $textPart === "Line 1\r\nLine 2");
$check(
    'every base64 line respects the 76 character limit',
    count(array_filter(
        explode("\r\n", $eml),
        static function (string $line): bool {
            return strlen($line) > 78;
        }
    )) === 0
);
$check(
    'the attachment filename is quoted and restricted to safe characters',
    (bool) preg_match('/Content-Disposition: attachment; filename="[A-Za-z0-9_-]+\.pdf"\r\n/', $eml)
);
$check(
    'the capture declares no active content type',
    stripos($eml, 'text/html') === false
    && stripos($eml, 'application/javascript') === false
    && stripos($eml, 'multipart/related') === false
);
$check(
    'the capture carries no markup, script or remote reference',
    stripos($eml, '<script') === false
    && stripos($eml, '<html') === false
    && stripos($eml, 'http://') === false
    && stripos($eml, 'https://') === false
    && stripos($eml, 'cid:') === false
);
$check(
    'the message id and sender are local and unroutable',
    strpos($eml, "\r\nFrom: sandbox@example.invalid\r\n") !== false
    && (bool) preg_match('/\r\nMessage-ID: <[a-f0-9]{24}@commerce-documents\.local>\r\n/', $eml)
);
$check('the sandbox event is recorded once, after the capture exists', count($binaryEvents->events) === 1
    && $binaryEvents->events[0][0] === 'document.sandbox_stored');
$check(
    'the audit context stores a recipient hash and never the address itself',
    ($binaryEvents->events[0][2]['recipient_hash'] ?? '') === hash('sha256', 'buyer@example.invalid')
    && strpos((string) json_encode($binaryEvents->events[0]), '@example.invalid') === false
);

// ---------------------------------------------------------------------------
// 5. Overwrite, collision, failure and repetition.
// ---------------------------------------------------------------------------

$collisionDirectory = $makeDirectory('collision');
$collisionMailer = new SandboxMailer($collisionDirectory, 'sandbox@example.invalid');
$pending = $collisionMailer->stage($snapshot, 'buyer@example.invalid', 'Sandbox preview', 'Body', 'pdf');
$target = $collisionDirectory . DIRECTORY_SEPARATOR . pathinfo($pending, PATHINFO_FILENAME) . '.eml';
file_put_contents($target, 'PRE-EXISTING');
$commitRefused = false;
try {
    $collisionMailer->commit($pending);
} catch (Throwable $error) {
    $commitRefused = $error instanceof RuntimeException;
}
$check('committing onto an existing capture name is refused', $commitRefused);
$check('the pre-existing file is left untouched', (string) file_get_contents($target) === 'PRE-EXISTING');
$collisionMailer->discard($pending);
$check('the pending artifact is removed after a refused commit', !file_exists($pending));

$foreign = $makeDirectory('foreign');
$foreignFile = $foreign . DIRECTORY_SEPARATOR . 'outside.eml';
file_put_contents($foreignFile, 'OUTSIDE');
$outsideRefused = false;
try {
    $collisionMailer->commit($foreignFile);
} catch (Throwable $error) {
    $outsideRefused = strpos($error->getMessage(), 'outside its capture directory') !== false;
}
$check('an artifact path outside the capture directory is refused by commit', $outsideRefused);
$check('the foreign file is not consumed', (string) file_get_contents($foreignFile) === 'OUTSIDE');

$missingRefused = false;
try {
    $collisionMailer->commit($collisionDirectory . DIRECTORY_SEPARATOR . 'does-not-exist.pending');
} catch (Throwable $error) {
    $missingRefused = strpos($error->getMessage(), 'does not exist') !== false;
}
$check('committing a non-existent artifact is refused', $missingRefused);

$committedTwice = false;
$secondPending = $collisionMailer->stage($snapshot, 'buyer@example.invalid', 'Sandbox preview', 'Body', 'pdf');
$committed = $collisionMailer->commit($secondPending);
try {
    $collisionMailer->commit($committed);
} catch (Throwable $error) {
    $committedTwice = strpos($error->getMessage(), 'not pending') !== false;
}
$check('an already committed capture cannot be committed again', $committedTwice);

$renderFailureDirectory = $makeDirectory('render-failure');
$renderFailed = false;
try {
    (new DeliverDocument(
        new ThrowingPdf(),
        new SandboxMailer($renderFailureDirectory, 'sandbox@example.invalid'),
        new CollectingEvents(),
        'document.sandbox_stored'
    ))->execute($snapshot, 'buyer@example.invalid', 'Sandbox preview', 'Body');
} catch (Throwable $error) {
    $renderFailed = true;
}
$check('a renderer failure aborts before anything is written', $renderFailed && $everything($renderFailureDirectory) === []);

$repeatDirectory = $makeDirectory('repeat');
$before = $snapshot->toArray();
$repeatEvents = new CollectingEvents();
$repeatDelivery = new DeliverDocument(
    new BinaryPdf(),
    new SandboxMailer($repeatDirectory, 'sandbox@example.invalid'),
    $repeatEvents,
    'document.sandbox_stored'
);
$repeatDelivery->execute($snapshot, 'buyer@example.invalid', 'Sandbox preview', 'Body');
$repeatDelivery->execute($snapshot, 'buyer@example.invalid', 'Sandbox preview', 'Body');
$repeated = $captures($repeatDirectory);
$check('a repeated capture writes a second file instead of overwriting the first', count($repeated) === 2);
$check('the two captures have distinct names', count(array_unique($repeated)) === 2);
$check(
    'both captures are structurally complete',
    count(array_filter($repeated, static function (string $file): bool {
        $content = (string) file_get_contents($file);
        preg_match('/boundary="([^"]+)"/', $content, $match);
        return $match !== [] && substr($content, -strlen('--' . $match[1] . "--\r\n")) === '--' . $match[1] . "--\r\n";
    })) === 2
);
$check('the immutable snapshot is byte-identical after two captures', $snapshot->toArray() === $before);
$check('each capture appends exactly one audit event', count($repeatEvents->events) === 2);

// ---------------------------------------------------------------------------
// 6. File and directory posture.
// ---------------------------------------------------------------------------

$posixPermissions = DIRECTORY_SEPARATOR === '/';
if ($posixPermissions) {
    $mode = fileperms($repeated[0]) & 0777;
    $check('a capture is readable only by its owner', $mode === 0600);
    $check('the capture directory is not group or world accessible', (fileperms($repeatDirectory) & 0077) === 0);
} else {
    echo "SKIP: posix permission bits are not meaningful on this platform\n";
}

$relativeName = 'cdk-sec-relative-' . bin2hex(random_bytes(4));
$relativeAccepted = false;
try {
    new SandboxMailer($relativeName);
    $relativeAccepted = true;
} catch (Throwable $error) {
    $relativeAccepted = false;
}
// FINDING F6: a relative path is resolved against the process working directory
// rather than refused, so the capture location depends on the SAPI.
$check('FINDING F6 pinned: a relative capture directory is accepted, not refused', $relativeAccepted);
if (is_dir(getcwd() . DIRECTORY_SEPARATOR . $relativeName)) {
    @rmdir(getcwd() . DIRECTORY_SEPARATOR . $relativeName);
}

$unwritableRefused = false;
try {
    new SandboxMailer($repeatDirectory . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . "\0" . 'b');
} catch (Throwable $error) {
    $unwritableRefused = true;
}
$check('an unusable directory path is refused rather than silently reused', $unwritableRefused);

$emptyRefused = false;
try {
    new SandboxMailer('   ');
} catch (Throwable $error) {
    $emptyRefused = true;
}
$check('a blank capture directory is refused', $emptyRefused);

// ---------------------------------------------------------------------------
// 7. Defects pinned at source level, so the findings stay reproducible.
// ---------------------------------------------------------------------------

preg_match('/private function writeExclusively\(.*?\n    \}/s', $mailerSource, $writeMatch);
$writeBody = $writeMatch[0] ?? '';
$check('the writeExclusively body was located', $writeBody !== '');
// FINDING F1: fwrite() may return a short count without returning false. Only the
// false case is handled, so a truncated capture is committed and audited as good.
$check(
    'FINDING F1 pinned: a short fwrite is not detected',
    strpos($writeBody, '$written === false') !== false
    && preg_match('/\$written\s*(!==|<|!=)\s*strlen/', $writeBody) === 0
);
$check(
    'FINDING F1 pinned: the fclose result is not checked either',
    (bool) preg_match('/\n\s*fclose\(\$handle\);/', $writeBody)
);
// FINDING F5: file_exists() then rename() is a check-then-act on a path an
// unprivileged local process may be able to create in between.
$check(
    'FINDING F5 pinned: commit guards the target with file_exists before rename',
    strpos($mailerSource, 'if (file_exists($target) || !@rename($source, $target))') !== false
);
// FINDING F2: the containment check treats ABSPATH as the document root and
// stands aside entirely when ABSPATH is absent or does not resolve.
$check(
    'FINDING F2 pinned: containment is judged only against ABSPATH',
    strpos($mailerSource, "if (!defined('ABSPATH')) {") !== false
    && strpos($mailerSource, 'if ($root === false) {') !== false
    && preg_match('/WP_CONTENT_DIR|DOCUMENT_ROOT|wp_upload_dir/', $mailerSource) === 0
);
// FINDING F3: only existence and writability are checked, never ownership, mode
// or whether the path was a symlink before realpath() followed it.
$check(
    'FINDING F3 pinned: a pre-existing capture directory is reused without an ownership or mode check',
    strpos($mailerSource, 'if (!is_dir($directory) || !is_writable($directory))') !== false
    && preg_match('/fileowner|is_link|lstat|filegroup/', $mailerSource) === 0
);
$check(
    'FINDING F3 pinned: the default directory is the shared system temp directory',
    strpos($controller, 'sys_get_temp_dir()') !== false
    && strpos($controller, "'commerce-documents-sandbox-mail'") !== false
);
// FINDING F4: nothing ever removes a committed capture. The only unlink is the
// failure rollback in discard().
$check(
    'FINDING F4 pinned: the only unlink in the mailer is the failure rollback',
    substr_count($mailerSource, 'unlink(') === 1
    && (bool) preg_match('/public function discard\(string \$artifact\): void.*?@unlink\(\$path\);/s', $mailerSource)
);
$check(
    'FINDING F4 pinned: no runtime code enumerates or deletes committed captures',
    // Deletion anywhere in runtime code is limited to the mailer rollback, and no
    // runtime file lists the capture directory, so nothing can age captures out.
    substr_count($registrationSources, 'unlink(') === 1
    && preg_match('/\bglob\s*\(|\bscandir\s*\(|\bRecursiveDirectoryIterator\b|\bDirectoryIterator\b/', $registrationSources) === 0
);
$check(
    'FINDING F4 pinned: no capture lifetime, cap or expiry setting is defined',
    preg_match('/sandbox\w*_(retention|ttl|max|expiry|lifetime|limit)/i', $registrationSources) === 0
    && preg_match('/\b(retention|expiry|ttl)\b/i', $mailerSource) === 0
);
// FINDING F7: an unsalted sha256 of an address is enumerable, not anonymous.
$check(
    'FINDING F7 pinned: the audit recipient hash is an unsalted sha256',
    strpos($deliverSource, "hash('sha256', strtolower(\$recipient))") !== false
);
// FINDING F8: shop managers, not only administrators, can write buyer pii to disk.
$check(
    'FINDING F8 pinned: the capability gate is manage_woocommerce',
    strpos($controller, "current_user_can('manage_woocommerce')") !== false
    && strpos($controller, "current_user_can('manage_options')") === false
);

// ---------------------------------------------------------------------------
// 8. ABSPATH-dependent behaviour. Runs last: the constant cannot be undefined.
// ---------------------------------------------------------------------------

$docroot = $makeDirectory('docroot');
mkdir($docroot . DIRECTORY_SEPARATOR . 'wp', 0700, true);
if (!defined('ABSPATH')) {
    define('ABSPATH', $docroot . DIRECTORY_SEPARATOR . 'wp' . DIRECTORY_SEPARATOR);
}

$insideRefused = false;
try {
    new SandboxMailer($docroot . DIRECTORY_SEPARATOR . 'wp' . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'mail');
} catch (Throwable $error) {
    $insideRefused = strpos($error->getMessage(), 'outside the web root') !== false;
}
$check('a directory inside ABSPATH is still refused', $insideRefused);

// FINDING F2 demonstrated: in a subdirectory install the served document root is
// the parent of ABSPATH, and a capture directory there is accepted.
$servedButAccepted = false;
try {
    new SandboxMailer($docroot . DIRECTORY_SEPARATOR . 'mail');
    $servedButAccepted = true;
} catch (Throwable $error) {
    $servedButAccepted = false;
}
$check(
    'FINDING F2 demonstrated: a directory in the ABSPATH parent is accepted although a subdirectory install serves it',
    $servedButAccepted
);
} finally {
    // Recursive: a refused capture directory can still have created nested
    // parents before the containment check rejected it.
    $removeTree = static function (string $path) use (&$removeTree): void {
        foreach ((array) glob($path . DIRECTORY_SEPARATOR . '*') as $entry) {
            is_dir($entry) ? $removeTree((string) $entry) : @unlink($entry);
        }
        @rmdir($path);
    };
    foreach (array_reverse($scratch) as $path) {
        $removeTree($path);
    }
}

echo "checks={$checks} pass={$passes} fail=" . ($checks - $passes) . "\n";
foreach ($failures as $failure) {
    echo "FAILED: {$failure}\n";
}
exit($failures === [] ? 0 : 1);
