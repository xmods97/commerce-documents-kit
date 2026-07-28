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
        ],
        'pl' => [
            'seller' => 'Sprzedawca',
            'buyer' => 'Nabywca',
            'description' => 'Opis',
            'quantity' => 'Ilość',
            'net' => 'Netto',
            'tax' => 'Podatek',
            'gross' => 'Brutto',
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
