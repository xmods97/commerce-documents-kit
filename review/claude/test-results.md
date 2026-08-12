# Test results — Commerce Documents Kit review

Branch `agent/geward-document-module-stage0`, range `20cd191..83950ae`.
PHP 8.3.30 (cli, ZTS, VC++ 2019 x64) from the local Laragon toolchain.

No network calls, no database, no WordPress bootstrap, no email transport, no migrations, no deployment. Nothing outside `review/claude/` was created or modified.

---

## 1. PHP lint

```
php -l over every *.php outside vendor/
checked=76  failed=0
```

Clean. Note that lint only proves parseability — it does not exercise the `wpdb` binding defect in C1, which is a runtime data problem.

## 2. PHPUnit — not run

`vendor/` is absent (correctly git-ignored) and `phpunit.xml.dist` bootstraps `vendor/autoload.php`. Running the suite would require `composer install`, which needs network access and is out of scope for this review. **The suite was not executed and I make no claim about its pass/fail state.**

One structural observation from reading the tests rather than running them: `FakeWpdb::insert()` (`tests/unit/WpdbPersistenceTest.php:129-137`) accepts the `$formats` argument and discards it. The persistence test therefore cannot detect C1, and would keep passing with the defect in place.

## 3. Read-only verification harness

`review/claude/verify.php` — loads the library classes through a local autoloader without modifying them. Full captured output: `review/claude/evidence/verify-output.txt`. Exit code 0.

### T1 — wpdb insert format misalignment → C1 **CONFIRMED**

```
cipher payload length: 1377
value stored when the column is bound with %d: '0'
RESULT: decrypt of the persisted value fails -> InvalidArgumentException: Encrypted payload version is invalid.
```

A 1377-byte ciphertext envelope collapses to `'0'` under `%d` binding. Reading it back throws. Since `save()` also writes `snapshot = ''`, there is no fallback copy.

### T2 — AES-GCM AAD binding and tamper detection → **PASS**

```
AAD swap:  detected -> Document decryption or integrity verification failed.
bit flip:  detected -> Document decryption or integrity verification failed.
```

Ciphertext cannot be moved between documents, and a single flipped bit is rejected. The cipher layer itself is correct.

### T3 — legacy plaintext fallback → H1 **CONFIRMED**

```
plaintext snapshot column round-trips with no MAC check: buyer=Attacker Supplied
content_hash recomputed from data, not verified against stored column
```

Arbitrary JSON in the `snapshot` column is accepted and reconstructed into a valid `DocumentSnapshot`. `contentHash()` is recomputed from whatever was supplied, so it always matches itself; the stored `content_hash` column is never consulted on read.

### T4 — BasicPdfRenderer → H4 **CONFIRMED** (container valid, content unusable)

```
bytes: 838 | header: %PDF-1.4
xref size declared: 6 | entries: 5 | all offsets resolve: yes
startxref: 655 -> xref
text shown in PDF:
  | ORDER_CONFIRMATION ORDER_CONFIRMATION/2026/000001
  | Issued: 2026-07-28T10:00:00+00:00
  | Seller: GEWARD
  | Buyer: Za???????? g????l?? ja????
  | Nieoplacone - platnosc przy odbiorze
  | Gross: 14429 PLN
items rendered: NO — line items are absent from the PDF
```

Structure is sound: every xref offset resolves to the object it claims, `startxref` points at `xref`, `%%EOF` present, `(`/`)`/`\` escaped correctly. Content is not: `Zażółć gęślą jaźń` is destroyed byte-wise, and the product line (`Slab`, quantity, unit price, tax) never appears.

Sample: `review/claude/evidence/basic-renderer-sample.pdf`.

### T5 — audit hash chain under concurrency → H5 **CONFIRMED**

```
sequential chain ok: yes
two writers reading the same tip produce two valid successors: yes (chain forks silently)
```

`AuditEventHash::next()` is deterministic and correct in isolation. The defect is in `WpdbEventLogger::record()`, which reads the chain tip with an unlocked `SELECT` and inserts without a transaction.

### T6 — SandboxMailer file handling → M5, M6, M7 **CONFIRMED**

```
multi-line body: REJECTED  (a normal email body cannot contain a newline)
file: doc_review_0001-20260811140350.eml | perms: 0666 | bytes: 1563
MIME-Version header present: NO
From header present: NO
```

The emitted `.eml` is well-formed enough to read but lacks `MIME-Version` and `From`. File mode follows the process umask (`0666` measured here); the `0700` directory is the only thing limiting exposure, and only on POSIX.

Sample: `review/claude/evidence/sandbox-mail-*/`.

### T7 — header injection and traversal → **PASS**

```
CRLF in subject   -> rejected
CRLF in recipient -> rejected
CRLF in body      -> rejected
```

All three vectors blocked. `document_id` is separately validated against `^[A-Za-z0-9_-]+$` before it reaches the filename, so `../` cannot enter the path.

### T8 — filename collision → M4 **CONFIRMED**

```
files before=1 after=1 (two sends in the same second)
```

Two distinct sends within one second leave a single `.eml`. The second silently overwrites the first, losing delivery evidence.

### T9 — snapshot metadata validation → **PASS**

```
["Ok"]             -> rejected (uppercase initial)
["bad key"]        -> rejected (space)
["payment_method"] -> rejected (array value)
["UPPER"]          -> rejected
```

The key pattern and scalar-only rule are enforced exactly as written.

## 4. Static checks

| Check | Result |
|---|---|
| Key material in tracked files | none — only the two constant *names* appear, in `ConfigKeyProvider.php` and `docs/geward-v1-baseline.md` |
| Key material in git history (`log --all -S`) | none |
| base64-shaped 32-byte literals in tree | none |
| `FeaturesUtil` / `declare_compatibility` / `before_woocommerce_init` | **absent** — no HPOS declaration (M8) |
| `SandboxMailer` / `BasicPdfRenderer` / `DeliverDocument` referenced from plugin code | **no** — test-only (`tests/unit/RenderingAndDeliveryTest.php`) |
| `wp_mail`, sockets, HTTP clients in delivery classes | none |
| `.gitignore` covers `*.eml` | **no** (M7) |
| Working tree modified outside `review/claude/` | no |

## 5. Fix pass — verification run

After the fixes were applied, the checks below were re-run. Everything in sections 1–4 above describes the **pre-fix** state and is kept as the baseline.

### PHP lint

```
php -l over every *.php outside vendor/
checked=83  failed=0
```

### Focused runtime checks

`review/claude/verify-fixes.php` — **59 checks, 59 pass, 0 fail, exit 0**. Full output: `evidence/verify-fixes-output.txt`.

| Group | Checks | Result |
|---|---|---|
| C1 — encrypted snapshot survives wpdb column formats | 3 | pass (cipher length 1613 preserved, `encryption_version` an int, round-trip hash matches) |
| H1 — legacy plaintext fallback is authenticated | 5 | pass (valid row accepted; tampered body, missing hash, wrong hash all rejected; removal boundary schema 5 > current 4) |
| H2/H3 — order confirmation only, and only when paid | 12 | pass (no document for pending / cancelled / failed / refunded / checkout-draft / processing+COD; paid order → `order_confirmation`; COD only when enrolled; `ConfigurableStatusPolicy` no longer constructed at runtime) |
| H5 — audit chain | 6 | pass (intact chain verifies, altered event caught at position 2, deleted event breaks the link, unique index present, per-document tip, retry on contention) |
| H6 / M9 — supersession and migration safety | 8 | pass (both columns present, correction token required, conditional claim, events on both documents, rollback plan emitted with prefix validation) |
| H4 — PDF content | 12 | pass (valid header/trailer, `/Differences` with `/aogonek`, no `?` substitution, both line items, totals `123,00`, unpaid COD notice, all 7 xref offsets resolve, `startxref` correct, long multibyte name truncated safely, no `ext-mbstring`, delimiters escaped, control bytes neutralised) |
| M4–M7 — sandbox mailer | 10 | pass (two sends in one second → two files, `MIME-Version` / `From` / `Message-ID` present, RFC 2047 subject, multi-line body accepted, 3/3 injection vectors rejected, no transport invoked, exclusive create) |
| M8 — HPOS | 3 | pass (`custom_order_tables` declared on `before_woocommerce_init`, description corrected) |

Sample output of the fixed renderer: `evidence/fixed-renderer-sample.pdf` (2687 bytes, 7 objects). Compare with the pre-fix `evidence/basic-renderer-sample.pdf` (838 bytes, 5 objects, no line items).

### Two checks that initially failed — both were faults in the harness, not the code

- **`file mode restricted`** reported `0666`. PHP's `chmod()` on Windows only toggles the read-only bit, so a POSIX mode is not observable on this machine. The check now asserts the `chmod($path, 0600)` call is made and records the platform limitation explicitly rather than claiming a guarantee it cannot demonstrate here.
- **`no network transport`** failed because the substring `wp_mail` appears in the class docblock sentence stating that the class never calls it. The check now matches invocations (`/\b(wp_mail|fsockopen|…)\s*\(/`) instead of bare substrings.

Neither indicated a defect in the runtime code. Both are recorded here rather than silently corrected.

### PHPUnit — still not run

`vendor/` remains absent and installing it needs network access. Tests were **written and lint-checked but not executed**: `tests/unit/ReviewFixesTest.php` (new, 8 test methods), plus updates to `WpdbPersistenceTest` (3 new methods, `FakeWpdb` now applies `$formats`) and `AdminSettingsTest` (2 new methods, settings shape updated). I make no claim that the suite passes — the equivalent assertions were exercised through `verify-fixes.php`, which does run.

## 6. PDF engine pass — verification run

Sections 1–5 describe the review and the fix pass and are kept as the baseline. This section covers the production PDF engine.

### PHP lint

```
php -l over every *.php outside vendor/
checked=92  failed=0
```

### Focused runtime checks

`review/claude/verify-pdf-engine.php` — **85 checks, 85 pass, 0 fail, exit 0**. Full output: `evidence/verify-pdf-engine-output.txt`.

*(Originally 73. E11 grew from one assertion to eight when the renderer was wired to the admin preview, and E13 — the layout collision detector — added five.)*

The harness renders documents with `EmbeddedFontPdfRenderer` and then reads them back: it walks the cross-reference table, extracts the streams, and recovers the page text **through the document's own `/ToUnicode` CMap**. That is what makes the Polish assertions meaningful — they prove the glyph ids written to the page correspond to the characters that went in, rather than proving a string appears somewhere in the file.

| Group | Checks | Result |
|---|---|---|
| E1 — PDF structure | 6 | pass (header, `%%EOF`, every xref offset lands on its object, `/Root` and `/Info` present, `/Count` matches `/Kids`, every stream `/Length` reaches its `endstream`) |
| E2 — embedded font | 11 | pass (two font programs embedded, Identity-H with Identity CIDToGIDMap, no `/Differences`, subset tag present, both subsets structurally consistent at 365 glyphs, digest matches, mismatched digest rejected, embedded bytes identical to the committed asset, 18/18 Polish letters distinct and non-zero with outlines) |
| E3 — Polish round trip | 6 | pass (`Zażółć Gęślą Jaźń Sp. z o.o.`, `ul. Świętokrzyska 5/7`, `Kraków`, Polish and English labels, `Müller & Sønner`, no `?` and no U+FFFD) |
| E4 — items, taxes, totals | 9 | pass (all three descriptions, quantities with units, unit prices, per-line rates `23%`/`8%`, net `666,00`, tax `142,16`, gross `808,16 PLN`, VAT summary, printed totals equal the snapshot arithmetic) |
| E5 — COD and corrections | 4 | pass (unpaid notice present when unconfirmed, absent when paid; corrected document number and Polish correction reason printed) |
| E6 — pagination | 7 | pass (140 items → 6 pages, `/Count` matches, header repeats on 5 continuation pages, footer on every page, 140/140 items present, totals still printed) |
| E7 — hostile input | 7 | pass (structurally valid output; **no `(` anywhere in any content stream**; every `Tj` preceded by a hex string; no injected token; no NUL or ESC; 20 000-character description bounded; malformed UTF-8 → U+FFFD) |
| E8 — determinism and size | 7 | pass (three renders byte-identical, no clock reading, dates from the snapshot, 5 000 items → 11 pages / 309 KB of a 350 KB ceiling, omissions declared on the document, normal document 123 KB) |
| E9 — no remote access | 10 | pass (no network call, single filesystem read, constant font path, style validated against two constants, private constructor, digest check, no URL/active content/external resource, no HTML or CSS stage) |
| E10 — glyph outlines | 1 | pass (**339/339 subset outlines identical to the source DejaVu Sans**, composites compared recursively through their components) |
| E11 — delivery posture | 8 | pass (exactly one renderer construction, in `AdminController`, inside the preview factory; no mailer and no `DeliverDocument` anywhere; `admin_post` registration only; capability plus per-document nonce; no transport in the controller; the WordPress logo provider constructed with no arguments) |
| E13 — no overlapping text | 5 | pass (the standard, paginated, hostile-input, long-Polish-description and implausibly-large-amount documents all lay out with at least 2 pt between neighbouring runs on a baseline) |
| E12 — M10 follow-up | 4 | pass (7/7 legacy types still constructible for reading, only `order_confirmation` and `correction` issuable, 5/5 legacy types refused at issue, refusal sits in `GenerateDocument`) |

E10 runs only when the directory holding the source `DejaVuSans.ttf` is passed as the first argument (or via `PDF_FONT_SOURCE_DIR`); it reports `SKIP` otherwise so the harness stays runnable without the backup tree. It was run with the source present:

```
php review/claude/verify-pdf-engine.php "<…>/dompdf/lib/fonts"
checks=73 pass=73 fail=0
```

### Regression

`review/claude/verify-fixes.php` re-run after the engine changes — **59/59 pass, exit 0**, unchanged.

### What could not be verified here

- **No PDF rasteriser exists on this machine** — no Ghostscript, poppler, qpdf or mutool. The rendered document was opened in the local browser's PDF viewer, which loaded it and read `ORDER_CONFIRMATION/2026/000042` from its info dictionary; that shows PDFium accepts the file, not that the page looks right. A single human look at `evidence/pdf-engine-standard.pdf` is the remaining check.
- **PHPUnit still not run.** `vendor/` remains absent. `tests/unit/EmbeddedFontPdfRendererTest.php` (12 test methods) and the new `GenerateDocumentTest::testLegacyFiscalTypesStayReadableButCannotBeIssued()` are written and lint-clean but **not executed**; I make no claim about the suite's pass/fail state. The equivalent assertions run in `verify-pdf-engine.php`, which does execute. The individual behaviours the test file depends on that are not covered by the harness — glyph mapping for Polish text, for characters outside the subset, and for control characters — were exercised directly and returned `[197,316,199,257,282]`, three identical replacement glyphs, and an empty array respectively.

### Evidence

| File | Contents |
|---|---|
| `evidence/verify-pdf-engine-output.txt` | Full harness output |
| `evidence/pdf-engine-standard.txt` | Text recovered from the standard document through its own `/ToUnicode` CMap |
| `evidence/pdf-engine-standard.pdf` | Three-line Polish document with the COD notice (git-ignored, as `*.pdf` is) |
| `evidence/pdf-engine-paginated.pdf` | 140 items across 6 pages |
| `evidence/pdf-engine-hostile.pdf` | The injection fixture |

## 7. Logo pass — verification run

### PHP lint

```
php -l over every *.php outside vendor/
checked=100  failed=0
```

### Focused runtime checks

`review/claude/verify-logo-security.php` — **69 checks, 69 pass, 0 fail, exit 0**. Full output: `evidence/verify-logo-security-output.txt`.

The harness builds a throwaway uploads directory under the system temp path, points a fake media library at it, and drives the real `WordPressLogoProvider` and `RasterImage` through it. It was run with a copy of the site's actual logo:

```
php review/claude/verify-logo-security.php "<…>/uploads/2023/04/Logo-600x249.png"
checks=64 pass=64 fail=0
```

| Group | Checks | Result |
|---|---|---|
| L1 — the image parser | 14 | pass (real GEWARD logo 600×249 accepted; RGBA transparency becomes a soft mask; opaque PNG carries none; baseline JPEG passed through as `/DCTDecode`; refused: SVG, SVG renamed to `.png`, PHP renamed to `.png`, empty input, a data URL, an http URL, oversized input, a decompression bomb, a 2600×2600 image, a truncated PNG, a PNG with a broken chunk CRC) |
| L2 — the resolver | 21 | pass (no Custom Logo → no logo, no error; valid local PNG and JPEG accepted; refused: SVG attachment, disguised SVG, disguised PHP, `.php` file, `https://`, `//host/`, `data:`, `php://`, `file://`, traversal out of uploads, traversal at `wp-config.php`, absolute path outside uploads, NUL byte in the path, missing file, oversized file, oversized image, decompression bomb; a wide enough size variant is preferred; a variant filename containing a path is skipped, not resolved) |
| L2b — the whole logo, never a crop | 5 | pass (against the real attachment shapes: a set containing both scaled copies and hard crops yields the proportional 480×199, never the 480×480 square crop that is the emblem with the wordmark cut off; a crops-only set falls back to the 1200×497 original; with no recorded original dimensions no variant is trusted) |
| L3 — the logo in the PDF | 13 | pass (one image resource declared and placed; dimensions and colour space correct; transparency travels as an 8-bit greyscale `/SMask`; document structurally valid; no active content; nothing remote; document text unchanged; table starts below the logo; a hostile filename embeds normally and never appears in the output) |
| L4 — the fallback | 7 | pass (null logo → no `/XObject` at all; identical to rendering with no provider; still valid; same text; rendering stays deterministic; the logo adds 38 KB) |
| L5 — the embedded pixels | 6 | pass (colour stream inflates to exactly 3 bytes per pixel, mask to exactly 1; the mask is not uniform; **1 980 sampled pixels match the source exactly, for both an opaque and a transparent logo**; both images written back out for inspection) |

### What was checked visually

This is the part the previous pass could not do at all, and it is now partly possible — for the image, not for the page.

- **Looked at:** `evidence/pdf-logo-extracted.png`, which is the image **taken back out of the finished PDF** by inflating its XObject stream and rewrapping it as a PNG. It shows the GEWARD wordmark, navy on white, correct proportions, no distortion, no colour shift, no channel swap. That confirms the whole path — resolver, parser, XObject, colour space — end to end.
- **Also confirmed:** both `evidence/pdf-logo-with.pdf` and `evidence/pdf-logo-without.pdf` load in the local browser's PDF viewer, which reads `ORDER_CONFIRMATION/2026/000042` from the info dictionary. PDFium therefore accepts a document carrying an image XObject.
- **Not looked at:** the rendered page. There is still no PDF rasteriser offline, so nobody has seen the logo *in position* on the invoice. Its placement is verified numerically — a fixed 150×46 pt box, right-aligned to the margin, aspect ratio preserved, with the following block pushed below the logo's lower edge — and the recovered page text confirms the table header and parties are still where they belong. A human opening `evidence/pdf-logo-with.pdf` remains the outstanding check.
- **Noticed while looking, and it led to a fix:** the evidence image was the bare navy wordmark with no emblem. Two separate causes, one of them a defect.
  1. **The offline material is out of date.** The only logo files available here come from a 2023 site backup taken before the branding changed; the current lockup — emblem plus wordmark — exists only in the live media library, and fetching it would be exactly the external request this design refuses to make. Nothing is hardcoded, so a document generated on the site itself carries whatever the Custom Logo currently is.
  2. **The variant preference could have picked a crop.** WordPress generates scaled copies *and* hard crops from one upload, and the real attachment set contains both — `Logo-480x199` is scaled, `Logo-480x480` and `Logo-1080x675` are crops. The original rule ("narrowest variant at least 480 px wide") could select a crop, which for a lockup means the emblem with the wordmark cut off. Fixed: a variant is used only if its proportions match the original within one percent, otherwise the full-size original is used, and with no recorded original dimensions no variant is trusted at all. Covered by L2b and by three PHPUnit cases.
- **Also worth knowing:** the library holds a navy-on-light and an inverted white-on-dark family; the inverted one would put a dark block on a white invoice. And if the site's Custom Logo is an SVG there will be no logo — SVG is refused deliberately, and the fix for that is a PNG Custom Logo, not a looser parser.

### PHPUnit — still not run

`vendor/` remains absent. `tests/unit/LogoEmbeddingTest.php` (17 test methods, one of them an 11-case data provider) is written and lint-clean but **not executed**. The equivalent assertions run in `verify-logo-security.php`, which does execute. The fixture builder the test relies on — a PNG constructed without ext-gd — was exercised directly and produced images that `RasterImage`, `getimagesize` and `finfo` all accept, with the RGBA variant yielding a soft mask as the test expects. The JPEG test skips itself when ext-gd is absent.

### Two notes on the sandbox, not on the product

- Windows will not create a filename containing `"`, `<` or `>`, so the hostile-filename fixture uses `logo';DROP TABLE wp_posts;--script.png`. The property under test — that no filename reaches the document — is unaffected.
- The sandbox is created under the system temp directory and removed at the end of the run, deliberately away from the OneDrive-backed working copy, whose ACL behaviour caused a spurious failure in an earlier pass.

## 8. Admin preview wiring — verification run

### PHP lint

```
php -l over every *.php outside vendor/
checked=101  failed=0
```

### Focused runtime checks

`review/claude/verify-admin-preview.php` — **34 checks, 34 pass, 0 fail, exit 0**. Full output: `evidence/verify-admin-preview-output.txt`.

This harness is different from the other two in one way that matters: the WordPress functions `get_theme_mod`, `get_post_mime_type`, `get_attached_file`, `wp_get_attachment_metadata` and `wp_upload_dir` are defined over a fixture, and the provider is then constructed **with no arguments** — exactly as `AdminController::pdfRenderer()` constructs it. It therefore proves the default wiring resolves a real logo, rather than proving a fake does.

| Group | Checks | Result |
|---|---|---|
| P1 — zero-argument wiring | 5 | pass (attachment id, MIME, a local path with no `://`, uploads base directory, and a no-argument provider that resolves the logo) |
| P2 — PNG Custom Logo | 6 | pass (one image XObject, drawn on page 1, transparency as a soft mask, structurally valid, text intact, nothing remote or active) |
| P3 — JPEG Custom Logo | 3 | pass (embedded as `/DCTDecode`, valid, no soft mask) |
| P4 — whole logo, never a crop | 3 | pass (the proportional 600×150 variant is chosen, the 480×480 square crop is not, and the document embeds the proportional one) |
| P5 — no logo and unusable logo | 8 | pass (no Custom Logo → no `/XObject` at all, valid, same text; an SVG Custom Logo produces a document **byte-identical to the no-logo one**; an SVG renamed to `.png` is refused on content; a missing file still produces a document) |
| P6 — the preview action | 9 | pass (registered on `admin_post`, capability checked first, nonce bound to the document, inline PDF headers with `nosniff`, buffers cleared, failures become `wp_die`, no transport/queue/write in the controller, nonce-signed link in the list, filename derived safely) |

### The invariant that changed

`verify-pdf-engine.php` E11 used to assert "no plugin code path constructs a PDF renderer or a mailer". That is no longer true, and pretending otherwise would have been the wrong way to keep a green check. It is now six assertions instead of one:

- a PDF renderer is constructed in **exactly one place**, `AdminController.php`;
- it is constructed only inside the `pdfRenderer()` factory;
- **no** plugin code constructs a mailer, and none constructs `DeliverDocument`;
- the preview is registered on `admin_post` only and `Plugin.php` never mentions it;
- the preview requires the capability and a per-document nonce;
- the controller contains no transport call.

`PluginIsolationTest` gained the same two assertions in PHPUnit form.

### Visual check

`evidence/pdf-preview-logo-extracted.png` is the image pulled back out of the PDF the **wired** path produced. It shows the complete lockup — mark plus wordmark, 1200×300, transparency intact — not the 480×480 square crop, which would have been the mark alone. That is precisely the distinction the crop fix exists to make, confirmed by looking.

Still not looked at: the page. No PDF rasteriser exists offline. Opening `evidence/pdf-preview-logo-png.pdf` once remains the outstanding check.

### PHPUnit — still not run

`vendor/` remains absent. `PluginIsolationTest` gained two methods; they are written and lint-clean but **not executed**. Their assertions are source-level and are duplicated in `verify-admin-preview.php` P6 and `verify-pdf-engine.php` E11, both of which do execute.

## 9. The first rasterised page — three layout defects

A rendered page was finally looked at, from outside this work. It showed two collisions, and chasing them turned up a third:

| Defect | Measured | Cause | Fix |
|---|---|---|---|
| Item description printed over the quantity | 3.93 pt overlap | The description wrap width was chosen by eye, with no allowance for how far back a right-aligned quantity reaches | The width is now *derived*: `COLUMN_QUANTITY − TABLE_LEFT − QUANTITY_RESERVE − gutter` |
| VAT summary gross printed over VAT summary tax | 2.79 pt overlap | That column repeated ` PLN` on every row while the items table did not | The currency is named once, in the section heading |
| Large amounts overflowed their columns | up to 12.65 pt | A five-figure amount is wider than the 38 pt the tax column allows | Every right-aligned numeric cell shrinks to fit, down to a 5 pt floor |

Truncation was rejected for the third: `1 234,5…` reads as a different amount, and a wrong number is worse than a small one.

### The check that now catches this

`verify-pdf-engine.php` E13 reads the document as a **layout** rather than as data. For every text run it recovers the font resource, size, position and glyph ids from the content stream, measures the run with the same metrics the renderer used, groups runs by baseline, and fails if two neighbours come within 2 pt of each other.

It runs against five documents: the standard one, the 140-item paginated one, the hostile-input one, one with long Polish descriptions, and one with a line worth about a million zloty. All five are clear.

This is the first check here that could have caught these bugs. Structure, text recovery, pixel comparison and font integrity all passed while two columns were printing on top of each other.

## 10. Sandbox email capture — independent review of `320840c`

Offline checks only. No email was sent, no migration run, no external service contacted.

| Check | Result |
|---|---|
| PHP lint | 103 files, 0 errors |
| `verify-sandbox-admin-wiring.php` | 18/18, exit 0 (was 10/10 before this review) |
| `verify-pdf-engine.php` | 87/87, exit 0 |
| `verify-fixes.php` | 59/59, exit 0 — the OneDrive ACL problem did not recur |
| `verify-logo-security.php` | 69/69, exit 0 |
| `verify-admin-preview.php` | 34/34, exit 0 |
| `git diff --check` | clean |
| PHPUnit | not run — `vendor/` absent |

### What the review found

One Medium, fixed: **a capture directory inside the web root was accepted.** Demonstrated before the fix by constructing `SandboxMailer` against a simulated `ABSPATH`; both a direct path under it and a `..` path resolving back inside it were accepted. Since a capture holds the buyer's name, their email address and the whole rendered invoice, that is a personal-data disclosure waiting for a directory listing or a backup crawler. `SandboxMailer` now resolves the directory with `realpath()` and refuses anything at or inside `realpath(ABSPATH)`.

Two Lows recorded and left open by choice: captures are never pruned, and the failure path writes the exception message to the PHP error log. Both are in `security-findings.md`.

### Two of the checkpoint's ten checks did not test their claims

- `pdf attachment is present` matched the string `application/pdf`. An empty or truncated attachment would have passed.
- `multiline body is preserved as MIME text` matched a `Content-Transfer-Encoding: base64` header and never looked at the body — so a body flattened to one line, the exact defect M6 fixed, would have passed.

Both now decode the MIME parts: the attachment must equal the rendered document byte for byte, and the text part must decode back to `"Line 1\r\nLine 2"`. Added alongside them: the recipient's provenance (snapshot, never the request), the pre-render address validation, the audit context carrying a hash rather than the address, and three web-root cases.

### Verified by reading, not by running

The capability check, the nonce, the `admin_post` registration and the absence of `admin_post_nopriv_*` are source-level facts confirmed by grep and by the harness; no WordPress runtime was involved. PHP 7.4 compatibility was checked by scanning `packages/` for 8.0-only syntax — none present.

## 11. Environment note (not a product finding)

On the second harness run, `SandboxMailer::__construct()` threw `Sandbox mail directory is not writable` for a directory it had itself created on the previous run. This is a Windows/OneDrive ACL artifact of the review sandbox, not a defect in the mailer; the harness was changed to use a unique directory per run and the check then passed. I mention it only so the log is not misread.
