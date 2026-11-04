<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering\Font;

use RuntimeException;

/**
 * A TrueType subset embedded into every generated PDF.
 *
 * Why a subset and not a parser: the font program and its metrics are produced
 * offline by tools/build-pdf-font.php and committed. At request time this class
 * reads two fixed files, checks the font program against the SHA-256 recorded in
 * the generated metrics, and does nothing else with it — the bytes are copied
 * into the PDF verbatim. There is no font parsing, no font cache, no font path
 * taken from input, and therefore none of the font-handling attack surface that
 * HTML-to-PDF engines carry.
 *
 * Text is written as glyph ids inside PDF hex strings, so document content can
 * never terminate a string or inject a content-stream operator: the only bytes
 * that reach the page are the sixteen hexadecimal digits.
 */
final class EmbeddedFont
{
    public const REGULAR = 'regular';
    public const BOLD = 'bold';

    /** Codepoint substitutions applied before the subset is consulted. */
    private const NORMALISATION = [
        0x00A0 => 0x0020, // no-break space
        0x2007 => 0x0020,
        0x2009 => 0x0020,
        0x202F => 0x0020,
        0x2028 => 0x0020,
        0x2029 => 0x0020,
    ];

    /** Replacement for anything the subset does not cover. */
    private const REPLACEMENT = 0xFFFD;

    /** @var string */
    private $style;
    /** @var string */
    private $program;
    /** @var array<string, mixed> */
    private $metrics;
    /** @var array<int, int> */
    private $glyphs;
    /** @var array<int, int> */
    private $widths;

    private function __construct(string $style)
    {
        // The style is one of two constants and is concatenated into the filename
        // only after this check, so no caller-supplied value can reach the path.
        if ($style !== self::REGULAR && $style !== self::BOLD) {
            throw new RuntimeException('Unknown embedded font style.');
        }

        $directory = __DIR__ . '/../../../resources/fonts';
        $programFile = $directory . '/dejavu-sans-' . $style . '.ttf';
        $metricsFile = $directory . '/dejavu-sans-' . $style . '.php';

        if (!is_file($programFile) || !is_file($metricsFile)) {
            throw new RuntimeException('The embedded font asset is missing; run tools/build-pdf-font.php.');
        }

        /** @var array<string, mixed> $metrics */
        $metrics = require $metricsFile;
        $program = file_get_contents($programFile);
        if ($program === false) {
            throw new RuntimeException('The embedded font asset could not be read.');
        }

        self::assertProgramMatches($program, (string) ($metrics['sha256'] ?? ''));

        $this->style = $style;
        $this->program = $program;
        $this->metrics = $metrics;
        $this->glyphs = (array) $metrics['glyphs'];
        $this->widths = (array) $metrics['widths'];
    }

    /**
     * The metrics and the font program are generated together by
     * tools/build-pdf-font.php. If they ever disagree the glyph ids would be
     * wrong, so this fails closed rather than rendering a document with scrambled
     * text. Exposed so the verification harness can exercise the rejection path
     * without touching the committed assets.
     */
    public static function assertProgramMatches(string $program, string $expectedSha256): void
    {
        if (!hash_equals($expectedSha256, hash('sha256', $program))) {
            throw new RuntimeException('The embedded font asset does not match its recorded checksum.');
        }
    }

    public static function regular(): self
    {
        return new self(self::REGULAR);
    }

    public static function bold(): self
    {
        return new self(self::BOLD);
    }

    public function style(): string
    {
        return $this->style;
    }

    public function program(): string
    {
        return $this->program;
    }

    /**
     * The six-letter subset tag PDF expects in front of an embedded font name.
     * Derived from the font checksum, so it is stable across runs.
     */
    public function subsetTag(): string
    {
        $digest = hash('sha256', $this->program, true);
        $tag = '';
        for ($i = 0; $i < 6; $i++) {
            $tag .= chr(ord('A') + (ord($digest[$i]) % 26));
        }
        return $tag;
    }

    public function baseFontName(): string
    {
        $name = (string) ($this->metrics['postscript_name'] ?? 'DejaVuSans');
        $name = preg_replace('/[^A-Za-z0-9\-]/', '', $name);
        return $this->subsetTag() . '+' . ($name === '' ? 'DejaVuSans' : $name);
    }

    public function ascent(): int
    {
        return (int) $this->metrics['ascent'];
    }

    public function descent(): int
    {
        return (int) $this->metrics['descent'];
    }

    public function capHeight(): int
    {
        return (int) $this->metrics['cap_height'];
    }

    public function italicAngle(): int
    {
        return (int) $this->metrics['italic_angle'];
    }

    /** @return int[] */
    public function boundingBox(): array
    {
        return array_map('intval', (array) $this->metrics['bbox']);
    }

    public function glyphCount(): int
    {
        return (int) $this->metrics['glyph_count'];
    }

    /** @return array<int, int> glyph id => width in 1/1000 em */
    public function widths(): array
    {
        return $this->widths;
    }

    /**
     * Glyph ids for a UTF-8 string. Anything the subset does not cover becomes the
     * replacement character rather than disappearing, so a rendering gap is
     * visible on the document instead of silently changing its meaning.
     *
     * @return int[]
     */
    public function glyphsFor(string $text): array
    {
        $glyphs = [];
        foreach (self::codepoints($text) as $codepoint) {
            $codepoint = self::NORMALISATION[$codepoint] ?? $codepoint;
            if ($codepoint < 0x20 || $codepoint === 0x7F) {
                // Control characters carry no glyph and must not reach the page.
                continue;
            }
            $glyphs[] = $this->glyphs[$codepoint]
                ?? $this->glyphs[self::REPLACEMENT]
                ?? $this->glyphs[0x3F]
                ?? 0;
        }
        return $glyphs;
    }

    /**
     * Width of a string in points at the given font size.
     */
    public function widthOf(string $text, float $size): float
    {
        $width = 0;
        foreach ($this->glyphsFor($text) as $glyph) {
            $width += $this->widths[$glyph] ?? 0;
        }
        return $width * $size / 1000;
    }

    /**
     * The string as a PDF hex string of glyph ids, including the delimiters.
     * Nothing but hexadecimal digits can be produced here.
     */
    public function hexString(string $text): string
    {
        $hex = '';
        foreach ($this->glyphsFor($text) as $glyph) {
            $hex .= sprintf('%04X', $glyph & 0xFFFF);
        }
        return '<' . $hex . '>';
    }

    /**
     * Glyph id => codepoint, for the /ToUnicode CMap that makes the text
     * selectable and searchable in a viewer.
     *
     * @return array<int, int>
     */
    public function reverseMap(): array
    {
        $reverse = [];
        foreach ($this->glyphs as $codepoint => $glyph) {
            if (!isset($reverse[$glyph])) {
                $reverse[$glyph] = $codepoint;
            }
        }
        ksort($reverse, SORT_NUMERIC);
        return $reverse;
    }

    /**
     * Strict UTF-8 decoding without ext-mbstring. Overlong forms, surrogates and
     * truncated sequences yield the replacement character instead of being
     * reinterpreted, so malformed input cannot smuggle a different codepoint.
     *
     * @return int[]
     */
    public static function codepoints(string $text): array
    {
        $codepoints = [];
        $length = strlen($text);
        for ($i = 0; $i < $length;) {
            $byte = ord($text[$i]);
            if ($byte < 0x80) {
                $codepoints[] = $byte;
                $i++;
                continue;
            }
            if ($byte >= 0xC2 && $byte <= 0xDF) {
                $width = 2;
                $codepoint = $byte & 0x1F;
            } elseif ($byte >= 0xE0 && $byte <= 0xEF) {
                $width = 3;
                $codepoint = $byte & 0x0F;
            } elseif ($byte >= 0xF0 && $byte <= 0xF4) {
                $width = 4;
                $codepoint = $byte & 0x07;
            } else {
                $codepoints[] = self::REPLACEMENT;
                $i++;
                continue;
            }

            if ($i + $width > $length) {
                $codepoints[] = self::REPLACEMENT;
                break;
            }
            $valid = true;
            for ($k = 1; $k < $width; $k++) {
                $continuation = ord($text[$i + $k]);
                if ($continuation < 0x80 || $continuation > 0xBF) {
                    $valid = false;
                    break;
                }
                $codepoint = ($codepoint << 6) | ($continuation & 0x3F);
            }
            if (!$valid
                || ($width === 3 && ($codepoint < 0x800 || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)))
                || ($width === 4 && ($codepoint < 0x10000 || $codepoint > 0x10FFFF))
            ) {
                $codepoints[] = self::REPLACEMENT;
                $i++;
                continue;
            }
            $codepoints[] = $codepoint;
            $i += $width;
        }
        return $codepoints;
    }
}
