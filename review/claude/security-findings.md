# Security & correctness findings — Commerce Documents Kit

Reviewer: Claude (independent, read-only)
Branch: `agent/geward-document-module-stage0`
Range originally reviewed: `20cd191..83950ae` (dff7778, b3caed5, 848cead, 83950ae)

Severity key: **Critical** = data loss or unusable system / **High** = blocks external PDF or email delivery / **Medium** = must fix before production / **Low** = hygiene.

## Status summary (updated after the PDF engine pass)

| Severity | Total | Fixed | Partially fixed | Open |
|---|---|---|---|---|
| Critical | 1 | 1 | – | – |
| High | 6 | 6 | – | – |
| Medium | 10 | 9 | 1 (M9) | – |
| Low | 6 | 4 | – | 2 (L2, L6) |

Verification: `review/claude/verify-fixes.php` — 59 checks, 59 pass, exit 0 (`evidence/verify-fixes-output.txt`).
`review/claude/verify-pdf-engine.php` — 73 checks, 73 pass, exit 0 (`evidence/verify-pdf-engine-output.txt`).
`review/claude/verify-logo-security.php` — 64 checks, 64 pass, exit 0 (`evidence/verify-logo-security-output.txt`).
PHP lint clean on 100 files.

**The PDF engine blocker is closed.** A production engine was selected, built and reviewed — see *Production PDF engine* below and `pdf-engine-decision.md`. Delivery remains unwired by design; that is the separate sandbox stage, not a blocker.

---

## Critical

### C1 — Insert format array misaligned; every encrypted snapshot is persisted as `0` — **FIXED**

> **Fix.** `WpdbDocumentRepository::save()` now builds one `column => [value, format]` map and derives both arrays from it, so the two can no longer drift apart. `FakeWpdb::insert()` applies `$formats` the way wpdb does, and `WpdbPersistenceTest::testEncryptedSnapshotSurvivesColumnFormatBinding()` asserts the ciphertext is neither `'0'` nor `0` and still decrypts. Verified: cipher length 1613 preserved, round-trip hash matches.

**File:** `packages/wordpress/src/WpdbDocumentRepository.php:61-76`

The data array has ten keys; the format array has ten specifiers, but `%d` sits at position 7, which binds to `snapshot_cipher`, not to `encryption_version`:

| # | column | bound as | correct |
|---|---|---|---|
| 6 | `snapshot` | `%s` | ✔ |
| 7 | `snapshot_cipher` | `%d` | �’ **must be `%s`** |
| 8 | `encryption_version` | `%s` | works by coercion |

`wpdb::insert()` passes formats to `wpdb::prepare()`, which renders `%d` through integer conversion. The ciphertext envelope (a ~1.4 KB JSON string) becomes the integer `0`.

`save()` also writes `'snapshot' => ''` (line 69), so no plaintext copy survives either.

**Consequence:** every document created through this repository is written with `snapshot_cipher = '0'` and `snapshot = ''` — the document content is destroyed at the moment of creation and cannot be recovered from the row. On read, `EncryptedPayload::fromJson('0')` throws, so `AdminController::documents()` (which has no try/catch around `decodeRow()`) fatals the whole admin list page as soon as one document exists.

**Reachable today** via the admin "Generate test document" button → `AdminController::generate()` (`:180-194`) → `Plugin::generateForOrder()` → `save()`.

**Why the suite does not catch it:** `FakeWpdb::insert()` in `tests/unit/WpdbPersistenceTest.php:129-137` accepts `$formats` and discards it, so the persistence test passes with the bug present.

**Verified:** `review/claude/test-results.md` T1.

---

## High

### H1 — Legacy plaintext fallback accepts unauthenticated snapshot data; `content_hash` is never verified — **FIXED**

> **Fix.** Both read paths now go through one method, `WpdbDocumentRepository::hydrate()`. The plaintext branch verifies `content_hash` against the **raw stored bytes** with `hash_equals` before decoding, and rejects a row whose hash is missing or malformed. Comparing against a rehydrated snapshot would not work: rehydration upgrades v1 payloads to v2 and could never reproduce the write-time hash — that subtlety is documented in the method.
>
> Removal boundary: `WpdbDocumentRepository::LEGACY_PLAINTEXT_REMOVED_IN_SCHEMA = 5` (current schema is 4), with `LegacySnapshotAudit` reporting how many rows still block the removal and how many cannot be authenticated at all. `Installer::preflight()` surfaces both. Verified: valid row accepted; tampered body, missing hash and wrong hash all rejected.

**Files:** `packages/woocommerce/src/AdminController.php:339-346`; `packages/wordpress/src/WpdbDocumentRepository.php:36-50`

Both read paths branch on `snapshot_cipher !== ''`. When it is empty they fall back to `json_decode($row['snapshot'])` and trust the result. That path has no AEAD tag, no HMAC and no comparison against the stored `content_hash` column — `content_hash` is written on insert (`WpdbDocumentRepository.php:72`) and read back nowhere in the repository.

Anyone able to write a row (SQL injection elsewhere, a compromised plugin, DB access, a restored/edited backup) can clear `snapshot_cipher`, supply arbitrary JSON in `snapshot`, and have it rendered and — once delivery is wired — emailed as an authentic document. The AEAD protection is opt-out by writing one empty string.

**Verified:** T3.

### H2 — The manual generation path still mints `invoice` / `proforma` documents — **FIXED**

> **Fix.** `Plugin::generateForOrder()` now constructs `PaidOrderPolicy`, which can only ever return `order_confirmation`. `ConfigurableStatusPolicy` is no longer constructed at runtime anywhere (asserted by a source check), and the proforma/invoice status matrices are gone from both the settings screen and `AdminSettings::sanitize()`. Because the manual button and the automatic hook share that one method, the manual path is covered by the same gate. The plugin header no longer advertises invoice generation.

**Files:** `packages/woocommerce/src/Plugin.php:117-122`; `packages/woocommerce/src/AdminController.php:180-194`

`Plugin.php:64-65` states the module is "deliberately disarmed until a paid-order policy is implemented", and the gate at `:66` correctly blocks the automatic hook. But `generateForOrder()` is also called directly by the admin button, and it constructs `ConfigurableStatusPolicy` from `proforma_statuses` / `invoice_statuses`, which returns `DocumentType::INVOICE` or `PROFORMA` (`ConfigurableStatusPolicy.php:39-45`).

An operator pressing "Generate test document" therefore produces a numbered document typed `invoice` in the `invoice:<year>` series. That is a second generator of officially-typed documents alongside Fakturownia, which the module was explicitly meant to prevent. `order_confirmation` is defined (`DocumentType.php:16`) but no WooCommerce code path ever produces it.

### H3 — No paid/COD gating exists; payment state is only a label — **FIXED**

> **Fix.** `PaidOrderPolicy::isPaid()` requires both a paid-class status (default `processing`, `completed`) **and** a gateway payment date. `documentTypeFor()` returns `null` otherwise, and `OrderMapper` throws rather than generating. Offline gateways are unpaid by default; an operator must both switch `cod_policy` to `status_only` and name the specific gateway id to enrol it, and the settings screen states plainly that doing so issues a document before the money arrives. The decision — policy, version, method, confirmation, cod policy — is written into the snapshot metadata so it is reconstructable later.
>
> Verified: no document for pending, cancelled, failed, refunded, checkout-draft, or processing+COD; a paid order yields `order_confirmation`; an enrolled COD gateway qualifies on a paid status only.

**File:** `packages/woocommerce/src/OrderMapper.php:41-46`

The commit adds `payment_method`, `payment_confirmed`, `payment_status` to snapshot metadata, derived from `$order->paidAt !== ''`. Nothing consumes these to *prevent* generation. Document creation is still driven purely by order status through `ConfigurableStatusPolicy`; a `cancelled` or `failed` order in the configured status list produces a document.

For COD specifically, WooCommerce never sets `date_paid`, so every COD order is permanently `payment_confirmed = no`. The implemented behaviour is "generate anyway and stamp it unpaid" (`HtmlRenderer.php:26-31`, `BasicPdfRenderer.php:20-25`), not "generate only after confirmed payment".

### H4 — `BasicPdfRenderer` destroys non-ASCII text and omits all line items — **FIXED** (with a remaining blocker on the engine itself)

> **Fix.** New `PdfTextEncoding` maps the Polish alphabet onto codes `0x80..` and declares them through an `/Encoding /Differences` array over Helvetica / Helvetica-Bold, so `Zażółć gęślą jaźń` renders as letters instead of `?`. Characters outside the standard set are transliterated rather than dropped; control bytes are neutralised and `(`, `)`, `\` escaped. The renderer now emits parties, an itemised table with quantity, net, tax and gross per line, a totals block, the unpaid-COD notice and correction references. Truncation is UTF-8 safe without `ext-mbstring`, which the package does not declare.
>
> Verified: valid header/trailer, all 7 xref offsets resolve, `startxref` correct, both line items and totals present, no `?` substitution, long multibyte names cut safely.
>
> **Closed for customer delivery in the engine pass.** `BasicPdfRenderer` is superseded by `EmbeddedFontPdfRenderer`, which embeds the glyph outlines instead of naming them, wraps text and paginates. See *Production PDF engine* below.

**File:** `packages/document-core/src/Rendering/BasicPdfRenderer.php:65-69, 14-30`

`preg_replace('/[^\x20-\x7E]/', '?', $value)` runs byte-wise on UTF-8, so each Polish character becomes several `?`. Measured: `Zażółć gęślą jaźń` → `Za???????? g????l?? ja????`. Seller and buyer names, street names and city names are all affected. The renderer also emits only type, number, issue date, seller, buyer and gross total — **no products, quantities, unit prices, tax rates or VAT breakdown appear in the PDF at all** (confirmed: the item description `Slab` is absent from the output).

The PDF container itself is structurally sound (header, five objects, xref offsets all resolve, `startxref` correct), and PDF string escaping of `\`, `(`, `)` is correct — but the document content is unusable. Blocking for anything sent to a customer.

**Verified:** T4; sample at `review/claude/evidence/basic-renderer-sample.pdf`.

### H5 — Audit hash chain forks silently under concurrency and is never verified — **FIXED**

> **Fix.** The chain is now **per document**, and concurrency is settled by the database rather than by a read-then-write lock: `UNIQUE KEY chain_position (document_id, prev_event_hash)` makes it impossible for two events of the same document to claim the same predecessor. A writer that loses the race gets a failed insert, re-reads the tip and retries (bounded at 5 attempts).
>
> This was chosen over `START TRANSACTION` / `SELECT … FOR UPDATE` and over `GET_LOCK` deliberately: WordPress does not guarantee a transactional storage engine, and a lost advisory lock fails open whereas a unique index fails closed. Per-document scoping also means one missing row no longer invalidates verification for every other document.
>
> `AuditChainVerifier` recomputes a document's chain and reports the first position that does not reconcile; the document view shows the result. Verified: intact chain passes, an altered event is caught at its position, a deleted event breaks the link.
>
> Migration note: adding this unique index over pre-existing global-chain rows can collide. `Installer::preflight()` counts duplicate `(document_id, prev_event_hash)` pairs and **blocks** the migration until they are reconciled.

**File:** `packages/wordpress/src/WpdbEventLogger.php:31-45`

```
SELECT event_hash FROM {$this->table} ORDER BY id DESC LIMIT 1
```

is a plain read with no `FOR UPDATE`, no transaction and no lock, followed by a separate `insert()`. Two concurrent writers read the same tip and both produce a valid-looking successor, leaving two branches with no marker of which is canonical. Verified: two `next()` calls from the same `previousHash` both succeed and differ.

Two further weaknesses: the chain is **global across all documents**, so one failed or deleted insert invalidates verification for every later event; and no verifier exists anywhere in the codebase — the chain is written but never checked, so tampering would not currently be noticed.

`AuditEventHash` itself is sound: HMAC-SHA256, 32-byte key required, previous hash format validated (`AuditEventHash.php:19-28`).

**Verified:** T5.

### H6 — A corrected document carries no evidence that it was corrected — **FIXED**

> **Fix.** Supersession is now recorded without touching the immutable snapshot: new `superseded_by` / `superseded_at` columns on the document row carry the relationship, while `snapshot_cipher`, `content_hash` and `document_number` are untouched — so immutability and auditability are both satisfied rather than traded off. The document view shows a red "This document has been replaced" banner, the list shows the state, and the correction form is withheld for an already-replaced document.
>
> Two audit events are written instead of one: `document.replaced` against the **original** and `document.corrected` against the correction, so neither side of the relationship has a clean-looking trail.
>
> Idempotency (was M1): the correction form carries a `correction_token` minted once per render; the idempotency key derives from parent id + token, so a resubmit resolves to the existing document. The existing-document check runs **before** the sequence number is allocated, so a duplicate submit burns no number. Supersession is claimed with a conditional `UPDATE … WHERE superseded_by = ''` before the number is drawn, so two concurrent corrections cannot both proceed, and the claim is released if anything downstream fails.

**File:** `packages/woocommerce/src/AdminController.php:231-283`, `:349-357`

`correct()` correctly leaves the original row untouched — immutability holds. But the audit event is recorded against the **new** document id only (`:274-278`), and `events()` filters by `document_id`. Opening the original document therefore shows a clean audit trail with no indication it has been superseded. `DocumentStatus::REPLACED` exists (`DocumentStatus.php:16`) and is never applied. The `commerce_document_links` row (`:266-272`) points child → parent, so the relationship is only discoverable by querying the links table backwards, which no UI does.

For a correction workflow this is the audit property that matters most.

---

## Medium

| ID | Finding | Location |
|---|---|---|
| M1 | **Corrections are not idempotent.** `sourceId` embeds `gmdate('YmdHis')` + `random_bytes(8)`, so every submit yields a fresh idempotency key. A double-click or browser resend creates a second correction document and consumes a second sequence number. | `AdminController.php:245` |
| M2 | **Search only covers the newest 50 rows.** The `LIMIT 50` is applied in SQL, filtering happens in PHP afterwards, so older documents are unfindable by number, order or ID. Buyer name is not searchable at all (it is inside the ciphertext). | `AdminController.php:305-322` |
| M3 | **No error isolation in the list.** `decodeRow()` is called without try/catch; a single undecryptable row takes down the entire page. Also an N+1 `COUNT(*)` audit query per row. | `AdminController.php:309-317` |
| M4 | **Sandbox mail files overwrite within the same second.** Filename is `{document_id}-{YmdHis}.eml`; two sends in one second leave one file. Verified: 2 sends → 1 file. Delivery evidence is lost. | `SandboxMailer.php:47` |
| M5 | **Non-conformant MIME.** No `MIME-Version: 1.0`, no `From:` header. Verified absent in generated `.eml`. Strict parsers will not treat the message as multipart. | `SandboxMailer.php:49-58` |
| M6 | **A normal email body cannot be produced.** `preg_match('/[\r\n]/', $subject . $message)` rejects newlines in the **body**, not just headers. Verified: multi-line body throws. Header safety and body content are conflated. | `SandboxMailer.php:38-41` |
| M7 | **File permissions and repo hygiene.** `.eml` files are created with the default umask (measured `0666` here) and contain buyer name, recipient address and the full PDF. The `0700` directory mitigates this on POSIX only while the path stays put. `.gitignore` excludes `*.pdf` but **not** `*.eml`, so a sandbox directory inside the repo would commit personal data. | `SandboxMailer.php:26-35, 59`; `.gitignore` |
| M8 | **No HPOS declaration.** No `FeaturesUtil::declare_compatibility` / `before_woocommerce_init` anywhere in the repository. WooCommerce will list the plugin as incompatible; on a store with HPOS enabled this blocks the feature or the plugin. The code itself uses only the CRUD API and would work. | `plugins/commerce-documents-woocommerce/commerce-documents-woocommerce.php` |
| M9 | **Migration safety is nominal.** "Backup confirmed" is a checkbox turned into a boolean; nothing verifies a backup exists. There is **no down-migration or rollback path**, and `applySchema()` is `dbDelta`-only, which cannot drop or narrow columns. Migration history is recorded after the schema change. | `AdminController.php:224`; `Installer.php:28-51, 63-69` |
| M10 | **Legacy types remain fully constructible.** `quote`, `proforma`, `invoice`, `receipt`, `credit_note` are still valid, and the settings screen still renders proforma/invoice status matrices. | `DocumentType.php:11-15`; `AdminController.php:125-128` |

### Medium — resolution

| ID | Status |
|---|---|
| M1 | **FIXED** — see H6. Token-based idempotency key; the existence check runs before the sequence number is allocated, so a resubmit burns no number. |
| M2 | **FIXED** — filtering moved into SQL over `document_id`, `document_type` and `source_id` with `esc_like`, so the whole table is searched rather than the newest page. `document_number` lives inside the ciphertext and still cannot be matched in SQL; the search field states what it covers. |
| M3 | **FIXED** — each row hydrates inside try/catch and renders as `UNREADABLE` instead of fataling the page; the audit count is a correlated subquery in the main statement rather than a query per row. |
| M4 | **FIXED** — the filename carries a random suffix and the file is created with `fopen(…, 'xb')`, so an existing file is never replaced. Verified: two sends in the same second leave two files. |
| M5 | **FIXED** — `MIME-Version`, `From`, `Date` and `Message-ID` added; non-ASCII subjects RFC 2047 encoded; both parts declare `Content-Transfer-Encoding: base64`. |
| M6 | **FIXED** — the header-break check now applies to header fields only; the body is normalised to CRLF and base64-encoded. Verified: multi-line body accepted, all three injection vectors still rejected. |
| M7 | **FIXED** — `chmod 0600` after exclusive create; `*.eml` and `sandbox-mail/` added to `.gitignore`. *Caveat: PHP's `chmod()` on Windows only toggles the read-only bit, so the mode is not observable on this machine — the check asserts the call and records the platform limit.* |
| M8 | **FIXED** — `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` on `before_woocommerce_init`, guarded by `class_exists`. |
| M9 | **PARTIALLY FIXED** — `preflight()` now returns `blockers` and `warnings`; `migrateToCurrentVersion()` refuses to run while a blocker is present (duplicate audit chain positions, unauthenticatable legacy rows), and the admin screen hides the migrate button while blocked. `Installer::rollbackPlan()` emits the schema-4 reversal statements with prefix validation. **Not fixed by design:** the rollback is printed, not executed — `dbDelta` cannot express column or index removal, an automated rollback button beside a migrate button is how the wrong one gets pressed, and the task forbids running migrations, so an executable down-path could not be tested here. Backup verification remains an operator attestation. |
| M10 | **FIXED** — `GenerateDocument::execute()` now calls `DocumentType::assertIssuable()`, so no new document of a legacy fiscal type can be created regardless of what a policy or a caller asks for; only `order_confirmation` and `correction` are issuable. `fromString()` still accepts every legacy value, so historical rows stay readable — that was the reason the constants could not simply be deleted. Removing the constants outright still belongs with the schema-5 legacy cleanup, but the risk M10 described (a second generator of officially-typed documents) is now closed in the application layer rather than only in the order policy. Verified: 5/5 legacy types refused at issue, 7/7 still constructible for reading. |

---

## Low

| ID | Finding | Location |
|---|---|---|
| L1 | Settings read without `??` → PHP 8 warnings on partial settings (`AdminSettings` uses `?? []` for the same keys). | `Plugin.php:114, 118-121` |
| L2 | Exception messages are passed through the redirect URL into the admin notice. Escaped on output and admin-only, but can surface table names and internal state. | `AdminController.php:291-299` |
| L3 | Plugin header still reads "Universal proforma and invoice generation foundation". | `commerce-documents-woocommerce.php:4` |
| L4 | `@mkdir` followed by `is_writable` is TOCTOU; the filename is predictable and written without `O_EXCL`, so a pre-created symlink could redirect the write on a shared host. | `SandboxMailer.php:26-35, 59` |
| L5 | No verifier or CLI exists for the audit chain — it is written but never validated. | `WpdbEventLogger.php` |
| L6 | `record()` uses `JSON_THROW_ON_ERROR`; an unencodable context throws *after* the document has already been persisted, aborting the caller with the document saved but unlogged. | `WpdbEventLogger.php:29` |

### Low — resolution

| ID | Status |
|---|---|
| L1 | **FIXED** — `Plugin::generateForOrder()` now reads every setting through `??` with a default. |
| L2 | **OPEN** — still admin-only and escaped on output. Left as-is: suppressing the message would make failed generations harder to diagnose, and a message catalogue is a larger change than the risk justifies. |
| L3 | **FIXED** — the header now reads "Issues internal order-confirmation documents for paid WooCommerce orders. Fiscal invoices are not generated automatically." |
| L4 | **FIXED** — `fopen(…, 'xb')` fails rather than following a pre-created symlink, and the filename now carries 6 random bytes so it is no longer predictable. |
| L5 | **FIXED** — `AuditChainVerifier` added and surfaced in the document view. |
| L6 | **OPEN** — ordering unchanged: the event is still written after the document is persisted. Fixing it properly means making persistence and logging one unit of work, which is the same transactional question deferred in H5 and should be decided together with it. |

---

## Confirmed strengths

These were tested, not assumed:

- **Nothing can send anything today.** `SandboxMailer`, `BasicPdfRenderer` and `DeliverDocument` are referenced only from `tests/unit/RenderingAndDeliveryTest.php`. No plugin code path constructs them. There is no `wp_mail`, no socket, no HTTP client in the delivery classes.
- **No key material in the tree or in git history.** Both keys resolve only from `wp-config.php` constants, and both providers fail closed with a clear message (`ConfigKeyProvider.php:13-36`). A scan of tracked files and of history for the constant names and for base64-shaped 32-byte literals found nothing.
- **AES-256-GCM is used correctly.** 12-byte random nonce per encryption, 16-byte tag, AAD bound to `document_id`, plus an identity re-check after decrypt (`EncryptedSnapshotCodec.php:36-38`). Verified: AAD swap detected, single-bit ciphertext flip detected.
- **Header and recipient injection are rejected** in all three attempted vectors (CRLF in subject, CRLF in recipient, CRLF in body).
- **Path traversal in the mail filename is not possible** — `document_id` is validated against `^[A-Za-z0-9_-]+$` before use (`SandboxMailer.php:44-46`).
- **The 0.2.2 idempotency landmine is fixed.** `IdempotencyKey::forSource()` no longer folds policy name or version into the hash; the legacy parameters are accepted and ignored (`IdempotencyKey.php:22-40`).
- **The `!== true` dead-gate from 0.2.2 is fixed** — the automatic hook now compares a string (`Plugin.php:66`).
- **Snapshot metadata validation is strict** — key pattern enforced, non-scalar values rejected (`DocumentSnapshot.php:67-77`). Verified against four inputs.
- **Schema v2 is additive and backward-compatible on read** (`DocumentSnapshot.php:110`), and `document_id`, `document_number` and totals remain derived inside the same validating constructor.
- **PHP lint clean:** 76 files, 0 errors, PHP 8.3.30.

---

## Production PDF engine — **CLOSED**, with its own security review

The engine chosen is `EmbeddedFontPdfRenderer`: a data-to-PDF writer with an embedded TrueType subset, built into the package. The alternatives and why they lost are in `pdf-engine-decision.md`; the short version is that the two engines physically present on this machine are a Dompdf **1.0.2** and an mPDF taken out of third-party plugin vendor directories — unpinned, unverifiable offline, and years behind the advisories that matter for exactly this use.

The review below treats the new engine as an untrusted component and asks the same questions that would have been asked of Dompdf.

| Requirement | Finding | How it was verified |
|---|---|---|
| Remote resources disabled | Not applicable in the usual sense: **there is no code that resolves a reference into a fetch.** No URL handling, no image loader, no `@font-face`, no stylesheet resolution. There is nothing to switch off, and so nothing that can be switched back on by a future configuration change. | Source scan of all four renderer classes for `curl_*`, `fsockopen`, `stream_socket_client`, `file_get_contents('http…')`, `wp_remote_*`, `get_headers`, `dns_get_record` — clean. |
| No external URLs, images or fonts in the output | The generated file contains no `http://`, `https://`, `/URI`, `/Launch`, `/GoToR` or `/EmbeddedFile`; no `/XObject` or `/Image` at all; exactly two `/FontFile2` streams and no `/FontFile` or `/FontFile3`. | Asserted against a rendered document. |
| No active content | No `/JavaScript`, `/JS`, `/OpenAction`, `/AA`, `/RichMedia` or `/XFA`. | Asserted against a rendered document. |
| No filesystem traversal | One filesystem read in the whole engine: `EmbeddedFont` opens `__DIR__ . '/../../../resources/fonts/dejavu-sans-' . $style . '.ttf'`. `$style` is compared against two class constants and rejected before it reaches the path; the constructor is `private` and the only entry points are `regular()` and `bold()`. No caller-supplied value can influence a path. | Source assertions plus the absence of any other `fopen`/`file_get_contents`/`include` in the four classes. |
| Safe input handling | Snapshot text never becomes PDF syntax. It is converted to glyph ids and emitted as hex strings, so `(`, `)`, `\`, newlines and operators cannot be expressed at all. Control bytes are dropped; malformed UTF-8 becomes U+FFFD instead of being reinterpreted as a different codepoint; newlines collapse to spaces; every field is length-capped. | A hostile fixture (`) Tj … (PWNED`, `\\ ( ) << >> endstream endobj`, NUL/CR/LF/ESC, broken UTF-8, a 20 000-character description, an injected `correction_of` and a hostile buyer name) renders to a structurally valid PDF whose content streams contain **no `(` at all**, no injected token, and no control byte. Every `Tj` in the stream is preceded by a hex string. |
| No HTML or CSS stage | The renderer contains no `DOMDocument`, no `loadHTML`, no markup. The entire parser class of defect is absent rather than configured away. | Source assertion. |
| Deterministic output | No clock reading, no randomness, no compression. `/CreationDate` and `/ModDate` come from the snapshot's own dates. | Three renders — twice from one instance, once from a fresh instance — are byte-identical; the file contains no current date. |
| Bounded document size | 300 line items rendered (the remainder is declared on the document), 30 pages maximum, per-field caps of 300/120/400 characters. | A 5 000-item order yields 11 pages and 309 KB against a 350 KB ceiling; a normal three-line document is 123 KB, of which 105 KB is the two font subsets. |
| Polish Unicode | Rendered from embedded outlines, not from the viewer's fonts. All 18 Polish letters map to distinct non-zero glyphs, each with an outline present in the subset. | Text is recovered from the finished PDF **through the document's own `/ToUnicode` CMap** — `Zażółć Gęślą Jaźń Sp. z o.o.`, `ul. Świętokrzyska 5/7`, `Kraków` all round-trip, with no `?` and no U+FFFD. `Müller & Sønner` round-trips too. |
| Line items, taxes, totals | Every description, quantity with unit, unit price, per-line VAT rate, net, tax and gross is printed; a VAT summary groups net/tax/gross by rate; the printed totals equal the snapshot arithmetic. | Read back from the rendered document; 140/140 line items present across 6 pages, none lost at a page boundary. |
| COD notice | The unpaid cash-on-delivery notice is boxed at the top of the document when `payment_method = cod` and the payment is unconfirmed, and absent when it is confirmed. | Both directions asserted. |
| Correction references | The corrected document number and the correction reason are printed. | Asserted, including Polish text in the reason. |
| No network calls | None exist. Nothing in the engine opens a socket or issues a request. | Source scan, as above. |

### The check that carries the most weight

Subsetting renumbers glyph ids, which means rewriting the component indices inside composite glyphs — and every Polish diacritic is a composite. An error there would place the wrong accent on the wrong letter while every structural check still passed; it would be visible only to a human looking at the page.

The harness therefore compares each of the 339 subset glyphs against the same glyph in the source DejaVu Sans: simple outlines byte for byte, composites everywhere except the indices, whose targets are then compared the same way, recursively. **All 339 match.** The outlines in the document are DejaVu's own.

### Logo embedding — reviewed separately

The first engine pass had **no image code at all**, and that was one of the reasons it was easy to defend. Adding the site logo reopens that surface deliberately, so it gets its own review. Evidence: `evidence/verify-logo-security-output.txt`, 64 checks, 64 pass, exit 0.

The property being defended: **the logo is a local file inside the WordPress uploads directory, and nothing else is ever opened.** WordPress is asked which attachment is the Custom Logo; its answer is then treated as untrusted input.

| Requirement | Finding | How it was verified |
|---|---|---|
| Only a local file from uploads | `WordPressLogoProvider` resolves the candidate with `realpath()` and requires the result to sit under `realpath(uploads)`. Because `realpath()` collapses `..` and follows symlinks first, containment is decided on the file's real location, not on how the path was spelled. | Refused: traversal out of uploads, traversal aimed at `wp-config.php`, an absolute path outside uploads, and a size-variant filename containing `../../`. |
| No remote fetch, ever | A candidate containing a scheme is rejected before the filesystem is touched. There is no HTTP client, no `wp_remote_*`, no URL-returning media function; `get_attached_file()` returns a path. | Refused: `https://`, `//host/`, `data:`, `php://`, `file://`. |
| PNG/JPEG only, SVG refused | The attachment MIME must be `image/png` or `image/jpeg`; the extension must match; `finfo` and `getimagesize` must both agree; and `RasterImage` re-derives the format from the signature. There is no SVG code path to reach. | Refused: an SVG attachment, an SVG renamed to `.png`, PHP renamed to `.png`, a `.php` file. |
| MIME checked by content, not by name | `finfo` on the bytes and `getimagesize` on the file must return the same type, and it must be PNG or JPEG. | An SVG named `.png` is refused as `image/svg+xml` even though its extension is allowed. |
| Bounded size | 2 MB on the file, 5 000 px per side, 4 MP in total, and the inflated PNG data is pinned to exactly the size its header declares. | Refused: an oversized file, a 2600×2600 image, and a PNG whose 10×10 header hides 5 MB of inflated data. |
| No path traversal, no arbitrary file read | Only the *basename* is taken from the size metadata; the directory always comes from the attachment's own path. The path checks above then apply to the result. | A size variant declaring `../../private/secret-logo.png` is skipped and the original is used instead. |
| No active content in the output | The image dictionary is written by the renderer from values `RasterImage` has already validated, not copied from the file. Ancillary PNG chunks — colour profiles, text, timestamps — are not read at all. | The document with a logo contains no `/JavaScript`, `/JS`, `/OpenAction`, `/AA`, `/Launch`, `/URI`, `/EmbeddedFile`, `/RichMedia`, and no `http(s)://`. |
| Hostile filenames cannot reach the document | Filenames are never written into the PDF. | A file named `logo';DROP TABLE wp_posts;--script.png` embeds normally and neither the name nor any fragment of it appears in the output. |
| Correct fallback | Every failure path returns null and the document is issued without a logo. Nothing in the logo path can throw into the renderer. | A document with no logo is byte-identical to one rendered with no provider at all, contains no `/XObject`, and is structurally valid. |
| Determinism preserved | Same snapshot and same logo file produce byte-identical output. | Asserted across separate renderer instances. |
| The right pixels | Not a security property, but the one a reader cares about: the image in the document is the image on disk. | The colour and mask streams are inflated back out of the finished PDF and compared with what GD reads from the source: 1 980 sampled pixels, 0 mismatches, for both an opaque and a transparent logo. |

Two notes on scope rather than findings:

- **`RasterImage` is the only component in the renderer that parses attacker-influenceable binary data.** It accepts bytes, never a path or a URL, so it cannot be talked into opening the wrong thing; deciding what may be read is entirely the resolver's job. Its accepted-format list is short and closed, and an unrecognised signature is a rejection rather than a guess.
- **PNG must be decoded, JPEG must not.** JPEG data goes into `/DCTDecode` untouched. PNG has to be inflated and unfiltered because transparency becomes a PDF soft mask — and the real GEWARD logo is an RGBA PNG, so a pass-through-only design would have refused the actual logo. The decoding is where a decompression bomb would live, which is why the inflated length is pinned rather than trusted.

### Residual risk on the engine

**No visual confirmation of the page was possible offline.** No PDF rasteriser exists on this machine (no Ghostscript, poppler, qpdf or mutool). The documents were opened in the local browser's PDF viewer, which loaded them and read the title from the info dictionary — that shows PDFium accepts the file, including one carrying an image XObject, not that the page looks right. One person should open `evidence/pdf-logo-with.pdf` once. Everything else is proven by reading the file back.

The **logo itself has been looked at**: it is extracted from the finished PDF into `evidence/pdf-logo-extracted.png` and is the GEWARD wordmark, navy on white, undistorted.

**Which logo the site is set to is worth an operator's attention.** The media library holds two families — `Logo-*.png` is navy on light, `Logo-2-*.png` is white on dark. Whichever attachment is the Custom Logo is embedded faithfully; if that is the inverted variant, a white invoice will carry a dark block. That is correct behaviour with the wrong asset, and it is a site setting, not a code change.

Still outstanding: no layout fidelity against the Fakturownia reference.

---

## Blocking status before any external PDF or email delivery

Original blocking set: C1, H1, H4, H2, H3, H5, H6, M4, M5, M6, M8.

| Item | Status |
|---|---|
| C1, H1, H2, H3, H4, H5, H6, M4, M5, M6, M8 | Fixed and verified |
| Production PDF engine | Selected, built, reviewed; open item is a single visual confirmation |

Nothing in the plugin wires a Mailer or a PdfRenderer — asserted automatically — so there is still no code path that can send anything. Delivery is now gated only on the separate end-to-end sandbox stage and its approval, not on the engine.
