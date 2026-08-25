<?php

declare(strict_types=1);

namespace Xmods\CommerceDocuments\Rendering;

use Xmods\CommerceDocuments\Contracts\LogoProvider;
use Xmods\CommerceDocuments\Contracts\PdfRenderer;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\Rendering\Font\EmbeddedFont;
use Xmods\CommerceDocuments\Rendering\Image\RasterImage;
use Xmods\CommerceDocuments\Rendering\Pdf\PageBuilder;
use Xmods\CommerceDocuments\Rendering\Pdf\PdfDocumentWriter;

/**
 * The production PDF engine.
 *
 * It takes a validated DocumentSnapshot and writes the PDF directly. There is no
 * HTML or CSS stage, so there is no markup parser, no stylesheet parser, no URL
 * resolution and no image loader — the classes of defect that make HTML-to-PDF
 * engines dangerous are absent by construction rather than switched off by
 * configuration. See review/claude/pdf-engine-decision.md for why this was chosen
 * over adopting an engine.
 *
 * Properties this renderer is expected to hold, each covered by a check in
 * review/claude/verify-pdf-engine.php:
 *
 * - Polish text renders from an embedded font subset, not from whatever the
 *   viewer happens to have installed.
 * - Document text is written as glyph ids inside hex strings, so no input can
 *   escape into the content stream.
 * - Every line item, the totals, the VAT summary, the unpaid cash-on-delivery
 *   notice and the correction references appear on the document.
 * - Long documents flow onto further pages with a repeated table header.
 * - The same snapshot always produces byte-identical output.
 * - Output size is bounded: the item count, the text length of each field and
 *   the page count all have hard limits.
 * - Nothing is fetched: no network call, no external font, image or stylesheet,
 *   and the only file read is the committed font asset addressed by a constant
 *   path.
 */
final class EmbeddedFontPdfRenderer implements PdfRenderer
{
    /**
     * Line items rendered in full; the remainder is summarised on the document.
     * Together with the page cap in PageBuilder this is what keeps the output
     * size bounded: 300 rows of long descriptions is eleven pages and about
     * 310 KB, of which 105 KB is the two embedded font subsets.
     */
    private const MAX_ITEMS = 300;

    /** Upper bounds on text taken from the snapshot before it is laid out. */
    private const MAX_DESCRIPTION = 300;
    private const MAX_FIELD = 120;
    private const MAX_NOTE = 400;

    private const TABLE_LEFT = 48.0;

    /**
     * The description column has to stop short of where the quantity column can
     * start, not merely short of it. Quantities are right-aligned on
     * COLUMN_QUANTITY, so the widest quantity a line can carry reaches back to
     * COLUMN_QUANTITY - QUANTITY_RESERVE; the description wraps before that, less
     * a gutter. Sizing this by eye is how "60×30×2 cm" ended up printed on top of
     * "2,5 szt.".
     */
    private const QUANTITY_RESERVE = 56.0;
    private const COLUMN_GUTTER = 6.0;
    private const DESCRIPTION_WIDTH = self::COLUMN_QUANTITY - self::TABLE_LEFT
        - self::QUANTITY_RESERVE - self::COLUMN_GUTTER;

    /** Right edges of the numeric columns. */
    private const COLUMN_QUANTITY = 302.0;
    private const COLUMN_UNIT_NET = 368.0;
    private const COLUMN_RATE = 404.0;
    private const COLUMN_NET = 462.0;
    private const COLUMN_TAX = 504.0;
    private const COLUMN_GROSS = 547.0;

    private const BODY_SIZE = 8.0;
    private const ROW_LEADING = 10.5;

    /**
     * Usable width of each right-aligned column: the distance to the column
     * before it, less a gutter. A value wider than this is set smaller rather
     * than allowed to overlap its neighbour.
     */
    private const CELL_GUTTER = 4.0;

    private static function columnWidth(float $rightEdge, float $previousEdge): float
    {
        return $rightEdge - $previousEdge - self::CELL_GUTTER;
    }

    /** The box the logo is fitted into, top right of the first page. */
    private const LOGO_MAX_WIDTH = 150.0;
    private const LOGO_MAX_HEIGHT = 46.0;
    private const LOGO_RESOURCE = 'Im0';

    /** @var int */
    private $currencyExponent;
    /** @var EmbeddedFont */
    private $regular;
    /** @var EmbeddedFont */
    private $bold;
    /** @var ?LogoProvider */
    private $logoProvider;

    public function __construct(
        int $currencyExponent = 2,
        ?EmbeddedFont $regular = null,
        ?EmbeddedFont $bold = null,
        ?LogoProvider $logoProvider = null
    ) {
        $this->currencyExponent = ($currencyExponent >= 0 && $currencyExponent <= 6)
            ? $currencyExponent
            : 2;
        $this->regular = $regular ?? EmbeddedFont::regular();
        $this->bold = $bold ?? EmbeddedFont::bold();
        $this->logoProvider = $logoProvider;
    }

    public function render(DocumentSnapshot $snapshot): string
    {
        $data = $snapshot->toArray();
        $labels = (new TemplateCatalog())->labels((string) $data['language']);
        $metadata = (array) ($data['metadata'] ?? []);
        $currency = self::field((string) $data['currency']);

        // A provider that cannot produce a safe image returns null, and the
        // document is issued without a logo rather than not issued at all.
        $logo = $this->logoProvider === null ? null : $this->logoProvider->logo();

        $design = DesignCatalog::normalize((string) ($metadata['pdf_design'] ?? DesignCatalog::CLASSIC));
        $page = new PageBuilder($this->regular, $this->bold);

        if ($design === DesignCatalog::CARD) {
            // A calm header band and a single highlighted total block echo the
            // reference card layout without introducing invoice/KSeF claims.
            $page->fillBox(0.0, 748.0, PageBuilder::WIDTH, 94.0, 0.91, 0.94, 0.98);
            $this->heading($page, $data, $labels, $metadata, $logo);
            $this->parties($page, $data, $labels);
            $this->items($page, $data, $labels, $currency);
            $this->cardTotals($page, $data, $labels, $currency);
            $this->taxSummary($page, $data, $labels, $currency);
            $this->corrections($page, $labels, $metadata);
        } elseif ($design === DesignCatalog::PANEL) {
            $this->panelLayout($page, $data, $labels, $metadata, $currency, $logo);
            for ($index = 0; $index < $page->pageCount(); $index++) {
                $page->prepend($index, function (PageBuilder $background) use ($data, $labels, $metadata, $currency): void {
                    $this->panelSidebar($background, $data, $labels, $metadata, $currency);
                });
            }
        } else {
            $this->heading($page, $data, $labels, $metadata, $logo);
            $this->parties($page, $data, $labels);
            $this->items($page, $data, $labels, $currency);
            $this->totals($page, $data, $labels, $currency);
            $this->taxSummary($page, $data, $labels, $currency);
            $this->corrections($page, $labels, $metadata);
        }
        $this->footers($page, $data, $labels);

        return $this->assemble($page, $data, $logo);
    }

    /** Layout B: a colored header and highlighted gross total. */
    private function cardTotals(PageBuilder $page, array $data, array $labels, string $currency): void
    {
        $totals = (array) ($data['totals'] ?? []);
        $page->ensure(72.0);
        $top = $page->y();
        $page->fillBox(330.0, $top - 58.0, 217.0, 58.0, 0.86, 0.92, 0.99);
        $page->text(342.0, $top - 17.0, $labels['net'], 9.0, false);
        $page->textRightFitted(538.0, $top - 17.0, $this->money((int) ($totals['net'] ?? 0)) . ' ' . $currency, 9.0, false, 90.0);
        $page->text(342.0, $top - 32.0, $labels['tax'], 9.0, false);
        $page->textRightFitted(538.0, $top - 32.0, $this->money((int) ($totals['tax'] ?? 0)) . ' ' . $currency, 9.0, false, 90.0);
        $page->text(342.0, $top - 48.0, $labels['total'] . ' ' . $labels['gross'], 10.0, true);
        $page->textRightFitted(538.0, $top - 48.0, $this->money((int) ($totals['gross'] ?? 0)) . ' ' . $currency, 11.0, true, 90.0);
        $page->moveTo($top - 72.0);
    }

    /** Layout C: content area on the left and a repeated status/payment panel. */
    private function panelLayout(
        PageBuilder $page,
        array $data,
        array $labels,
        array $metadata,
        string $currency,
        ?RasterImage $logo
    ): void {
        $this->panelHeading($page, $data, $labels, $metadata, $logo);
        $top = $page->y();
        $sellerBottom = $this->panelParty($page, 44.0, $top, $labels['seller'], (array) $data['seller'], 155.0);
        $buyerBottom = $this->panelParty($page, 220.0, $top, $labels['buyer'], (array) $data['buyer'], 155.0);
        $page->moveTo(min($sellerBottom, $buyerBottom) - 14.0);
        $this->panelItems($page, $data, $labels, $currency);
        $this->panelTotals($page, $data, $labels, $currency);
        $this->panelTaxSummary($page, $data, $labels, $currency);
        $this->panelCorrections($page, $labels, $metadata);
    }

    private function panelHeading(PageBuilder $page, array $data, array $labels, array $metadata, ?RasterImage $logo): void
    {
        if ($logo !== null) {
            $scale = min(115.0 / $logo->width(), 38.0 / $logo->height());
            $page->image(self::LOGO_RESOURCE, 430.0, 790.0 - $logo->height() * $scale, $logo->width() * $scale, $logo->height() * $scale);
        }
        $title = strtoupper(str_replace('_', ' ', (string) $data['document_type']));
        $page->line(44.0, $title, 14.0, true, 18.0);
        $page->line(44.0, self::field((string) $data['document_number']), 10.0, true, 15.0);
        $page->line(44.0, $labels['issued'] . ': ' . self::date((string) $data['issued_at']), 8.5, false, 11.0);
        if (isset($metadata['order_number'])) {
            $page->line(44.0, $labels['order'] . ': #' . self::field((string) $metadata['order_number']), 8.5, false, 11.0);
        }
        $page->advance(9.0);
    }

    private function panelParty(PageBuilder $page, float $x, float $top, string $heading, array $party, float $width): float
    {
        $address = (array) ($party['address'] ?? []);
        $page->text($x, $top, $heading, 8.5, true);
        $y = $top - 12.0;
        $lines = [];
        foreach ([
            (string) ($party['name'] ?? ''),
            (string) ($party['tax_identifier'] ?? '') !== '' ? 'NIP: ' . (string) $party['tax_identifier'] : '',
            trim((string) ($address['line1'] ?? '') . ' ' . (string) ($address['line2'] ?? '')),
            trim((string) ($address['postal_code'] ?? '') . ' ' . (string) ($address['city'] ?? '')),
            (string) ($address['country_code'] ?? ''),
            (string) ($party['email'] ?? ''),
        ] as $value) {
            $value = self::field($value);
            if (trim($value) === '') {
                continue;
            }
            foreach ($page->wrap($value, $width, 8.0, false, 3) as $wrapped) {
                $lines[] = $wrapped;
            }
        }
        foreach ($lines as $line) {
            $page->text($x, $y, $line, 8.0);
            $y -= 10.0;
        }
        return $y;
    }

    private function panelItems(PageBuilder $page, array $data, array $labels, string $currency): void
    {
        $this->panelTableHeader($page, $labels, false);
        $rendered = 0;
        foreach ((array) $data['items'] as $item) {
            if (!is_array($item) || $rendered >= self::MAX_ITEMS) {
                break;
            }
            $description = $page->wrap(self::text((string) ($item['description'] ?? ''), self::MAX_DESCRIPTION), 185.0, 7.5, false, 5);
            if ($description === []) {
                $description = ['—'];
            }
            $height = count($description) * 9.5 + 2.0;
            if ($page->ensure($height + 24.0)) {
                $this->panelTableHeader($page, $labels, true);
            }
            $y = $page->y();
            foreach ($description as $index => $line) {
                $page->text(44.0, $y - $index * 9.5, $line, 7.5);
            }
            $quantity = self::scaled((int) ($item['quantity']['scaled_units'] ?? 0), (int) ($item['quantity']['scale'] ?? 0));
            $unit = self::field((string) ($item['unit'] ?? ''));
            $page->textRightFitted(255.0, $y, trim($quantity . ' ' . $unit), 7.5, false, 55.0);
            foreach ([[300.0, $this->money((int) ($item['net'] ?? 0)), 41.0], [343.0, $this->money((int) ($item['tax'] ?? 0)), 39.0], [382.0, $this->money((int) ($item['gross'] ?? 0)), 35.0]] as $cell) {
                $page->textRightFitted($cell[0], $y, $cell[1], 7.5, false, $cell[2]);
            }
            $page->moveTo($y - $height);
            $rendered++;
        }
        $omitted = count((array) $data['items']) - $rendered;
        if ($omitted > 0) {
            $page->ensure(18.0);
            $page->line(44.0, '… ' . $omitted . ' ' . $labels['further_lines'], 7.5, true, 10.0);
        }
        $page->advance(2.0);
        $page->rule($page->y(), 44.0, 382.0);
        $page->advance(12.0);
    }

    private function panelTableHeader(PageBuilder $page, array $labels, bool $continued): void
    {
        $y = $page->y();
        $heading = $labels['description'] . ($continued ? ' (' . $labels['continued'] . ')' : '');
        $page->text(44.0, $y, $heading, 7.5, true);
        $page->textRight(255.0, $y, $labels['quantity'], 7.5, true);
        $page->textRight(300.0, $y, $labels['net'], 7.5, true);
        $page->textRight(343.0, $y, $labels['tax'], 7.5, true);
        $page->textRight(382.0, $y, $labels['gross'], 7.5, true);
        $page->moveTo($y - 4.0);
        $page->rule($page->y(), 44.0, 382.0);
        $page->advance(10.0);
    }

    private function panelTotals(PageBuilder $page, array $data, array $labels, string $currency): void
    {
        $totals = (array) ($data['totals'] ?? []);
        $page->ensure(58.0);
        $top = $page->y();
        $page->fillBox(230.0, $top - 48.0, 152.0, 48.0, 0.93, 0.96, 0.99);
        $page->textRight(320.0, $top - 14.0, $labels['net'], 8.0);
        $page->textRightFitted(382.0, $top - 14.0, $this->money((int) ($totals['net'] ?? 0)) . ' ' . $currency, 8.0, false, 58.0);
        $page->textRight(320.0, $top - 27.0, $labels['tax'], 8.0);
        $page->textRightFitted(382.0, $top - 27.0, $this->money((int) ($totals['tax'] ?? 0)) . ' ' . $currency, 8.0, false, 58.0);
        $page->textRight(320.0, $top - 42.0, $labels['total'] . ' ' . $labels['gross'], 9.0, true);
        $page->textRightFitted(382.0, $top - 42.0, $this->money((int) ($totals['gross'] ?? 0)) . ' ' . $currency, 9.0, true, 62.0);
        $page->moveTo($top - 62.0);
    }

    private function panelTaxSummary(PageBuilder $page, array $data, array $labels, string $currency): void
    {
        $groups = [];
        foreach ((array) $data['items'] as $item) {
            if (!is_array($item)) { continue; }
            $rate = (int) ($item['tax_rate_ppm'] ?? 0);
            if (!isset($groups[$rate])) { $groups[$rate] = ['net' => 0, 'tax' => 0, 'gross' => 0]; }
            $groups[$rate]['net'] += (int) ($item['net'] ?? 0);
            $groups[$rate]['tax'] += (int) ($item['tax'] ?? 0);
            $groups[$rate]['gross'] += (int) ($item['gross'] ?? 0);
        }
        if ($groups === []) { return; }
        ksort($groups, SORT_NUMERIC);
        $page->ensure(26.0 + count($groups) * 10.0);
        $page->line(44.0, $labels['tax_summary'] . ' (' . $currency . ')', 8.0, true, 11.0);
        $y = $page->y();
        $page->textRight(255.0, $y, $labels['tax_rate'], 7.5, true);
        $page->textRight(300.0, $y, $labels['net'], 7.5, true);
        $page->textRight(343.0, $y, $labels['tax'], 7.5, true);
        $page->textRight(382.0, $y, $labels['gross'], 7.5, true);
        $page->advance(10.0);
        foreach ($groups as $rate => $amounts) {
            $y = $page->y();
            $page->textRightFitted(255.0, $y, self::percentage((int) $rate), 7.5, false, 42.0);
            $page->textRightFitted(300.0, $y, $this->money($amounts['net']), 7.5, false, 41.0);
            $page->textRightFitted(343.0, $y, $this->money($amounts['tax']), 7.5, false, 39.0);
            $page->textRightFitted(382.0, $y, $this->money($amounts['gross']), 7.5, false, 35.0);
            $page->advance(10.0);
        }
    }

    private function panelCorrections(PageBuilder $page, array $labels, array $metadata): void
    {
        if (!isset($metadata['correction_of'])) { return; }
        $page->ensure(40.0);
        $page->line(44.0, $labels['correction_of'] . ': ' . self::field((string) $metadata['correction_of']), 8.0, true, 11.0);
        $note = self::text((string) ($metadata['correction_note'] ?? ''), self::MAX_NOTE);
        foreach ($page->wrap($labels['correction_reason'] . ': ' . $note, 338.0, 8.0, false, 3) as $line) {
            $page->line(44.0, $line, 8.0, false, 10.0);
        }
    }

    private function panelSidebar(PageBuilder $page, array $data, array $labels, array $metadata, string $currency): void
    {
        $page->fillBox(410.0, PageBuilder::BOTTOM, 185.0, 694.0, 0.95, 0.97, 0.99);
        $page->box(410.0, PageBuilder::BOTTOM, 185.0, 694.0, 0.82);
        $x = 430.0;
        $page->text($x, 742.0, strtoupper($labels['status'] ?? 'STATUS'), 8.0, true);
        $badge = self::paymentBadge($metadata, (string) ($data['document_type'] ?? ''));
        $badgeLines = $page->wrap($badge === '' ? '—' : $badge, 145.0, 9.0, true, 3);
        $y = 726.0;
        foreach ($badgeLines as $line) { $page->text($x, $y, $line, 9.0, true); $y -= 11.0; }
        $totals = (array) ($data['totals'] ?? []);
        $page->text($x, 674.0, $labels['total'] . ' ' . $labels['gross'], 8.0, true);
        $page->text($x, 655.0, $this->money((int) ($totals['gross'] ?? 0)) . ' ' . $currency, 14.0, true);
        $page->rule(640.0, $x, 575.0, 0.82);
        $page->text($x, 620.0, $labels['net'], 8.0, false);
        $page->textRight(575.0, 620.0, $this->money((int) ($totals['net'] ?? 0)) . ' ' . $currency, 8.0);
        $page->text($x, 604.0, $labels['tax'], 8.0, false);
        $page->textRight(575.0, 604.0, $this->money((int) ($totals['tax'] ?? 0)) . ' ' . $currency, 8.0);
        if (isset($metadata['order_number'])) {
            $page->text($x, 565.0, $labels['order'], 8.0, true);
            $page->text($x, 550.0, '#' . self::field((string) $metadata['order_number']), 9.0);
        }
        $page->text($x, 515.0, $labels['issued'], 8.0, true);
        $page->text($x, 500.0, self::date((string) $data['issued_at']), 8.0);
    }
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $labels
     * @param array<string, mixed> $metadata
     */
    private function heading(
        PageBuilder $page,
        array $data,
        array $labels,
        array $metadata,
        ?RasterImage $logo = null
    ): void {
        $logoBottom = null;
        if ($logo !== null) {
            // Fitted into a fixed box, aspect ratio preserved, anchored to the
            // right margin so it cannot grow into the heading text whatever the
            // uploaded image's proportions are.
            $scale = min(
                self::LOGO_MAX_WIDTH / $logo->width(),
                self::LOGO_MAX_HEIGHT / $logo->height()
            );
            $width = $logo->width() * $scale;
            $height = $logo->height() * $scale;
            $logoTop = $page->y() + 6.0;
            $page->image(self::LOGO_RESOURCE, PageBuilder::RIGHT - $width, $logoTop - $height, $width, $height);
            $logoBottom = $logoTop - $height;
        }

        $title = strtoupper(str_replace('_', ' ', (string) $data['document_type']));
        $page->line(PageBuilder::MARGIN, $title, 15.0, true, 20.0);
        $page->line(PageBuilder::MARGIN, self::field((string) $data['document_number']), 11.0, true, 18.0);

        $page->line(
            PageBuilder::MARGIN,
            $labels['issued'] . ': ' . self::date((string) $data['issued_at']),
            9.0,
            false,
            12.0
        );
        if (isset($metadata['order_number'])) {
            $page->line(
                PageBuilder::MARGIN,
                $labels['order'] . ': #' . self::field((string) $metadata['order_number']),
                9.0,
                false,
                12.0
            );
        }

        if (self::paymentBadge($metadata, (string) ($data['document_type'] ?? '')) !== '') {
            // Boxed rather than inline: whether the money has arrived is the single
            // fact an operator reads off this document first.
            $page->advance(4.0);
            $top = $page->y();
            $page->box(PageBuilder::MARGIN, $top - 18.0, PageBuilder::RIGHT - PageBuilder::MARGIN, 20.0);
            $page->text(
                PageBuilder::MARGIN + 8.0,
                $top - 12.0,
                self::paymentBadge($metadata, (string) ($data['document_type'] ?? '')),
                10.0,
                true
            );
            $page->moveTo($top - 24.0);
        }

        // Whatever the heading text did, the next block starts below the logo.
        if ($logoBottom !== null && $logoBottom - 12.0 < $page->y()) {
            $page->moveTo($logoBottom - 12.0);
        }
        $page->advance(10.0);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $labels
     */
    private function parties(PageBuilder $page, array $data, array $labels): void
    {
        $top = $page->y();
        $sellerBottom = $this->party($page, PageBuilder::MARGIN, $top, $labels['seller'], (array) $data['seller']);
        $buyerBottom = $this->party($page, 320.0, $top, $labels['buyer'], (array) $data['buyer']);
        $page->moveTo(min($sellerBottom, $buyerBottom) - 16.0);
    }

    /** @param array<string, mixed> $party */
    private function party(PageBuilder $page, float $x, float $top, string $heading, array $party): float
    {
        $address = (array) ($party['address'] ?? []);
        $page->text($x, $top, $heading, 9.0, true);
        $y = $top - 13.0;

        $lines = [];
        foreach ([
            (string) ($party['name'] ?? ''),
            (string) ($party['tax_identifier'] ?? '') !== '' ? 'NIP: ' . (string) $party['tax_identifier'] : '',
            trim((string) ($address['line1'] ?? '') . ' ' . (string) ($address['line2'] ?? '')),
            trim((string) ($address['postal_code'] ?? '') . ' ' . (string) ($address['city'] ?? '')),
            (string) ($address['country_code'] ?? ''),
            (string) ($party['email'] ?? ''),
        ] as $value) {
            $value = self::field($value);
            if (trim($value) === '') {
                continue;
            }
            foreach ($page->wrap($value, 210.0, 9.0, false, 3) as $wrapped) {
                $lines[] = $wrapped;
            }
        }

        foreach ($lines as $line) {
            $page->text($x, $y, $line, 9.0);
            $y -= 11.5;
        }
        return $y;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $labels
     */
    private function items(PageBuilder $page, array $data, array $labels, string $currency): void
    {
        $items = (array) $data['items'];
        $this->tableHeader($page, $labels, false);

        $rendered = 0;
        foreach ($items as $item) {
            if (!is_array($item) || $rendered >= self::MAX_ITEMS) {
                break;
            }

            $description = $page->wrap(
                self::text((string) ($item['description'] ?? ''), self::MAX_DESCRIPTION),
                self::DESCRIPTION_WIDTH,
                self::BODY_SIZE,
                false,
                4
            );
            if ($description === []) {
                $description = ['—'];
            }

            $height = count($description) * self::ROW_LEADING + 2.0;
            if ($page->ensure($height + 30.0)) {
                $this->tableHeader($page, $labels, true);
            }

            $y = $page->y();
            foreach ($description as $index => $line) {
                $page->text(self::TABLE_LEFT, $y - $index * self::ROW_LEADING, $line, self::BODY_SIZE);
            }

            $quantity = self::scaled(
                (int) ($item['quantity']['scaled_units'] ?? 0),
                (int) ($item['quantity']['scale'] ?? 0)
            );
            $unit = self::field((string) ($item['unit'] ?? ''));
            foreach ($this->numericCells($item) as $cell) {
                $page->textRightFitted($cell[0], $y, $cell[1], self::BODY_SIZE, false, $cell[2]);
            }
            $page->textRightFitted(
                self::COLUMN_QUANTITY,
                $y,
                trim($quantity . ' ' . $unit),
                self::BODY_SIZE,
                false,
                self::QUANTITY_RESERVE - self::CELL_GUTTER
            );

            $page->moveTo($y - $height);
            $rendered++;
        }

        $omitted = count($items) - $rendered;
        if ($omitted > 0) {
            $page->ensure(20.0);
            $page->line(
                self::TABLE_LEFT,
                '… ' . $omitted . ' ' . $labels['further_lines'],
                self::BODY_SIZE,
                true,
                12.0
            );
        }

        $page->advance(2.0);
        $page->rule($page->y());
        $page->advance(14.0);
    }

    /**
     * The numeric cells of one line item as [right edge, text, usable width].
     *
     * @param array<string, mixed> $item
     * @return array<int, array{0:float,1:string,2:float}>
     */
    private function numericCells(array $item): array
    {
        return [
            [
                self::COLUMN_UNIT_NET,
                $this->money((int) ($item['unit_net'] ?? 0)),
                self::columnWidth(self::COLUMN_UNIT_NET, self::COLUMN_QUANTITY),
            ],
            [
                self::COLUMN_RATE,
                self::percentage((int) ($item['tax_rate_ppm'] ?? 0)),
                self::columnWidth(self::COLUMN_RATE, self::COLUMN_UNIT_NET),
            ],
            [
                self::COLUMN_NET,
                $this->money((int) ($item['net'] ?? 0)),
                self::columnWidth(self::COLUMN_NET, self::COLUMN_RATE),
            ],
            [
                self::COLUMN_TAX,
                $this->money((int) ($item['tax'] ?? 0)),
                self::columnWidth(self::COLUMN_TAX, self::COLUMN_NET),
            ],
            [
                self::COLUMN_GROSS,
                $this->money((int) ($item['gross'] ?? 0)),
                self::columnWidth(self::COLUMN_GROSS, self::COLUMN_TAX),
            ],
        ];
    }

    /** @param array<string, string> $labels */
    private function tableHeader(PageBuilder $page, array $labels, bool $continued): void
    {
        $y = $page->y();
        $heading = $labels['description'] . ($continued ? ' (' . $labels['continued'] . ')' : '');
        $page->text(self::TABLE_LEFT, $y, $heading, self::BODY_SIZE, true);
        $page->textRight(self::COLUMN_QUANTITY, $y, $labels['quantity'], self::BODY_SIZE, true);
        $page->textRight(self::COLUMN_UNIT_NET, $y, $labels['unit_price'], self::BODY_SIZE, true);
        $page->textRight(self::COLUMN_RATE, $y, $labels['tax_rate'], self::BODY_SIZE, true);
        $page->textRight(self::COLUMN_NET, $y, $labels['net'], self::BODY_SIZE, true);
        $page->textRight(self::COLUMN_TAX, $y, $labels['tax'], self::BODY_SIZE, true);
        $page->textRight(self::COLUMN_GROSS, $y, $labels['gross'], self::BODY_SIZE, true);
        $page->moveTo($y - 4.0);
        $page->rule($page->y());
        $page->advance(12.0);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $labels
     */
    private function totals(PageBuilder $page, array $data, array $labels, string $currency): void
    {
        $totals = (array) ($data['totals'] ?? []);
        $page->ensure(60.0);

        foreach ([
            [$labels['net'], (int) ($totals['net'] ?? 0), false],
            [$labels['tax'], (int) ($totals['tax'] ?? 0), false],
            [$labels['total'] . ' ' . $labels['gross'], (int) ($totals['gross'] ?? 0), true],
        ] as $row) {
            $y = $page->y();
            $bold = (bool) $row[2];
            $size = $bold ? 11.0 : 9.5;
            $page->textRightFitted(
                self::COLUMN_NET,
                $y,
                (string) $row[0],
                $size,
                $bold,
                self::COLUMN_NET - 320.0
            );
            $page->textRightFitted(
                self::COLUMN_GROSS,
                $y,
                $this->money((int) $row[1]) . ' ' . $currency,
                $size,
                $bold,
                self::columnWidth(self::COLUMN_GROSS, self::COLUMN_NET)
            );
            $page->advance($bold ? 18.0 : 13.0);
        }
    }

    /**
     * VAT grouped by rate. A Polish document is expected to show the split even
     * when a single rate applies.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $labels
     */
    private function taxSummary(PageBuilder $page, array $data, array $labels, string $currency): void
    {
        $groups = [];
        foreach ((array) $data['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rate = (int) ($item['tax_rate_ppm'] ?? 0);
            if (!isset($groups[$rate])) {
                $groups[$rate] = ['net' => 0, 'tax' => 0, 'gross' => 0];
            }
            $groups[$rate]['net'] += (int) ($item['net'] ?? 0);
            $groups[$rate]['tax'] += (int) ($item['tax'] ?? 0);
            $groups[$rate]['gross'] += (int) ($item['gross'] ?? 0);
        }
        if ($groups === []) {
            return;
        }
        ksort($groups, SORT_NUMERIC);

        $page->ensure(30.0 + count($groups) * 12.0);
        $page->advance(6.0);
        // The currency is named once in the heading. Repeating it on every gross
        // cell widened that column into the tax column beside it.
        $page->line(self::TABLE_LEFT, $labels['tax_summary'] . ' (' . $currency . ')', 9.0, true, 13.0);

        $y = $page->y();
        $page->textRight(self::COLUMN_RATE, $y, $labels['tax_rate'], self::BODY_SIZE, true);
        $page->textRight(self::COLUMN_NET, $y, $labels['net'], self::BODY_SIZE, true);
        $page->textRight(self::COLUMN_TAX, $y, $labels['tax'], self::BODY_SIZE, true);
        $page->textRight(self::COLUMN_GROSS, $y, $labels['gross'], self::BODY_SIZE, true);
        $page->advance(11.0);

        foreach ($groups as $rate => $amounts) {
            $y = $page->y();
            foreach ([
                [self::COLUMN_RATE, self::percentage((int) $rate), self::COLUMN_UNIT_NET],
                [self::COLUMN_NET, $this->money($amounts['net']), self::COLUMN_RATE],
                [self::COLUMN_TAX, $this->money($amounts['tax']), self::COLUMN_NET],
                [self::COLUMN_GROSS, $this->money($amounts['gross']), self::COLUMN_TAX],
            ] as $cell) {
                $page->textRightFitted(
                    (float) $cell[0],
                    $y,
                    (string) $cell[1],
                    self::BODY_SIZE,
                    false,
                    self::columnWidth((float) $cell[0], (float) $cell[2])
                );
            }
            $page->advance(11.0);
        }
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, mixed> $metadata
     */
    private function corrections(PageBuilder $page, array $labels, array $metadata): void
    {
        if (!isset($metadata['correction_of'])) {
            return;
        }

        $page->ensure(46.0);
        $page->advance(10.0);
        $page->rule($page->y());
        $page->advance(14.0);
        $page->line(
            PageBuilder::MARGIN,
            $labels['correction_of'] . ': ' . self::field((string) $metadata['correction_of']),
            9.0,
            true,
            13.0
        );
        $note = self::text((string) ($metadata['correction_note'] ?? ''), self::MAX_NOTE);
        if (trim($note) !== '') {
            foreach ($page->wrap($labels['correction_reason'] . ': ' . $note, 499.0, 9.0, false, 4) as $line) {
                $page->line(PageBuilder::MARGIN, $line, 9.0, false, 12.0);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $labels
     */
    private function footers(PageBuilder $page, array $data, array $labels): void
    {
        $total = $page->pageCount();
        $number = self::field((string) $data['document_number']);
        for ($index = 0; $index < $total; $index++) {
            $page->footer(
                $index,
                $number,
                $labels['page'] . ' ' . ($index + 1) . ' ' . $labels['page_of'] . ' ' . $total
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function assemble(PageBuilder $page, array $data, ?RasterImage $logo = null): string
    {
        $writer = new PdfDocumentWriter();

        $catalog = $writer->reserve();
        $pagesNode = $writer->reserve();
        $regularFont = $this->fontObjects($writer, $this->regular);
        $boldFont = $this->fontObjects($writer, $this->bold);

        $resources = '<< /Font << /F1 ' . $regularFont . ' 0 R /F2 ' . $boldFont . ' 0 R >>';
        if ($logo !== null) {
            $resources .= ' /XObject << /' . self::LOGO_RESOURCE . ' '
                . $this->imageObject($writer, $logo) . ' 0 R >>';
        }
        $resources .= ' >>';

        $pageObjects = [];
        foreach ($page->pages() as $content) {
            $stream = $writer->addStream('', $content);
            $pageObjects[] = $writer->add(
                '<< /Type /Page /Parent ' . $pagesNode . ' 0 R'
                . ' /MediaBox [0 0 ' . PageBuilder::number(PageBuilder::WIDTH)
                . ' ' . PageBuilder::number(PageBuilder::HEIGHT) . ']'
                . ' /Resources ' . $resources
                . ' /Contents ' . $stream . ' 0 R >>'
            );
        }

        $kids = [];
        foreach ($pageObjects as $object) {
            $kids[] = $object . ' 0 R';
        }
        $writer->put(
            $pagesNode,
            '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pageObjects) . ' >>'
        );
        $writer->put($catalog, '<< /Type /Catalog /Pages ' . $pagesNode . ' 0 R >>');

        // Every value in the document information dictionary comes from the
        // snapshot, so the file carries no clock reading and no host detail.
        $issued = PdfDocumentWriter::date((string) $data['issued_at']);
        $created = PdfDocumentWriter::date((string) $data['created_at']);
        $info = $writer->add(
            '<< /Title ' . PdfDocumentWriter::literal((string) $data['document_number'])
            . ' /Producer ' . PdfDocumentWriter::literal('Commerce Documents Kit')
            . ' /Creator ' . PdfDocumentWriter::literal('Commerce Documents Kit')
            . ($created !== '' ? ' /CreationDate ' . PdfDocumentWriter::literal($created) : '')
            . ($issued !== '' ? ' /ModDate ' . PdfDocumentWriter::literal($issued) : '')
            . ' >>'
        );

        return $writer->build($catalog, $info);
    }

    /**
     * Writes the logo as an image XObject and returns its object number.
     *
     * The image is a passive resource: pixel data, dimensions and a colour space,
     * with an optional greyscale soft mask for transparency. It carries no
     * action, no annotation and no reference to anything outside the file — the
     * whole dictionary is written here, from values RasterImage has already
     * validated, rather than copied from the source file.
     */
    private function imageObject(PdfDocumentWriter $writer, RasterImage $logo): int
    {
        $softMask = '';
        if ($logo->softMask() !== null) {
            $mask = $writer->addStream(
                '/Type /XObject /Subtype /Image'
                . ' /Width ' . $logo->width() . ' /Height ' . $logo->height()
                . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode',
                (string) $logo->softMask()
            );
            $softMask = ' /SMask ' . $mask . ' 0 R';
        }

        return $writer->addStream(
            '/Type /XObject /Subtype /Image'
            . ' /Width ' . $logo->width() . ' /Height ' . $logo->height()
            . ' /ColorSpace ' . $logo->colourSpace()
            . ' /BitsPerComponent ' . $logo->bitsPerComponent()
            . ' /Filter ' . $logo->filter()
            . ($logo->decodeParms() === '' ? '' : ' /DecodeParms ' . $logo->decodeParms())
            . $softMask,
            $logo->data()
        );
    }

    /** Returns the object number of the Type0 font. */
    private function fontObjects(PdfDocumentWriter $writer, EmbeddedFont $font): int
    {
        $program = $font->program();
        $fontFile = $writer->addStream('/Length1 ' . strlen($program), $program);

        $bbox = $font->boundingBox();
        $descriptor = $writer->add(
            '<< /Type /FontDescriptor /FontName /' . $font->baseFontName()
            . ' /Flags 32'
            . ' /FontBBox [' . implode(' ', $bbox) . ']'
            . ' /ItalicAngle ' . $font->italicAngle()
            . ' /Ascent ' . $font->ascent()
            . ' /Descent ' . $font->descent()
            . ' /CapHeight ' . $font->capHeight()
            . ' /StemV ' . ($font->style() === EmbeddedFont::BOLD ? 140 : 80)
            . ' /FontFile2 ' . $fontFile . " 0 R >>"
        );

        $widths = $font->widths();
        ksort($widths, SORT_NUMERIC);
        $descendant = $writer->add(
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $font->baseFontName()
            . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
            . ' /FontDescriptor ' . $descriptor . ' 0 R'
            . ' /DW 1000 /W [0 [' . implode(' ', $widths) . ']]'
            . ' /CIDToGIDMap /Identity >>'
        );

        $toUnicode = $writer->addStream('', $this->toUnicodeCMap($font));

        return $writer->add(
            '<< /Type /Font /Subtype /Type0 /BaseFont /' . $font->baseFontName()
            . ' /Encoding /Identity-H'
            . ' /DescendantFonts [' . $descendant . ' 0 R]'
            . ' /ToUnicode ' . $toUnicode . ' 0 R >>'
        );
    }

    /**
     * Maps glyph ids back to codepoints so the text stays selectable, searchable
     * and machine-readable — which is also what makes the verification harness
     * able to read a finished document back.
     */
    private function toUnicodeCMap(EmbeddedFont $font): string
    {
        $entries = $font->reverseMap();
        $chunks = array_chunk($entries, 100, true);

        $body = '';
        foreach ($chunks as $chunk) {
            $body .= count($chunk) . " beginbfchar\n";
            foreach ($chunk as $glyph => $codepoint) {
                $body .= sprintf("<%04X> <%04X>\n", $glyph & 0xFFFF, $codepoint & 0xFFFF);
            }
            $body .= "endbfchar\n";
        }

        return "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $body
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    /** @param array<string, mixed> $metadata */
    private static function isUnpaidOnDelivery(array $metadata): bool
    {
        return (string) ($metadata['payment_status'] ?? '') === 'cash_on_delivery_unpaid'
            || (strtolower((string) ($metadata['payment_method'] ?? '')) === 'cod'
                && (string) ($metadata['payment_confirmed'] ?? 'no') !== 'yes');
    }

    /** @param array<string, mixed> $metadata */
    private static function paymentBadge(array $metadata, string $documentType = ''): string
    {
        $notice = trim((string) ($metadata['payment_notice'] ?? ''));
        if ($notice !== '' && in_array((string) ($metadata['payment_badge'] ?? ''), ['unpaid', 'paid'], true)) {
            return $notice;
        }
        if (self::isUnpaidOnDelivery($metadata)) {
            return 'NIEOPŁACONE — płatność przy odbiorze';
        }
        return self::isLegacyOrderConfirmation($metadata, $documentType)
            ? 'NIEOPŁACONE — płatność niepotwierdzona'
            : '';
    }

    /** @param array<string, mixed> $metadata */
    private static function isLegacyOrderConfirmation(array $metadata, string $documentType): bool
    {
        return $documentType === 'order_confirmation'
            && !array_key_exists('payment_badge', $metadata)
            && !array_key_exists('payment_notice', $metadata)
            && !(
                strtolower((string) ($metadata['payment_method'] ?? '')) === 'cod'
                && (string) ($metadata['payment_confirmed'] ?? 'no') === 'yes'
            );
    }

    private function money(int $minorUnits): string
    {
        return self::scaled($minorUnits, $this->currencyExponent, true);
    }

    private static function scaled(int $units, int $scale, bool $group = false): string
    {
        $negative = $units < 0;
        $digits = $units === PHP_INT_MIN
            ? substr((string) PHP_INT_MIN, 1)
            : (string) abs($units);

        if ($scale === 0) {
            $whole = $digits;
            $fraction = '';
        } else {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $whole = substr($digits, 0, -$scale);
            $fraction = substr($digits, -$scale);
        }

        if ($group && strlen($whole) > 4) {
            $whole = strrev(implode(' ', str_split(strrev($whole), 3)));
        }

        return ($negative ? '-' : '') . $whole . ($fraction === '' ? '' : ',' . $fraction);
    }

    /** Parts per million as a percentage, e.g. 230000 -> "23%". */
    private static function percentage(int $partsPerMillion): string
    {
        $hundredths = intdiv($partsPerMillion, 100);
        $whole = intdiv($hundredths, 100);
        $fraction = $hundredths % 100;
        if ($fraction === 0) {
            return $whole . '%';
        }
        return $whole . ',' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT) . '%';
    }

    private static function date(string $atom): string
    {
        $parsed = \DateTimeImmutable::createFromFormat(DATE_ATOM, $atom);
        return $parsed === false ? self::field($atom) : $parsed->format('Y-m-d');
    }

    /** A short single-line value taken from the snapshot. */
    private static function field(string $value): string
    {
        return self::text($value, self::MAX_FIELD);
    }

    /**
     * Bounds a snapshot value before it is laid out. Line breaks collapse to
     * spaces so a multi-line value cannot displace the layout, and the length cap
     * keeps a hostile or accidental megabyte-long field from inflating the file.
     */
    private static function text(string $value, int $limit): string
    {
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
        if (strlen($value) <= $limit) {
            return $value;
        }
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false || $characters === null) {
            return substr($value, 0, $limit);
        }
        if (count($characters) <= $limit) {
            return $value;
        }
        return implode('', array_slice($characters, 0, $limit - 1)) . '…';
    }
}
