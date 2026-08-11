# Commerce Documents Kit — review fixes, final report

Branch: `agent/geward-document-module-stage0`
Baseline for the fixes: `cecbf84`
Companion documents: `security-findings.md` (per-finding status), `test-results.md` (evidence)

Constraints observed: no change to the main Geward repository, no branch or worktree change, no WordPress or database migration, no Laragon install, no deploy/push/merge/release, no real email, no external PDF or email provider, no Fakturownia or KSeF, no secrets in Git or the database.

---

## Outcome

| Severity | Total | Fixed | Partially fixed | Open |
|---|---|---|---|---|
| Critical | 1 | 1 | – | – |
| High | 6 | 6 | – | – |
| Medium | 10 | 8 | 2 (M9, M10) | – |
| Low | 6 | 4 | – | 2 (L2, L6) |

PHP lint: 83 files, 0 errors. Focused runtime checks: 59/59 pass, exit 0. PHPUnit not executed — `vendor/` is absent and installing it requires network access.

**One blocker is recorded rather than worked around**: the production PDF engine. Details at the end.

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

**M10 — removing legacy document types.** The settings screen and the runtime policy can no longer produce `proforma` or `invoice`, but `DocumentType` still accepts them so historical rows stay readable. Removing the constants belongs with the schema-5 legacy cleanup, not before it.

**L2, L6 — open.** L2 (exception text in the admin notice) is admin-only and escaped; suppressing it would make failed generations harder to diagnose. L6 (the audit event is written after the document is persisted) needs persistence and logging to become one unit of work, which is the same transactional question deferred in H5 and should be decided with it, not separately.

---

## Blocker: production PDF engine

**This one I stopped on rather than worked around, as instructed.**

`BasicPdfRenderer` is no longer lossy, but it is still not what should reach a customer:

- it depends on the **viewer's** Helvetica containing the glyphs named in the `/Differences` array — true in Acrobat, pdf.js and Ghostscript, not guaranteed everywhere, and there is no font embedding;
- no text wrapping and no pagination — beyond ~24 line items it truncates with a marker instead of flowing to page 2;
- no logo and no layout fidelity against the Fakturownia reference.

Closing this means embedding a TrueType subset or adopting an engine (Dompdf 3.x being the obvious candidate). Both require a Composer dependency and therefore network access, which is out of scope for this pass, and the engine choice carries its own security surface.

**Recommended sequence:** decide the engine → security-review it specifically (for Dompdf: pinned version, `isRemoteEnabled = false`, no external resources in the template — that exact combination is what made the legacy WebToffee plugin exploitable) → only then wire delivery.

---

## Current delivery posture

`SandboxMailer`, `BasicPdfRenderer` and `DeliverDocument` are still referenced only from tests. No plugin code path constructs a Mailer or a PdfRenderer, and no transport call exists in any of them. **Nothing can send anything today**, which remains the correct posture until the engine decision and the end-to-end sandbox stage are done.

---

## Limitations

- **PHPUnit was not executed.** New and updated tests are written and lint-clean but unrun; I make no claim about the suite's pass/fail state. The equivalent assertions were exercised through `verify-fixes.php`, which does run and passes 59/59.
- **No WordPress runtime was involved.** wpdb, dbDelta, HPOS declaration and the admin screens are verified by code reading and by a format-applying wpdb stand-in, not against a live WordPress. The C1 fix in particular should be confirmed once against a real wpdb — it is a five-minute check.
- **The new schema (version 4) has not been applied anywhere.** Adding `chain_position` over existing global-chain rows is the risky step; `preflight()` is designed to block it, but that blocking path has not been exercised against real data.
- **File permissions could not be verified on this machine** — PHP's `chmod()` on Windows only toggles the read-only bit. The 0600 mode is asserted as a call, not as an observed result.
