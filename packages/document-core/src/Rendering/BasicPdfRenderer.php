<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering;

use Xmods\CommerceDocuments\Contracts\PdfRenderer;
use Xmods\CommerceDocuments\DocumentSnapshot;

/** Dependency-free local PDF renderer for sandbox/print output. */
final class BasicPdfRenderer implements PdfRenderer
{
    public function render(DocumentSnapshot $snapshot): string
    {
        $data = $snapshot->toArray();
        $lines = [
            strtoupper((string) $data['document_type']) . ' ' . (string) $data['document_number'],
            'Issued: ' . (string) $data['issued_at'],
            'Seller: ' . (string) ($data['seller']['name'] ?? ''),
            'Buyer: ' . (string) ($data['buyer']['name'] ?? ''),
        ];
        $metadata = (array) ($data['metadata'] ?? []);
        if (strtolower((string) ($metadata['payment_method'] ?? '')) === 'cod'
            && (string) ($metadata['payment_confirmed'] ?? 'no') !== 'yes'
        ) {
            $lines[] = 'Nieoplacone - platnosc przy odbiorze';
        }
        $lines[] = 'Gross: ' . (string) ($data['totals']['gross'] ?? 0) . ' ' . (string) $data['currency'];

        $stream = "BT\n/F1 12 Tf\n72 760 Td\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $stream .= "0 -18 Td\n";
            }
            $stream .= '(' . self::escape($line) . ") Tj\n";
        }
        $stream .= "ET\n";

        return self::document($stream);
    }

    private static function document(string $stream): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
        ];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    private static function escape(string $value): string
    {
        $value = preg_replace('/[^\x20-\x7E]/', '?', $value);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $value);
    }
}
