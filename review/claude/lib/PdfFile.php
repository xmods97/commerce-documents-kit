<?php
/**
 * A minimal PDF reader shared by the review harnesses.
 *
 * Deliberately independent of the renderer: it walks the cross-reference table,
 * so a wrong offset fails here instead of passing unnoticed, and it recovers
 * page text through each font's own /ToUnicode CMap rather than from anything
 * the renderer kept in memory.
 */

declare(strict_types=1);
final class PdfFile
{
    /** @var string */
    public $raw;
    /** @var array<int, array{dict:string, stream:?string}> */
    public $objects = [];
    /** @var int[] */
    public $offsets = [];
    /** @var string */
    public $trailer = '';

    public function __construct(string $raw)
    {
        $this->raw = $raw;

        if (!preg_match('/startxref\s+(\d+)\s+%%EOF/', $raw, $match)) {
            throw new RuntimeException('No startxref/%%EOF found.');
        }
        $xref = (int) $match[1];
        if (substr($raw, $xref, 4) !== 'xref') {
            throw new RuntimeException('startxref does not point at the xref table.');
        }

        $lines = preg_split('/\r?\n/', substr($raw, $xref));
        if (!preg_match('/^0 (\d+)$/', trim($lines[1]), $sizeMatch)) {
            throw new RuntimeException('Malformed xref subsection header.');
        }
        $size = (int) $sizeMatch[1];
        for ($i = 1; $i < $size; $i++) {
            $entry = $lines[$i + 2];
            if (!preg_match('/^(\d{10}) 00000 n $/', $entry, $entryMatch)) {
                throw new RuntimeException(sprintf('Malformed xref entry %d: %s', $i, $entry));
            }
            $this->offsets[$i] = (int) $entryMatch[1];
        }

        foreach ($this->offsets as $number => $offset) {
            $this->objects[$number] = self::readObject($raw, $number, $offset);
        }

        $trailerAt = strpos($raw, 'trailer', $xref);
        $this->trailer = $trailerAt === false ? '' : substr($raw, $trailerAt, 200);
    }

    /** @return array{dict:string, stream:?string} */
    private static function readObject(string $raw, int $number, int $offset): array
    {
        $head = $number . " 0 obj\n";
        if (substr($raw, $offset, strlen($head)) !== $head) {
            throw new RuntimeException(sprintf('Object %d is not at its xref offset.', $number));
        }
        $rest = substr($raw, $offset + strlen($head));

        if (preg_match('/^(<<[^>]*(?:>(?!>)[^>]*)*>>)\s*stream\n/', $rest, $match)) {
            $length = 0;
            if (preg_match('/\/Length (\d+)/', $match[1], $lengthMatch)) {
                $length = (int) $lengthMatch[1];
            }
            $stream = substr($rest, strlen($match[0]), $length);
            $terminator = substr($rest, strlen($match[0]) + $length, 20);
            if (strpos($terminator, 'endstream') === false) {
                throw new RuntimeException(sprintf('Object %d: /Length does not reach endstream.', $number));
            }
            return ['dict' => $match[1], 'stream' => $stream];
        }

        $end = strpos($rest, "\nendobj");
        if ($end === false) {
            throw new RuntimeException(sprintf('Object %d has no endobj.', $number));
        }
        return ['dict' => substr($rest, 0, $end), 'stream' => null];
    }

    /** @return int[] object numbers whose dictionary matches the pattern */
    public function findObjects(string $pattern): array
    {
        $found = [];
        foreach ($this->objects as $number => $object) {
            if (preg_match($pattern, $object['dict'])) {
                $found[] = $number;
            }
        }
        return $found;
    }

    /** @return int[] page object numbers, in Kids order */
    public function pageObjects(): array
    {
        $pages = $this->findObjects('#/Type\s*/Pages#');
        if ($pages === []) {
            throw new RuntimeException('No /Pages node.');
        }
        preg_match('/\/Kids \[(.*?)\]/', $this->objects[$pages[0]]['dict'], $match);
        preg_match_all('/(\d+) 0 R/', $match[1] ?? '', $kids);
        return array_map('intval', $kids[1]);
    }

    public function declaredPageCount(): int
    {
        $pages = $this->findObjects('#/Type\s*/Pages#');
        preg_match('/\/Count (\d+)/', $this->objects[$pages[0]]['dict'], $match);
        return (int) ($match[1] ?? 0);
    }

    public function contentOfPage(int $pageObject): string
    {
        preg_match('/\/Contents (\d+) 0 R/', $this->objects[$pageObject]['dict'], $match);
        return (string) $this->objects[(int) $match[1]]['stream'];
    }

    /**
     * Font resource name (F1, F2) => glyph id => codepoint, read from each font's
     * own /ToUnicode CMap.
     *
     * @return array<string, array<int, int>>
     */
    public function toUnicodeMaps(): array
    {
        $pages = $this->pageObjects();
        preg_match_all(
            '#/(F\d+) (\d+) 0 R#',
            $this->objects[$pages[0]]['dict'],
            $resources,
            PREG_SET_ORDER
        );

        $maps = [];
        foreach ($resources as $resource) {
            $type0 = $this->objects[(int) $resource[2]]['dict'];
            if (!preg_match('#/ToUnicode (\d+) 0 R#', $type0, $match)) {
                continue;
            }
            $cmap = (string) $this->objects[(int) $match[1]]['stream'];
            preg_match_all('/<([0-9A-F]{4})> <([0-9A-F]{4})>/', $cmap, $entries, PREG_SET_ORDER);
            $map = [];
            foreach ($entries as $entry) {
                $map[hexdec($entry[1])] = hexdec($entry[2]);
            }
            $maps[$resource[1]] = $map;
        }
        return $maps;
    }

    /** The embedded font program of a Type0 font resource. */
    public function fontProgram(string $resourceName): string
    {
        $pages = $this->pageObjects();
        preg_match('#/' . $resourceName . ' (\d+) 0 R#', $this->objects[$pages[0]]['dict'], $match);
        $type0 = $this->objects[(int) $match[1]]['dict'];
        preg_match('#/DescendantFonts \[(\d+) 0 R\]#', $type0, $descendantMatch);
        $descendant = $this->objects[(int) $descendantMatch[1]]['dict'];
        preg_match('#/FontDescriptor (\d+) 0 R#', $descendant, $descriptorMatch);
        $descriptor = $this->objects[(int) $descriptorMatch[1]]['dict'];
        preg_match('#/FontFile2 (\d+) 0 R#', $descriptor, $fileMatch);
        return (string) $this->objects[(int) $fileMatch[1]]['stream'];
    }

    /**
     * Text of one page, recovered through the document's own /ToUnicode CMaps.
     */
    public function textOfPage(int $pageObject): string
    {
        $maps = $this->toUnicodeMaps();
        $content = $this->contentOfPage($pageObject);
        $current = 'F1';
        $text = '';

        preg_match_all('#/(F\d+) [\d.]+ Tf|<([0-9A-Fa-f]*)> Tj#', $content, $tokens, PREG_SET_ORDER);
        foreach ($tokens as $token) {
            if (($token[1] ?? '') !== '') {
                $current = $token[1];
                continue;
            }
            $hex = $token[2] ?? '';
            foreach (str_split($hex, 4) as $glyphHex) {
                if (strlen($glyphHex) < 4) {
                    continue;
                }
                $codepoint = $maps[$current][hexdec($glyphHex)] ?? 0xFFFD;
                $text .= self::utf8($codepoint);
            }
            $text .= "\n";
        }
        return $text;
    }

    public function text(): string
    {
        $text = '';
        foreach ($this->pageObjects() as $page) {
            $text .= $this->textOfPage($page) . "\n";
        }
        return $text;
    }

    private static function utf8(int $codepoint): string
    {
        if ($codepoint < 0x80) {
            return chr($codepoint);
        }
        if ($codepoint < 0x800) {
            return chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
        }
        return chr(0xE0 | ($codepoint >> 12))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }
}
