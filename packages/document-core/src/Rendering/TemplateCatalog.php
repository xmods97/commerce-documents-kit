<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering;

use InvalidArgumentException;

final class TemplateCatalog
{
    private const LABELS = [
        'en' => [
            'seller' => 'Seller',
            'buyer' => 'Buyer',
            'description' => 'Description',
            'quantity' => 'Quantity',
            'net' => 'Net',
            'tax' => 'Tax',
            'gross' => 'Gross',
            'issued' => 'Issued',
            'print' => 'Print / save as PDF',
            'unit_price' => 'Unit price',
            'tax_rate' => 'VAT',
            'total' => 'Total',
            'tax_summary' => 'VAT summary',
            'order' => 'Order',
            'page' => 'Page',
            'page_of' => 'of',
            'continued' => 'continued',
            'further_lines' => 'further line(s) not shown',
            'unpaid_cod' => 'UNPAID — cash on delivery',
            'correction_of' => 'Correction of document',
            'correction_reason' => 'Reason',
        ],
        'pl' => [
            'seller' => 'Sprzedawca',
            'buyer' => 'Nabywca',
            'description' => 'Opis',
            'quantity' => 'Ilość',
            'net' => 'Netto',
            'tax' => 'Podatek',
            'gross' => 'Brutto',
            'issued' => 'Data wystawienia',
            'print' => 'Drukuj / zapisz PDF',
            'unit_price' => 'Cena jedn.',
            'tax_rate' => 'VAT',
            'total' => 'Razem',
            'tax_summary' => 'Zestawienie VAT',
            'order' => 'Zamówienie',
            'page' => 'Strona',
            'page_of' => 'z',
            'continued' => 'ciąg dalszy',
            'further_lines' => 'dalszych pozycji nie pokazano',
            'unpaid_cod' => 'NIEOPŁACONE — płatność przy odbiorze',
            'correction_of' => 'Korekta dokumentu',
            'correction_reason' => 'Powód',
        ],
    ];

    public function labels(string $languageTag): array
    {
        $language = substr($languageTag, 0, 2);
        if (!isset(self::LABELS[$language])) {
            throw new InvalidArgumentException('No template labels for language.');
        }
        return self::LABELS[$language];
    }
}
