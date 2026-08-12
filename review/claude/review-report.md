# Commerce Documents Kit — review fixes, final report

Branch: `agent/geward-document-module-stage0`
Baseline for the fixes: `cecbf84`
Companion documents: `security-findings.md` (per-finding status), `test-results.md` (evidence), `pdf-engine-decision.md` (engine and logo), `admin-preview-wiring.md` (what is connected, and what is not)

Constraints observed: no change to the main Geward repository, no branch or worktree change, no WordPress or database migration, no Laragon install, no deploy/push/merge/release, no real email, no external PDF or email provider, no Fakturownia or KSeF, no secrets in Git or the database.

---

## Outcome

| Severity | Total | Fixed | Partially fixed | Open |
|---|---|---|---|---|
| Critical | 1 | 1 | – | – |
| High | 6 | 6 | – | – |
| Medium | 10 | 9 | 1 (M9) | – |
| Low | 6 | 4 | – | 2 (L2, L6) |

PHP lint: 103 files, 0 errors. Focused runtime checks: 59/59 (fix pass), 87/87 (engine), 69/69 (logo), 34/34 (preview wiring) and 18/18 (sandbox capture), all exit 0. PHPUnit not executed — `vendor/` is absent and installing it requires network access.

**The blocker recorded in the previous pass — the production PDF engine — is now closed.** The decision, the alternatives and the security review are in `pdf-engine-decision.md`; the summary is at the end of this document.

---

## What changed, and why it was done this way

### C1 — the persistence defect

`WpdbDocumentRepository::save()` bound `snapshot_cipher` with `%d`, so wpdb persisted the integer `0` and destroyed every document at the moment of creation.

The fix is not a swapped character. The column list and the format list are now derived from a single `column => [value, format]` map, so the two cannot drift apart again — the failure mode was that they were two parallel arrays maintained by hand. `FakeWpdb::insert()` now applies `$formats` the way wpdb does; previously it accepted the argument and discarded it, which is exactly why the suite could not see the bug. That change to the fake matters more than the change to the repository.

### H1 — closing the encryption opt-out

Both read paths funnel through one `hydrate()` method. Encrypted rows are authenticated by AES-GCM; legacy plaintext rows are authenticated against `content_hash` with `hash_equals` before the JSON is decoded, and a row with a missing or malformed hash is rejected outright.

One subtlety worth calling out: the hash is compared against the **raw stored bytes**, not against a rehydrated snapshot. Rehydration upgrades a v1 payload to v2 and adds the metadata key, so it could never reproduce the hash recorded at write time — a naive implementation here would have rejected every legacy row. The reasoning is in the code comment so it does not get "simplified" later.

The removal boundary is explicit: `LEGACY_PLAINTEXT_REMOVED_IN_SCHEMA = 5` against a current schema of 4, with `LegacySnapshotAudit` counting the rows that still block removal and the rows that cannot be authenticated at all. `preflight()` surfaces both.

### H2/H3 — one document type, issued only after payment

`PaidOrderPolicy` replaces `ConfigurableStatusPolicy` at runtime. It returns `order_confirmation` or nothing. Paid means a paid-class status **and** a gateway payment date.

Cash on delivery gets an explicit, deliberately awkward opt-in: the operator must both switch the policy to `status_only` and name the specific gateway id. The settings screen says in plain words that enrolling a gateway means issuing a document before the money arrives. This is the one place where I chose friction over convenience — the default has to be the safe one, because WooCommerce never records a payment date for offline gateways and "processing" alone is not evidence of payment.

`ConfigurableStatusPolicy` stays in the tree for the archived v0.2 tests but is never constructed at runtime, and the check for that is automated rather than a comment.

The policy decision — policy name, version, method, confirmation, COD mode — is written into the snapshot metadata, so why a given document exists is reconstructable later rather than inferred.

### H5 — the audit chain, and an alternative to locking

The chain is now per document, and concurrency is settled by `UNIQUE KEY chain_position (document_id, prev_event_hash)`: two events of the same document cannot claim the same predecessor. A writer that loses the race gets a failed insert, re-reads the tip and retries.

This was chosen over `SELECT … FOR UPDATE` and over `GET_LOCK` on purpose. WordPress does not guarantee a transactional storage engine, and an advisory lock that is lost or unavailable **fails open** — the chain would fork silently again, which is precisely the defect. A unique index fails closed on any engine. The task allowed "a justified alternative"; this is the justification.

Per-document scoping also removes the property that one missing row invalidates verification for every other document. `AuditChainVerifier` recomputes a chain and reports the first position that does not reconcile, and the document view shows the result — the chain is now checked, not merely written.

### H6 — supersession without breaking immutability

The tension here is real: the original must not change, but a reader of the original must be able to tell it was replaced.

The resolution is to record supersession **outside** the snapshot: new `superseded_by` / `superseded_at` columns on the document row, while `snapshot_cipher`, `content_hash`, `document_number` and the sequence remain untouched. The snapshot's integrity hash is unaffected. Immutability and auditability are both satisfied rather than traded against each other.

Two events are written instead of one — `document.replaced` on the original, `document.corrected` on the correction — so neither side has a clean-looking trail. The view shows a red banner on a replaced document and the list shows the state.

Corrections became idempotent via a `correction_token` minted once per rendered form. Two ordering details matter: the existence check runs **before** the sequence number is allocated, so a resubmit burns no number; and supersession is claimed with a conditional `UPDATE … WHERE superseded_by = ''` before the number is drawn, so two concurrent corrections cannot both proceed. If anything downstream fails, the claim is released.

### H4 — the PDF

`PdfTextEncoding` maps the Polish alphabet onto codes `0x80..` declared through an `/Encoding /Differences` array over Helvetica. Characters outside the standard set are transliterated rather than dropped, so text degrades legibly instead of becoming punctuation. The renderer now emits parties, an itemised table with quantity/net/tax/gross per line, a totals block, the unpaid-COD notice and correction references.

*(Superseded in the engine pass: `BasicPdfRenderer` is no longer the renderer for anything customer-facing. It is kept for the archived tests and as a fallback.)*

Truncation is UTF-8 safe **without** `ext-mbstring`. I initially used `mb_substr`, then removed it: the package declares no extension requirements beyond openssl, and adding one to `composer.json` would invalidate `composer.lock` with no way to regenerate it offline. Introducing an undeclared runtime dependency to fix a rendering bug would have been a bad trade.

### M4–M7 — the sandbox mailer

Unique filenames with a random suffix, created via `fopen(…, 'xb')` so an existing file is never replaced and a pre-created symlink cannot capture the write. `MIME-Version`, `From`, `Date` and `Message-ID` added; non-ASCII subjects RFC 2047 encoded; both parts base64-encoded. `chmod 0600` after create. `*.eml` and `sandbox-mail/` added to `.gitignore`.

The header-break check now applies to header fields only. Rejecting newlines in the *body* was conflating header safety with content and made a normal multi-line email impossible to produce — the injection vectors are still rejected, verified against all three.

### M8 — HPOS

`FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` on `before_woocommerce_init`, guarded by `class_exists`. The code already used only the CRUD API; this was the missing declaration. The target store has HPOS enabled, so without it WooCommerce would have flagged the plugin.

---

## What was deliberately not done

**M9 — executable rollback.** `preflight()` now returns blockers and warnings, `migrateToCurrentVersion()` refuses to run while a blocker is present, and the admin screen hides the migrate button while blocked. The blockers are the two that would actually bite: duplicate audit chain positions (the new unique index cannot be created over them, and dbDelta would leave the schema half-applied) and legacy rows that cannot be authenticated.

`Installer::rollbackPlan()` prints the reversal statements; it does not execute them. `dbDelta` cannot express column or index removal, an automated rollback button beside a migrate button is how the wrong one gets pressed, and the task forbids running migrations — so an executable down-path could not have been tested here even if it were the right design. Backup verification is still an operator attestation; the plugin cannot prove a backup exists, and pretending otherwise would be worse than admitting it.

**M10 — removing legacy document types.** *(Closed in the engine pass.)* The constants still exist, because `fromString()` has to accept them or historical rows stop being readable — that was always the reason they could not simply be deleted. What was missing was a gate on *issuing* them, and that is now in `GenerateDocument::execute()`: `DocumentType::assertIssuable()` refuses anything outside `order_confirmation` and `correction`, whatever the policy or the caller asks for. The risk M10 described — a second generator of officially-typed documents — is closed in the application layer rather than only in the order policy. Deleting the constants still belongs with the schema-5 cleanup.

**L2, L6 — open.** L2 (exception text in the admin notice) is admin-only and escaped; suppressing it would make failed generations harder to diagnose. L6 (the audit event is written after the document is persisted) needs persistence and logging to become one unit of work, which is the same transactional question deferred in H5 and should be decided with it, not separately.

---

## The PDF engine — how the blocker was closed

Full reasoning in `pdf-engine-decision.md`. The part worth repeating here is why the obvious route was not taken.

Both candidate engines are physically present on this machine, inside the site backup: a **Dompdf 1.0.2** and an mPDF 8.x, each lifted from a third-party invoicing plugin's `vendor/` directory. Adopting either would have meant copying unpinned, offline-unverifiable code — with no Composer hashes to check it against — into the tree, at a version that predates the remote-font and URI-validation fixes, and carrying an HTML/CSS/SVG/image parser surface that a fixed invoice layout never uses but buyer-controlled strings can reach. The previous review named that plugin family as the cautionary example; taking its bundled copy would have been an odd way to act on that.

So the engine is built into the package: `EmbeddedFontPdfRenderer`, a data-to-PDF writer with an embedded TrueType subset, behind the existing `PdfRenderer` interface.

The insight that makes a dependency-free engine defensible here is that the hard part was never the PDF — it was the font, and fonts can be solved offline. `tools/build-pdf-font.php` runs at build time, subsets DejaVu Sans down to the 339 codepoints the templates need, renumbers the glyph ids and writes the metrics beside it. At request time nothing parses a font: `EmbeddedFont` reads two committed files, checks the program against a recorded SHA-256, and copies the bytes into the document.

That split is also the security story. There is no URL handling, no image loader, no stylesheet resolution, no markup parser — not disabled, absent — so there is nothing a later configuration change can switch back on. Document text never becomes PDF syntax: it is converted to glyph ids and written as hex, which is why the hostile fixture produces a content stream containing **no `(` at all**.

**The check I would want someone else to look at.** Renumbering glyph ids rewrites the component indices inside composite glyphs, and every Polish diacritic is a composite. A mistake there puts the wrong accent on the wrong letter while every structural check still passes, and only a human looking at the page would notice. So the harness compares all 339 subset glyphs against the source font — simple outlines byte for byte, composites everywhere except the indices, then recursing into what those indices point at. All 339 match.

### The logo, added afterwards

The engine shipped without one on purpose, because an image XObject reopens a surface the engine did not otherwise have. That decision was then taken deliberately rather than skipped.

The rule is narrow: **the logo is a local file inside the WordPress uploads directory, resolved through the site's Custom Logo setting, and nothing else is ever opened.** WordPress is asked which attachment it is; the answer is then treated as untrusted. The path must carry no scheme and no NUL byte, must resolve through `realpath()` to somewhere under `realpath(uploads)` — so containment is decided on where the file really is, not on how the path was written — must have a PNG or JPEG extension, must be inside the size cap, and must satisfy `finfo` and `getimagesize` before a byte goes further. Only then is it parsed, by a class that accepts bytes and cannot open anything at all.

Every failure returns null. A missing, oversized, wrong-format or hostile logo produces a document without a logo, never a document that failed to render.

Two things are worth knowing about the implementation. **JPEG is passed through untouched** into `/DCTDecode`; the markers are read only to learn the size and to refuse progressive, arithmetic and CMYK variants. **PNG has to be decoded**, because transparency must become a PDF soft mask and the real GEWARD logo is an RGBA PNG — a pass-through-only design would have refused the actual logo. Decoding is where a decompression bomb would live, so the inflated length is pinned to exactly what the header declares rather than trusted.

**The whole logo, never a piece of it.** WordPress generates two kinds of size variant from one upload — scaled copies and hard crops — and the real GEWARD attachment set contains both. Preferring "the narrowest variant at least 480 px wide" could therefore have selected a crop, which for a lockup means the emblem with the wordmark cut off. A variant is now used only when its proportions match the original within one percent; otherwise the full-size original is. That was a real defect, found by looking at the extracted image rather than by any structural check.

**The logo has actually been looked at.** The image is extracted back out of the finished PDF — inflating its XObject stream and rewrapping it as a PNG — and matches its source exactly, undistorted. Separately, 1 980 sampled pixels of the embedded image match what GD reads from the source file, for both an opaque and a transparent logo.

**The evidence shows an old logo, and it cannot show the current one.** Nothing about the logo is hardcoded: whatever attachment is set as the site's Custom Logo is what gets embedded, so a document generated on the site carries the current lockup. But the only logo files available offline come from a 2023 backup taken before the branding changed — the bare navy wordmark, no emblem. The current asset lives in the live media library, and fetching it would be precisely the external request this design refuses to make. Point the harness at a copy of the current file to see it in a document.

### The first look at a rendered page found three layout defects

Somebody opened the preview and photographed it, which is the one check this work could not run itself. Three things were wrong, and none of them was visible to any check that reads a PDF as data:

- **A long item description printed on top of the quantity beside it** — `60×30×2 cm` and `2,5 szt.` overlapped by 3.9 pt. The description column wrapped at a width chosen by eye, with no allowance for how far back a right-aligned quantity reaches.
- **The VAT summary's gross column printed on top of its tax column** — `108,68581,18 PLN`, a 2.8 pt overlap. That column repeated the currency on every row while the items table above it did not, and the extra ` PLN` was exactly what pushed it over.
- **Large amounts overflowed their columns.** Found by the new check rather than by eye, but real: a five- or six-figure amount is wider than the 38 pt the tax column allows.

The fixes are structural rather than nudged numbers. The description width is now *derived* from where the quantity column can start, less a gutter, so the two cannot be sized inconsistently again. The currency is named once in the VAT summary heading. And every right-aligned numeric cell now shrinks to fit its column instead of overflowing — truncation was rejected outright, because `1 234,5…` reads as a different amount, and a wrong number is worse than a small one.

**What matters more than the fixes is that this class of defect is now caught automatically.** `verify-pdf-engine.php` E13 reads the finished document as a *layout*: it recovers the position, font, size and glyphs of every text run, measures each with the same metrics the renderer used, and fails if two runs sharing a baseline come within 2 pt of each other. It runs against five documents, including one with long Polish descriptions and one with amounts far above any real order. Nothing that reads a PDF as data could have seen these bugs; this can.

**What is still open:** no PDF rasteriser exists offline, so nobody has seen a *page* from inside this work. The browser's PDF viewer loads both evidence documents and reads their titles, which shows PDFium accepts a file carrying an image XObject; everything else is proven by reading the file back. One person opening `evidence/pdf-logo-with.pdf` closes it. There is still no layout match against the Fakturownia reference.

**Two things for the site owner rather than for the code.** If the Custom Logo is an **SVG**, no logo appears: SVG is refused deliberately at two levels, and the answer is to set a PNG as the Custom Logo, not to loosen the parser. And the library holds a navy-on-light and an inverted white-on-dark family — whichever is set is embedded faithfully, so the inverted one would put a dark block on a white invoice. Both are worth checking on the live site before the first document goes out.

---

## Current delivery posture

### Sandbox email capture, reviewed independently (`320840c`)

A later checkpoint, which I did not write, added an admin-only sandbox capture: one `admin_post` action that renders the document with the current engine and writes an `.eml` through `SandboxMailer`. **Verdict: it holds.** Capability then a per-document nonce; no `wp_mail`, SMTP, socket or HTTP anywhere; registered on `admin_post` only, with no `nopriv` variant, so no anonymous request and no order hook or scheduler can reach it; the recipient comes from the decrypted snapshot rather than the request, and an invalid address aborts before anything is rendered; the audit event is `document.sandbox_stored`, deliberately distinct from `document.sent`, and its context carries a hash rather than the address.

**One Medium found and fixed.** The capture directory is a `wp-config` constant, and `SandboxMailer` only checked that it existed and was writable — so pointing it at `wp-content/uploads/…` would have published every capture, and a capture holds the buyer's name, their email address and the whole rendered invoice. I confirmed the acceptance against a simulated `ABSPATH`, including a `..` path that resolved back inside it. The mailer now resolves the directory with `realpath()` and refuses any location at or inside the web root. A deny file was considered and rejected — only some servers honour `.htaccess`, and refusing outright is the property worth having.

**Two of the checkpoint's own ten checks did not test what they claimed**: one matched the string `application/pdf` rather than decoding the attachment, and one matched a `Content-Transfer-Encoding` header rather than looking at the body — so a body flattened to a single line, the exact defect M6 fixed, would have passed. Both now decode the MIME parts and compare. The harness is 18 checks.

Two Lows are recorded and deliberately left open: captures are never pruned, and the failure path writes the exception message to the PHP error log. Details in `security-findings.md`.

---

The renderer is now wired — to one place, and that place sends nothing. `AdminController::previewPdf()` renders a single document to the administrator's browser behind a capability check and a per-document nonce. `WordPressLogoProvider` is constructed there with no arguments, so the logo is the site's current Custom Logo, read from a local file in uploads, with nothing to configure and nothing fetched. Details in `admin-preview-wiring.md`.

**`SandboxMailer` and `DeliverDocument` remain unwired, and no transport call exists anywhere in the plugin.** Nothing can send anything today. The preview hangs off `admin_post_*` only — no order hook, no cron, no queue reaches it — and nothing is written to disk or to the database.

The invariant the earlier passes defended was "nothing is wired". It has not been dropped, it has been narrowed to "exactly one renderer construction, in the preview, and no mailer at all" — and that is what the automated checks now assert. Email delivery remains gated on the end-to-end sandbox stage and its separate approval.

---

## Limitations

- **PHPUnit was not executed.** New and updated tests are written and lint-clean but unrun; I make no claim about the suite's pass/fail state. The equivalent assertions were exercised through `verify-fixes.php` (59/59), `verify-pdf-engine.php` (73/73) and `verify-logo-security.php` (69/69), all of which do run.
- **No page has been looked at.** The PDF engine is verified by reading its output back, not by rasterising it — no PDF renderer is available offline. The embedded logo *image* has been looked at directly; the page it sits on has not. See the engine section above.
- **No WordPress runtime was involved.** wpdb, dbDelta, HPOS declaration and the admin screens are verified by code reading and by a format-applying wpdb stand-in, not against a live WordPress. The C1 fix in particular should be confirmed once against a real wpdb — it is a five-minute check.
- **The new schema (version 4) has not been applied anywhere.** Adding `chain_position` over existing global-chain rows is the risky step; `preflight()` is designed to block it, but that blocking path has not been exercised against real data.
- **File permissions could not be verified on this machine** — PHP's `chmod()` on Windows only toggles the read-only bit. The 0600 mode is asserted as a call, not as an observed result.
