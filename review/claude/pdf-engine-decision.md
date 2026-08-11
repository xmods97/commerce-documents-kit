# PDF engine decision

Branch: `agent/geward-document-module-stage0`
Baseline: `14d1236` (review fixes), `91798ce` (review report)
Companion documents: `security-findings.md`, `test-results.md`, `review-report.md`
Evidence: `evidence/verify-pdf-engine-output.txt`

**Decision: build the engine into the package — a data-to-PDF writer with an embedded TrueType subset — rather than adopt Dompdf or mPDF.**

Implemented as `Xmods\CommerceDocuments\Rendering\EmbeddedFontPdfRenderer` behind the existing `PdfRenderer` interface. 73 checks pass, exit 0.

---

## What was actually available

The blocker recorded in the previous pass said the choice needed a Composer dependency and therefore network access. That turned out to be only half true — three routes existed, and they are not equivalent.

### A. Install Dompdf 3.x or mPDF 8.x from Packagist

Not possible in this pass: network installs are out of scope, and `composer.lock` could not be regenerated offline, so any hand-edit of `composer.json` would leave the lock inconsistent for everyone else.

Set aside rather than rejected — it stays the reasonable route if the engine ever has to render designed HTML templates.

### B. Take a copy already on this machine

Both engines are present in the site backup:

| Library | Version | Path |
|---|---|---|
| dompdf/dompdf | **1.0.2** | `Backup/geward.pl/…/print-invoices-packing-slip-labels-for-woocommerce/includes/vendor/` |
| mpdf/mpdf | 8.x | `Backup/geward.pl/…/mpdf-addon-for-pdf-invoices/vendor/` |

**Rejected.** Not because vendoring is wrong in principle, but because of what these particular copies are:

- **Dompdf 1.0.2 is years behind the advisories that matter.** The remote-font and URI-validation issues fixed across 1.2.1, 2.0.x and 3.x are exactly the class of defect this document is meant to avoid, and 1.0.2 predates all of them. The previous review named the legacy WebToffee plugin's chain as the cautionary example — this is a copy taken from that same family of plugins.
- **Provenance cannot be verified offline.** These are files extracted from a third-party plugin's vendor directory, not a Composer install with hashes. There is no way here to prove the bytes match an upstream release. Copying unverifiable third-party code into the tree to fix a rendering defect is a worse trade than the defect.
- **Both bring a large parser surface** — HTML, CSS, SVG, image loading, URI resolution, font caching — nearly all of it unused by a fixed invoice layout, and all of it reachable from buyer-controlled strings.
- **Both add runtime requirements** (`ext-mbstring`, `ext-gd` for mPDF) that the package deliberately does not declare, plus their own dependency trees.

### C. Write the engine (chosen)

The task allows a dependency-free renderer only if it genuinely supports Polish Unicode, line items, totals, pagination and correct PDF output. It does — and the reason it can is that the hard part is not the PDF, it is the font. That part is solved offline, once, at build time.

---

## What was built

| Piece | File | Role |
|---|---|---|
| Subsetting tool | `tools/build-pdf-font.php` | Build-time only. Reads a full TrueType font, keeps the 339 codepoints the templates need plus every component glyph the kept composites refer to, renumbers the glyph ids and writes the subset with its metrics. |
| Font assets | `packages/document-core/resources/fonts/dejavu-sans-{regular,bold}.ttf` | 53 012 B and 51 780 B, 365 glyphs each. |
| Generated metrics | `…/dejavu-sans-{regular,bold}.php` | Codepoint → glyph id, glyph widths, vertical metrics, and the SHA-256 of the font program. |
| Font access | `Rendering/Font/EmbeddedFont.php` | Reads the two committed files, verifies the program against the recorded digest, maps text to glyph ids. **No font parsing at request time.** |
| PDF file assembly | `Rendering/Pdf/PdfDocumentWriter.php` | Indirect objects, cross-reference table, trailer. |
| Layout | `Rendering/Pdf/PageBuilder.php` | Geometry, measurement, wrapping, page breaks, running footer. |
| Engine | `Rendering/EmbeddedFontPdfRenderer.php` | The `PdfRenderer` implementation. |

The division matters: the only code that has ever parsed a font runs offline in `tools/`, and the only code that runs in a web request reads two fixed files and copies bytes.

### Font and licence

DejaVu Sans, taken from the local Dompdf bundle. Its licence — Bitstream Vera plus public-domain DejaVu changes — permits redistribution, modification and embedding; the modified-name restriction applies to the words "Bitstream" and "Vera", which the DejaVu naming already satisfies. The licence text is extracted from the font's own `name` table (id 13) into `resources/fonts/LICENCE-DejaVu.txt`, and the `name` table is deliberately kept inside each subset so the notice travels with every embedded copy.

Only the font *file* comes from the backup. No plugin code was copied, and the main Geward repository was not modified.

---

## Why this is production-ready and the previous renderer was not

| Requirement | `BasicPdfRenderer` (previous) | `EmbeddedFontPdfRenderer` |
|---|---|---|
| Polish Unicode | Glyph *names* in `/Differences`, resolved by whatever Helvetica the viewer has | Glyph *outlines* embedded in the file |
| Line items | Yes | Yes, with per-line VAT rate and a VAT summary grouped by rate |
| Totals | Yes | Yes |
| Text wrapping | No | Yes, with character-level breaking for unbreakable strings |
| Pagination | No — truncated past ~24 rows | Yes, with a repeated table header and a running footer |
| Valid PDF | Yes | Yes, verified by reading it back through its own xref |
| Injection surface | Literal strings, escaped by hand | Hex glyph strings — delimiters cannot be expressed |

The decisive property is the first one. A `/Differences` array is a *request* that the viewer supply a glyph; an embedded subset is the glyph. `Zażółć gęślą jaźń` now renders from bytes inside the document.

---

## Security review of the chosen engine

Full detail and per-check evidence in `security-findings.md`. In short:

- **No remote resources are disabled, because none can be requested.** There is no URL handling, no image loader, no `@font-face`, no stylesheet resolution — no code that takes a reference and fetches it. Verified by source scan and by asserting the output contains no `/URI`, `/Launch`, `/GoToR`, `/EmbeddedFile`, `/JavaScript`, `/OpenAction`, `/AA`, `/XObject` or `http(s)://`.
- **No filesystem traversal.** One `file_get_contents`, on `__DIR__ . '/../../../resources/fonts/dejavu-sans-' . $style . '.ttf'`, where `$style` is checked against two class constants before it reaches the path. The constructor is private; there is no public API that accepts a path.
- **Input handling.** Snapshot text never becomes PDF syntax: it is converted to glyph ids and written as hex. Control bytes are dropped, malformed UTF-8 becomes U+FFFD rather than being reinterpreted, newlines collapse to spaces, and each field has a length cap.
- **Deterministic output.** No clock, no random, no compression. The document information dictionary is derived from the snapshot's own dates. Two renders of the same snapshot are byte-identical.
- **Bounded size.** 300 line items, 30 pages, per-field length caps. A 5 000-item order produces 11 pages and 309 KB, and says on the document how many lines were omitted.
- **Asset integrity.** The font program is checked against a SHA-256 recorded beside it and rendering fails closed on mismatch.

### The check that mattered most

Renumbering glyph ids rewrites the component indices inside composite glyphs — and every Polish diacritic is a composite. A mistake there would put the wrong accent on the wrong letter while every structural check still passed, and it would only be visible to a human looking at the page.

So the harness compares each of the 339 glyphs in the embedded subset against the same glyph in the source DejaVu Sans: simple outlines byte for byte, composites everywhere except the indices, whose targets are then compared the same way, recursively. **All 339 match.** The shapes on the page are DejaVu's, unmodified.

---

## What this does not settle

- **No visual confirmation.** No PDF rasteriser is available offline (no Ghostscript, poppler, qpdf or mutool on this machine). The document was opened in the local browser's PDF viewer, which loaded it and read its title from the info dictionary — that shows PDFium accepts the file, not that the page looks right. Everything else is proven by reading the file back; **one human should still open `evidence/pdf-engine-standard.pdf` and look at it.** That is a two-minute check and it is the only open item on the engine itself.
- **No logo and no layout fidelity against the Fakturownia reference.** The layout is clean and complete, not a visual match. Adding a logo means an image XObject, which is a new decision with its own surface, and it should be taken deliberately rather than folded into this one.
- **Delivery is still not wired.** Nothing in the plugin constructs a renderer or a mailer. That remains correct until the end-to-end sandbox stage is approved separately.

## If this turns out to be the wrong call

The engine sits behind `PdfRenderer` and is constructed nowhere in plugin code. Replacing it with Dompdf later is one adapter class and one wiring line; nothing else in the package knows how a PDF is made. The build tool and the font assets stay useful either way.
