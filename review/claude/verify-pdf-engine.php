<?php
/**
 * Offline verification harness for the production PDF engine.
 *
 * It renders documents with EmbeddedFontPdfRenderer and reads the result back:
 * the PDF structure, the embedded font program, and the page text recovered
 * through the document's own /ToUnicode CMap. Recovering the text that way is
 * what makes the Polish checks meaningful — it proves the glyph ids written to
 * the page correspond to the characters that went in, rather than proving that a
 * string appears somewhere in the file.
 *
 * No network, no database, no WordPress, no email transport. Evidence goes to
 * evidence/.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $map = [
        'Xmods\\CommerceDocuments\\WooCommerce\\' => $root . '/packages/woocommerce/src/',
        'Xmods\\CommerceDocuments\\WordPress\\'   => $root . '/packages/wordpress/src/',
        'Xmods\\CommerceDocuments\\'              => $root . '/packages/document-core/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strpos($class, $prefix) !== 0) {
            continue;
        }
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_readable($file)) {
            require $file;
        }
        return;
    }
});

use Xmods\CommerceDocuments\Address;
use Xmods\CommerceDocuments\Currency;
use Xmods\CommerceDocuments\DocumentItem;
use Xmods\CommerceDocuments\DocumentSnapshot;
use Xmods\CommerceDocuments\DocumentStatus;
use Xmods\CommerceDocuments\DocumentType;
use Xmods\CommerceDocuments\Application\GenerateDocument;
use Xmods\CommerceDocuments\Language;
use Xmods\CommerceDocuments\Money;
use Xmods\CommerceDocuments\Party;
use Xmods\CommerceDocuments\Quantity;
use Xmods\CommerceDocuments\Rendering\EmbeddedFontPdfRenderer;
use Xmods\CommerceDocuments\Rendering\Font\EmbeddedFont;
use Xmods\CommerceDocuments\TaxRate;

$evidence = __DIR__ . '/evidence';
if (!is_dir($evidence)) {
    mkdir($evidence, 0700, true);
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
}
function section(string $title): void
{
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
}

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// The PDF reader lives in lib/PdfFile.php so the logo harness reads generated
// documents with exactly the same code.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/lib/PdfFile.php';

/** Independent structural check of an embedded TrueType subset. */
final class TrueTypeCheck
{
    /** @var string */
    private $data;
    /** @var array<string, array{0:int,1:int}> */
    private $tables = [];

    public function __construct(string $data)
    {
        $this->data = $data;
        $numTables = self::u16(4);
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + $i * 16;
            $this->tables[substr($data, $record, 4)] = [self::u32($record + 8), self::u32($record + 12)];
        }
    }

    /** @return string[] problems found; empty means the subset is consistent */
    public function problems(): array
    {
        $problems = [];

        if (substr($this->data, 0, 4) !== "\x00\x01\x00\x00") {
            $problems[] = 'not a TrueType outline font';
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap', 'loca', 'glyf', 'name', 'post'] as $required) {
            if (!isset($this->tables[$required])) {
                $problems[] = 'missing table ' . $required;
            }
        }
        if ($problems !== []) {
            return $problems;
        }

        foreach ($this->tables as $tag => $table) {
            if ($table[0] + $table[1] > strlen($this->data)) {
                $problems[] = 'table ' . $tag . ' runs past the end of the font';
            }
        }

        $numGlyphs = self::u16($this->tables['maxp'][0] + 4);
        $indexToLoc = self::u16($this->tables['head'][0] + 50);
        if ($indexToLoc !== 1) {
            $problems[] = 'loca is not in long format';
        }
        if ($this->tables['loca'][1] !== ($numGlyphs + 1) * 4) {
            $problems[] = 'loca length does not match numGlyphs';
        }
        if (self::u16($this->tables['hhea'][0] + 34) !== $numGlyphs) {
            $problems[] = 'numberOfHMetrics does not cover every glyph';
        }
        if ($this->tables['hmtx'][1] !== $numGlyphs * 4) {
            $problems[] = 'hmtx length does not match numGlyphs';
        }

        $loca = [];
        for ($i = 0; $i <= $numGlyphs; $i++) {
            $loca[$i] = self::u32($this->tables['loca'][0] + $i * 4);
        }
        for ($i = 0; $i < $numGlyphs; $i++) {
            if ($loca[$i] > $loca[$i + 1]) {
                $problems[] = 'loca is not monotonic at glyph ' . $i;
                break;
            }
        }
        if ($loca[$numGlyphs] > $this->tables['glyf'][1]) {
            $problems[] = 'loca runs past the end of glyf';
        }

        // Every composite must reference a glyph that is present and has an outline.
        for ($glyph = 0; $glyph < $numGlyphs; $glyph++) {
            if ($loca[$glyph + 1] - $loca[$glyph] < 10) {
                continue;
            }
            $offset = $this->tables['glyf'][0] + $loca[$glyph];
            if (self::i16($offset) >= 0) {
                continue;
            }
            foreach ($this->componentsAt($offset, $loca[$glyph + 1] - $loca[$glyph]) as $component) {
                if ($component >= $numGlyphs) {
                    $problems[] = sprintf('composite %d refers to glyph %d, out of range', $glyph, $component);
                    continue;
                }
                if ($loca[$component + 1] <= $loca[$component]) {
                    $problems[] = sprintf('composite %d refers to empty glyph %d', $glyph, $component);
                }
            }
        }

        // Table checksums, and the whole-file checkSumAdjustment.
        foreach ($this->tables as $tag => $table) {
            $stored = self::u32(12 + array_search($tag, array_keys($this->tables), true) * 16 + 4);
            $data = substr($this->data, $table[0], $table[1]);
            if ($tag === 'head') {
                $data = substr_replace($data, "\x00\x00\x00\x00", 8, 4);
            }
            if (self::checksum($data) !== $stored) {
                $problems[] = 'checksum mismatch on table ' . $tag;
            }
        }
        $headOffset = $this->tables['head'][0];
        $stored = self::u32($headOffset + 8);
        $zeroed = substr_replace($this->data, "\x00\x00\x00\x00", $headOffset + 8, 4);
        if (((0xB1B0AFBA - self::checksumOf($zeroed)) & 0xFFFFFFFF) !== $stored) {
            $problems[] = 'checkSumAdjustment does not match the file';
        }

        return $problems;
    }

    public function numGlyphs(): int
    {
        return self::u16($this->tables['maxp'][0] + 4);
    }

    public function glyphHasOutline(int $glyph): bool
    {
        $base = $this->tables['loca'][0];
        return self::u32($base + ($glyph + 1) * 4) > self::u32($base + $glyph * 4);
    }

    /** @return int[] */
    private function componentsAt(int $offset, int $length): array
    {
        $components = [];
        $position = 10;
        while ($position + 4 <= $length) {
            $flags = self::u16($offset + $position);
            $components[] = self::u16($offset + $position + 2);
            $position += 4;
            $position += ($flags & 0x0001) ? 4 : 2;
            if ($flags & 0x0008) {
                $position += 2;
            } elseif ($flags & 0x0040) {
                $position += 4;
            } elseif ($flags & 0x0080) {
                $position += 8;
            }
            if (!($flags & 0x0020)) {
                break;
            }
        }
        return $components;
    }

    private static function checksum(string $data): int
    {
        return self::checksumOf($data);
    }

    private static function checksumOf(string $data): int
    {
        $data .= str_repeat("\x00", (4 - strlen($data) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }
        return $sum;
    }

    private function u16(int $offset): int
    {
        return unpack('n', substr($this->data, $offset, 2))[1];
    }

    private function i16(int $offset): int
    {
        $value = $this->u16($offset);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(int $offset): int
    {
        return unpack('N', substr($this->data, $offset, 4))[1];
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/**
 * @param array<int, array{0:string,1:int,2:int,3:int}> $items description, qty, unit net, tax ppm
 * @param array<string, mixed> $metadata
 */
function snapshot(
    array $items,
    array $metadata = [],
    string $language = 'pl-PL',
    string $buyer = 'Zażółć Gęślą Jaźń Sp. z o.o.'
): DocumentSnapshot {
    $currency = Currency::fromCode('PLN');
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
        Language::fromTag($language),
        Party::create(
            'GEWARD Sp. z o.o.',
            '1234567890',
            'biuro@geward.test',
            Address::create('ul. Kamienna 12', '', '00-950', 'Warszawa', 'mazowieckie', 'PL')
        ),
        Party::create(
            $buyer,
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

$renderer = new EmbeddedFontPdfRenderer();

// ---------------------------------------------------------------------------

section('E1 — PDF structure');

$standard = snapshot(
    [
        ['Płyta granitowa Nero Assoluto, polerowana 60×30×2 cm', 250, 18900, 230000],
        ['Cięcie i obróbka krawędzi — usługa', 100, 12000, 230000],
        ['Klej montażowy', 300, 2450, 80000],
    ],
    ['order_number' => '4242', 'payment_method' => 'cod', 'payment_confirmed' => 'no']
);
$pdf = $renderer->render($standard);
file_put_contents($evidence . '/pdf-engine-standard.pdf', $pdf);

check('starts with a PDF header', strpos($pdf, "%PDF-1.4\n") === 0);
check('ends with %%EOF', substr(rtrim($pdf), -5) === '%%EOF');

$file = null;
try {
    $file = new PdfFile($pdf);
    check('xref parses and every offset lands on its object', true, count($file->offsets) . ' objects');
} catch (Throwable $exception) {
    check('xref parses and every offset lands on its object', false, $exception->getMessage());
}

if ($file === null) {
    echo PHP_EOL . 'Structure is unreadable; remaining checks cannot run.' . PHP_EOL;
    exit(1);
}

check('trailer names a catalog and an info dictionary', (bool) preg_match('#/Root \d+ 0 R /Info \d+ 0 R#', $file->trailer));
check('/Count matches the number of /Kids', $file->declaredPageCount() === count($file->pageObjects()),
    $file->declaredPageCount() . ' pages');
check('every stream /Length reaches its endstream', true, 'checked while parsing');

section('E2 — embedded font, not the viewer\'s');

$regularProgram = $file->fontProgram('F1');
$boldProgram = $file->fontProgram('F2');
check('a font program is embedded for both styles',
    strlen($regularProgram) > 10000 && strlen($boldProgram) > 10000,
    sprintf('regular %d B, bold %d B', strlen($regularProgram), strlen($boldProgram)));
check('fonts are Identity-H CID fonts with an Identity CIDToGIDMap',
    substr_count($pdf, '/Encoding /Identity-H') === 2 && substr_count($pdf, '/CIDToGIDMap /Identity') === 2);
check('no /Differences encoding is relied on', strpos($pdf, '/Differences') === false);
check('the subset carries a subset tag', (bool) preg_match('#/BaseFont /[A-Z]{6}\+#', $pdf));

foreach (['F1' => $regularProgram, 'F2' => $boldProgram] as $resource => $program) {
    $problems = (new TrueTypeCheck($program))->problems();
    check('embedded ' . $resource . ' subset is structurally consistent', $problems === [],
        $problems === [] ? (new TrueTypeCheck($program))->numGlyphs() . ' glyphs' : implode('; ', $problems));
}

$font = EmbeddedFont::regular();
$assetDirectory = $root . '/packages/document-core/resources/fonts';
$recorded = (array) require $assetDirectory . '/dejavu-sans-regular.php';
check('the committed font asset matches the digest recorded beside it',
    hash('sha256', (string) file_get_contents($assetDirectory . '/dejavu-sans-regular.ttf')) === $recorded['sha256']);
$rejected = false;
try {
    EmbeddedFont::assertProgramMatches('tampered font program', $recorded['sha256']);
} catch (Throwable $exception) {
    $rejected = true;
}
check('a font program that does not match its digest is rejected', $rejected);
check('the embedded program is the committed asset, byte for byte',
    $regularProgram === file_get_contents($assetDirectory . '/dejavu-sans-regular.ttf'));
$polishGlyphs = [];
foreach (['ą', 'ć', 'ę', 'ł', 'ń', 'ó', 'ś', 'ź', 'ż', 'Ą', 'Ć', 'Ę', 'Ł', 'Ń', 'Ó', 'Ś', 'Ź', 'Ż'] as $letter) {
    $glyphs = $font->glyphsFor($letter);
    $polishGlyphs[$letter] = $glyphs[0] ?? 0;
}
check('every Polish letter maps to a distinct, non-zero glyph',
    count(array_unique($polishGlyphs)) === 18 && !in_array(0, $polishGlyphs, true));

$check = new TrueTypeCheck($regularProgram);
$outlines = 0;
foreach ($polishGlyphs as $glyph) {
    if ($check->glyphHasOutline($glyph)) {
        $outlines++;
    }
}
check('every Polish glyph has an outline in the embedded subset', $outlines === 18, $outlines . '/18');

section('E3 — Polish text survives the round trip');

$text = $file->text();
file_put_contents($evidence . '/pdf-engine-standard.txt', $text);

check('buyer name round-trips through the document\'s own CMap',
    strpos($text, 'Zażółć Gęślą Jaźń Sp. z o.o.') !== false);
check('Polish street and city names round-trip',
    strpos($text, 'ul. Świętokrzyska 5/7') !== false && strpos($text, 'Kraków') !== false);
check('Polish labels are rendered', strpos($text, 'Sprzedawca') !== false && strpos($text, 'Nabywca') !== false);
check('no character was replaced with "?" or U+FFFD',
    strpos($text, '?') === false && strpos($text, "\u{FFFD}") === false);

$english = new PdfFile($renderer->render(snapshot([['Granite slab', 100, 10000, 230000]], [], 'en-GB', 'Müller & Sønner')));
$englishText = $english->text();
check('other Latin scripts render as themselves',
    strpos($englishText, 'Müller & Sønner') !== false);
check('English labels are rendered', strpos($englishText, 'Seller') !== false && strpos($englishText, 'Buyer') !== false);

section('E4 — line items, taxes and totals');

check('every line item description appears',
    strpos($text, 'Płyta granitowa Nero Assoluto') !== false
    && strpos($text, 'Cięcie i obróbka krawędzi') !== false
    && strpos($text, 'Klej montażowy') !== false);
check('quantities and units appear',
    strpos($text, '2,5 szt.') !== false && strpos($text, '3 szt.') !== false);
check('unit prices appear', strpos($text, '189,00') !== false);
check('per-line tax rates appear', strpos($text, '23%') !== false && strpos($text, '8%') !== false);

$totals = $standard->toArray()['totals'];
$formatted = static function (int $minor): string {
    $digits = str_pad((string) abs($minor), 3, '0', STR_PAD_LEFT);
    $whole = substr($digits, 0, -2);
    if (strlen($whole) > 4) {
        $whole = strrev(implode(' ', str_split(strrev($whole), 3)));
    }
    return $whole . ',' . substr($digits, -2);
};
check('the net total appears', strpos($text, $formatted($totals['net'])) !== false, $formatted($totals['net']));
check('the tax total appears', strpos($text, $formatted($totals['tax'])) !== false, $formatted($totals['tax']));
check('the gross total appears and is labelled',
    strpos($text, $formatted($totals['gross']) . ' PLN') !== false, $formatted($totals['gross']) . ' PLN');
check('a VAT summary is printed', strpos($text, 'Zestawienie VAT') !== false);

$sumNet = 0;
$sumTax = 0;
foreach ($standard->toArray()['items'] as $item) {
    $sumNet += $item['net'];
    $sumTax += $item['tax'];
}
check('the printed totals equal the snapshot arithmetic',
    $sumNet === $totals['net'] && $sumTax === $totals['tax'] && $totals['net'] + $totals['tax'] === $totals['gross']);

section('E5 — cash on delivery and corrections');

check('the unpaid cash-on-delivery notice is printed',
    strpos($text, 'NIEOPŁACONE — płatność przy odbiorze') !== false);

$paid = new PdfFile($renderer->render(snapshot(
    [['Płyta', 100, 10000, 230000]],
    ['payment_method' => 'card', 'payment_confirmed' => 'yes']
)));
check('a paid document carries no unpaid notice', strpos($paid->text(), 'NIEOPŁACONE') === false);

$correction = new PdfFile($renderer->render(snapshot(
    [['Płyta granitowa', 100, 10000, 230000]],
    [
        'correction_of' => 'ORDER_CONFIRMATION/2026/000041',
        'correction_note' => 'Błędna ilość w pozycji 1 — korekta na wniosek klienta.',
    ]
)));
$correctionText = $correction->text();
check('the corrected document number is printed',
    strpos($correctionText, 'Korekta dokumentu: ORDER_CONFIRMATION/2026/000041') !== false);
check('the correction reason is printed',
    strpos($correctionText, 'Błędna ilość w pozycji 1') !== false);

section('E6 — pagination');

$many = [];
for ($i = 1; $i <= 140; $i++) {
    $many[] = [sprintf('Pozycja %03d — płyta granitowa w rozmiarze niestandardowym', $i), 100, 5000 + $i, 230000];
}
$longPdf = $renderer->render(snapshot($many, ['order_number' => '4243']));
file_put_contents($evidence . '/pdf-engine-paginated.pdf', $longPdf);
$long = new PdfFile($longPdf);
$longText = $long->text();

check('a long document flows onto further pages', count($long->pageObjects()) > 1,
    count($long->pageObjects()) . ' pages');
check('/Count matches the pages actually written',
    $long->declaredPageCount() === count($long->pageObjects()));
check('the table header repeats on continuation pages',
    substr_count($longText, 'ciąg dalszy') === count($long->pageObjects()) - 1);
check('every page carries a footer with its number',
    substr_count($longText, 'Strona ') === count($long->pageObjects()));
check('the first and the last line item both appear',
    strpos($longText, 'Pozycja 001') !== false && strpos($longText, 'Pozycja 140') !== false);

$missing = [];
for ($i = 1; $i <= 140; $i++) {
    if (strpos($longText, sprintf('Pozycja %03d', $i)) === false) {
        $missing[] = $i;
    }
}
check('no line item is lost at a page boundary', $missing === [],
    $missing === [] ? '140/140 present' : 'missing ' . implode(',', array_slice($missing, 0, 10)));
check('the totals still print after the table',
    strpos($longText, 'Razem') !== false);

section('E7 — hostile and malformed input');

$hostile = [
    [") Tj 0 0 Td (INJECTED) Tj\nBT /F1 40 Tf 100 700 Td (PWNED", 100, 10000, 230000],
    ['\\\\ ( ) << >> /Type /Action endstream endobj', 100, 10000, 230000],
    ["NUL\x00 CR\r LF\n TAB\t ESC\x1b", 100, 10000, 230000],
    ["invalid utf-8: \xC3\x28 \xE2\x82 \xF0\x9F", 100, 10000, 230000],
    [str_repeat('A', 20000), 100, 10000, 230000],
];
$hostilePdf = $renderer->render(snapshot(
    $hostile,
    [
        'order_number' => ") Tj (",
        'correction_of' => "1 0 obj << /Type /Catalog >>",
        'correction_note' => str_repeat("<script>\x00", 500),
    ],
    'pl-PL',
    ") Tj\nBT /F2 60 Tf 0 0 Td (OWNED"
));
file_put_contents($evidence . '/pdf-engine-hostile.pdf', $hostilePdf);

$hostileFile = null;
try {
    $hostileFile = new PdfFile($hostilePdf);
    check('hostile input still produces a structurally valid PDF', true);
} catch (Throwable $exception) {
    check('hostile input still produces a structurally valid PDF', false, $exception->getMessage());
}

$contents = '';
if ($hostileFile !== null) {
    foreach ($hostileFile->pageObjects() as $page) {
        $contents .= $hostileFile->contentOfPage($page);
    }
}
check('no literal string appears in any content stream', strpos($contents, '(') === false);
check('document text reaches the page only as hex glyph ids',
    (bool) preg_match('/^[\x20-\x7E\n]*$/', $contents)
    && preg_match_all('/<[0-9A-F]*> Tj/', $contents) === preg_match_all('/ Tj/', $contents));
check('no injected operator survives',
    strpos($contents, 'INJECTED') === false
    && strpos($contents, 'PWNED') === false
    && strpos($contents, 'OWNED') === false);
check('no NUL or control byte reaches the content stream',
    strpos($contents, "\x00") === false && strpos($contents, "\x1b") === false);
check('the overlong description is bounded, not emitted whole',
    strpos($contents, str_repeat('0041', 400)) === false);

$hostileText = $hostileFile === null ? '' : $hostileFile->text();
check('malformed UTF-8 becomes the replacement character, not a different letter',
    strpos($hostileText, "\u{FFFD}") !== false);

section('E8 — determinism and bounded size');

$first = $renderer->render($standard);
$second = $renderer->render($standard);
$third = (new EmbeddedFontPdfRenderer())->render($standard);
check('the same snapshot renders byte-identically', $first === $second && $first === $third,
    'sha256 ' . substr(hash('sha256', $first), 0, 16));
check('no clock reading enters the file',
    strpos($first, gmdate('D:Y')) === false && strpos($first, 'D:' . gmdate('Ymd')) === false);
check('document dates come from the snapshot',
    strpos($first, 'D:20260728100000+02\'00\'') !== false);

$huge = [];
for ($i = 0; $i < 5000; $i++) {
    $huge[] = [sprintf('Pozycja %04d z bardzo długim opisem produktu kamiennego', $i), 100, 9900, 230000];
}
$hugePdf = $renderer->render(snapshot($huge));
$hugeFile = new PdfFile($hugePdf);
check('a 5000-item order stays within the page cap', count($hugeFile->pageObjects()) <= 30,
    count($hugeFile->pageObjects()) . ' pages');
check('the omitted lines are declared on the document',
    strpos($hugeFile->text(), 'dalszych pozycji nie pokazano') !== false);
check('output size stays bounded', strlen($hugePdf) < 350 * 1024,
    round(strlen($hugePdf) / 1024) . ' KB of a 350 KB ceiling');
check('a typical three-line document is small', strlen($pdf) < 200 * 1024,
    round(strlen($pdf) / 1024) . ' KB');

section('E9 — no remote resources, no network, no traversal');

$sources = [
    'EmbeddedFontPdfRenderer' => $root . '/packages/document-core/src/Rendering/EmbeddedFontPdfRenderer.php',
    'EmbeddedFont' => $root . '/packages/document-core/src/Rendering/Font/EmbeddedFont.php',
    'PageBuilder' => $root . '/packages/document-core/src/Rendering/Pdf/PageBuilder.php',
    'PdfDocumentWriter' => $root . '/packages/document-core/src/Rendering/Pdf/PdfDocumentWriter.php',
];
$network = '/\b(curl_\w+|fsockopen|stream_socket_client|file_get_contents\s*\(\s*[\'"]https?|fopen\s*\(\s*[\'"]https?'
    . '|wp_remote_\w+|socket_create|http_\w+|get_headers|dns_get_record)\s*\(/i';
$networkHits = [];
$readHits = [];
foreach ($sources as $name => $path) {
    $source = (string) file_get_contents($path);
    if (preg_match($network, $source)) {
        $networkHits[] = $name;
    }
    if (preg_match('/\b(file_get_contents|fopen|readfile|include|require|file_put_contents)\s*\(/', $source, $m)) {
        $readHits[] = $name . ':' . $m[1];
    }
}
check('no network call in any renderer class', $networkHits === [],
    $networkHits === [] ? 'clean' : implode(',', $networkHits));
check('the only filesystem access is the font asset in EmbeddedFont',
    $readHits === ['EmbeddedFont:file_get_contents'],
    implode(', ', $readHits));

$rendererSource = (string) file_get_contents($sources['EmbeddedFontPdfRenderer']);
$fontSource = (string) file_get_contents($sources['EmbeddedFont']);
check('the font path is a constant, with no caller-supplied segment',
    strpos($fontSource, "__DIR__ . '/../../../resources/fonts'") !== false
    && preg_match('/\$directory \. \'\/dejavu-sans-\' \. \$style/', $fontSource) === 1);
check('the style is validated against two constants before it reaches the path',
    (bool) preg_match('/if \(\$style !== self::REGULAR && \$style !== self::BOLD\)/', $fontSource));
check('EmbeddedFont cannot be constructed with an arbitrary path',
    (bool) preg_match('/private function __construct/', $fontSource));
check('the font program is checked against a recorded digest before use',
    strpos($fontSource, 'hash_equals') !== false);

check('no URL, remote reference or external resource in the output',
    stripos($pdf, 'http://') === false
    && stripos($pdf, 'https://') === false
    && strpos($pdf, '/URI') === false
    && strpos($pdf, '/Launch') === false
    && strpos($pdf, '/GoToR') === false
    && strpos($pdf, '/EmbeddedFile') === false);
check('no active content in the output',
    strpos($pdf, '/JavaScript') === false
    && strpos($pdf, '/JS') === false
    && strpos($pdf, '/OpenAction') === false
    && strpos($pdf, '/AA') === false
    && strpos($pdf, '/RichMedia') === false
    && strpos($pdf, '/XFA') === false);
check('no external font, image or stylesheet is referenced',
    strpos($pdf, '/Image') === false
    && strpos($pdf, '/XObject') === false
    && substr_count($pdf, '/FontFile2') === 2
    && strpos($pdf, '/FontFile ') === false
    && strpos($pdf, '/FontFile3') === false);
check('no HTML or CSS stage exists in the engine',
    stripos($rendererSource, 'DOMDocument') === false
    && stripos($rendererSource, 'loadHTML') === false
    && strpos($rendererSource, '<style') === false);

section('E10 — glyph outlines are the original ones');

/**
 * Compares a glyph in the subset with the same glyph in the source font.
 *
 * This is the check that answers the question a viewer would otherwise have to
 * answer: renumbering glyph ids rewrites the component indices inside composite
 * glyphs, and a mistake there would put the wrong accent on the wrong letter
 * while every structural check still passed. Simple glyphs must match byte for
 * byte; composites must match everywhere except the indices, whose targets are
 * then compared the same way, recursively.
 */
final class OutlineComparison
{
    /** @var string */
    private $source;
    /** @var string */
    private $subset;
    /** @var int[] */
    private $sourceLoca;
    /** @var int[] */
    private $subsetLoca;
    /** @var int */
    private $sourceGlyf;
    /** @var int */
    private $subsetGlyf;
    /** @var array<int,int> */
    public $sourceCmap;

    public function __construct(string $source, string $subset)
    {
        $this->source = $source;
        $this->subset = $subset;
        [$this->sourceLoca, $this->sourceGlyf, $this->sourceCmap] = self::index($source);
        [$this->subsetLoca, $this->subsetGlyf] = self::index($subset);
    }

    /** @return array{0:int[],1:int,2:array<int,int>} */
    private static function index(string $data): array
    {
        $tables = [];
        $numTables = unpack('n', substr($data, 4, 2))[1];
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + $i * 16;
            $tables[substr($data, $record, 4)] = [
                unpack('N', substr($data, $record + 8, 4))[1],
                unpack('N', substr($data, $record + 12, 4))[1],
            ];
        }
        $numGlyphs = unpack('n', substr($data, $tables['maxp'][0] + 4, 2))[1];
        $format = unpack('n', substr($data, $tables['head'][0] + 50, 2))[1];
        $loca = [];
        for ($i = 0; $i <= $numGlyphs; $i++) {
            $loca[] = $format === 0
                ? unpack('n', substr($data, $tables['loca'][0] + $i * 2, 2))[1] * 2
                : unpack('N', substr($data, $tables['loca'][0] + $i * 4, 4))[1];
        }

        $cmap = [];
        $cmapOffset = $tables['cmap'][0];
        $count = unpack('n', substr($data, $cmapOffset + 2, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $record = $cmapOffset + 4 + $i * 8;
            $platform = unpack('n', substr($data, $record, 2))[1];
            $encoding = unpack('n', substr($data, $record + 2, 2))[1];
            $offset = $cmapOffset + unpack('N', substr($data, $record + 4, 4))[1];
            if ($platform !== 3 || $encoding !== 1 || unpack('n', substr($data, $offset, 2))[1] !== 4) {
                continue;
            }
            $segX2 = unpack('n', substr($data, $offset + 6, 2))[1];
            $segments = intdiv($segX2, 2);
            $endBase = $offset + 14;
            $startBase = $endBase + $segX2 + 2;
            $deltaBase = $startBase + $segX2;
            $rangeBase = $deltaBase + $segX2;
            for ($s = 0; $s < $segments; $s++) {
                $end = unpack('n', substr($data, $endBase + $s * 2, 2))[1];
                $start = unpack('n', substr($data, $startBase + $s * 2, 2))[1];
                $delta = unpack('n', substr($data, $deltaBase + $s * 2, 2))[1];
                $rangeOffset = unpack('n', substr($data, $rangeBase + $s * 2, 2))[1];
                if ($start === 0xFFFF) {
                    continue;
                }
                for ($c = $start; $c <= $end; $c++) {
                    if ($rangeOffset === 0) {
                        $glyph = ($c + $delta) & 0xFFFF;
                    } else {
                        $at = $rangeBase + $s * 2 + $rangeOffset + ($c - $start) * 2;
                        $glyph = unpack('n', substr($data, $at, 2))[1];
                        if ($glyph !== 0) {
                            $glyph = ($glyph + $delta) & 0xFFFF;
                        }
                    }
                    if ($glyph !== 0) {
                        $cmap[$c] = $glyph;
                    }
                }
            }
            break;
        }
        return [$loca, $tables['glyf'][0], $cmap];
    }

    private function glyph(bool $subset, int $id): string
    {
        $loca = $subset ? $this->subsetLoca : $this->sourceLoca;
        $base = $subset ? $this->subsetGlyf : $this->sourceGlyf;
        $data = $subset ? $this->subset : $this->source;
        if (!isset($loca[$id + 1]) || $loca[$id + 1] <= $loca[$id]) {
            return '';
        }
        return substr($data, $base + $loca[$id], $loca[$id + 1] - $loca[$id]);
    }

    /** @return string '' when the outlines agree, otherwise the disagreement */
    public function compare(int $sourceId, int $subsetId, int $depth = 0): string
    {
        if ($depth > 5) {
            return 'composite nesting too deep';
        }
        $a = $this->glyph(false, $sourceId);
        $b = $this->glyph(true, $subsetId);
        if ($a === '' || $b === '') {
            return $a === $b ? '' : 'one side has no outline';
        }
        // The subset pads each glyph to a four-byte boundary.
        $a = rtrim($a, "\x00");
        $b = rtrim($b, "\x00");

        $contours = unpack('n', substr($a, 0, 2))[1];
        if ($contours < 0x8000) {
            return $a === $b ? '' : 'simple outline differs';
        }

        $sourceComponents = self::componentsOf($a);
        $subsetComponents = self::componentsOf($b);
        if (count($sourceComponents) !== count($subsetComponents)) {
            return 'component count differs';
        }
        $strippedSource = $a;
        $strippedSubset = $b;
        foreach (array_keys($sourceComponents) as $offset) {
            $strippedSource = substr_replace($strippedSource, '??', $offset, 2);
        }
        foreach (array_keys($subsetComponents) as $offset) {
            $strippedSubset = substr_replace($strippedSubset, '??', $offset, 2);
        }
        if ($strippedSource !== $strippedSubset) {
            return 'composite placement differs';
        }

        $sourceIds = array_values($sourceComponents);
        $subsetIds = array_values($subsetComponents);
        foreach ($sourceIds as $index => $component) {
            $problem = $this->compare($component, $subsetIds[$index], $depth + 1);
            if ($problem !== '') {
                return 'component ' . $index . ': ' . $problem;
            }
        }
        return '';
    }

    /** @return array<int,int> offset => component glyph id */
    private static function componentsOf(string $glyph): array
    {
        $components = [];
        $position = 10;
        while ($position + 4 <= strlen($glyph)) {
            $flags = unpack('n', substr($glyph, $position, 2))[1];
            $components[$position + 2] = unpack('n', substr($glyph, $position + 2, 2))[1];
            $position += 4;
            $position += ($flags & 0x0001) ? 4 : 2;
            if ($flags & 0x0008) {
                $position += 2;
            } elseif ($flags & 0x0040) {
                $position += 4;
            } elseif ($flags & 0x0080) {
                $position += 8;
            }
            if (!($flags & 0x0020)) {
                break;
            }
        }
        return $components;
    }
}

$sourceDirectory = $argv[1] ?? getenv('PDF_FONT_SOURCE_DIR');
$sourceFile = is_string($sourceDirectory) && $sourceDirectory !== ''
    ? rtrim($sourceDirectory, '/\\') . DIRECTORY_SEPARATOR . 'DejaVuSans.ttf'
    : '';

if ($sourceFile === '' || !is_file($sourceFile)) {
    echo '  SKIP  outline comparison — pass the directory holding DejaVuSans.ttf'
        . ' as the first argument to run it' . PHP_EOL;
} else {
    $comparison = new OutlineComparison((string) file_get_contents($sourceFile), $regularProgram);
    $subsetMap = (array) $recorded['glyphs'];
    $problems = [];
    $compared = 0;
    foreach ($subsetMap as $codepoint => $subsetGlyph) {
        $sourceGlyph = $comparison->sourceCmap[$codepoint] ?? null;
        if ($sourceGlyph === null) {
            $problems[] = sprintf('U+%04X missing from the source font', $codepoint);
            continue;
        }
        $problem = $comparison->compare($sourceGlyph, $subsetGlyph);
        $compared++;
        if ($problem !== '') {
            $problems[] = sprintf('U+%04X: %s', $codepoint, $problem);
        }
    }
    check('every subset outline is identical to the source font outline',
        $problems === [],
        $problems === []
            ? $compared . ' glyphs compared, including composites and their components'
            : implode('; ', array_slice($problems, 0, 5)));
}

section('E11 — delivery posture: admin sandbox delivery only');

// The renderer is now reachable from the admin preview. That is a deliberate
// change and it narrows, rather than removes, the invariant: exactly one place
// may construct a renderer, and exactly one explicit admin place may construct
// the local SandboxMailer/DeliverDocument pair. No order hook may reach either.
$rendererWiring = [];
$mailerWiring = [];
$deliveryWiring = [];
foreach (glob($root . '/packages/*/src/*.php') as $path) {
    $source = (string) file_get_contents($path);
    if (preg_match('/new\s+(EmbeddedFontPdfRenderer|BasicPdfRenderer)\s*\(/', $source, $match)) {
        $rendererWiring[] = basename($path) . ' -> ' . $match[1];
    }
    if (preg_match('/new\s+(SandboxMailer)\s*\(/', $source, $match)) {
        $mailerWiring[] = basename($path) . ' -> ' . $match[1];
    }
    if (preg_match('/new\s+DeliverDocument\s*\(/', $source)) {
        $deliveryWiring[] = basename($path);
    }
}

check('a PDF renderer is constructed in exactly one place',
    $rendererWiring === ['AdminController.php -> EmbeddedFontPdfRenderer'],
    $rendererWiring === [] ? 'nothing wired' : implode(', ', $rendererWiring));
check('only the admin controller constructs the sandbox mailer',
    $mailerWiring === ['AdminController.php -> SandboxMailer'],
    $mailerWiring === [] ? 'nothing wired' : implode(', ', $mailerWiring));
check('only the admin controller constructs the delivery use case',
    $deliveryWiring === ['AdminController.php'],
    $deliveryWiring === [] ? 'nothing wired' : implode(', ', $deliveryWiring));

$controller = (string) file_get_contents($root . '/packages/woocommerce/src/AdminController.php');
$plugin = (string) file_get_contents($root . '/packages/woocommerce/src/Plugin.php');

check('the renderer is constructed only inside the preview factory',
    substr_count($controller, 'new EmbeddedFontPdfRenderer(') === 1
    && (bool) preg_match('/private static function pdfRenderer\(\).*?new EmbeddedFontPdfRenderer\(/s', $controller));
check('sandbox delivery is registered on admin_post only and not on the order hook',
    strpos($controller, "add_action('admin_post_commerce_documents_sandbox_email'") !== false
    && strpos($plugin, 'sandboxEmail') === false
    && preg_match('/add_action\(\s*[\'\"]woocommerce_[^\'\"]*[\'\"]\s*,\s*\[self::class, [\'\"]sandboxEmail/', $controller) === 0);
check('sandbox delivery is explicitly local and has no transport call',
    strpos($controller, "new SandboxMailer(self::sandboxMailDirectory(), 'sandbox@example.invalid')") !== false
    && strpos($controller, "'document.sandbox_stored'") !== false
    && preg_match('/\b(wp_mail|mail|fsockopen|curl_\w+|wp_remote_\w+)\s*\(/', $controller) === 0);
check('the preview is registered on admin_post only, never on an order hook',
    strpos($controller, "add_action('admin_post_commerce_documents_preview_pdf'") !== false
    && preg_match('/add_action\(\s*[\'"]woocommerce_[^\'"]*[\'"]\s*,\s*\[self::class, [\'"]previewPdf/', $controller) === 0
    && strpos($plugin, 'previewPdf') === false);
check('the preview requires the capability and a nonce bound to the document',
    strpos($controller, "public static function previewPdf(): void") !== false
    && strpos($controller, "if (!current_user_can('manage_woocommerce'))") !== false
    && strpos($controller, "check_admin_referer('commerce_documents_' . \$nonceAction . '_' . \$documentId)") !== false);
check('the preview sends the document to the browser and nowhere else',
    preg_match('/\b(wp_mail|mail|fsockopen|curl_\w+|wp_remote_\w+|file_put_contents)\s*\(/', $controller) === 0,
    'no transport and no write in the controller');
check('the logo provider is the WordPress one, constructed without arguments',
    strpos($controller, 'new WordPressLogoProvider()') !== false);

section('E13 — no two pieces of text share the same space');

/**
 * Finds text that collides on the page.
 *
 * Everything else in this harness reads the document as data; this reads it as a
 * *layout*. It recovers the position, font, size and glyphs of every text run,
 * measures each run with the same font metrics the renderer used, and checks
 * that runs sharing a baseline keep a gap between them.
 *
 * This exists because two columns were printing on top of each other — a long
 * item description running into the quantity beside it, and the VAT summary's
 * gross column running into its tax column — while every structural and content
 * check passed. Nothing that reads a PDF as data can see that; only a rasteriser
 * or an arithmetic check like this one can.
 *
 * @param array<string, EmbeddedFont> $fonts resource name => font
 * @return string[] descriptions of each collision
 */
function collisions(string $content, array $fonts, float $minimumGap = 2.0): array
{
    preg_match_all(
        '#BT\s*/(F\d+) ([\d.]+) Tf\s*(-?[\d.]+) (-?[\d.]+) Td\s*<([0-9A-Fa-f]*)> Tj\s*ET#',
        $content,
        $runs,
        PREG_SET_ORDER
    );

    $lines = [];
    foreach ($runs as $run) {
        $font = $fonts[$run[1]] ?? null;
        if ($font === null) {
            continue;
        }
        $size = (float) $run[2];
        $x = (float) $run[3];
        $y = (float) $run[4];

        $widths = $font->widths();
        $width = 0.0;
        foreach (str_split($run[5], 4) as $glyphHex) {
            if (strlen($glyphHex) === 4) {
                $width += ($widths[hexdec($glyphHex)] ?? 0) * $size / 1000;
            }
        }

        $text = '';
        foreach ($font->reverseMap() as $glyph => $codepoint) {
            unset($glyph, $codepoint);
            break;
        }
        $key = number_format($y, 2, '.', '');
        $lines[$key][] = ['x' => $x, 'end' => $x + $width, 'hex' => $run[5], 'size' => $size];
    }

    $problems = [];
    foreach ($lines as $y => $runsOnLine) {
        usort($runsOnLine, static function (array $a, array $b): int {
            return $a['x'] <=> $b['x'];
        });
        $count = count($runsOnLine);
        for ($i = 1; $i < $count; $i++) {
            $gap = $runsOnLine[$i]['x'] - $runsOnLine[$i - 1]['end'];
            if ($gap < $minimumGap) {
                $problems[] = sprintf(
                    'y=%s: run ending at %.2f is %.2f pt from the next starting at %.2f',
                    $y,
                    $runsOnLine[$i - 1]['end'],
                    $gap,
                    $runsOnLine[$i]['x']
                );
            }
        }
    }
    return $problems;
}

$layoutFonts = ['F1' => EmbeddedFont::regular(), 'F2' => EmbeddedFont::bold()];

$layoutCases = [
    'the standard document' => $pdf,
    'the paginated document' => $longPdf,
    'the hostile-input document' => $hostilePdf,
    'a document with long Polish descriptions' => $renderer->render(snapshot(
        [
            ['Płyta granitowa Nero Assoluto, polerowana 60×30×2 cm', 250, 18900, 230000],
            ['Bardzo długa nazwa produktu kamiennego z dodatkowymi parametrami technicznymi', 123456, 1234567, 230000],
            ['Krótka', 1, 1, 0],
        ],
        ['order_number' => '4242', 'payment_method' => 'cod', 'payment_confirmed' => 'no']
    )),
    // A single line worth about a million zloty — far above any real order here,
    // and the point at which the shrink-to-fit rule is doing the work.
    'a document with implausibly large amounts' => $renderer->render(snapshot(
        [['Pozycja', 9999, 999999, 230000]],
        ['order_number' => '999999999']
    )),
];

foreach ($layoutCases as $label => $document) {
    $file = new PdfFile($document);
    $problems = [];
    foreach ($file->pageObjects() as $page) {
        foreach (collisions($file->contentOfPage($page), $layoutFonts) as $problem) {
            $problems[] = $problem;
        }
    }
    check('no text collides in ' . $label, $problems === [],
        $problems === [] ? 'clear' : implode(' | ', array_slice($problems, 0, 3)));
}

section('E12 — M10 follow-up: legacy types readable, not issuable');

$readable = [];
$issuable = [];
foreach (DocumentType::values() as $value) {
    $type = DocumentType::fromString($value);
    $readable[] = $value;
    if ($type->isIssuable()) {
        $issuable[] = $value;
    }
}
check('every historical and internal type is constructible, so historical rows stay readable',
    count($readable) === 8, implode(', ', $readable));
check('only internal confirmations and correction may be issued',
    $issuable === ['order_confirmation', 'payment_confirmation', 'correction'], implode(', ', $issuable));

$refused = [];
foreach (['invoice', 'proforma', 'receipt', 'credit_note', 'quote'] as $legacy) {
    try {
        DocumentType::fromString($legacy)->assertIssuable();
    } catch (Throwable $exception) {
        $refused[] = $legacy;
    }
}
check('issuing a legacy fiscal type is refused', count($refused) === 5, implode(', ', $refused));
check('the refusal sits in the application service, not only in the order policy',
    strpos(
        (string) file_get_contents($root . '/packages/document-core/src/Application/GenerateDocument.php'),
        'assertIssuable()'
    ) !== false);

// ---------------------------------------------------------------------------

echo PHP_EOL . sprintf('checks=%d pass=%d fail=%d', $pass + $fail, $pass, $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
