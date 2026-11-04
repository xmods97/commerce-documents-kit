<?php

declare(strict_types=1);

/**
 * Offline build tool: turns a full TrueType font into the small, fixed subset
 * that the PDF renderer embeds.
 *
 * This runs at build time, never at request time. The renderer only reads the
 * generated .ttf and the generated metrics file; it contains no font parser, so
 * a malformed font can never be parsed inside a web request.
 *
 * Usage:
 *   php tools/build-pdf-font.php <source.ttf> <regular|bold>
 *
 * The source font must be a licence-compatible TrueType file (the shipped subset
 * is built from DejaVu Sans, whose licence permits embedding and redistribution;
 * the licence text is extracted from the font's own name table into
 * packages/document-core/resources/fonts/LICENSE-DejaVu.txt).
 *
 * The subset keeps only the glyphs listed in CHARSET plus every component glyph
 * a kept composite refers to. Glyph ids are renumbered, so composite component
 * indices are rewritten; `verify-pdf-engine.php` re-parses the result and checks
 * every one of them against the source font.
 */

const OUTPUT_DIR = __DIR__ . '/../packages/document-core/resources/fonts';

/** @return int[] */
function charset(): array
{
    $codepoints = [];
    // Printable ASCII.
    for ($c = 0x20; $c <= 0x7E; $c++) {
        $codepoints[] = $c;
    }
    // Latin-1 Supplement: German, French, Nordic and Spanish buyer names, plus
    // the degree, section and multiplication signs used in item descriptions.
    for ($c = 0xA0; $c <= 0xFF; $c++) {
        $codepoints[] = $c;
    }
    // Latin Extended-A: the full Polish alphabet, and Czech, Slovak, Hungarian,
    // Romanian and Baltic letters that appear in EU addresses.
    for ($c = 0x100; $c <= 0x17F; $c++) {
        $codepoints[] = $c;
    }
    // Typography and currency actually used by the templates.
    foreach ([
        0x2010, 0x2011, 0x2013, 0x2014, 0x2018, 0x2019, 0x201A, 0x201C, 0x201D,
        0x201E, 0x2020, 0x2022, 0x2026, 0x2030, 0x2039, 0x203A, 0x20AC, 0x2122,
        0x2212, 0xFFFD,
    ] as $c) {
        $codepoints[] = $c;
    }

    return $codepoints;
}

final class TrueTypeReader
{
    /** @var string */
    private $data;
    /** @var array<string, array{0:int,1:int}> */
    private $tables = [];

    public function __construct(string $data)
    {
        $this->data = $data;
        if (strlen($data) < 12) {
            throw new RuntimeException('Font file is too short.');
        }
        $version = substr($data, 0, 4);
        if ($version !== "\x00\x01\x00\x00" && $version !== 'true') {
            throw new RuntimeException('Only TrueType outline fonts are supported.');
        }
        $numTables = self::uint16($data, 4);
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + $i * 16;
            $tag = substr($data, $record, 4);
            $offset = self::uint32($data, $record + 8);
            $length = self::uint32($data, $record + 12);
            if ($offset + $length > strlen($data)) {
                throw new RuntimeException(sprintf('Table %s runs past the end of the font.', $tag));
            }
            $this->tables[$tag] = [$offset, $length];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap', 'loca', 'glyf', 'name', 'post'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new RuntimeException(sprintf('Font has no %s table.', $required));
            }
        }
    }

    public function has(string $tag): bool
    {
        return isset($this->tables[$tag]);
    }

    public function table(string $tag): string
    {
        if (!isset($this->tables[$tag])) {
            throw new RuntimeException(sprintf('Font has no %s table.', $tag));
        }
        return substr($this->data, $this->tables[$tag][0], $this->tables[$tag][1]);
    }

    public function unitsPerEm(): int
    {
        return self::uint16($this->table('head'), 18);
    }

    public function indexToLocFormat(): int
    {
        return self::uint16($this->table('head'), 50);
    }

    public function numGlyphs(): int
    {
        return self::uint16($this->table('maxp'), 4);
    }

    public function numberOfHMetrics(): int
    {
        return self::uint16($this->table('hhea'), 34);
    }

    /** @return array{ascent:int,descent:int,lineGap:int} */
    public function verticalMetrics(): array
    {
        $hhea = $this->table('hhea');
        return [
            'ascent' => self::int16($hhea, 4),
            'descent' => self::int16($hhea, 6),
            'lineGap' => self::int16($hhea, 8),
        ];
    }

    /** @return int[] {xMin, yMin, xMax, yMax} */
    public function boundingBox(): array
    {
        $head = $this->table('head');
        return [
            self::int16($head, 36),
            self::int16($head, 38),
            self::int16($head, 40),
            self::int16($head, 42),
        ];
    }

    public function italicAngle(): float
    {
        $post = $this->table('post');
        $fixed = self::uint32($post, 4);
        if ($fixed >= 0x80000000) {
            $fixed -= 0x100000000;
        }
        return $fixed / 65536.0;
    }

    /** @return int[] loca offsets, numGlyphs + 1 entries */
    public function loca(): array
    {
        $loca = $this->table('loca');
        $format = $this->indexToLocFormat();
        $count = $this->numGlyphs() + 1;
        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $offsets[] = $format === 0
                ? self::uint16($loca, $i * 2) * 2
                : self::uint32($loca, $i * 4);
        }
        return $offsets;
    }

    /** @return array{0:int,1:int} advance width and left side bearing */
    public function metricsFor(int $glyphId): array
    {
        $hmtx = $this->table('hmtx');
        $count = $this->numberOfHMetrics();
        if ($glyphId < $count) {
            return [self::uint16($hmtx, $glyphId * 4), self::int16($hmtx, $glyphId * 4 + 2)];
        }
        $advance = self::uint16($hmtx, ($count - 1) * 4);
        $lsbOffset = $count * 4 + ($glyphId - $count) * 2;
        $lsb = $lsbOffset + 2 <= strlen($hmtx) ? self::int16($hmtx, $lsbOffset) : 0;
        return [$advance, $lsb];
    }

    /**
     * Unicode to glyph id from the (3,1) format 4 subtable, which every font used
     * here provides. Format 12 is not needed: the subset is BMP-only by design.
     *
     * @return array<int, int>
     */
    public function unicodeMap(): array
    {
        $cmap = $this->table('cmap');
        $subtableOffset = null;
        $count = self::uint16($cmap, 2);
        for ($i = 0; $i < $count; $i++) {
            $record = 4 + $i * 8;
            $platform = self::uint16($cmap, $record);
            $encoding = self::uint16($cmap, $record + 2);
            $offset = self::uint32($cmap, $record + 4);
            if ($platform === 3 && $encoding === 1 && self::uint16($cmap, $offset) === 4) {
                $subtableOffset = $offset;
                break;
            }
        }
        if ($subtableOffset === null) {
            throw new RuntimeException('Font has no (3,1) format 4 cmap subtable.');
        }

        $segCountX2 = self::uint16($cmap, $subtableOffset + 6);
        $segments = intdiv($segCountX2, 2);
        $endBase = $subtableOffset + 14;
        $startBase = $endBase + $segCountX2 + 2;
        $deltaBase = $startBase + $segCountX2;
        $rangeBase = $deltaBase + $segCountX2;

        $map = [];
        for ($i = 0; $i < $segments; $i++) {
            $end = self::uint16($cmap, $endBase + $i * 2);
            $start = self::uint16($cmap, $startBase + $i * 2);
            $delta = self::uint16($cmap, $deltaBase + $i * 2);
            $rangeOffset = self::uint16($cmap, $rangeBase + $i * 2);
            if ($start > $end || $start === 0xFFFF) {
                continue;
            }
            for ($codepoint = $start; $codepoint <= $end; $codepoint++) {
                if ($rangeOffset === 0) {
                    $glyph = ($codepoint + $delta) & 0xFFFF;
                } else {
                    $index = $rangeBase + $i * 2 + $rangeOffset + ($codepoint - $start) * 2;
                    if ($index + 2 > strlen($cmap)) {
                        continue;
                    }
                    $glyph = self::uint16($cmap, $index);
                    if ($glyph !== 0) {
                        $glyph = ($glyph + $delta) & 0xFFFF;
                    }
                }
                if ($glyph !== 0) {
                    $map[$codepoint] = $glyph;
                }
            }
        }
        return $map;
    }

    /**
     * Component glyph ids referenced by a composite glyph, with the byte offset of
     * each index so it can be renumbered.
     *
     * @return array<int, int> offset within the glyph => component glyph id
     */
    public static function components(string $glyph): array
    {
        if (strlen($glyph) < 10 || self::int16($glyph, 0) >= 0) {
            return [];
        }
        $components = [];
        $position = 10;
        while ($position + 4 <= strlen($glyph)) {
            $flags = self::uint16($glyph, $position);
            $components[$position + 2] = self::uint16($glyph, $position + 2);
            $position += 4;
            $position += ($flags & 0x0001) ? 4 : 2;   // ARG_1_AND_2_ARE_WORDS
            if ($flags & 0x0008) {                     // WE_HAVE_A_SCALE
                $position += 2;
            } elseif ($flags & 0x0040) {               // X_AND_Y_SCALE
                $position += 4;
            } elseif ($flags & 0x0080) {               // TWO_BY_TWO
                $position += 8;
            }
            if (!($flags & 0x0020)) {                  // MORE_COMPONENTS
                break;
            }
        }
        return $components;
    }

    /** @return array<int, string> name id => UTF-8 value, Windows/en-US records only */
    public function names(): array
    {
        $name = $this->table('name');
        $count = self::uint16($name, 2);
        $stringOffset = self::uint16($name, 4);
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $record = 6 + $i * 12;
            $platform = self::uint16($name, $record);
            $encoding = self::uint16($name, $record + 2);
            $language = self::uint16($name, $record + 4);
            $nameId = self::uint16($name, $record + 6);
            $length = self::uint16($name, $record + 8);
            $offset = self::uint16($name, $record + 10);
            if ($platform !== 3 || $encoding !== 1 || $language !== 0x0409) {
                continue;
            }
            $value = substr($name, $stringOffset + $offset, $length);
            $names[$nameId] = self::fromUtf16Be($value);
        }
        return $names;
    }

    private static function fromUtf16Be(string $value): string
    {
        $out = '';
        for ($i = 0; $i + 1 < strlen($value); $i += 2) {
            $code = self::uint16($value, $i);
            if ($code < 0x80) {
                $out .= chr($code);
            } elseif ($code < 0x800) {
                $out .= chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
            } else {
                $out .= chr(0xE0 | ($code >> 12))
                    . chr(0x80 | (($code >> 6) & 0x3F))
                    . chr(0x80 | ($code & 0x3F));
            }
        }
        return $out;
    }

    public static function uint16(string $data, int $offset): int
    {
        return unpack('n', substr($data, $offset, 2))[1];
    }

    public static function int16(string $data, int $offset): int
    {
        $value = self::uint16($data, $offset);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public static function uint32(string $data, int $offset): int
    {
        return unpack('N', substr($data, $offset, 4))[1];
    }
}

final class SubsetBuilder
{
    /** @var TrueTypeReader */
    private $font;

    public function __construct(TrueTypeReader $font)
    {
        $this->font = $font;
    }

    /**
     * @param int[] $codepoints
     * @return array{font:string,metrics:array<string,mixed>}
     */
    public function build(array $codepoints): array
    {
        $unicodeMap = $this->font->unicodeMap();
        $loca = $this->font->loca();
        $glyf = $this->font->table('glyf');

        $wanted = [0 => true];
        $sourceForCodepoint = [];
        foreach ($codepoints as $codepoint) {
            if (!isset($unicodeMap[$codepoint])) {
                fprintf(STDERR, "  warning: U+%04X has no glyph, skipped\n", $codepoint);
                continue;
            }
            $sourceForCodepoint[$codepoint] = $unicodeMap[$codepoint];
            $wanted[$unicodeMap[$codepoint]] = true;
        }

        // Composites must travel with the glyphs they are assembled from.
        $queue = array_keys($wanted);
        while ($queue !== []) {
            $glyphId = array_pop($queue);
            $start = $loca[$glyphId];
            $end = $loca[$glyphId + 1];
            if ($end <= $start) {
                continue;
            }
            foreach (TrueTypeReader::components(substr($glyf, $start, $end - $start)) as $component) {
                if (!isset($wanted[$component])) {
                    $wanted[$component] = true;
                    $queue[] = $component;
                }
            }
        }

        $sourceIds = array_keys($wanted);
        sort($sourceIds, SORT_NUMERIC);
        $newIdFor = array_flip($sourceIds);
        $newCount = count($sourceIds);

        $newGlyf = '';
        $newLoca = '';
        foreach ($sourceIds as $sourceId) {
            $newLoca .= pack('N', strlen($newGlyf));
            $start = $loca[$sourceId];
            $end = $loca[$sourceId + 1];
            if ($end <= $start) {
                continue;
            }
            $glyph = substr($glyf, $start, $end - $start);
            foreach (TrueTypeReader::components($glyph) as $offset => $component) {
                // Renumbering keeps composites pointing at the same outline.
                $glyph = substr_replace($glyph, pack('n', $newIdFor[$component]), $offset, 2);
            }
            while (strlen($glyph) % 4 !== 0) {
                $glyph .= "\x00";
            }
            $newGlyf .= $glyph;
        }
        $newLoca .= pack('N', strlen($newGlyf));

        $newHmtx = '';
        $widths = [];
        $unitsPerEm = $this->font->unitsPerEm();
        foreach ($sourceIds as $newId => $sourceId) {
            [$advance, $lsb] = $this->font->metricsFor($sourceId);
            $newHmtx .= pack('n', $advance) . pack('n', $lsb & 0xFFFF);
            $widths[$newId] = (int) round($advance * 1000 / $unitsPerEm);
        }

        $glyphs = [];
        foreach ($sourceForCodepoint as $codepoint => $sourceId) {
            $glyphs[$codepoint] = $newIdFor[$sourceId];
        }
        ksort($glyphs, SORT_NUMERIC);

        $tables = [
            'OS/2' => $this->font->has('OS/2') ? $this->font->table('OS/2') : null,
            'cmap' => $this->buildCmap($glyphs),
            'cvt ' => $this->font->has('cvt ') ? $this->font->table('cvt ') : null,
            'fpgm' => $this->font->has('fpgm') ? $this->font->table('fpgm') : null,
            'glyf' => $newGlyf,
            'head' => $this->buildHead(),
            'hhea' => $this->buildHhea($newCount),
            'hmtx' => $newHmtx,
            'loca' => $newLoca,
            'maxp' => $this->buildMaxp($newCount),
            'name' => $this->buildName(),
            'post' => $this->buildPost(),
            'prep' => $this->font->has('prep') ? $this->font->table('prep') : null,
        ];
        $tables = array_filter($tables, static function ($value): bool {
            return $value !== null;
        });

        $binary = $this->assemble($tables);

        $capHeight = $this->capHeight($sourceForCodepoint, $loca, $glyf, $unitsPerEm);
        $vertical = $this->font->verticalMetrics();
        $bbox = $this->font->boundingBox();
        $scale = static function (int $value) use ($unitsPerEm): int {
            return (int) round($value * 1000 / $unitsPerEm);
        };
        $names = $this->font->names();

        return [
            'font' => $binary,
            'metrics' => [
                'family' => $names[1] ?? 'Unknown',
                'postscript_name' => $names[6] ?? ($names[1] ?? 'Unknown'),
                'units_per_em' => $unitsPerEm,
                'ascent' => $scale($vertical['ascent']),
                'descent' => $scale($vertical['descent']),
                'cap_height' => $capHeight,
                'italic_angle' => (int) round($this->font->italicAngle()),
                'bbox' => [$scale($bbox[0]), $scale($bbox[1]), $scale($bbox[2]), $scale($bbox[3])],
                'glyph_count' => $newCount,
                'glyphs' => $glyphs,
                'widths' => $widths,
                'sha256' => hash('sha256', $binary),
                'byte_length' => strlen($binary),
            ],
        ];
    }

    /** @param array<int,int> $sourceForCodepoint */
    private function capHeight(array $sourceForCodepoint, array $loca, string $glyf, int $unitsPerEm): int
    {
        $glyphId = $sourceForCodepoint[0x48] ?? null; // 'H'
        if ($glyphId === null || $loca[$glyphId + 1] <= $loca[$glyphId]) {
            return 700;
        }
        $glyph = substr($glyf, $loca[$glyphId], $loca[$glyphId + 1] - $loca[$glyphId]);
        return (int) round(TrueTypeReader::int16($glyph, 8) * 1000 / $unitsPerEm); // yMax
    }

    /** @param array<int,int> $glyphs */
    private function buildCmap(array $glyphs): string
    {
        // Contiguous runs become format 4 segments; the trailing 0xFFFF segment is
        // required by the specification.
        $segments = [];
        $current = null;
        foreach ($glyphs as $codepoint => $glyphId) {
            if ($current !== null
                && $codepoint === $current['end'] + 1
                && $glyphId === $current['startGlyph'] + ($codepoint - $current['start'])
            ) {
                $current['end'] = $codepoint;
                continue;
            }
            if ($current !== null) {
                $segments[] = $current;
            }
            $current = ['start' => $codepoint, 'end' => $codepoint, 'startGlyph' => $glyphId];
        }
        if ($current !== null) {
            $segments[] = $current;
        }
        $segments[] = ['start' => 0xFFFF, 'end' => 0xFFFF, 'startGlyph' => 0];

        $segCount = count($segments);
        $searchRange = 2 * (2 ** (int) floor(log($segCount, 2)));
        $endCodes = '';
        $startCodes = '';
        $deltas = '';
        $rangeOffsets = '';
        foreach ($segments as $segment) {
            $endCodes .= pack('n', $segment['end']);
            $startCodes .= pack('n', $segment['start']);
            $delta = $segment['start'] === 0xFFFF
                ? 1
                : ($segment['startGlyph'] - $segment['start']) & 0xFFFF;
            $deltas .= pack('n', $delta & 0xFFFF);
            $rangeOffsets .= pack('n', 0);
        }

        $subtable = pack('nnn', 4, 16 + $segCount * 8, 0)
            . pack('nnnn', $segCount * 2, $searchRange, (int) floor(log($segCount, 2)), $segCount * 2 - $searchRange)
            . $endCodes . pack('n', 0) . $startCodes . $deltas . $rangeOffsets;

        return pack('nn', 0, 1) . pack('nnN', 3, 1, 12) . $subtable;
    }

    private function buildHead(): string
    {
        $head = $this->font->table('head');
        $head = substr_replace($head, pack('N', 0), 8, 4);   // checkSumAdjustment, filled in later
        $head = substr_replace($head, pack('n', 1), 50, 2);  // indexToLocFormat: long
        return substr($head, 0, 54);
    }

    private function buildHhea(int $numberOfHMetrics): string
    {
        $hhea = substr($this->font->table('hhea'), 0, 36);
        return substr_replace($hhea, pack('n', $numberOfHMetrics), 34, 2);
    }

    private function buildMaxp(int $numGlyphs): string
    {
        $maxp = $this->font->table('maxp');
        return substr_replace($maxp, pack('n', $numGlyphs), 4, 2);
    }

    /**
     * Keeps the identification and licensing records only. The copyright and the
     * licence text travel with the subset; the 15 KB of localised marketing
     * strings do not.
     */
    private function buildName(): string
    {
        $names = $this->font->names();
        $keep = [0, 1, 2, 3, 4, 5, 6, 13, 14];
        $records = '';
        $strings = '';
        $count = 0;
        foreach ($keep as $nameId) {
            if (!isset($names[$nameId])) {
                continue;
            }
            $value = self::toUtf16Be($names[$nameId]);
            $records .= pack('nnnnnn', 3, 1, 0x0409, $nameId, strlen($value), strlen($strings));
            $strings .= $value;
            $count++;
        }
        return pack('nnn', 0, $count, 6 + $count * 12) . $records . $strings;
    }

    private static function toUtf16Be(string $value): string
    {
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length;) {
            $byte = ord($value[$i]);
            if ($byte < 0x80) {
                $code = $byte;
                $i += 1;
            } elseif ($byte < 0xE0) {
                $code = (($byte & 0x1F) << 6) | (ord($value[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($byte < 0xF0) {
                $code = (($byte & 0x0F) << 12)
                    | ((ord($value[$i + 1]) & 0x3F) << 6)
                    | (ord($value[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $code = 0xFFFD;
                $i += 4;
            }
            $out .= pack('n', $code);
        }
        return $out;
    }

    private function buildPost(): string
    {
        // Version 3.0: no glyph names. Saves 60 KB and removes the only table whose
        // contents depend on glyph numbering.
        $post = $this->font->table('post');
        return pack('N', 0x00030000) . substr($post, 4, 28);
    }

    /** @param array<string, string> $tables */
    private function assemble(array $tables): string
    {
        ksort($tables, SORT_STRING);
        $count = count($tables);
        $searchRange = 16 * (2 ** (int) floor(log($count, 2)));
        $header = pack('N', 0x00010000)
            . pack('nnnn', $count, $searchRange, (int) floor(log($count, 2)), $count * 16 - $searchRange);

        $offset = 12 + $count * 16;
        $directory = '';
        $body = '';
        foreach ($tables as $tag => $data) {
            $padded = $data . str_repeat("\x00", (4 - strlen($data) % 4) % 4);
            $directory .= $tag . pack('N', self::checksum($padded)) . pack('N', $offset) . pack('N', strlen($data));
            $body .= $padded;
            $offset += strlen($padded);
        }

        $binary = $header . $directory . $body;

        // checkSumAdjustment must be 0xB1B0AFBA minus the checksum of the whole file.
        $headOffset = null;
        $position = 12;
        foreach ($tables as $tag => $data) {
            if ($tag === 'head') {
                $headOffset = TrueTypeReader::uint32($binary, $position + 8);
                break;
            }
            $position += 16;
        }
        if ($headOffset === null) {
            throw new RuntimeException('Subset has no head table.');
        }
        $adjustment = (0xB1B0AFBA - self::checksum($binary)) & 0xFFFFFFFF;
        return substr_replace($binary, pack('N', $adjustment), $headOffset + 8, 4);
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\x00", (4 - strlen($data) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }
        return $sum;
    }
}

// ---------------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool runs on the command line only.\n");
    exit(1);
}
if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/build-pdf-font.php <source.ttf> <regular|bold>\n");
    exit(1);
}

$source = $argv[1];
$style = $argv[2];
if (!in_array($style, ['regular', 'bold'], true)) {
    fwrite(STDERR, "Style must be 'regular' or 'bold'.\n");
    exit(1);
}
if (!is_file($source) || !is_readable($source)) {
    fwrite(STDERR, sprintf("Cannot read %s\n", $source));
    exit(1);
}

$raw = file_get_contents($source);
printf("source: %s (%d bytes, sha256 %s)\n", basename($source), strlen($raw), hash('sha256', $raw));

$font = new TrueTypeReader($raw);
$result = (new SubsetBuilder($font))->build(charset());

if (!is_dir(OUTPUT_DIR) && !mkdir(OUTPUT_DIR, 0775, true) && !is_dir(OUTPUT_DIR)) {
    fwrite(STDERR, "Cannot create the output directory.\n");
    exit(1);
}

$fontFile = OUTPUT_DIR . '/dejavu-sans-' . $style . '.ttf';
$metricsFile = OUTPUT_DIR . '/dejavu-sans-' . $style . '.php';

file_put_contents($fontFile, $result['font']);

$metrics = $result['metrics'];
$metrics['source_sha256'] = hash('sha256', $raw);
$metrics['style'] = $style;

$export = "<?php\n\n"
    . "declare(strict_types=1);\n\n"
    . "/**\n"
    . " * Generated by tools/build-pdf-font.php. Do not edit by hand.\n"
    . " *\n"
    . " * Metrics for the embedded " . $style . " subset. The renderer reads this file\n"
    . " * instead of parsing the font at request time.\n"
    . " */\n\n"
    . 'return ' . var_export($metrics, true) . ";\n";
file_put_contents($metricsFile, $export);

$names = $font->names();
$licence = OUTPUT_DIR . '/LICENCE-DejaVu.txt';
if (!is_file($licence) && isset($names[13])) {
    file_put_contents(
        $licence,
        "Licence text extracted from the source font's name table (id 13) by\n"
        . "tools/build-pdf-font.php. Source font: " . ($names[4] ?? 'unknown') . "\n"
        . "Licence URL (name id 14): " . ($names[14] ?? 'not stated') . "\n\n"
        . "----------------------------------------------------------------------\n\n"
        . $names[13] . "\n"
    );
    printf("licence: wrote %s\n", basename($licence));
}

printf(
    "subset: %s (%d bytes, %d glyphs, %d mapped codepoints, sha256 %s)\n",
    basename($fontFile),
    strlen($result['font']),
    $metrics['glyph_count'],
    count($metrics['glyphs']),
    $metrics['sha256']
);
printf("metrics: %s\n", basename($metricsFile));
