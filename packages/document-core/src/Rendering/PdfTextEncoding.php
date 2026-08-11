<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering;

/**
 * Maps the Polish alphabet onto a custom single-byte PDF encoding.
 *
 * The previous renderer replaced every non-ASCII byte with '?', which destroyed
 * customer names, street names and city names. A dependency-free renderer cannot
 * embed a TrueType font, but it can declare an /Encoding /Differences array over
 * a standard Type1 base font, which the standard-14 glyph set does cover for
 * Polish. Characters outside that set are transliterated rather than dropped, so
 * text degrades legibly instead of turning into punctuation.
 */
final class PdfTextEncoding
{
    /** Glyph names assigned to codes 0x80.. in the /Differences array. */
    private const GLYPHS = [
        0x80 => ['ą', 'aogonek'],
        0x81 => ['ć', 'cacute'],
        0x82 => ['ę', 'eogonek'],
        0x83 => ['ł', 'lslash'],
        0x84 => ['ń', 'nacute'],
        0x85 => ['ó', 'oacute'],
        0x86 => ['ś', 'sacute'],
        0x87 => ['ź', 'zacute'],
        0x88 => ['ż', 'zdotaccent'],
        0x89 => ['Ą', 'Aogonek'],
        0x8A => ['Ć', 'Cacute'],
        0x8B => ['Ę', 'Eogonek'],
        0x8C => ['Ł', 'Lslash'],
        0x8D => ['Ń', 'Nacute'],
        0x8E => ['Ó', 'Oacute'],
        0x8F => ['Ś', 'Sacute'],
        0x90 => ['Ź', 'Zacute'],
        0x91 => ['Ż', 'Zdotaccent'],
        0x92 => ['—', 'emdash'],
        0x93 => ['€', 'Euro'],
    ];

    /** Legible fallbacks for characters with no glyph in the standard set. */
    private const TRANSLITERATION = [
        'ß' => 'ss', 'æ' => 'ae', 'ø' => 'o', 'å' => 'a', 'č' => 'c', 'š' => 's',
        'ž' => 'z', 'ě' => 'e', 'ř' => 'r', 'ů' => 'u', 'ď' => 'd', 'ť' => 't',
        'ň' => 'n', 'ý' => 'y', 'á' => 'a', 'í' => 'i', 'é' => 'e', 'ú' => 'u',
        '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '…' => '...',
        ' ' => ' ',
    ];

    /** The /Differences array body for the font resource. */
    public static function differences(): string
    {
        $parts = [];
        $previous = null;
        foreach (self::GLYPHS as $code => $glyph) {
            if ($previous === null || $code !== $previous + 1) {
                $parts[] = (string) $code;
            }
            $parts[] = '/' . $glyph[1];
            $previous = $code;
        }
        return implode(' ', $parts);
    }

    /**
     * Converts UTF-8 text into the single-byte encoding declared above and escapes
     * it for use inside a PDF literal string.
     */
    public static function encode(string $text): string
    {
        $reverse = [];
        foreach (self::GLYPHS as $code => $glyph) {
            $reverse[$glyph[0]] = chr($code);
        }

        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length;) {
            $byte = ord($text[$i]);
            if ($byte < 0x80) {
                // Printable ASCII passes through; control characters are dropped so
                // they cannot terminate a PDF string or inject an operator.
                $out .= ($byte >= 0x20 && $byte <= 0x7E) ? $text[$i] : ' ';
                $i++;
                continue;
            }
            $width = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);
            $char = substr($text, $i, $width);
            $i += $width;

            if (isset($reverse[$char])) {
                $out .= $reverse[$char];
            } elseif (isset(self::TRANSLITERATION[$char])) {
                $out .= self::TRANSLITERATION[$char];
            } else {
                $out .= '?';
            }
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $out);
    }
}
