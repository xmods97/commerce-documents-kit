<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering\Pdf;

use RuntimeException;
use Xmods\CommerceDocuments\Rendering\Font\EmbeddedFont;

/**
 * Page geometry, text placement, wrapping and page breaks.
 *
 * One instance per rendered document, so rendering holds no state between calls
 * and the same snapshot always produces the same bytes.
 */
final class PageBuilder
{
    public const WIDTH = 595.0;
    public const HEIGHT = 842.0;
    public const MARGIN = 48.0;
    public const RIGHT = 547.0;
    /** Content stops here; the footer lives below. */
    public const BOTTOM = 74.0;

    /** Refuses to grow without bound, whatever the order contains. */
    public const MAX_PAGES = 30;

    /** @var EmbeddedFont */
    private $regular;
    /** @var EmbeddedFont */
    private $bold;
    /** @var string[] */
    private $pages = [];
    /** @var int */
    private $current = -1;
    /** @var float */
    private $y = 0.0;

    public function __construct(EmbeddedFont $regular, EmbeddedFont $bold)
    {
        $this->regular = $regular;
        $this->bold = $bold;
        $this->addPage();
    }

    public function addPage(): void
    {
        if (count($this->pages) >= self::MAX_PAGES) {
            throw new RuntimeException('The document exceeds the maximum page count.');
        }
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
        $this->y = self::HEIGHT - self::MARGIN;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** @return string[] */
    public function pages(): array
    {
        return $this->pages;
    }

    public function y(): float
    {
        return $this->y;
    }

    public function moveTo(float $y): void
    {
        $this->y = $y;
    }

    public function advance(float $points): void
    {
        $this->y -= $points;
    }

    public function remaining(): float
    {
        return $this->y - self::BOTTOM;
    }

    /** True when a new page was started to make room. */
    public function ensure(float $needed): bool
    {
        if ($this->remaining() >= $needed) {
            return false;
        }
        $this->addPage();
        return true;
    }

    public function font(bool $bold): EmbeddedFont
    {
        return $bold ? $this->bold : $this->regular;
    }

    public function widthOf(string $text, float $size, bool $bold = false): float
    {
        return $this->font($bold)->widthOf($text, $size);
    }

    public function text(float $x, float $y, string $text, float $size, bool $bold = false): void
    {
        if ($text === '') {
            return;
        }
        $this->pages[$this->current] .= "BT\n/" . ($bold ? 'F2' : 'F1') . ' ' . self::number($size) . " Tf\n"
            . self::number($x) . ' ' . self::number($y) . " Td\n"
            . $this->font($bold)->hexString($text) . " Tj\nET\n";
    }

    /** Writes at the current cursor and moves it down by $leading. */
    public function line(float $x, string $text, float $size, bool $bold = false, float $leading = 12.0): void
    {
        $this->text($x, $this->y, $text, $size, $bold);
        $this->y -= $leading;
    }

    public function textRight(float $rightEdge, float $y, string $text, float $size, bool $bold = false): void
    {
        $this->text($rightEdge - $this->widthOf($text, $size, $bold), $y, $text, $size, $bold);
    }

    /**
     * Writes the running footer on an already-built page. Called after layout,
     * when the total page count is known.
     */
    public function footer(int $pageIndex, string $left, string $right): void
    {
        if (!isset($this->pages[$pageIndex])) {
            throw new RuntimeException('Unknown page index.');
        }
        $previous = $this->current;
        $this->current = $pageIndex;
        $this->rule(self::BOTTOM - 14.0, self::MARGIN, self::RIGHT, 0.8);
        $this->text(self::MARGIN, self::BOTTOM - 26.0, $left, 7.5);
        $this->textRight(self::RIGHT, self::BOTTOM - 26.0, $right, 7.5);
        $this->current = $previous;
    }

    public function rule(float $y, float $from = self::MARGIN, float $to = self::RIGHT, float $gray = 0.7): void
    {
        $this->pages[$this->current] .= self::number($gray) . " G\n0.6 w\n"
            . self::number($from) . ' ' . self::number($y) . " m\n"
            . self::number($to) . ' ' . self::number($y) . " l\nS\n0 G\n";
    }

    /**
     * Places an image XObject. The name is a fixed internal identifier chosen by
     * the renderer, never anything derived from a filename or from document
     * content, so nothing here can name an object that does not exist.
     */
    public function image(string $name, float $x, float $y, float $width, float $height): void
    {
        if (!preg_match('/^Im[0-9]+$/', $name)) {
            throw new RuntimeException('Invalid image resource name.');
        }
        $this->pages[$this->current] .= "q\n"
            . self::number($width) . ' 0 0 ' . self::number($height) . ' '
            . self::number($x) . ' ' . self::number($y) . " cm\n"
            . '/' . $name . " Do\nQ\n";
    }

    public function box(float $x, float $y, float $width, float $height, float $gray = 0.4): void
    {
        $this->pages[$this->current] .= self::number($gray) . " G\n0.8 w\n"
            . self::number($x) . ' ' . self::number($y) . ' '
            . self::number($width) . ' ' . self::number($height) . " re\nS\n0 G\n";
    }

    /**
     * Greedy wrapping on spaces, with character-level breaking for words that do
     * not fit a line on their own, so a long unbroken product code cannot run off
     * the page.
     *
     * @return string[]
     */
    public function wrap(string $text, float $maxWidth, float $size, bool $bold = false, int $maxLines = 6): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [];
        }

        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($this->widthOf($candidate, $size, $bold) <= $maxWidth) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }
            foreach ($this->breakWord($word, $maxWidth, $size, $bold) as $index => $piece) {
                if ($index > 0) {
                    $lines[] = $line;
                }
                $line = $piece;
            }
            if (count($lines) > $maxLines) {
                break;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = $this->ellipsise($lines[$maxLines - 1], $maxWidth, $size, $bold);
        }
        return $lines;
    }

    /** @return string[] */
    private function breakWord(string $word, float $maxWidth, float $size, bool $bold): array
    {
        $characters = self::characters($word);
        $pieces = [];
        $piece = '';
        foreach ($characters as $character) {
            if ($piece !== '' && $this->widthOf($piece . $character, $size, $bold) > $maxWidth) {
                $pieces[] = $piece;
                $piece = '';
            }
            $piece .= $character;
        }
        if ($piece !== '') {
            $pieces[] = $piece;
        }
        return $pieces === [] ? [''] : $pieces;
    }

    /** Cuts a single line to width and appends an ellipsis. */
    public function ellipsise(string $text, float $maxWidth, float $size, bool $bold = false): string
    {
        if ($this->widthOf($text, $size, $bold) <= $maxWidth) {
            return $text;
        }
        $characters = self::characters($text);
        $out = '';
        foreach ($characters as $character) {
            if ($this->widthOf($out . $character . '…', $size, $bold) > $maxWidth) {
                break;
            }
            $out .= $character;
        }
        return rtrim($out) . '…';
    }

    /**
     * UTF-8 character split without ext-mbstring; invalid bytes are carried
     * through as single characters and become the replacement glyph downstream.
     *
     * @return string[]
     */
    private static function characters(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false || $characters === null) {
            return str_split($text);
        }
        return $characters;
    }

    /** Fixed-precision, locale-independent number formatting for the content stream. */
    public static function number(float $value): string
    {
        $formatted = sprintf('%.2F', $value);
        return rtrim(rtrim($formatted, '0'), '.') === '' ? '0' : rtrim(rtrim($formatted, '0'), '.');
    }
}
