<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering\Pdf;

use RuntimeException;

/**
 * Assembles indirect objects into a PDF file with a correct cross-reference
 * table.
 *
 * Streams are written uncompressed on purpose. It costs size but buys three
 * things that matter more here: the output does not depend on the zlib version
 * present on the host, so two runs of the same input produce byte-identical
 * files; the package needs no extension beyond what it already declares; and the
 * verification harness can read a generated document without a decompressor.
 */
final class PdfDocumentWriter
{
    /** @var string[] object body, indexed from 1 */
    private $objects = [];

    public function reserve(): int
    {
        $this->objects[] = '';
        return count($this->objects);
    }

    public function put(int $number, string $body): void
    {
        if ($number < 1 || $number > count($this->objects)) {
            throw new RuntimeException('Unknown PDF object number.');
        }
        $this->objects[$number - 1] = $body;
    }

    public function add(string $body): int
    {
        $number = $this->reserve();
        $this->put($number, $body);
        return $number;
    }

    public function addStream(string $dictionaryEntries, string $data): int
    {
        return $this->add(
            '<< ' . $dictionaryEntries . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream"
        );
    }

    public function build(int $rootObject, int $infoObject): string
    {
        foreach ($this->objects as $index => $body) {
            if ($body === '') {
                throw new RuntimeException(sprintf('PDF object %d was reserved but never written.', $index + 1));
            }
        }

        // The binary comment on line two marks the file as containing 8-bit data.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($this->objects as $index => $body) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }

        $count = count($this->objects);
        $startxref = strlen($pdf);
        $pdf .= "xref\n0 " . ($count + 1) . "\n0000000000 65535 f \n";
        for ($number = 1; $number <= $count; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= "trailer\n<< /Size " . ($count + 1)
            . ' /Root ' . $rootObject . ' 0 R'
            . ' /Info ' . $infoObject . " 0 R >>\nstartxref\n" . $startxref . "\n%%EOF\n";

        return $pdf;
    }

    /**
     * Escapes a value for a PDF literal string. Used for metadata only — page
     * text is written as glyph hex strings, which cannot carry delimiters.
     */
    public static function literal(string $value): string
    {
        $ascii = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            if ($byte >= 0x20 && $byte <= 0x7E) {
                $ascii .= $value[$i];
            } elseif ($byte >= 0x80) {
                $ascii .= '?';
            }
        }
        return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii) . ')';
    }

    /** A PDF date string, e.g. D:20260811120000+02'00'. */
    public static function date(string $atom): string
    {
        $parsed = \DateTimeImmutable::createFromFormat(DATE_ATOM, $atom);
        if ($parsed === false) {
            return '';
        }
        $offset = $parsed->format('O'); // +0200
        return 'D:' . $parsed->format('YmdHis')
            . substr($offset, 0, 3) . "'" . substr($offset, 3, 2) . "'";
    }
}
