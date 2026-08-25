<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\Rendering\EmbeddedFontPdfRenderer;
use Xmods\CommerceDocuments\Rendering\Font\EmbeddedFont;
use Xmods\CommerceDocuments\TaxRate;

/**
 * The production PDF engine.
 *
 * The assertions read the finished PDF back rather than inspecting the renderer's
 * internals: text is recovered through the document's own /ToUnicode CMap, so a
 * passing Polish assertion proves the glyph ids on the page correspond to the
 * characters that went in. review/claude/verify-pdf-engine.php runs the same
 * checks plus a byte-for-byte comparison of every glyph outline against the
 * source font.
 */
final class EmbeddedFontPdfRendererTest extends TestCase
{
    public function testProducesAStructurallyValidPdf(): void
    {
        $pdf = (new EmbeddedFontPdfRenderer())->render($this->snapshot());
        $reader = new PdfReader($pdf);

        self::assertStringStartsWith("%PDF-1.4\n", $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);
        self::assertSame(count($reader->pageObjects()), $reader->declaredPageCount());
        self::assertMatchesRegularExpression('#/Root \d+ 0 R /Info \d+ 0 R#', $reader->trailer());
    }

    public function testPolishTextRoundTripsThroughTheEmbeddedFont(): void
    {
        $pdf = (new EmbeddedFontPdfRenderer())->render($this->snapshot());
        $text = (new PdfReader($pdf))->text();

        self::assertStringContainsString('Zażółć Gęślą Jaźń Sp. z o.o.', $text);
        self::assertStringContainsString('ul. Świętokrzyska 5/7', $text);
        self::assertStringContainsString('Sprzedawca', $text);
        self::assertStringNotContainsString('?', $text);
        self::assertStringNotContainsString("\u{FFFD}", $text);

        // The glyphs come from an embedded subset, not from the viewer's fonts.
        self::assertSame(2, substr_count($pdf, '/FontFile2'));
        self::assertSame(2, substr_count($pdf, '/Encoding /Identity-H'));
        self::assertStringNotContainsString('/Differences', $pdf);
    }

    public function testRendersEveryLineItemWithTaxesAndTotals(): void
    {
        $snapshot = $this->snapshot();
        $text = (new PdfReader((new EmbeddedFontPdfRenderer())->render($snapshot)))->text();
        $totals = $snapshot->toArray()['totals'];

        self::assertStringContainsString('Płyta granitowa', $text);
        self::assertStringContainsString('Klej montażowy', $text);
        self::assertStringContainsString('23%', $text);
        self::assertStringContainsString('8%', $text);
        self::assertStringContainsString('Zestawienie VAT', $text);
        self::assertStringContainsString($this->money($totals['net']), $text);
        self::assertStringContainsString($this->money($totals['tax']), $text);
        self::assertStringContainsString($this->money($totals['gross']) . ' PLN', $text);
    }

    public function testUnpaidCashOnDeliveryNoticeAppearsOnlyWhenThePaymentIsNotConfirmed(): void
    {
        $renderer = new EmbeddedFontPdfRenderer();

        $unpaid = (new PdfReader($renderer->render(
            $this->snapshot(['payment_method' => 'cod', 'payment_confirmed' => 'no'])
        )))->text();
        $paid = (new PdfReader($renderer->render(
            $this->snapshot(['payment_method' => 'cod', 'payment_confirmed' => 'yes'])
        )))->text();

        self::assertStringContainsString('NIEOPŁACONE — płatność przy odbiorze', $unpaid);
        self::assertStringNotContainsString('NIEOPŁACONE', $paid);
    }

    public function testLegacyOrderConfirmationGetsUnpaidFallbackInPolish(): void
    {
        $text = (new PdfReader((new EmbeddedFontPdfRenderer())->render($this->snapshot())))->text();

        self::assertStringContainsString('NIEOPŁACONE — płatność niepotwierdzona', $text);
        self::assertStringContainsString('Sprzedawca', $text);
        self::assertStringContainsString('Nabywca', $text);
        self::assertStringNotContainsString('Продавец', $text);
        self::assertStringNotContainsString('Покупатель', $text);
    }

    public function testCorrectionReferencesAppearOnTheDocument(): void
    {
        $text = (new PdfReader((new EmbeddedFontPdfRenderer())->render($this->snapshot([
            'correction_of' => 'ORDER_CONFIRMATION/2026/000041',
            'correction_note' => 'Błędna ilość w pozycji 1.',
        ]))))->text();

        self::assertStringContainsString('Korekta dokumentu: ORDER_CONFIRMATION/2026/000041', $text);
        self::assertStringContainsString('Błędna ilość w pozycji 1.', $text);
    }

    public function testLongDocumentsPaginateWithoutLosingLineItems(): void
    {
        $items = [];
        for ($i = 1; $i <= 120; $i++) {
            $items[] = [sprintf('Pozycja %03d — płyta granitowa o niestandardowym rozmiarze', $i), 100, 5000, 230000];
        }

        $reader = new PdfReader((new EmbeddedFontPdfRenderer())->render($this->snapshot([], $items)));
        $text = $reader->text();

        self::assertGreaterThan(1, count($reader->pageObjects()));
        self::assertSame(count($reader->pageObjects()), $reader->declaredPageCount());
        for ($i = 1; $i <= 120; $i++) {
            self::assertStringContainsString(sprintf('Pozycja %03d', $i), $text);
        }
        self::assertSame(count($reader->pageObjects()) - 1, substr_count($text, 'ciąg dalszy'));
        self::assertSame(count($reader->pageObjects()), substr_count($text, 'Strona '));
        self::assertStringContainsString('Razem', $text);
    }

    public function testDocumentTextCannotEscapeIntoTheContentStream(): void
    {
        $reader = new PdfReader((new EmbeddedFontPdfRenderer())->render($this->snapshot(
            ['order_number' => ") Tj ("],
            [
                [") Tj 0 0 Td (INJECTED) Tj\nBT /F1 40 Tf 100 700 Td (PWNED", 100, 10000, 230000],
                ["NUL\x00 CR\r LF\n backslash \\ parens ( )", 100, 10000, 230000],
                ["broken utf-8: \xC3\x28\xE2\x82", 100, 10000, 230000],
            ]
        )));

        $content = '';
        foreach ($reader->pageObjects() as $page) {
            $content .= $reader->contentOfPage($page);
        }

        // Text reaches the page as glyph ids in hex strings, so no input byte can
        // terminate a string or start an operator.
        self::assertStringNotContainsString('(', $content);
        self::assertStringNotContainsString('INJECTED', $content);
        self::assertStringNotContainsString('PWNED', $content);
        self::assertStringNotContainsString("\x00", $content);
        self::assertSame(
            preg_match_all('/ Tj/', $content),
            preg_match_all('/<[0-9A-F]*> Tj/', $content)
        );
        self::assertStringContainsString("\u{FFFD}", $reader->text());
    }

    public function testOutputIsDeterministicAndCarriesNoClockReading(): void
    {
        $snapshot = $this->snapshot();
        $first = (new EmbeddedFontPdfRenderer())->render($snapshot);
        $second = (new EmbeddedFontPdfRenderer())->render($snapshot);

        self::assertSame($first, $second);
        self::assertStringContainsString("D:20260728100000+02'00'", $first);
        self::assertStringNotContainsString('D:' . gmdate('Ymd'), $first);
    }

    public function testOutputSizeIsBounded(): void
    {
        $items = [];
        for ($i = 0; $i < 2000; $i++) {
            $items[] = [sprintf('Pozycja %04d z długim opisem produktu kamiennego', $i), 100, 9900, 230000];
        }

        $pdf = (new EmbeddedFontPdfRenderer())->render($this->snapshot([], $items));
        $reader = new PdfReader($pdf);

        self::assertLessThanOrEqual(30, count($reader->pageObjects()));
        self::assertLessThan(350 * 1024, strlen($pdf));
        self::assertStringContainsString('dalszych pozycji nie pokazano', $reader->text());
    }

    public function testTheDocumentReferencesNothingOutsideItself(): void
    {
        $pdf = (new EmbeddedFontPdfRenderer())->render($this->snapshot());

        foreach (['http://', 'https://', '/URI', '/Launch', '/GoToR', '/EmbeddedFile',
                  '/JavaScript', '/OpenAction', '/AA', '/XObject', '/RichMedia'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $pdf);
        }
    }

    public function testTheEmbeddedFontIsRejectedWhenItDoesNotMatchItsDigest(): void
    {
        $directory = dirname(__DIR__, 2) . '/packages/document-core/resources/fonts';
        $metrics = require $directory . '/dejavu-sans-regular.php';

        self::assertSame(
            $metrics['sha256'],
            hash('sha256', (string) file_get_contents($directory . '/dejavu-sans-regular.ttf'))
        );

        $this->expectException(RuntimeException::class);
        EmbeddedFont::assertProgramMatches('not the font program', $metrics['sha256']);
    }

    public function testUnsupportedCharactersBecomeTheReplacementGlyphRatherThanDisappearing(): void
    {
        $font = EmbeddedFont::regular();

        $polish = $font->glyphsFor('ążćŁŚ');
        self::assertCount(5, $polish);
        self::assertNotContains(0, $polish);
        self::assertSame(count($polish), count(array_unique($polish)));

        // Outside the subset: shown as the replacement character, not dropped.
        $outside = $font->glyphsFor('日本語');
        self::assertCount(3, $outside);
        self::assertSame([$outside[0]], array_unique($outside));
        self::assertNotSame($polish[0], $outside[0]);

        // Control characters carry no glyph and are removed.
        self::assertSame([], $font->glyphsFor("\x00\x01\x1b"));
    }

    private function money(int $minorUnits): string
    {
        $digits = str_pad((string) abs($minorUnits), 3, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -2);
        if (strlen($whole) > 4) {
            $whole = strrev(implode(' ', str_split(strrev($whole), 3)));
        }
        return $whole . ',' . substr($digits, -2);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<int, array{0:string,1:int,2:int,3:int}> $items
     */
    private function snapshot(array $metadata = [], array $items = []): DocumentSnapshot
    {
        $currency = Currency::fromCode('PLN');
        if ($items === []) {
            $items = [
                ['Płyta granitowa Nero Assoluto, polerowana 60×30×2 cm', 250, 18900, 230000],
                ['Klej montażowy', 300, 2450, 80000],
            ];
        }

        $documentItems = [];
        foreach ($items as $item) {
            $documentItems[] = DocumentItem::create(
                $item[0],
                Quantity::fromScaledUnits($item[1], 2),
                'szt.',
                Money::fromMinorUnits($item[2], $currency),
                TaxRate::fromPartsPerMillion($item[3])
            );
        }

        return DocumentSnapshot::create(
            'doc_pdf_engine',
            'ORDER_CONFIRMATION/2026/000042',
            DocumentType::fromString(DocumentType::ORDER_CONFIRMATION),
            DocumentStatus::fromString(DocumentStatus::ISSUED),
            'woocommerce_order',
            '4242',
            $currency,
            Language::fromTag('pl-PL'),
            Party::create(
                'GEWARD Sp. z o.o.',
                '1234567890',
                'biuro@geward.test',
                Address::create('ul. Kamienna 12', '', '00-950', 'Warszawa', 'mazowieckie', 'PL')
            ),
            Party::create(
                'Zażółć Gęślą Jaźń Sp. z o.o.',
                '',
                'klient@example.invalid',
                Address::create('ul. Świętokrzyska 5/7', 'lok. 3', '31-042', 'Kraków', 'małopolskie', 'PL')
            ),
            $documentItems,
            '2026-07-28T10:00:00+02:00',
            '2026-07-28T10:00:00+02:00',
            1,
            $metadata
        );
    }
}

/**
 * Reads a generated PDF back. Deliberately minimal and independent of the
 * renderer: it walks the cross-reference table, so a wrong offset fails here
 * rather than passing unnoticed.
 */
final class PdfReader
{
    /** @var array<int, array{dict:string, stream:?string}> */
    private $objects = [];
    /** @var string */
    private $trailer = '';

    public function __construct(string $raw)
    {
        if (!preg_match('/startxref\s+(\d+)\s+%%EOF/', $raw, $match)) {
            throw new RuntimeException('No startxref/%%EOF found.');
        }
        $xref = (int) $match[1];
        if (substr($raw, $xref, 4) !== 'xref') {
            throw new RuntimeException('startxref does not point at the xref table.');
        }

        $lines = preg_split('/\r?\n/', substr($raw, $xref));
        if (!preg_match('/^0 (\d+)$/', trim((string) $lines[1]), $sizeMatch)) {
            throw new RuntimeException('Malformed xref subsection header.');
        }
        for ($number = 1; $number < (int) $sizeMatch[1]; $number++) {
            if (!preg_match('/^(\d{10}) 00000 n $/', (string) $lines[$number + 2], $entry)) {
                throw new RuntimeException('Malformed xref entry ' . $number . '.');
            }
            $this->objects[$number] = self::readObject($raw, $number, (int) $entry[1]);
        }

        $trailerAt = strpos($raw, 'trailer', $xref);
        $this->trailer = $trailerAt === false ? '' : substr($raw, $trailerAt, 200);
    }

    /** @return array{dict:string, stream:?string} */
    private static function readObject(string $raw, int $number, int $offset): array
    {
        $head = $number . " 0 obj\n";
        if (substr($raw, $offset, strlen($head)) !== $head) {
            throw new RuntimeException('Object ' . $number . ' is not at its xref offset.');
        }
        $rest = substr($raw, $offset + strlen($head));

        if (preg_match('/^(<<[^>]*(?:>(?!>)[^>]*)*>>)\s*stream\n/', $rest, $match)) {
            $length = preg_match('/\/Length (\d+)/', $match[1], $lengthMatch) ? (int) $lengthMatch[1] : 0;
            $stream = substr($rest, strlen($match[0]), $length);
            if (strpos(substr($rest, strlen($match[0]) + $length, 20), 'endstream') === false) {
                throw new RuntimeException('Object ' . $number . ': /Length does not reach endstream.');
            }
            return ['dict' => $match[1], 'stream' => $stream];
        }

        $end = strpos($rest, "\nendobj");
        if ($end === false) {
            throw new RuntimeException('Object ' . $number . ' has no endobj.');
        }
        return ['dict' => substr($rest, 0, $end), 'stream' => null];
    }

    public function trailer(): string
    {
        return $this->trailer;
    }

    /** @return int[] */
    public function pageObjects(): array
    {
        preg_match('/\/Kids \[(.*?)\]/', $this->objects[$this->pagesNode()]['dict'], $match);
        preg_match_all('/(\d+) 0 R/', (string) ($match[1] ?? ''), $kids);
        return array_map('intval', $kids[1]);
    }

    public function declaredPageCount(): int
    {
        preg_match('/\/Count (\d+)/', $this->objects[$this->pagesNode()]['dict'], $match);
        return (int) ($match[1] ?? 0);
    }

    public function contentOfPage(int $pageObject): string
    {
        preg_match('/\/Contents (\d+) 0 R/', $this->objects[$pageObject]['dict'], $match);
        return (string) $this->objects[(int) $match[1]]['stream'];
    }

    public function text(): string
    {
        $maps = $this->toUnicodeMaps();
        $text = '';
        foreach ($this->pageObjects() as $page) {
            $current = 'F1';
            preg_match_all(
                '#/(F\d+) [\d.]+ Tf|<([0-9A-Fa-f]*)> Tj#',
                $this->contentOfPage($page),
                $tokens,
                PREG_SET_ORDER
            );
            foreach ($tokens as $token) {
                if (($token[1] ?? '') !== '') {
                    $current = $token[1];
                    continue;
                }
                foreach (str_split((string) ($token[2] ?? ''), 4) as $glyphHex) {
                    if (strlen($glyphHex) === 4) {
                        $text .= self::utf8($maps[$current][hexdec($glyphHex)] ?? 0xFFFD);
                    }
                }
                $text .= "\n";
            }
            $text .= "\n";
        }
        return $text;
    }

    private function pagesNode(): int
    {
        foreach ($this->objects as $number => $object) {
            if (preg_match('#/Type\s*/Pages#', $object['dict'])) {
                return $number;
            }
        }
        throw new RuntimeException('No /Pages node.');
    }

    /** @return array<string, array<int, int>> */
    private function toUnicodeMaps(): array
    {
        $page = $this->pageObjects()[0];
        preg_match_all('#/(F\d+) (\d+) 0 R#', $this->objects[$page]['dict'], $resources, PREG_SET_ORDER);

        $maps = [];
        foreach ($resources as $resource) {
            if (!preg_match('#/ToUnicode (\d+) 0 R#', $this->objects[(int) $resource[2]]['dict'], $match)) {
                continue;
            }
            preg_match_all(
                '/<([0-9A-F]{4})> <([0-9A-F]{4})>/',
                (string) $this->objects[(int) $match[1]]['stream'],
                $entries,
                PREG_SET_ORDER
            );
            $map = [];
            foreach ($entries as $entry) {
                $map[hexdec($entry[1])] = hexdec($entry[2]);
            }
            $maps[$resource[1]] = $map;
        }
        return $maps;
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
