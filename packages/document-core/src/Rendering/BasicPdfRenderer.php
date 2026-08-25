<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering;

use Xmods\CommerceDocuments\Contracts\PdfRenderer;
use Xmods\CommerceDocuments\DocumentSnapshot;

/**
 * Superseded by EmbeddedFontPdfRenderer — do not use for anything a customer
 * sees. Kept for the archived tests and as the fallback described in
 * review/claude/pdf-engine-decision.md.
 *
 * Renders the full document: parties, every line item with quantity, net, tax and
 * gross, and the document totals. Polish text is preserved through
 * PdfTextEncoding rather than stripped to ASCII.
 *
 * Why it is not the production engine: it relies on the viewer's standard-14
 * Helvetica containing the Polish glyphs named in the /Differences array — true
 * in Acrobat, pdf.js and Ghostscript, not guaranteed everywhere — and it embeds
 * no font, wraps no text and cannot paginate. EmbeddedFontPdfRenderer carries its
 * own font subset and closes all three gaps.
 */
final class BasicPdfRenderer implements PdfRenderer
{
    private const PAGE_WIDTH = 595;
    private const PAGE_HEIGHT = 842;
    private const MARGIN = 48;
    private const MAX_ROWS = 24;

    /** @var int */
    private $currencyExponent;

    public function __construct(int $currencyExponent = 2)
    {
        $this->currencyExponent = ($currencyExponent >= 0 && $currencyExponent <= 6)
            ? $currencyExponent
            : 2;
    }

    public function render(DocumentSnapshot $snapshot): string
    {
        $data = $snapshot->toArray();
        $labels = (new TemplateCatalog())->labels((string) $data['language']);
        $metadata = (array) ($data['metadata'] ?? []);
        $currency = (string) $data['currency'];

        $ops = [];
        $y = self::PAGE_HEIGHT - self::MARGIN;

        $title = strtoupper(str_replace('_', ' ', (string) $data['document_type']))
            . ' ' . (string) $data['document_number'];
        $ops[] = self::text(self::MARGIN, $y, $title, 15, true);
        $y -= 22;

        $ops[] = self::text(self::MARGIN, $y, $labels['issued'] . ': ' . (string) $data['issued_at'], 9);
        $y -= 13;
        if (isset($metadata['order_number'])) {
            $ops[] = self::text(self::MARGIN, $y, 'Zamówienie: #' . (string) $metadata['order_number'], 9);
            $y -= 13;
        }

        if (self::isUnpaid($metadata, (string) ($data['document_type'] ?? ''))) {
            $ops[] = self::text(
                self::MARGIN,
                $y,
                self::paymentNotice($metadata, (string) ($data['document_type'] ?? '')),
                10,
                true
            );
            $y -= 16;
        }
        if ((string) ($metadata['payment_badge'] ?? '') === 'paid') {
            $ops[] = self::text(self::MARGIN, $y, (string) ($metadata['payment_notice'] ?? 'PAID'), 10, true);
            $y -= 16;
        }
        $y -= 8;

        $partyTop = $y;
        $y = self::party($ops, self::MARGIN, $partyTop, $labels['seller'], (array) $data['seller']);
        $buyerBottom = self::party($ops, 320, $partyTop, $labels['buyer'], (array) $data['buyer']);
        $y = min($y, $buyerBottom) - 18;

        // Column origins: description, quantity, net, tax, gross.
        $columns = [self::MARGIN, 300, 366, 438, 505];
        $headers = [$labels['description'], $labels['quantity'], $labels['net'], $labels['tax'], $labels['gross']];
        foreach ($headers as $index => $header) {
            $ops[] = self::text($columns[$index], $y, $header, 9, true);
        }
        $y -= 4;
        $ops[] = self::rule($y, self::MARGIN, self::PAGE_WIDTH - self::MARGIN);
        $y -= 13;

        $items = (array) $data['items'];
        $rendered = 0;
        foreach ($items as $item) {
            if ($rendered >= self::MAX_ROWS) {
                $ops[] = self::text(
                    self::MARGIN,
                    $y,
                    sprintf('... %d further line(s) not shown', count($items) - $rendered),
                    9
                );
                $y -= 13;
                break;
            }
            $cells = [
                self::truncate((string) ($item['description'] ?? ''), 52),
                self::scaled((int) ($item['quantity']['scaled_units'] ?? 0), (int) ($item['quantity']['scale'] ?? 0))
                    . ' ' . (string) ($item['unit'] ?? ''),
                $this->money((int) ($item['net'] ?? 0)),
                $this->money((int) ($item['tax'] ?? 0)),
                $this->money((int) ($item['gross'] ?? 0)),
            ];
            foreach ($cells as $index => $cell) {
                $ops[] = self::text($columns[$index], $y, $cell, 9);
            }
            $y -= 13;
            $rendered++;
        }

        $y -= 4;
        $ops[] = self::rule($y, self::MARGIN, self::PAGE_WIDTH - self::MARGIN);
        $y -= 16;

        $totals = (array) ($data['totals'] ?? []);
        foreach ([
            [$labels['net'], (int) ($totals['net'] ?? 0), false],
            [$labels['tax'], (int) ($totals['tax'] ?? 0), false],
            [$labels['gross'], (int) ($totals['gross'] ?? 0), true],
        ] as $row) {
            $ops[] = self::text(366, $y, (string) $row[0], 10, (bool) $row[2]);
            $ops[] = self::text(455, $y, $this->money((int) $row[1]) . ' ' . $currency, 10, (bool) $row[2]);
            $y -= 14;
        }

        if (isset($metadata['correction_of'])) {
            $y -= 10;
            $ops[] = self::text(self::MARGIN, $y, 'Korekta dokumentu: ' . (string) $metadata['correction_of'], 9);
            $y -= 12;
            $ops[] = self::text(self::MARGIN, $y, 'Powód: ' . self::truncate((string) ($metadata['correction_note'] ?? ''), 80), 9);
        }

        return self::document(implode('', $ops));
    }

    /** @param array<string, mixed> $metadata */
    private static function isUnpaid(array $metadata, string $documentType = ''): bool
    {
        return (string) ($metadata['payment_badge'] ?? '') === 'unpaid'
            || (string) ($metadata['payment_status'] ?? '') === 'cash_on_delivery_unpaid'
            || (strtolower((string) ($metadata['payment_method'] ?? '')) === 'cod'
                && (string) ($metadata['payment_confirmed'] ?? 'no') !== 'yes')
            || ($documentType === 'order_confirmation'
                && !array_key_exists('payment_badge', $metadata)
                && !array_key_exists('payment_notice', $metadata)
                && !(
                    strtolower((string) ($metadata['payment_method'] ?? '')) === 'cod'
                    && (string) ($metadata['payment_confirmed'] ?? 'no') === 'yes'
                ));
    }

    /** @param array<string, mixed> $metadata */
    private static function paymentNotice(array $metadata, string $documentType = ''): string
    {
        if ($documentType === 'order_confirmation'
            && !array_key_exists('payment_badge', $metadata)
            && !array_key_exists('payment_notice', $metadata)
            && !(
                strtolower((string) ($metadata['payment_method'] ?? '')) === 'cod'
                && (string) ($metadata['payment_confirmed'] ?? 'no') === 'yes'
            )) {
            return 'NIEOPŁACONE — płatność niepotwierdzona';
        }
        return 'NIEOPŁACONE — płatność przy odbiorze';
    }

    /**
     * @param string[] $ops
     * @param array<string, mixed> $party
     */
    private static function party(array &$ops, int $x, int $y, string $heading, array $party): int
    {
        $address = (array) ($party['address'] ?? []);
        $ops[] = self::text($x, $y, $heading, 10, true);
        $y -= 14;
        $lines = array_filter([
            (string) ($party['name'] ?? ''),
            (string) ($party['tax_identifier'] ?? '') !== '' ? 'NIP: ' . (string) $party['tax_identifier'] : '',
            trim((string) ($address['line1'] ?? '') . ' ' . (string) ($address['line2'] ?? '')),
            trim((string) ($address['postal_code'] ?? '') . ' ' . (string) ($address['city'] ?? '')),
            (string) ($address['country_code'] ?? ''),
            (string) ($party['email'] ?? ''),
        ], static function (string $value): bool {
            return trim($value) !== '';
        });
        foreach ($lines as $line) {
            $ops[] = self::text($x, $y, self::truncate($line, 42), 9);
            $y -= 12;
        }
        return $y;
    }

    private function money(int $minorUnits): string
    {
        return self::scaled($minorUnits, $this->currencyExponent);
    }

    private static function scaled(int $units, int $scale): string
    {
        $negative = $units < 0;
        $digits = $units === PHP_INT_MIN
            ? substr((string) PHP_INT_MIN, 1)
            : (string) abs($units);
        if ($scale === 0) {
            return ($negative ? '-' : '') . $digits;
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        return ($negative ? '-' : '') . substr($digits, 0, -$scale) . ',' . substr($digits, -$scale);
    }

    /**
     * UTF-8 aware truncation without ext-mbstring: the package declares no
     * extension requirements beyond openssl, and cutting mid-sequence would emit
     * a broken character.
     */
    private static function truncate(string $value, int $limit): string
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            // Invalid UTF-8: fall back to a byte-safe cut.
            return strlen($value) <= $limit ? $value : substr($value, 0, $limit - 3) . '...';
        }
        if (count($characters) <= $limit) {
            return $value;
        }
        return implode('', array_slice($characters, 0, $limit - 3)) . '...';
    }

    private static function text(int $x, int $y, string $value, int $size, bool $bold = false): string
    {
        return "BT\n/" . ($bold ? 'F2' : 'F1') . ' ' . $size . " Tf\n"
            . $x . ' ' . $y . " Td\n(" . PdfTextEncoding::encode($value) . ") Tj\nET\n";
    }

    private static function rule(int $y, int $from, int $to): string
    {
        return "0.6 w\n" . $from . ' ' . $y . " m\n" . $to . ' ' . $y . " l\nS\n";
    }

    private static function document(string $stream): string
    {
        $differences = PdfTextEncoding::differences();
        $encoding = '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [' . $differences . '] >>';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . ']'
                . ' /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding 7 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding 7 0 R >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
            $encoding,
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
}
