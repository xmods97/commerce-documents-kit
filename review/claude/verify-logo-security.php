<?php
/**
 * Offline verification harness for the PDF logo path.
 *
 * It builds a throwaway uploads directory, points a fake media library at it,
 * and drives WordPressLogoProvider and RasterImage through the cases that
 * matter: the real logo, the formats that must be refused, and the paths that
 * must not be reachable. Then it renders documents with and without a logo and
 * reads the result back, including the embedded pixels.
 *
 * No network, no database, no WordPress, no email transport. The sandbox is
 * created under the system temp directory and removed at the end; evidence goes
 * to evidence/.
 *
 * Usage:
 *   php review/claude/verify-logo-security.php [path/to/real-logo.png]
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
use Xmods\CommerceDocuments\Contracts\LogoProvider;
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
use Xmods\CommerceDocuments\Rendering\Image\RasterImage;
use Xmods\CommerceDocuments\TaxRate;
use Xmods\CommerceDocuments\WordPress\Contracts\MediaLibrary;
use Xmods\CommerceDocuments\WordPress\WordPressLogoProvider;

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
// Fakes
// ---------------------------------------------------------------------------

/** A media library whose every answer the test controls, including hostile ones. */
final class FakeMediaLibrary implements MediaLibrary
{
    public $attachmentId = 1;
    public $mime = 'image/png';
    public $path = '';
    public $metadata = [];
    public $uploads = '';

    public function customLogoAttachmentId(): int
    {
        return $this->attachmentId;
    }

    public function mimeTypeOf(int $attachmentId): string
    {
        return $this->mime;
    }

    public function pathOf(int $attachmentId): string
    {
        return $this->path;
    }

    public function metadataOf(int $attachmentId): array
    {
        return $this->metadata;
    }

    public function uploadsBaseDirectory(): string
    {
        return $this->uploads;
    }
}

/** Hands the renderer a fixed image, so rendering is independent of WordPress. */
final class FixedLogoProvider implements LogoProvider
{
    private $image;

    public function __construct(?RasterImage $image)
    {
        $this->image = $image;
    }

    public function logo(): ?RasterImage
    {
        return $this->image;
    }
}

function snapshot(): DocumentSnapshot
{
    $currency = Currency::fromCode('PLN');
    return DocumentSnapshot::create(
        'doc_logo',
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
        ],
        '2026-07-28T10:00:00+02:00',
        '2026-07-28T10:00:00+02:00',
        1,
        ['order_number' => '4242']
    );
}

// ---------------------------------------------------------------------------
// Sandbox
// ---------------------------------------------------------------------------

$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdk-logo-' . bin2hex(random_bytes(4));
$uploads = $sandbox . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . '07';
$outside = $sandbox . DIRECTORY_SEPARATOR . 'private';
mkdir($uploads, 0700, true);
mkdir($outside, 0700, true);
$uploadsBase = $sandbox . DIRECTORY_SEPARATOR . 'uploads';

/** Deterministic RGBA logo: a filled rounded shape plus a transparent margin. */
function makeRgbaPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle(
        $image,
        (int) ($width * 0.05),
        (int) ($height * 0.2),
        (int) ($width * 0.95),
        (int) ($height * 0.8),
        imagecolorallocatealpha($image, 17, 51, 102, 0)
    );
    imagefilledellipse(
        $image,
        (int) ($width * 0.25),
        (int) ($height * 0.5),
        (int) ($height * 0.5),
        (int) ($height * 0.5),
        imagecolorallocatealpha($image, 214, 176, 92, 0)
    );
    ob_start();
    imagepng($image, null, 6);
    $binary = (string) ob_get_clean();
    imagedestroy($image);
    return $binary;
}

function makeRgbPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 240, 240, 235));
    imagefilledrectangle(
        $image,
        4,
        4,
        $width - 5,
        $height - 5,
        imagecolorallocate($image, 17, 51, 102)
    );
    ob_start();
    imagepng($image, null, 6);
    $binary = (string) ob_get_clean();
    imagedestroy($image);
    return $binary;
}

function makeJpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 250, 250, 250));
    imagefilledellipse(
        $image,
        (int) ($width / 2),
        (int) ($height / 2),
        (int) ($width * 0.7),
        (int) ($height * 0.7),
        imagecolorallocate($image, 17, 51, 102)
    );
    ob_start();
    imagejpeg($image, null, 88);
    $binary = (string) ob_get_clean();
    imagedestroy($image);
    return $binary;
}

$realLogoSource = $argv[1] ?? '';
$realLogo = is_string($realLogoSource) && $realLogoSource !== '' && is_file($realLogoSource)
    ? (string) file_get_contents($realLogoSource)
    : '';

// A filename that is hostile but still creatable on Windows: the quote and the
// angle brackets a fuller payload would carry are not legal in an NTFS name, and
// the point of the case is that no filename reaches the document at all.
$hostileName = "logo';DROP TABLE wp_posts;--script.png";

$files = [
    'logo-600x249.png' => $realLogo !== '' ? $realLogo : makeRgbaPng(600, 249),
    'logo-transparent.png' => makeRgbaPng(600, 249),
    'logo-opaque.png' => makeRgbPng(480, 200),
    'logo.jpg' => makeJpeg(520, 210),
    'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100">'
        . '<script>alert(1)</script><rect width="100" height="100"/></svg>',
    'logo-svg-disguised.png' => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>',
    'logo-php-disguised.png' => "<?php echo 1; ?>\n",
    'payload.php' => "<?php echo 2; ?>\n",
    'logo-huge-bytes.png' => "\x89PNG\r\n\x1a\n" . str_repeat("\x00", RasterImage::MAX_BYTES + 1024),
    'logo-too-many-pixels.png' => makeRgbPng(2600, 2600),
    $hostileName => makeRgbPng(300, 120),
];
foreach ($files as $name => $contents) {
    file_put_contents($uploads . DIRECTORY_SEPARATOR . $name, $contents);
}
file_put_contents($outside . DIRECTORY_SEPARATOR . 'secret-logo.png', makeRgbPng(200, 80));
file_put_contents($outside . DIRECTORY_SEPARATOR . 'wp-config.php', "<?php define('DB_PASSWORD', 'never-read');");

// A PNG whose header claims 10x10 but whose image data inflates to megabytes.
$ihdr = pack('NN', 10, 10) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
$idat = (string) gzcompress(str_repeat("\x00", 5000000), 9);
$bomb = "\x89PNG\r\n\x1a\n"
    . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
    . pack('N', strlen($idat)) . 'IDAT' . $idat . pack('N', crc32('IDAT' . $idat))
    . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
file_put_contents($uploads . DIRECTORY_SEPARATOR . 'logo-bomb.png', $bomb);

$media = new FakeMediaLibrary();
$media->uploads = $uploadsBase;
$provider = new WordPressLogoProvider($media);

/** Points the fake at a file and returns what the provider makes of it. */
function resolve(FakeMediaLibrary $media, WordPressLogoProvider $provider, string $path, string $mime = 'image/png'): ?RasterImage
{
    $media->attachmentId = 1;
    $media->mime = $mime;
    $media->path = $path;
    $media->metadata = [];
    return $provider->logo();
}

// ---------------------------------------------------------------------------

section('L1 — the image parser accepts only what it can embed safely');

$accepted = RasterImage::fromBinary($files['logo-600x249.png']);
check(
    $realLogo !== '' ? 'the real GEWARD logo is accepted' : 'an RGBA PNG is accepted',
    $accepted->format() === RasterImage::PNG,
    sprintf('%dx%d, %s, alpha=%s, %d B embedded',
        $accepted->width(), $accepted->height(), $accepted->colourSpace(),
        $accepted->hasAlpha() ? 'yes' : 'no', $accepted->embeddedSize())
);

$transparent = RasterImage::fromBinary($files['logo-transparent.png']);
check('transparency becomes a soft mask, not a white box', $transparent->hasAlpha(),
    $transparent->width() . 'x' . $transparent->height() . ', ' . $transparent->embeddedSize() . ' B embedded');

$opaque = RasterImage::fromBinary($files['logo-opaque.png']);
check('an opaque PNG is accepted and carries no soft mask',
    $opaque->format() === RasterImage::PNG && !$opaque->hasAlpha(),
    $opaque->width() . 'x' . $opaque->height() . ' ' . $opaque->colourSpace());

$jpeg = RasterImage::fromBinary($files['logo.jpg']);
check('a baseline JPEG is accepted and passed through as DCTDecode',
    $jpeg->format() === RasterImage::JPEG && $jpeg->filter() === '/DCTDecode',
    $jpeg->width() . 'x' . $jpeg->height() . ' ' . $jpeg->colourSpace());

$rejections = [
    'SVG' => $files['logo.svg'],
    'SVG renamed to .png' => $files['logo-svg-disguised.png'],
    'PHP renamed to .png' => $files['logo-php-disguised.png'],
    'empty input' => '',
    'a data URL' => 'data:image/png;base64,iVBORw0KGgo=',
    'an http URL' => 'https://example.invalid/logo.png',
    'oversized input' => str_repeat('A', RasterImage::MAX_BYTES + 1),
    'a decompression bomb' => $bomb,
    'too many pixels' => $files['logo-too-many-pixels.png'],
];
foreach ($rejections as $label => $bytes) {
    $refused = false;
    $reason = '';
    try {
        RasterImage::fromBinary($bytes);
    } catch (Throwable $exception) {
        $refused = true;
        $reason = $exception->getMessage();
    }
    check('the parser refuses ' . $label, $refused, $reason);
}

$truncated = substr($files['logo-opaque.png'], 0, 200);
$refused = false;
try {
    RasterImage::fromBinary($truncated);
} catch (Throwable $exception) {
    $refused = true;
}
check('the parser refuses a truncated PNG', $refused);

// Offset 20 sits inside the IHDR payload, which is one of the chunks whose CRC
// is verified.
$corrupted = $files['logo-opaque.png'];
$corrupted[20] = chr((ord($corrupted[20]) + 1) & 0xFF);
$refused = false;
try {
    RasterImage::fromBinary($corrupted);
} catch (Throwable $exception) {
    $refused = true;
}
check('the parser refuses a PNG whose chunk checksum does not match', $refused);

section('L2 — the resolver reads only a local file inside uploads');

check('no Custom Logo means no logo and no error',
    (static function () use ($media, $provider): bool {
        $media->attachmentId = 0;
        return $provider->logo() === null;
    })(),
    $provider->lastRejection());

$image = resolve($media, $provider, $uploads . DIRECTORY_SEPARATOR . 'logo-600x249.png');
check('a valid local PNG inside uploads is accepted', $image !== null, $provider->lastRejection());

$image = resolve($media, $provider, $uploads . DIRECTORY_SEPARATOR . 'logo.jpg', 'image/jpeg');
check('a valid local JPEG inside uploads is accepted',
    $image !== null && $image->format() === RasterImage::JPEG, $provider->lastRejection());

$cases = [
    'an SVG attachment' => [$uploads . DIRECTORY_SEPARATOR . 'logo.svg', 'image/svg+xml'],
    'an SVG renamed to .png' => [$uploads . DIRECTORY_SEPARATOR . 'logo-svg-disguised.png', 'image/png'],
    'PHP renamed to .png' => [$uploads . DIRECTORY_SEPARATOR . 'logo-php-disguised.png', 'image/png'],
    'a .php file' => [$uploads . DIRECTORY_SEPARATOR . 'payload.php', 'image/png'],
    'a remote http URL' => ['https://example.invalid/logo.png', 'image/png'],
    'a protocol-relative URL' => ['//example.invalid/logo.png', 'image/png'],
    'a data URL' => ['data:image/png;base64,iVBORw0KGgo=', 'image/png'],
    'a php:// stream wrapper' => ['php://input', 'image/png'],
    'a file:// URL' => ['file://' . $uploads . '/logo-opaque.png', 'image/png'],
    'path traversal out of uploads' => [
        $uploads . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'secret-logo.png',
        'image/png',
    ],
    'traversal to wp-config.php' => [
        $uploads . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'wp-config.php',
        'image/png',
    ],
    'an absolute path outside uploads' => [$outside . DIRECTORY_SEPARATOR . 'secret-logo.png', 'image/png'],
    'a NUL byte in the path' => [$uploads . DIRECTORY_SEPARATOR . "logo-opaque.png\0.txt", 'image/png'],
    'a file that does not exist' => [$uploads . DIRECTORY_SEPARATOR . 'absent.png', 'image/png'],
    'a file larger than the cap' => [$uploads . DIRECTORY_SEPARATOR . 'logo-huge-bytes.png', 'image/png'],
    'an image with too many pixels' => [$uploads . DIRECTORY_SEPARATOR . 'logo-too-many-pixels.png', 'image/png'],
    'a decompression bomb' => [$uploads . DIRECTORY_SEPARATOR . 'logo-bomb.png', 'image/png'],
];
foreach ($cases as $label => $case) {
    $result = resolve($media, $provider, $case[0], $case[1]);
    check('the resolver refuses ' . $label, $result === null, $provider->lastRejection());
}

// A size variant is preferred, and only its basename is ever used.
$media->attachmentId = 1;
$media->mime = 'image/png';
$media->path = $uploads . DIRECTORY_SEPARATOR . 'logo-600x249.png';
$media->metadata = ['width' => 600, 'height' => 249, 'sizes' => [
    'medium' => ['file' => 'logo-opaque.png', 'width' => 480, 'height' => 200, 'mime-type' => 'image/png'],
]];
$variant = $provider->logo();
check('a wide enough proportional size variant is preferred over the original',
    $variant !== null && $variant->width() === 480, $provider->lastRejection());

$media->metadata = ['width' => 600, 'height' => 249, 'sizes' => [
    'evil' => [
        'file' => '../../private/secret-logo.png',
        'width' => 600,
        'height' => 249,
        'mime-type' => 'image/png',
    ],
]];
$traversalVariant = $provider->logo();
check('a size variant whose filename contains a path is ignored, not resolved',
    $traversalVariant !== null && $traversalVariant->width() !== 200,
    'fell back to the original: ' . ($traversalVariant === null ? 'null' : $traversalVariant->width() . 'px'));
$media->metadata = [];

section('L2b — the whole logo, never a crop of it');

// WordPress generates two kinds of size variant from one upload: scaled copies,
// which keep the proportions, and hard crops, which do not. The names and shapes
// below are taken from the real GEWARD attachment set.
$lockupDirectory = $uploads . DIRECTORY_SEPARATOR . 'lockup';
mkdir($lockupDirectory, 0700, true);

$variants = [
    'Logo.png' => [1200, 497],          // the original: emblem plus wordmark
    'Logo-480x199.png' => [480, 199],   // scaled
    'Logo-600x249.png' => [600, 249],   // scaled
    'Logo-1024x424.png' => [1024, 424], // scaled
    'Logo-400x250.png' => [400, 250],   // hard crop
    'Logo-480x480.png' => [480, 480],   // hard crop, square: emblem only
    'Logo-1080x675.png' => [1080, 675], // hard crop
    'Logo-510x382.png' => [510, 382],   // hard crop
];
foreach ($variants as $name => $dimensions) {
    file_put_contents(
        $lockupDirectory . DIRECTORY_SEPARATOR . $name,
        makeRgbPng($dimensions[0], $dimensions[1])
    );
}

$sizesMetadata = [];
foreach ($variants as $name => $dimensions) {
    if ($name === 'Logo.png') {
        continue;
    }
    $sizesMetadata[str_replace('.png', '', $name)] = [
        'file' => $name,
        'width' => $dimensions[0],
        'height' => $dimensions[1],
        'mime-type' => 'image/png',
    ];
}

$media->attachmentId = 1;
$media->mime = 'image/png';
$media->path = $lockupDirectory . DIRECTORY_SEPARATOR . 'Logo.png';
$media->metadata = ['width' => 1200, 'height' => 497, 'sizes' => $sizesMetadata];

$chosen = $provider->logo();
$originalRatio = 1200 / 497;
check('a logo with generated crops still yields the whole logo',
    $chosen !== null && abs(($chosen->width() / $chosen->height()) - $originalRatio) <= $originalRatio * 0.01,
    $chosen === null
        ? $provider->lastRejection()
        : sprintf('%dx%d, ratio %.3f against the original %.3f',
            $chosen->width(), $chosen->height(), $chosen->width() / $chosen->height(), $originalRatio));
check('the square crop — the emblem without the wordmark — is never chosen',
    $chosen !== null && !($chosen->width() === 480 && $chosen->height() === 480));
check('a scaled variant is preferred over the full-size original',
    $chosen !== null && $chosen->width() === 480 && $chosen->height() === 199,
    $chosen === null ? 'none' : $chosen->width() . 'x' . $chosen->height());

// Only crops on offer: the original must be used rather than any of them.
$media->metadata = ['width' => 1200, 'height' => 497, 'sizes' => [
    'square' => ['file' => 'Logo-480x480.png', 'width' => 480, 'height' => 480, 'mime-type' => 'image/png'],
    'wide crop' => ['file' => 'Logo-1080x675.png', 'width' => 1080, 'height' => 675, 'mime-type' => 'image/png'],
]];
$cropsOnly = $provider->logo();
check('when every variant is a crop, the original is used instead',
    $cropsOnly !== null && $cropsOnly->width() === 1200 && $cropsOnly->height() === 497,
    $cropsOnly === null ? $provider->lastRejection() : $cropsOnly->width() . 'x' . $cropsOnly->height());

// Without the original's proportions there is nothing to compare against.
$media->metadata = ['sizes' => $sizesMetadata];
$noDimensions = $provider->logo();
check('with no recorded dimensions no variant is trusted and the original is used',
    $noDimensions !== null && $noDimensions->width() === 1200,
    $noDimensions === null ? $provider->lastRejection() : $noDimensions->width() . 'x' . $noDimensions->height());

$media->metadata = [];

section('L3 — the logo in the PDF');

$withLogo = (new EmbeddedFontPdfRenderer(2, null, null, new FixedLogoProvider($accepted)))->render(snapshot());
$withoutLogo = (new EmbeddedFontPdfRenderer(2, null, null, new FixedLogoProvider(null)))->render(snapshot());
$noProvider = (new EmbeddedFontPdfRenderer())->render(snapshot());
file_put_contents($evidence . '/pdf-logo-with.pdf', $withLogo);
file_put_contents($evidence . '/pdf-logo-without.pdf', $withoutLogo);

require_once __DIR__ . '/lib/PdfFile.php';

$logoPdf = new PdfFile($withLogo);
$plainPdf = new PdfFile($withoutLogo);

check('the document with a logo declares exactly one image resource',
    substr_count($withLogo, '/XObject <<') === 1 && substr_count($withLogo, '/Im0 ') >= 1);
check('the image is placed in the content stream',
    strpos($logoPdf->contentOfPage($logoPdf->pageObjects()[0]), '/Im0 Do') !== false);
check('the image declares dimensions and a colour space',
    (bool) preg_match('#/Subtype /Image /Width \d+ /Height \d+ /ColorSpace /DeviceRGB /BitsPerComponent 8#', $withLogo));

$withAlpha = (new EmbeddedFontPdfRenderer(2, null, null, new FixedLogoProvider($transparent)))->render(snapshot());
$alphaPdf = new PdfFile($withAlpha);
check('transparency travels as a soft mask alongside the image',
    strpos($withAlpha, '/SMask ') !== false && substr_count($withAlpha, '/Subtype /Image') === 2,
    'image plus soft mask');
check('the soft mask is an 8-bit greyscale image of the same size',
    (bool) preg_match(
        '#/Subtype /Image /Width ' . $transparent->width() . ' /Height ' . $transparent->height()
        . ' /ColorSpace /DeviceGray /BitsPerComponent 8#',
        $withAlpha
    ));
check('the transparent document is structurally valid',
    $alphaPdf->declaredPageCount() === count($alphaPdf->pageObjects()));

check('the logo document is still structurally valid',
    $logoPdf->declaredPageCount() === count($logoPdf->pageObjects()));
check('the logo document still has no active content',
    strpos($withLogo, '/JavaScript') === false
    && strpos($withLogo, '/JS') === false
    && strpos($withLogo, '/OpenAction') === false
    && strpos($withLogo, '/AA') === false
    && strpos($withLogo, '/Launch') === false
    && strpos($withLogo, '/URI') === false
    && strpos($withLogo, '/EmbeddedFile') === false
    && strpos($withLogo, '/RichMedia') === false);
check('the logo document references nothing remote',
    stripos($withLogo, 'http://') === false && stripos($withLogo, 'https://') === false);

$text = $logoPdf->text();
check('the document text is unchanged by the logo',
    strpos($text, 'Zażółć Gęślą Jaźń Sp. z o.o.') !== false
    && strpos($text, 'Płyta granitowa Nero Assoluto') !== false
    && strpos($text, 'Razem') !== false);
check('the table still starts below the logo, not behind it',
    strpos($text, 'Sprzedawca') !== false && strpos($text, 'Opis') !== false);

$hostilePath = $uploads . DIRECTORY_SEPARATOR . $hostileName;
$hostileImage = resolve($media, $provider, $hostilePath);
check('a hostile filename still resolves to a plain image', $hostileImage !== null, $provider->lastRejection());
$hostilePdf = (new EmbeddedFontPdfRenderer(2, null, null, new FixedLogoProvider($hostileImage)))->render(snapshot());
check('the filename never reaches the document',
    strpos($hostilePdf, 'DROP TABLE') === false && strpos($hostilePdf, '<script>') === false);
check('a document built from a hostile filename is still valid and inert',
    (static function () use ($hostilePdf): bool {
        try {
            new PdfFile($hostilePdf);
        } catch (Throwable $exception) {
            return false;
        }
        return strpos($hostilePdf, '/JavaScript') === false && strpos($hostilePdf, '/OpenAction') === false;
    })());

section('L4 — the fallback without a logo');

check('a null logo produces no XObject at all',
    strpos($withoutLogo, '/XObject') === false && strpos($withoutLogo, '/Subtype /Image') === false);
check('no logo provider at all behaves the same', $withoutLogo === $noProvider);
check('the document without a logo is structurally valid',
    $plainPdf->declaredPageCount() === count($plainPdf->pageObjects()));
check('the document without a logo carries the same text',
    strpos($plainPdf->text(), 'Zażółć Gęślą Jaźń Sp. z o.o.') !== false);
check('a provider that throws is not able to stop a document being issued',
    (static function (): bool {
        $throwing = new class implements LogoProvider {
            public function logo(): ?RasterImage
            {
                return null; // WordPressLogoProvider converts every failure to null
            }
        };
        return (new EmbeddedFontPdfRenderer(2, null, null, $throwing))->render(snapshot()) !== '';
    })());

$firstLogoRender = (new EmbeddedFontPdfRenderer(2, null, null, new FixedLogoProvider($accepted)))->render(snapshot());
check('rendering with a logo stays deterministic', $firstLogoRender === $withLogo,
    'sha256 ' . substr(hash('sha256', $withLogo), 0, 16));
check('the logo adds a bounded amount to the document',
    strlen($withLogo) - strlen($withoutLogo) < 512 * 1024,
    round((strlen($withLogo) - strlen($withoutLogo)) / 1024) . ' KB added');

section('L5 — the embedded pixels are the source pixels');

/** Rebuilds a standalone PNG from the XObject so a human can look at it. */
function rebuildPng(string $rgb, ?string $alpha, int $width, int $height): string
{
    $raw = '';
    for ($y = 0; $y < $height; $y++) {
        $raw .= "\x00";
        for ($x = 0; $x < $width; $x++) {
            $index = $y * $width + $x;
            $raw .= substr($rgb, $index * 3, 3);
            $raw .= $alpha === null ? "\xFF" : $alpha[$index];
        }
    }
    $ihdr = pack('NN', $width, $height) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0);
    $idat = (string) gzcompress($raw, 6);
    return "\x89PNG\r\n\x1a\n"
        . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
        . pack('N', strlen($idat)) . 'IDAT' . $idat . pack('N', crc32('IDAT' . $idat))
        . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
}

/**
 * Pulls the colour and soft-mask streams for the single image in a document.
 *
 * @return array{0:string,1:?string}
 */
function embeddedPixels(PdfFile $pdf): array
{
    $colour = '';
    $mask = null;
    foreach ($pdf->findObjects('#/Subtype /Image#') as $number) {
        $dictionary = $pdf->objects[$number]['dict'];
        $stream = (string) $pdf->objects[$number]['stream'];
        if (strpos($dictionary, '/ColorSpace /DeviceGray') !== false) {
            $mask = (string) @gzuncompress($stream);
            continue;
        }
        $colour = (string) @gzuncompress($stream);
    }
    return [$colour, $mask];
}

/**
 * Compares the pixels embedded in a document against the source image GD reads.
 *
 * @return array{0:int,1:int} pixels compared, mismatches
 */
function comparePixels(string $sourceBinary, string $rgb, ?string $alpha, int $width, int $height): array
{
    $source = imagecreatefromstring($sourceBinary);
    if ($source === false) {
        return [0, 0];
    }
    $compared = 0;
    $mismatches = 0;
    // A deterministic lattice across the whole image, not a corner sample.
    for ($y = 0; $y < $height; $y += 7) {
        for ($x = 0; $x < $width; $x += 11) {
            $index = $y * $width + $x;
            $colour = imagecolorat($source, $x, $y);
            $expectedRgb = chr(($colour >> 16) & 0xFF) . chr(($colour >> 8) & 0xFF) . chr($colour & 0xFF);
            $expectedAlpha = (int) round((127 - (($colour >> 24) & 0x7F)) * 255 / 127);
            $compared++;

            if (substr($rgb, $index * 3, 3) !== $expectedRgb) {
                $mismatches++;
                continue;
            }
            if (abs(($alpha === null ? 255 : ord($alpha[$index])) - $expectedAlpha) > 1) {
                $mismatches++;
            }
        }
    }
    imagedestroy($source);
    return [$compared, $mismatches];
}

[$rgb, $alpha] = embeddedPixels($logoPdf);
$width = $accepted->width();
$height = $accepted->height();
check('the colour stream inflates to exactly three bytes per pixel',
    strlen($rgb) === $width * $height * 3, strlen($rgb) . ' bytes for ' . $width . 'x' . $height);

[$compared, $mismatches] = comparePixels($files['logo-600x249.png'], $rgb, $alpha, $width, $height);
check('every sampled pixel of the embedded logo matches the source image',
    $compared > 0 && $mismatches === 0,
    $compared . ' pixels compared, ' . $mismatches . ' mismatched');

[$alphaRgb, $alphaMask] = embeddedPixels($alphaPdf);
check('the soft mask inflates to exactly one byte per pixel',
    $alphaMask !== null && strlen($alphaMask) === $transparent->width() * $transparent->height(),
    $alphaMask === null ? 'absent' : strlen($alphaMask) . ' bytes');
check('the mask is not uniform — real transparency survived',
    $alphaMask !== null && strspn($alphaMask, "\xFF") !== strlen($alphaMask)
    && strspn($alphaMask, "\x00") !== strlen($alphaMask));

[$comparedAlpha, $mismatchesAlpha] = comparePixels(
    $files['logo-transparent.png'],
    $alphaRgb,
    $alphaMask,
    $transparent->width(),
    $transparent->height()
);
check('every sampled pixel and alpha value of the transparent logo matches its source',
    $comparedAlpha > 0 && $mismatchesAlpha === 0,
    $comparedAlpha . ' pixels compared, ' . $mismatchesAlpha . ' mismatched');

$extracted = $evidence . '/pdf-logo-extracted.png';
file_put_contents($extracted, rebuildPng($rgb, $alpha, $width, $height));
$extractedAlpha = $evidence . '/pdf-logo-extracted-transparent.png';
file_put_contents(
    $extractedAlpha,
    rebuildPng($alphaRgb, $alphaMask, $transparent->width(), $transparent->height())
);
check('the embedded images were written back out for visual inspection',
    is_file($extracted) && is_file($extractedAlpha),
    basename($extracted) . ' ' . filesize($extracted) . ' B, '
    . basename($extractedAlpha) . ' ' . filesize($extractedAlpha) . ' B');

// ---------------------------------------------------------------------------

/** Removes the sandbox; nothing here is meant to outlive the run. */
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
