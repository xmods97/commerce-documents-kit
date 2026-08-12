<?php
/**
 * Offline verification of the admin PDF preview wiring.
 *
 * The other harnesses drive the renderer and the resolver with fakes. This one
 * exercises the **default** path the admin preview actually uses: a
 * WordPressLogoProvider constructed with no arguments, which falls through to
 * NativeMediaLibrary, which calls the WordPress functions. Those functions do
 * not exist outside a request, so they are defined here over a fixture — that is
 * the only way to prove the zero-argument wiring resolves a logo at all.
 *
 * The renderer is built exactly as AdminController::pdfRenderer() builds it.
 *
 * No network, no database, no email transport, no WordPress bootstrap. The
 * sandbox lives under the system temp directory and is removed at the end;
 * evidence goes to evidence/.
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
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\Rendering\EmbeddedFontPdfRenderer;
use Xmods\CommerceDocuments\TaxRate;
use Xmods\CommerceDocuments\WordPress\NativeMediaLibrary;
use Xmods\CommerceDocuments\WordPress\WordPressLogoProvider;

require_once __DIR__ . '/lib/PdfFile.php';

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

// ---------------------------------------------------------------------------
// The WordPress surface NativeMediaLibrary talks to, over a mutable fixture.
// ---------------------------------------------------------------------------

$GLOBALS['wp_site'] = [
    'custom_logo' => 0,
    'mime' => '',
    'file' => '',
    'metadata' => [],
    'uploads' => '',
];

function get_theme_mod(string $name, $default = false)
{
    return $name === 'custom_logo' ? $GLOBALS['wp_site']['custom_logo'] : $default;
}

function get_post_mime_type($post = null)
{
    return $GLOBALS['wp_site']['mime'];
}

function get_attached_file(int $attachmentId, bool $unfiltered = false)
{
    return $GLOBALS['wp_site']['file'];
}

function wp_get_attachment_metadata(int $attachmentId, bool $unfiltered = false)
{
    return $GLOBALS['wp_site']['metadata'];
}

function wp_upload_dir(?string $time = null, bool $createDir = true, bool $refreshCache = false): array
{
    return ['basedir' => $GLOBALS['wp_site']['uploads'], 'error' => false];
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdk-preview-' . bin2hex(random_bytes(4));
$uploads = $sandbox . DIRECTORY_SEPARATOR . 'uploads';
$year = $uploads . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '08';
mkdir($year, 0700, true);
$GLOBALS['wp_site']['uploads'] = $uploads;

function makeLogoPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
    // A mark on the left and a wordmark bar on the right: a lockup whose left
    // half alone is recognisably not the whole logo.
    imagefilledellipse(
        $image,
        (int) ($height * 0.5),
        (int) ($height * 0.5),
        (int) ($height * 0.8),
        (int) ($height * 0.8),
        imagecolorallocatealpha($image, 23, 162, 148, 0)
    );
    imagefilledrectangle(
        $image,
        (int) ($height * 1.1),
        (int) ($height * 0.3),
        $width - 4,
        (int) ($height * 0.7),
        imagecolorallocatealpha($image, 16, 32, 72, 0)
    );
    ob_start();
    imagepng($image, null, 6);
    $binary = (string) ob_get_clean();
    imagedestroy($image);
    return $binary;
}

function makeLogoJpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 255, 255, 255));
    imagefilledellipse(
        $image,
        (int) ($height * 0.5),
        (int) ($height * 0.5),
        (int) ($height * 0.8),
        (int) ($height * 0.8),
        imagecolorallocate($image, 23, 162, 148)
    );
    imagefilledrectangle(
        $image,
        (int) ($height * 1.1),
        (int) ($height * 0.3),
        $width - 4,
        (int) ($height * 0.7),
        imagecolorallocate($image, 16, 32, 72)
    );
    ob_start();
    imagejpeg($image, null, 88);
    $binary = (string) ob_get_clean();
    imagedestroy($image);
    return $binary;
}

$files = [
    'Logo.png' => makeLogoPng(1200, 300),
    'Logo-600x150.png' => makeLogoPng(600, 150),
    'Logo-480x480.png' => makeLogoPng(480, 480),   // hard crop: the mark alone
    'Logo-300x188.png' => makeLogoPng(300, 188),   // hard crop
    'Logo.jpg' => makeLogoJpeg(800, 200),
    'Logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="50">'
        . '<script>alert(1)</script><rect width="200" height="50"/></svg>',
];
foreach ($files as $name => $contents) {
    file_put_contents($year . DIRECTORY_SEPARATOR . $name, $contents);
}

function snapshot(): DocumentSnapshot
{
    $currency = Currency::fromCode('PLN');
    return DocumentSnapshot::create(
        'doc_preview',
        'ORDER_CONFIRMATION/2026/000042',
        DocumentType::fromString(DocumentType::ORDER_CONFIRMATION),
        DocumentStatus::fromString(DocumentStatus::ISSUED),
        'woocommerce_order',
        '4242',
        $currency,
        Language::fromTag('pl-PL'),
        Party::create(
            'GEWARD Sp. z o.o.',
            '1234567890',
            'biuro@geward.test',
            Address::create('ul. Kamienna 12', '', '00-950', 'Warszawa', 'mazowieckie', 'PL')
        ),
        Party::create(
            'Zażółć Gęślą Jaźń Sp. z o.o.',
            '',
            'klient@example.invalid',
            Address::create('ul. Świętokrzyska 5/7', 'lok. 3', '31-042', 'Kraków', 'małopolskie', 'PL')
        ),
        [
            DocumentItem::create(
                'Płyta granitowa Nero Assoluto, polerowana 60×30×2 cm',
                Quantity::fromScaledUnits(250, 2),
                'szt.',
                Money::fromMinorUnits(18900, $currency),
                TaxRate::fromPartsPerMillion(230000)
            ),
            DocumentItem::create(
                'Klej montażowy',
                Quantity::fromScaledUnits(300, 2),
                'szt.',
                Money::fromMinorUnits(2450, $currency),
                TaxRate::fromPartsPerMillion(80000)
            ),
        ],
        '2026-07-28T10:00:00+02:00',
        '2026-07-28T10:00:00+02:00',
        1,
        ['order_number' => '4242']
    );
}

/**
 * Builds the renderer exactly as AdminController::pdfRenderer() does, including
 * the zero-argument provider.
 */
function previewRenderer(): EmbeddedFontPdfRenderer
{
    return new EmbeddedFontPdfRenderer(
        2,
        null,
        null,
        new WordPressLogoProvider()
    );
}

/** @param array<string, mixed> $site */
function setSite(array $site): void
{
    $GLOBALS['wp_site'] = array_merge($GLOBALS['wp_site'], $site);
}

// ---------------------------------------------------------------------------

section('P1 — the zero-argument wiring reaches WordPress');

$library = new NativeMediaLibrary();
setSite([
    'custom_logo' => 77,
    'mime' => 'image/png',
    'file' => $year . DIRECTORY_SEPARATOR . 'Logo.png',
    'metadata' => [],
]);
check('the media library reads the Custom Logo attachment id',
    $library->customLogoAttachmentId() === 77);
check('the media library reads the attachment MIME type', $library->mimeTypeOf(77) === 'image/png');
check('the media library reads a local path, not a URL',
    $library->pathOf(77) === $year . DIRECTORY_SEPARATOR . 'Logo.png'
    && strpos($library->pathOf(77), '://') === false);
check('the media library reads the uploads base directory',
    $library->uploadsBaseDirectory() === $uploads);
check('a provider constructed with no arguments resolves the logo',
    (new WordPressLogoProvider())->logo() !== null);

section('P2 — a PNG Custom Logo');

$pngPdf = previewRenderer()->render(snapshot());
file_put_contents($evidence . '/pdf-preview-logo-png.pdf', $pngPdf);
$png = new PdfFile($pngPdf);

check('the preview carries an image XObject',
    substr_count($pngPdf, '/XObject <<') === 1 && strpos($pngPdf, '/Subtype /Image') !== false);
check('the image is drawn on the first page',
    strpos($png->contentOfPage($png->pageObjects()[0]), '/Im0 Do') !== false);
check('transparency travels as a soft mask', strpos($pngPdf, '/SMask ') !== false);
check('the document is structurally valid',
    $png->declaredPageCount() === count($png->pageObjects()));
check('the document text is intact',
    strpos($png->text(), 'Zażółć Gęślą Jaźń Sp. z o.o.') !== false
    && strpos($png->text(), 'Płyta granitowa') !== false
    && strpos($png->text(), 'Razem') !== false);
check('nothing remote and nothing active is referenced',
    stripos($pngPdf, 'http://') === false && stripos($pngPdf, 'https://') === false
    && strpos($pngPdf, '/URI') === false && strpos($pngPdf, '/JavaScript') === false
    && strpos($pngPdf, '/OpenAction') === false && strpos($pngPdf, '/Launch') === false);

section('P3 — a JPEG Custom Logo');

setSite([
    'mime' => 'image/jpeg',
    'file' => $year . DIRECTORY_SEPARATOR . 'Logo.jpg',
    'metadata' => [],
]);
$jpegPdf = previewRenderer()->render(snapshot());
file_put_contents($evidence . '/pdf-preview-logo-jpeg.pdf', $jpegPdf);
$jpeg = new PdfFile($jpegPdf);

check('a JPEG logo is embedded as DCTDecode',
    strpos($jpegPdf, '/Filter /DCTDecode') !== false && strpos($jpegPdf, '/Subtype /Image') !== false);
check('the JPEG preview is structurally valid',
    $jpeg->declaredPageCount() === count($jpeg->pageObjects()));
check('a JPEG carries no soft mask', strpos($jpegPdf, '/SMask ') === false);

section('P4 — the whole logo, never a crop');

setSite([
    'mime' => 'image/png',
    'file' => $year . DIRECTORY_SEPARATOR . 'Logo.png',
    'metadata' => [
        'width' => 1200,
        'height' => 300,
        'sizes' => [
            'scaled' => ['file' => 'Logo-600x150.png', 'width' => 600, 'height' => 150, 'mime-type' => 'image/png'],
            'square' => ['file' => 'Logo-480x480.png', 'width' => 480, 'height' => 480, 'mime-type' => 'image/png'],
            'crop' => ['file' => 'Logo-300x188.png', 'width' => 300, 'height' => 188, 'mime-type' => 'image/png'],
        ],
    ],
]);
$chosen = (new WordPressLogoProvider())->logo();
check('a scaled variant is chosen and its proportions match the original',
    $chosen !== null && $chosen->width() === 600 && $chosen->height() === 150,
    $chosen === null ? 'none' : $chosen->width() . 'x' . $chosen->height());
check('the square crop — the mark without the wordmark — is not chosen',
    $chosen !== null && $chosen->width() !== $chosen->height());

$cropPdf = previewRenderer()->render(snapshot());
check('the preview embeds the proportional variant',
    (bool) preg_match('#/Subtype /Image /Width 600 /Height 150#', $cropPdf));

section('P5 — no logo, and an unusable logo');

setSite(['custom_logo' => 0, 'mime' => '', 'file' => '', 'metadata' => []]);
$noLogoPdf = previewRenderer()->render(snapshot());
file_put_contents($evidence . '/pdf-preview-no-logo.pdf', $noLogoPdf);
$noLogo = new PdfFile($noLogoPdf);

check('no Custom Logo yields a document with no image at all',
    strpos($noLogoPdf, '/XObject') === false && strpos($noLogoPdf, '/Subtype /Image') === false);
check('the document without a logo is structurally valid',
    $noLogo->declaredPageCount() === count($noLogo->pageObjects()));
check('the document without a logo carries the same text',
    strpos($noLogo->text(), 'Zażółć Gęślą Jaźń Sp. z o.o.') !== false);

setSite([
    'custom_logo' => 78,
    'mime' => 'image/svg+xml',
    'file' => $year . DIRECTORY_SEPARATOR . 'Logo.svg',
    'metadata' => [],
]);
$provider = new WordPressLogoProvider();
$svgLogo = $provider->logo();
check('an SVG Custom Logo is refused rather than embedded', $svgLogo === null, $provider->lastRejection());

$svgPdf = previewRenderer()->render(snapshot());
check('an SVG Custom Logo still produces a valid document, without a logo',
    $svgPdf === $noLogoPdf,
    'byte-identical to the no-logo document');

// An SVG renamed to .png passes the extension check, so content sniffing is the
// thing that has to catch it.
file_put_contents($year . DIRECTORY_SEPARATOR . 'Logo-disguised.png', $files['Logo.svg']);
setSite(['mime' => 'image/png', 'file' => $year . DIRECTORY_SEPARATOR . 'Logo-disguised.png']);
$provider = new WordPressLogoProvider();
check('an SVG renamed to .png is refused on its content, not its name', $provider->logo() === null,
    $provider->lastRejection());
check('a document is still produced when the logo lies about its type',
    previewRenderer()->render(snapshot()) === $noLogoPdf);

setSite([
    'mime' => 'image/png',
    'file' => $year . DIRECTORY_SEPARATOR . 'absent.png',
]);
check('a missing logo file does not stop the document',
    previewRenderer()->render(snapshot()) === $noLogoPdf);

section('P6 — the preview action itself');

$controller = (string) file_get_contents($root . '/packages/woocommerce/src/AdminController.php');

check('the preview action is registered on admin_post',
    strpos($controller, "add_action('admin_post_commerce_documents_preview_pdf', [self::class, 'previewPdf'])") !== false);
check('it checks the capability before anything else',
    (bool) preg_match("/function previewPdf\(\): void\s*\{\s*if \(!current_user_can\('manage_woocommerce'\)\)/", $controller));
check('it checks a nonce bound to the requested document',
    strpos($controller, "check_admin_referer('commerce_documents_preview_pdf_' . \$documentId)") !== false);
check('it responds as an inline PDF with a sniffing guard',
    strpos($controller, "header('Content-Type: application/pdf')") !== false
    && strpos($controller, "Content-Disposition: inline") !== false
    && strpos($controller, "X-Content-Type-Options: nosniff") !== false);
check('it clears any buffered output before writing the binary',
    strpos($controller, 'ob_end_clean()') !== false);
check('a rendering failure becomes an error page, not a broken file',
    (bool) preg_match('/catch \(Throwable \$error\) \{\s*wp_die\(/', $controller));
check('no transport, no queue and no write exist in the controller',
    preg_match('/\b(wp_mail|fsockopen|curl_\w+|wp_remote_\w+|file_put_contents|wp_schedule_)\w*\s*\(/', $controller) === 0);
check('the preview link is nonce-signed in the documents list',
    strpos($controller, "'commerce_documents_preview_pdf_' . \$document['document_id']") !== false);

$filenames = [];
foreach (['ORDER_CONFIRMATION/2026/000042' => 'ORDER_CONFIRMATION-2026-000042.pdf'] as $number => $expected) {
    $sanitised = preg_replace('/[^A-Za-z0-9._-]+/', '-', $number);
    $filenames[] = trim((string) $sanitised, '-') . '.pdf' === $expected;
}
check('the download filename is derived safely from the document number',
    !in_array(false, $filenames, true));

// ---------------------------------------------------------------------------

function removeTree(string $path): void
{
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($full) ? removeTree($full) : @unlink($full);
    }
    @rmdir($path);
}
removeTree($sandbox);

echo PHP_EOL . sprintf('checks=%d pass=%d fail=%d', $pass + $fail, $pass, $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
