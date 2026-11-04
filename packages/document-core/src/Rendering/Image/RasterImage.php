<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering\Image;

use InvalidArgumentException;

/**
 * A PNG or JPEG turned into something a PDF image XObject can carry.
 *
 * This is the only component in the renderer that parses attacker-influenceable
 * binary data, so it is written to be hostile-first:
 *
 * - It accepts **bytes**, never a path or a URL. Deciding which file may be read
 *   is somebody else's job (see WordPressLogoProvider); this class cannot open
 *   anything, so it cannot be talked into opening the wrong thing.
 * - Every length is checked against the buffer before it is used, chunk counts
 *   and pixel counts are capped, and the inflated size of the image data is
 *   bounded to exactly what the header declares — a decompression bomb inflates
 *   to the declared size and no further.
 * - The accepted format list is short and closed: 8-bit PNG (greyscale, RGB,
 *   indexed, and either of those with alpha) and baseline JPEG. Interlaced PNG,
 *   16-bit samples, progressive and arithmetic-coded JPEG and CMYK are rejected
 *   with a reason rather than guessed at.
 * - Nothing is executed, nothing is written, no chunk type outside the handful
 *   listed below is even looked at.
 *
 * A rejection is an InvalidArgumentException. The caller is expected to treat it
 * as "no logo" and carry on: a document without a logo is worth more than a
 * document that failed to render.
 */
final class RasterImage
{
    public const PNG = 'png';
    public const JPEG = 'jpeg';

    /** Bytes accepted from the source file. */
    public const MAX_BYTES = 2097152; // 2 MB

    /** Pixels accepted, which also bounds the memory the decoder can use. */
    public const MAX_PIXELS = 4000000;

    /** Neither dimension may exceed this. */
    public const MAX_DIMENSION = 5000;

    /** PNG chunks read before the file is considered malformed. */
    private const MAX_CHUNKS = 512;

    /** @var string */
    private $format;
    /** @var int */
    private $width;
    /** @var int */
    private $height;
    /** @var int */
    private $bitsPerComponent;
    /** @var string DeviceRGB, DeviceGray, or an /Indexed array body */
    private $colourSpace;
    /** @var string PDF stream filter name */
    private $filter;
    /** @var string */
    private $data;
    /** @var string decode parameters for the stream, may be empty */
    private $decodeParms;
    /** @var ?string 8-bit greyscale soft mask, Flate compressed */
    private $softMask;

    private function __construct(
        string $format,
        int $width,
        int $height,
        int $bitsPerComponent,
        string $colourSpace,
        string $filter,
        string $data,
        string $decodeParms = '',
        ?string $softMask = null
    ) {
        $this->format = $format;
        $this->width = $width;
        $this->height = $height;
        $this->bitsPerComponent = $bitsPerComponent;
        $this->colourSpace = $colourSpace;
        $this->filter = $filter;
        $this->data = $data;
        $this->decodeParms = $decodeParms;
        $this->softMask = $softMask;
    }

    /**
     * @throws InvalidArgumentException when the bytes are not an image this
     *                                  renderer is prepared to embed
     */
    public static function fromBinary(string $binary): self
    {
        if ($binary === '') {
            throw new InvalidArgumentException('Image is empty.');
        }
        if (strlen($binary) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Image exceeds the maximum accepted size.');
        }

        if (strncmp($binary, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return self::fromPng($binary);
        }
        if (strncmp($binary, "\xFF\xD8\xFF", 3) === 0) {
            return self::fromJpeg($binary);
        }

        // Everything else, including SVG, is refused here. There is no sniffing
        // fallback: an unrecognised signature is a rejection, not a guess.
        throw new InvalidArgumentException('Image is neither PNG nor JPEG.');
    }

    public function format(): string
    {
        return $this->format;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function bitsPerComponent(): int
    {
        return $this->bitsPerComponent;
    }

    public function colourSpace(): string
    {
        return $this->colourSpace;
    }

    public function filter(): string
    {
        return $this->filter;
    }

    public function data(): string
    {
        return $this->data;
    }

    public function decodeParms(): string
    {
        return $this->decodeParms;
    }

    public function softMask(): ?string
    {
        return $this->softMask;
    }

    public function hasAlpha(): bool
    {
        return $this->softMask !== null;
    }

    /** Total bytes this image will add to a document. */
    public function embeddedSize(): int
    {
        return strlen($this->data) + ($this->softMask === null ? 0 : strlen($this->softMask));
    }

    // -----------------------------------------------------------------------
    // PNG
    // -----------------------------------------------------------------------

    private static function fromPng(string $binary): self
    {
        if (!function_exists('gzuncompress') || !function_exists('gzcompress')) {
            // Splitting alpha out of a PNG needs the pixels, and getting the
            // pixels needs zlib. Without it the honest answer is "no logo".
            throw new InvalidArgumentException('PNG support requires ext-zlib.');
        }

        $header = null;
        $palette = '';
        $transparency = null;
        $image = '';
        $length = strlen($binary);
        $position = 8;
        $chunks = 0;
        $ended = false;

        while ($position + 8 <= $length) {
            if (++$chunks > self::MAX_CHUNKS) {
                throw new InvalidArgumentException('PNG has too many chunks.');
            }
            $size = self::uint32($binary, $position);
            $type = substr($binary, $position + 4, 4);
            if ($size > $length - $position - 12) {
                throw new InvalidArgumentException('PNG chunk length runs past the end of the file.');
            }
            $body = substr($binary, $position + 8, $size);
            $storedCrc = self::uint32($binary, $position + 8 + $size);
            $position += 12 + $size;

            if (in_array($type, ['IHDR', 'PLTE', 'tRNS', 'IDAT', 'IEND'], true)
                && crc32($type . $body) !== $storedCrc
            ) {
                throw new InvalidArgumentException('PNG chunk ' . $type . ' fails its checksum.');
            }

            switch ($type) {
                case 'IHDR':
                    if ($header !== null || $size !== 13) {
                        throw new InvalidArgumentException('PNG header chunk is invalid.');
                    }
                    $header = unpack('Nwidth/Nheight/Cdepth/Ccolour/Ccompression/Cfilter/Cinterlace', $body);
                    break;
                case 'PLTE':
                    $palette = $body;
                    break;
                case 'tRNS':
                    $transparency = $body;
                    break;
                case 'IDAT':
                    $image .= $body;
                    if (strlen($image) > self::MAX_BYTES) {
                        throw new InvalidArgumentException('PNG image data exceeds the maximum accepted size.');
                    }
                    break;
                case 'IEND':
                    $ended = true;
                    break;
                default:
                    // Ancillary chunks — colour profiles, text, timestamps — are
                    // not read at all. Nothing outside the five above influences
                    // what is embedded.
                    break;
            }
            if ($ended) {
                break;
            }
        }

        if ($header === null || $image === '' || !$ended) {
            throw new InvalidArgumentException('PNG is truncated or has no image data.');
        }

        $width = $header['width'];
        $height = $header['height'];
        $depth = $header['depth'];
        $colour = $header['colour'];

        self::assertDimensions($width, $height);
        if ($header['compression'] !== 0 || $header['filter'] !== 0) {
            throw new InvalidArgumentException('PNG uses an unsupported compression or filter method.');
        }
        if ($header['interlace'] !== 0) {
            throw new InvalidArgumentException('Interlaced PNG is not supported.');
        }

        $channels = self::pngChannels($colour);
        if ($depth === 16) {
            throw new InvalidArgumentException('16-bit PNG is not supported.');
        }
        if ($colour === 3) {
            if (!in_array($depth, [1, 2, 4, 8], true)) {
                throw new InvalidArgumentException('Indexed PNG must be 1, 2, 4 or 8 bits deep.');
            }
            if ($palette === '' || strlen($palette) % 3 !== 0) {
                throw new InvalidArgumentException('Indexed PNG has no usable palette.');
            }
        } elseif ($depth !== 8) {
            throw new InvalidArgumentException('Only 8-bit PNG samples are supported.');
        }

        $bitsPerPixel = $depth * $channels;
        $stride = intdiv($width * $bitsPerPixel + 7, 8);
        $expected = ($stride + 1) * $height;

        // The inflated size is pinned to what the header describes, so a crafted
        // stream cannot expand beyond it.
        $raw = @gzuncompress($image, $expected);
        if ($raw === false || strlen($raw) !== $expected) {
            throw new InvalidArgumentException('PNG image data does not match its declared dimensions.');
        }

        $samples = self::unfilter($raw, $stride, $height, max(1, intdiv($bitsPerPixel, 8)));

        return self::fromPngSamples($samples, $width, $height, $depth, $colour, $palette, $transparency);
    }

    private static function pngChannels(int $colourType): int
    {
        switch ($colourType) {
            case 0:
                return 1; // greyscale
            case 2:
                return 3; // truecolour
            case 3:
                return 1; // indexed
            case 4:
                return 2; // greyscale + alpha
            case 6:
                return 4; // truecolour + alpha
            default:
                throw new InvalidArgumentException('PNG uses an unsupported colour type.');
        }
    }

    private static function fromPngSamples(
        string $samples,
        int $width,
        int $height,
        int $depth,
        int $colour,
        string $palette,
        ?string $transparency
    ): self {
        $pixels = $width * $height;
        $alpha = null;

        switch ($colour) {
            case 0:
                $colourSpace = '/DeviceGray';
                $colourData = $samples;
                if ($transparency !== null && strlen($transparency) >= 2) {
                    $alpha = self::colourKeyMask($samples, $pixels, 1, chr(ord($transparency[1])));
                }
                break;
            case 2:
                $colourSpace = '/DeviceRGB';
                $colourData = $samples;
                if ($transparency !== null && strlen($transparency) >= 6) {
                    $key = $transparency[1] . $transparency[3] . $transparency[5];
                    $alpha = self::colourKeyMask($samples, $pixels, 3, $key);
                }
                break;
            case 3:
                $colourSpace = '[/Indexed /DeviceRGB ' . (intdiv(strlen($palette), 3) - 1)
                    . ' <' . bin2hex($palette) . '>]';
                $colourData = $samples;
                if ($transparency !== null && $transparency !== '') {
                    if ($depth !== 8) {
                        throw new InvalidArgumentException(
                            'Indexed PNG with transparency must be 8 bits deep.'
                        );
                    }
                    $alpha = '';
                    for ($i = 0; $i < $pixels; $i++) {
                        $index = ord($samples[$i]);
                        $alpha .= $index < strlen($transparency) ? $transparency[$index] : "\xFF";
                    }
                }
                break;
            case 4:
                $colourSpace = '/DeviceGray';
                $colourData = '';
                $alpha = '';
                for ($i = 0; $i < $pixels; $i++) {
                    $colourData .= $samples[$i * 2];
                    $alpha .= $samples[$i * 2 + 1];
                }
                break;
            case 6:
            default:
                $colourSpace = '/DeviceRGB';
                $colourData = '';
                $alpha = '';
                for ($i = 0; $i < $pixels; $i++) {
                    $offset = $i * 4;
                    $colourData .= substr($samples, $offset, 3);
                    $alpha .= $samples[$offset + 3];
                }
                break;
        }

        // A fully opaque alpha channel is dropped: it would double the embedded
        // size and change nothing on the page.
        if ($alpha !== null && strspn($alpha, "\xFF") === strlen($alpha)) {
            $alpha = null;
        }

        return new self(
            self::PNG,
            $width,
            $height,
            $depth,
            $colourSpace,
            '/FlateDecode',
            (string) gzcompress($colourData, 6),
            '',
            $alpha === null ? null : (string) gzcompress($alpha, 6)
        );
    }

    private static function colourKeyMask(string $samples, int $pixels, int $components, string $key): ?string
    {
        $mask = '';
        $transparent = false;
        for ($i = 0; $i < $pixels; $i++) {
            if (substr($samples, $i * $components, $components) === $key) {
                $mask .= "\x00";
                $transparent = true;
            } else {
                $mask .= "\xFF";
            }
        }
        return $transparent ? $mask : null;
    }

    /** Reverses the per-scanline PNG filters. */
    private static function unfilter(string $raw, int $stride, int $height, int $bpp): string
    {
        $out = '';
        $previous = str_repeat("\x00", $stride);
        $position = 0;

        for ($row = 0; $row < $height; $row++) {
            $filter = ord($raw[$position]);
            $line = substr($raw, $position + 1, $stride);
            $position += $stride + 1;

            switch ($filter) {
                case 0: // None
                    break;
                case 1: // Sub
                    for ($i = $bpp; $i < $stride; $i++) {
                        $line[$i] = chr((ord($line[$i]) + ord($line[$i - $bpp])) & 0xFF);
                    }
                    break;
                case 2: // Up
                    for ($i = 0; $i < $stride; $i++) {
                        $line[$i] = chr((ord($line[$i]) + ord($previous[$i])) & 0xFF);
                    }
                    break;
                case 3: // Average
                    for ($i = 0; $i < $stride; $i++) {
                        $left = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
                        $line[$i] = chr((ord($line[$i]) + intdiv($left + ord($previous[$i]), 2)) & 0xFF);
                    }
                    break;
                case 4: // Paeth
                    for ($i = 0; $i < $stride; $i++) {
                        $left = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
                        $above = ord($previous[$i]);
                        $corner = $i >= $bpp ? ord($previous[$i - $bpp]) : 0;
                        $estimate = $left + $above - $corner;
                        $dl = abs($estimate - $left);
                        $da = abs($estimate - $above);
                        $dc = abs($estimate - $corner);
                        if ($dl <= $da && $dl <= $dc) {
                            $predictor = $left;
                        } elseif ($da <= $dc) {
                            $predictor = $above;
                        } else {
                            $predictor = $corner;
                        }
                        $line[$i] = chr((ord($line[$i]) + $predictor) & 0xFF);
                    }
                    break;
                default:
                    throw new InvalidArgumentException('PNG uses an unknown scanline filter.');
            }

            $out .= $line;
            $previous = $line;
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // JPEG
    // -----------------------------------------------------------------------

    /**
     * JPEG needs no decoding: PDF's /DCTDecode filter takes the compressed data
     * as it stands. The markers are walked only to learn the dimensions and to
     * refuse the variants viewers handle inconsistently.
     */
    private static function fromJpeg(string $binary): self
    {
        $length = strlen($binary);
        $position = 2;
        $frames = 0;

        while ($position + 4 <= $length) {
            if ($binary[$position] !== "\xFF") {
                throw new InvalidArgumentException('JPEG marker structure is invalid.');
            }
            $marker = ord($binary[$position + 1]);
            if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $position += 2;
                continue;
            }
            if ($marker === 0xD9 || $marker === 0xDA) {
                break; // end of image, or start of scan
            }

            $segment = self::uint16($binary, $position + 2);
            if ($segment < 2 || $position + 2 + $segment > $length) {
                throw new InvalidArgumentException('JPEG segment length runs past the end of the file.');
            }

            // Baseline (C0) and extended sequential (C1) only. C2 is progressive,
            // C9-CB are arithmetic coded, C3/C5-C7 are lossless or hierarchical:
            // all are either unsupported by /DCTDecode or rendered inconsistently.
            if ($marker === 0xC0 || $marker === 0xC1) {
                if (++$frames > 1 || $segment < 8) {
                    throw new InvalidArgumentException('JPEG frame header is invalid.');
                }
                $precision = ord($binary[$position + 4]);
                $height = self::uint16($binary, $position + 5);
                $width = self::uint16($binary, $position + 7);
                $components = ord($binary[$position + 9]);

                if ($precision !== 8) {
                    throw new InvalidArgumentException('Only 8-bit JPEG is supported.');
                }
                if ($components !== 1 && $components !== 3) {
                    throw new InvalidArgumentException('Only greyscale and YCbCr JPEG are supported.');
                }
                self::assertDimensions($width, $height);

                return new self(
                    self::JPEG,
                    $width,
                    $height,
                    8,
                    $components === 1 ? '/DeviceGray' : '/DeviceRGB',
                    '/DCTDecode',
                    $binary
                );
            }
            if (($marker >= 0xC2 && $marker <= 0xCF) && $marker !== 0xC4 && $marker !== 0xCC) {
                throw new InvalidArgumentException('Progressive, lossless or arithmetic JPEG is not supported.');
            }

            $position += 2 + $segment;
        }

        throw new InvalidArgumentException('JPEG has no baseline frame header.');
    }

    private static function assertDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Image has no area.');
        }
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new InvalidArgumentException('Image is larger than the maximum accepted dimension.');
        }
        if ($width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Image has more pixels than the renderer accepts.');
        }
    }

    private static function uint16(string $data, int $offset): int
    {
        return unpack('n', substr($data, $offset, 2))[1];
    }

    private static function uint32(string $data, int $offset): int
    {
        return unpack('N', substr($data, $offset, 4))[1];
    }
}
