<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering;

use Xmods\CommerceDocuments\DocumentSnapshot;

final class HtmlRenderer
{
    /** @var TemplateCatalog */
    private $catalog;

    public function __construct(TemplateCatalog $catalog)
    {
        $this->catalog = $catalog;
    }

    public function render(DocumentSnapshot $snapshot, ?int $currencyExponent = null): string
    {
        if ($currencyExponent !== null && ($currencyExponent < 0 || $currencyExponent > 6)) {
            throw new \InvalidArgumentException('Currency exponent must be between 0 and 6.');
        }
        $data = $snapshot->toArray();
        $labels = $this->catalog->labels($data['language']);
        $metadata = (array) ($data['metadata'] ?? []);
        $paymentNotice = strtolower((string) ($metadata['payment_method'] ?? '')) === 'cod'
            && (string) ($metadata['payment_confirmed'] ?? 'no') !== 'yes'
            ? '<p><strong>Nieopłacone — płatność przy odbiorze</strong></p>'
            : '';
        if ((string) ($metadata['payment_status'] ?? '') === 'cash_on_delivery_unpaid') {
            $paymentNotice = '<p><strong>Nieopłacone — płatność przy odbiorze</strong></p>';
        }
        if (in_array((string) ($metadata['payment_badge'] ?? ''), ['unpaid', 'paid'], true)) {
            $paymentNotice = '<p><strong>'
                . self::escape((string) ($metadata['payment_notice'] ?? ''))
                . '</strong></p>';
        }
        $rows = '';
        foreach ($data['items'] as $item) {
            $rows .= '<tr><td>' . self::escape($item['description']) . '</td>'
                . '<td>' . self::escape(self::formatScaled(
                    (int) $item['quantity']['scaled_units'],
                    (int) $item['quantity']['scale']
                )) . ' ' . self::escape($item['unit']) . '</td>'
                . '<td>' . self::escape(self::formatAmount((int) $item['net'], $currencyExponent)) . '</td>'
                . '<td>' . self::escape(self::formatAmount((int) $item['tax'], $currencyExponent)) . '</td>'
                . '<td>' . self::escape(self::formatAmount((int) $item['gross'], $currencyExponent)) . '</td></tr>';
        }

        return '<!doctype html><html lang="' . self::escape($data['language']) . '"><head>'
            . '<meta charset="utf-8"><title>' . self::escape($data['document_number']) . '</title>'
            . '<style>body{font:14px Arial,sans-serif;color:#1d2327;max-width:900px;margin:32px auto;padding:0 20px}'
            . 'header{display:flex;justify-content:space-between;align-items:start}.parties{display:grid;grid-template-columns:1fr 1fr;gap:30px}'
            . 'table{width:100%;border-collapse:collapse;margin-top:24px}th,td{padding:9px;border-bottom:1px solid #ccd0d4;text-align:left}'
            . '.total{text-align:right;font-size:18px;font-weight:bold}.print{padding:9px 14px}@media print{.print{display:none}body{margin:0}}</style>'
            . '</head><body><header>'
            . '<h1>' . self::escape(strtoupper($data['document_type']) . ' ' . $data['document_number']) . '</h1>'
            . '<button class="print" onclick="window.print()">' . self::escape($labels['print']) . '</button></header>'
            . '<p>' . self::escape($labels['issued']) . ': ' . self::escape($data['issued_at']) . '</p>' . $paymentNotice . '<div class="parties">'
            . '<section><h2>' . self::escape($labels['seller']) . '</h2>'
            . self::party($data['seller']) . '</section>'
            . '<section><h2>' . self::escape($labels['buyer']) . '</h2>'
            . self::party($data['buyer']) . '</section></div>'
            . '<table><thead><tr><th>' . self::escape($labels['description']) . '</th><th>'
            . self::escape($labels['quantity']) . '</th><th>' . self::escape($labels['net'])
            . '</th><th>' . self::escape($labels['tax']) . '</th><th>'
            . self::escape($labels['gross']) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="total" data-currency="' . self::escape($data['currency']) . '">'
            . self::escape($labels['gross']) . ': '
            . self::escape(self::formatAmount((int) $data['totals']['gross'], $currencyExponent))
            . ' ' . self::escape($data['currency']) . '</p>'
            . '</body></html>';
    }

    private static function formatAmount(int $minorUnits, ?int $exponent): string
    {
        return $exponent === null ? (string) $minorUnits : self::formatScaled($minorUnits, $exponent);
    }

    private static function formatScaled(int $units, int $scale): string
    {
        $negative = $units < 0;
        $digits = $units === PHP_INT_MIN
            ? substr((string) PHP_INT_MIN, 1)
            : (string) abs($units);
        if ($scale === 0) {
            return ($negative ? '-' : '') . $digits;
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        return ($negative ? '-' : '')
            . substr($digits, 0, -$scale)
            . '.'
            . substr($digits, -$scale);
    }

    private static function party(array $party): string
    {
        $address = (array) ($party['address'] ?? []);
        $lines = array_filter([
            (string) ($party['name'] ?? ''),
            (string) ($party['tax_identifier'] ?? ''),
            trim((string) ($address['line1'] ?? '') . ' ' . (string) ($address['line2'] ?? '')),
            trim((string) ($address['postal_code'] ?? '') . ' ' . (string) ($address['city'] ?? '')),
            (string) ($address['country_code'] ?? ''),
            (string) ($party['email'] ?? ''),
        ], static function (string $value): bool {
            return trim($value) !== '';
        });
        return '<p>' . implode('<br>', array_map([self::class, 'escape'], $lines)) . '</p>';
    }

    private static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
