<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering;

/** Safe, finite PDF layout choices persisted in immutable document metadata. */
final class DesignCatalog
{
    public const CLASSIC = 'classic';
    public const CARD = 'card';
    public const PANEL = 'panel';

    public static function normalize(string $value): string
    {
        return in_array($value, [self::CLASSIC, self::CARD, self::PANEL], true)
            ? $value
            : self::CLASSIC;
    }

    /** @return array<string, string> */
    public static function options(string $language = 'pl'): array
    {
        if (strpos(strtolower($language), 'ru') === 0) {
            return [
                self::CLASSIC => 'Классический — реестр',
                self::CARD => 'Карточка — выделенная сумма',
                self::PANEL => 'Панель — статус сбоку',
            ];
        }
        return [
            self::CLASSIC => 'Klasyczny — rejestr',
            self::CARD => 'Karta — wyróżniona suma',
            self::PANEL => 'Panel — status z boku',
        ];
    }
}
