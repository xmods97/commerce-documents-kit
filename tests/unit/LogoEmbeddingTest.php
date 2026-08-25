<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
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

/**
 * The site logo in the PDF.
 *
 * The resolver's job is to decide which local file may be read; the parser's job
 * is to decide which bytes may be embedded. Both are exercised here against a
 * throwaway uploads directory, because the interesting cases are the refusals.
 *
 * review/claude/verify-logo-security.php runs the same cases plus a pixel-level
 * comparison of the embedded image against its source.
 */
final class LogoEmbeddingTest extends TestCase
{
    /** @var string */
    private $sandbox = '';
    /** @var string */
    private $uploads = '';
    /** @var string */
    private $outside = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdk-logo-test-' . bin2hex(random_bytes(4));
        $this->uploads = $this->sandbox . DIRECTORY_SEPARATOR . 'uploads';
        $this->outside = $this->sandbox . DIRECTORY_SEPARATOR . 'private';
        mkdir($this->uploads . DIRECTORY_SEPARATOR . '2026', 0700, true);
        mkdir($this->outside, 0700, true);
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            self::removeTree($this->sandbox);
        }
    }

    public function testNoCustomLogoYieldsNoLogoAndNoError(): void
    {
        $media = $this->media('');
        $media->attachmentId = 0;

        $provider = new WordPressLogoProvider($media);

        self::assertNull($provider->logo());
        self::assertStringContainsString('No Custom Logo', $provider->lastRejection());
    }

    public function testAValidLocalPngInsideUploadsIsAccepted(): void
    {
        $path = $this->write('logo.png', self::png(24, 10));
        $provider = new WordPressLogoProvider($this->media($path));

        $logo = $provider->logo();

        self::assertInstanceOf(RasterImage::class, $logo);
        self::assertSame(RasterImage::PNG, $logo->format());
        self::assertSame(24, $logo->width());
        self::assertSame(10, $logo->height());
        self::assertSame('', $provider->lastRejection());
    }

    public function testThemeHeaderLogoIsUsedWhenCustomLogoIsUnset(): void
    {
        $path = $this->write('geward-logo-primary-1200.png', self::png(1200, 497));
        $media = $this->media($path);
        $media->attachmentId = 0;
        $media->themeAttachmentId = 130;

        $logo = (new WordPressLogoProvider($media))->logo();

        self::assertInstanceOf(RasterImage::class, $logo);
        self::assertSame(1200, $logo->width());
        self::assertSame(497, $logo->height());
    }

    public function testAValidLocalJpegInsideUploadsIsAccepted(): void
    {
        if (!function_exists('imagejpeg')) {
            self::markTestSkipped('ext-gd is needed to produce a JPEG fixture.');
        }

        $path = $this->write('logo.jpg', self::jpeg(32, 16));
        $provider = new WordPressLogoProvider($this->media($path, 'image/jpeg'));

        $logo = $provider->logo();

        self::assertInstanceOf(RasterImage::class, $logo);
        self::assertSame(RasterImage::JPEG, $logo->format());
        self::assertSame('/DCTDecode', $logo->filter());
    }

    /**
     * @dataProvider refusedPaths
     */
    public function testTheResolverRefusesUnsafeSources(string $case): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"></svg>';
        $svgPath = $this->write('logo.svg', $svg);
        $disguisedPath = $this->write('disguised.png', $svg);
        $goodPath = $this->write('good.png', self::png(8, 8));
        $oversizedPath = $this->write(
            'oversized.png',
            "\x89PNG\r\n\x1a\n" . str_repeat("\x00", RasterImage::MAX_BYTES + 16)
        );
        file_put_contents($this->outside . DIRECTORY_SEPARATOR . 'secret.png', self::png(8, 8));

        $sources = [
            'svg attachment' => [$svgPath, 'image/svg+xml'],
            'svg renamed to png' => [$disguisedPath, 'image/png'],
            'remote url' => ['https://example.invalid/logo.png', 'image/png'],
            'protocol relative url' => ['//example.invalid/logo.png', 'image/png'],
            'data url' => ['data:image/png;base64,iVBORw0KGgo=', 'image/png'],
            'stream wrapper' => ['php://input', 'image/png'],
            'path traversal' => [
                dirname($goodPath) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
                    . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'secret.png',
                'image/png',
            ],
            'outside uploads' => [$this->outside . DIRECTORY_SEPARATOR . 'secret.png', 'image/png'],
            'nul byte' => [$goodPath . "\0.txt", 'image/png'],
            'oversized file' => [$oversizedPath, 'image/png'],
            'missing file' => [dirname($goodPath) . DIRECTORY_SEPARATOR . 'absent.png', 'image/png'],
        ];

        [$path, $mime] = $sources[$case];
        $provider = new WordPressLogoProvider($this->media($path, $mime));

        self::assertNull($provider->logo(), $case . ' must not resolve');
        self::assertNotSame('', $provider->lastRejection());
    }

    /** @return array<string, string[]> */
    public function refusedPaths(): array
    {
        return [
            'svg attachment' => ['svg attachment'],
            'svg renamed to png' => ['svg renamed to png'],
            'remote url' => ['remote url'],
            'protocol relative url' => ['protocol relative url'],
            'data url' => ['data url'],
            'stream wrapper' => ['stream wrapper'],
            'path traversal' => ['path traversal'],
            'outside uploads' => ['outside uploads'],
            'nul byte' => ['nul byte'],
            'oversized file' => ['oversized file'],
            'missing file' => ['missing file'],
        ];
    }

    /**
     * WordPress generates both scaled copies and hard crops from one upload. A
     * crop of a logo is a piece of a logo — for a lockup that means the emblem
     * without the wordmark — so only variants that keep the original proportions
     * may be used.
     */
    public function testACroppedSizeVariantIsNeverChosenOverTheWholeLogo(): void
    {
        $original = $this->write('Logo.png', self::png(1200, 497));
        $this->write('Logo-480x199.png', self::png(480, 199));   // scaled
        $this->write('Logo-480x480.png', self::png(480, 480));   // hard crop: emblem only
        $this->write('Logo-1080x675.png', self::png(1080, 675)); // hard crop

        $media = $this->media($original);
        $media->metadata = [
            'width' => 1200,
            'height' => 497,
            'sizes' => [
                'scaled' => ['file' => 'Logo-480x199.png', 'width' => 480, 'height' => 199, 'mime-type' => 'image/png'],
                'square' => ['file' => 'Logo-480x480.png', 'width' => 480, 'height' => 480, 'mime-type' => 'image/png'],
                'wide' => ['file' => 'Logo-1080x675.png', 'width' => 1080, 'height' => 675, 'mime-type' => 'image/png'],
            ],
        ];

        $logo = (new WordPressLogoProvider($media))->logo();

        self::assertInstanceOf(RasterImage::class, $logo);
        self::assertSame(480, $logo->width());
        self::assertSame(199, $logo->height());
        self::assertEqualsWithDelta(1200 / 497, $logo->width() / $logo->height(), 0.03);
    }

    public function testWhenEveryVariantIsACropTheOriginalIsUsed(): void
    {
        $original = $this->write('Logo.png', self::png(1200, 497));
        $this->write('Logo-480x480.png', self::png(480, 480));

        $media = $this->media($original);
        $media->metadata = [
            'width' => 1200,
            'height' => 497,
            'sizes' => [
                'square' => ['file' => 'Logo-480x480.png', 'width' => 480, 'height' => 480, 'mime-type' => 'image/png'],
            ],
        ];

        $logo = (new WordPressLogoProvider($media))->logo();

        self::assertInstanceOf(RasterImage::class, $logo);
        self::assertSame(1200, $logo->width());
        self::assertSame(497, $logo->height());
    }

    public function testWithoutRecordedDimensionsNoVariantIsTrusted(): void
    {
        $original = $this->write('Logo.png', self::png(600, 249));
        $this->write('Logo-480x199.png', self::png(480, 199));

        $media = $this->media($original);
        $media->metadata = ['sizes' => [
            'scaled' => ['file' => 'Logo-480x199.png', 'width' => 480, 'height' => 199, 'mime-type' => 'image/png'],
        ]];

        $logo = (new WordPressLogoProvider($media))->logo();

        self::assertInstanceOf(RasterImage::class, $logo);
        self::assertSame(600, $logo->width());
    }

    public function testASizeVariantFilenameCannotCarryAPath(): void
    {
        $original = $this->write('logo.png', self::png(600, 100));
        file_put_contents($this->outside . DIRECTORY_SEPARATOR . 'secret.png', self::png(40, 40));

        $media = $this->media($original);
        $media->metadata = ['width' => 600, 'height' => 100, 'sizes' => [
            'evil' => [
                'file' => '../../private/secret.png',
                'width' => 600,
                'height' => 100,
                'mime-type' => 'image/png',
            ],
        ]];

        $logo = (new WordPressLogoProvider($media))->logo();

        // The traversal entry is skipped and the original is used instead.
        self::assertInstanceOf(RasterImage::class, $logo);
        self::assertSame(600, $logo->width());
        self::assertNotSame(40, $logo->height());
    }

    public function testTheParserRefusesEverythingItCannotEmbedSafely(): void
    {
        $refused = [
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            'php' => "<?php echo 1; ?>",
            'empty' => '',
            'data url' => 'data:image/png;base64,iVBORw0KGgo=',
            'remote url' => 'https://example.invalid/logo.png',
            'oversized' => str_repeat('A', RasterImage::MAX_BYTES + 1),
            'truncated png' => substr(self::png(8, 8), 0, 30),
        ];

        foreach ($refused as $label => $bytes) {
            try {
                RasterImage::fromBinary($bytes);
                self::fail($label . ' should have been refused.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testTheParserRefusesADecompressionBomb(): void
    {
        $header = pack('NN', 10, 10) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
        $data = (string) gzcompress(str_repeat("\x00", 2000000), 9);
        $bomb = "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header))
            . pack('N', strlen($data)) . 'IDAT' . $data . pack('N', crc32('IDAT' . $data))
            . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));

        $this->expectException(InvalidArgumentException::class);
        RasterImage::fromBinary($bomb);
    }

    public function testTheParserRefusesAnImageBeyondTheDimensionCap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RasterImage::fromBinary(self::png(RasterImage::MAX_DIMENSION + 1, 2));
    }

    public function testADocumentWithALogoCarriesAnImageXObjectAndNothingActive(): void
    {
        $logo = RasterImage::fromBinary(self::png(120, 40));
        $pdf = (new EmbeddedFontPdfRenderer(2, null, null, new StubLogoProvider($logo)))->render($this->snapshot());

        self::assertSame(1, substr_count($pdf, '/XObject <<'));
        self::assertStringContainsString('/Subtype /Image', $pdf);
        self::assertStringContainsString('/Im0 Do', $pdf);
        self::assertMatchesRegularExpression(
            '#/Subtype /Image /Width 120 /Height 40 /ColorSpace /DeviceRGB /BitsPerComponent 8#',
            $pdf
        );

        foreach (['/JavaScript', '/JS', '/OpenAction', '/AA', '/Launch', '/URI', '/EmbeddedFile',
                  '/RichMedia', 'http://', 'https://'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $pdf);
        }
    }

    public function testTransparencyBecomesASoftMask(): void
    {
        $logo = RasterImage::fromBinary(self::png(20, 10, true));

        self::assertTrue($logo->hasAlpha());

        $pdf = (new EmbeddedFontPdfRenderer(2, null, null, new StubLogoProvider($logo)))->render($this->snapshot());

        self::assertStringContainsString('/SMask ', $pdf);
        self::assertSame(2, substr_count($pdf, '/Subtype /Image'));
        self::assertMatchesRegularExpression(
            '#/Subtype /Image /Width 20 /Height 10 /ColorSpace /DeviceGray /BitsPerComponent 8#',
            $pdf
        );
    }

    public function testADocumentWithoutALogoRemainsValid(): void
    {
        $snapshot = $this->snapshot();
        $withoutProvider = (new EmbeddedFontPdfRenderer())->render($snapshot);
        $withNullLogo = (new EmbeddedFontPdfRenderer(2, null, null, new StubLogoProvider(null)))
            ->render($snapshot);

        self::assertSame($withoutProvider, $withNullLogo);
        self::assertStringNotContainsString('/XObject', $withoutProvider);
        self::assertStringNotContainsString('/Subtype /Image', $withoutProvider);
        self::assertStringStartsWith("%PDF-1.4\n", $withoutProvider);
        self::assertStringEndsWith("%%EOF\n", $withoutProvider);
    }

    public function testAHostileFilenameNeverReachesTheDocument(): void
    {
        $name = "logo';DROP TABLE wp_posts;--script.png";
        $path = $this->write($name, self::png(60, 20));
        $provider = new WordPressLogoProvider($this->media($path));

        $logo = $provider->logo();
        self::assertInstanceOf(RasterImage::class, $logo);

        $pdf = (new EmbeddedFontPdfRenderer(2, null, null, new StubLogoProvider($logo)))->render($this->snapshot());

        self::assertStringNotContainsString('DROP TABLE', $pdf);
        self::assertStringNotContainsString('wp_posts', $pdf);
        self::assertStringNotContainsString('/JavaScript', $pdf);
        self::assertStringNotContainsString('/OpenAction', $pdf);
    }

    public function testRenderingWithALogoStaysDeterministic(): void
    {
        $logo = RasterImage::fromBinary(self::png(80, 30, true));
        $snapshot = $this->snapshot();

        $first = (new EmbeddedFontPdfRenderer(2, null, null, new StubLogoProvider($logo)))->render($snapshot);
        $second = (new EmbeddedFontPdfRenderer(2, null, null, new StubLogoProvider($logo)))->render($snapshot);

        self::assertSame($first, $second);
    }

    // -----------------------------------------------------------------------

    private function media(string $path, string $mime = 'image/png'): FakeMediaLibraryForTests
    {
        $media = new FakeMediaLibraryForTests();
        $media->uploads = $this->uploads;
        $media->path = $path;
        $media->mime = $mime;
        return $media;
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->uploads . DIRECTORY_SEPARATOR . '2026' . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    /** A valid PNG built without ext-gd: 8-bit RGB, or RGBA with a transparent left half. */
    private static function png(int $width, int $height, bool $alpha = false): string
    {
        $channels = $alpha ? 4 : 3;
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00"; // filter: none
            for ($x = 0; $x < $width; $x++) {
                $raw .= chr(($x * 7) % 256) . chr(($y * 11) % 256) . chr(($x + $y) % 256);
                if ($alpha) {
                    $raw .= $x < intdiv($width, 2) ? "\x00" : "\xFF";
                }
            }
        }

        $header = pack('NN', $width, $height) . chr(8) . chr($alpha ? 6 : 2) . chr(0) . chr(0) . chr(0);
        $data = (string) gzcompress($raw, 6);

        return "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . $header . pack('N', crc32('IHDR' . $header))
            . pack('N', strlen($data)) . 'IDAT' . $data . pack('N', crc32('IDAT' . $data))
            . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    }

    private static function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 20, 60, 110));
        ob_start();
        imagejpeg($image, null, 85);
        $binary = (string) ob_get_clean();
        imagedestroy($image);
        return $binary;
    }

    private static function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? self::removeTree($full) : @unlink($full);
        }
        @rmdir($path);
    }

    private function snapshot(): DocumentSnapshot
    {
        $currency = Currency::fromCode('PLN');
        $address = Address::create('ul. Kamienna 12', '', '00-950', 'Warszawa', '', 'PL');

        return DocumentSnapshot::create(
            'doc_logo',
            'ORDER_CONFIRMATION/2026/000042',
            DocumentType::fromString(DocumentType::ORDER_CONFIRMATION),
            DocumentStatus::fromString(DocumentStatus::ISSUED),
            'woocommerce_order',
            '4242',
            $currency,
            Language::fromTag('pl-PL'),
            Party::create('GEWARD Sp. z o.o.', '1234567890', '', $address),
            Party::create('Zażółć Gęślą Jaźń', '', '', $address),
            [
                DocumentItem::create(
                    'Płyta granitowa',
                    Quantity::one(),
                    'szt.',
                    Money::fromMinorUnits(18900, $currency),
                    TaxRate::fromPartsPerMillion(230000)
                ),
            ],
            '2026-07-28T10:00:00+02:00',
            '2026-07-28T10:00:00+02:00'
        );
    }
}

/** A media library whose answers the test controls, including hostile ones. */
final class FakeMediaLibraryForTests implements MediaLibrary
{
    public $attachmentId = 1;
    public $themeAttachmentId = 0;
    public $mime = 'image/png';
    public $path = '';
    public $metadata = [];
    public $uploads = '';

    public function customLogoAttachmentId(): int
    {
        return $this->attachmentId;
    }

    public function themeHeaderLogoAttachmentId(): int
    {
        return $this->themeAttachmentId;
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

final class StubLogoProvider implements LogoProvider
{
    /** @var ?RasterImage */
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
