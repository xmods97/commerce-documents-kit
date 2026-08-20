# Handoff — Commerce Documents Kit, sandbox-email checkpoint

**Status: PASS at `6a16f60`.**
Branch: `agent/geward-document-module-stage0`, isolated clone at `.artifact-work/commerce-documents-kit`.
Working tree clean. Nothing pushed, merged, deployed or released. No email was ever sent.

Everything below was re-run and re-read at the time of writing, not recalled.

---

## 1. What this branch now is

A document module that can **produce** an order confirmation as a PDF and **capture** it locally as an `.eml`, entirely inside the WordPress admin, driven by an operator clicking a button. The encrypted immutable snapshot is the durable document record; PDFs are generated on demand for preview/download. It cannot send anything, and no order hook or scheduler can make it act.

Two admin actions exist, both on `admin_post_*`, both behind `manage_woocommerce` plus a nonce bound to the specific document:

| Action | Does | Does not |
|---|---|---|
| `commerce_documents_preview_pdf` | Renders one document and returns it inline as `application/pdf` | Store anything, send anything |
| `commerce_documents_sandbox_email` | Renders the same PDF and writes one `.eml` to a local directory; prunes only expired generated captures; records `document.sandbox_stored` | Call `wp_mail`, SMTP, sockets or HTTP |

## 2. Commits, oldest first

| Commit | What it did |
|---|---|
| `cdc1317` | Production PDF engine: a data-to-PDF writer with an embedded DejaVu Sans subset. Dompdf 1.0.2 and mPDF, both present only as unpinned copies inside third-party plugin vendor directories, were rejected. |
| `e5392de` | Site logo from the WordPress Custom Logo, read from a local file in uploads. PNG decoded (transparency becomes a soft mask), JPEG passed through as `/DCTDecode`. |
| `2343a4e` | Never embed a cropped size variant. WordPress generates scaled copies *and* hard crops; a crop of a lockup is the mark with the wordmark cut off. |
| `b68b05d` | Wired the renderer and the logo provider to the admin preview — one construction site, no mailer. |
| `e1ddb4d` | Layout: two columns were printing on top of each other. Fixed structurally, and added a collision detector that reads the PDF as a layout. |
| `320840c` | *(not mine)* Admin-only sandbox email capture. |
| `6a16f60` | Independent review of `320840c`: refused capture directories inside the web root, strengthened two checks that did not test their claims. |

## 3. Checks — all re-run at handoff time

```
PHP lint                            103 files, 0 errors
review/claude/verify-sandbox-admin-wiring.php   18/18   exit 0
review/claude/verify-pdf-engine.php             87/87   exit 0
review/claude/verify-logo-security.php          69/69   exit 0
review/claude/verify-admin-preview.php          34/34   exit 0
review/claude/verify-fixes.php                  59/59   exit 0
git diff --check                                clean
```

267 checks, all passing. To reproduce (PHP 8.3 from the local toolchain; PHP 7.4 also targeted):

```bash
php review/claude/verify-sandbox-admin-wiring.php
```

```bash
php review/claude/verify-admin-preview.php
```

`verify-pdf-engine.php` and `verify-logo-security.php` take an optional path argument — the directory holding `DejaVuSans.ttf` and a logo file respectively. Without it they skip the source-comparison checks and still pass.

**PHPUnit has never been run.** `vendor/` is absent and installing it needs network access. Every test file is written and lint-clean; the equivalent assertions live in the harnesses above, which do execute. Make no claim about the suite's state until someone runs it.

## 4. Open risks, most important first

1. **No page has ever been rasterised inside this work.** Layout is verified arithmetically — E13 measures every text run and fails if two on a baseline come within 2 pt — but that check exists *because* the first human look found two collisions. Look at a real page after any layout change.
2. **Sandbox captures are temporary test artifacts.** Each `.eml` holds the buyer's name, their email address and the whole rendered invoice. Generated captures older than 7 days are pruned when the sandbox action is run; the window can be overridden with `COMMERCE_DOCUMENTS_SANDBOX_RETENTION_DAYS` in `wp-config.php` (1–3650). Immutable snapshots and audit events are never pruned.
3. **The web-root guard depends on `ABSPATH`.** It refuses a capture directory at or inside `realpath(ABSPATH)`. An nginx `alias`, a symlinked docroot or a second docroot outside `ABSPATH` is not covered. The default — a directory under the system temp path — is unaffected.
4. **No WordPress runtime was ever involved.** Capability checks, nonces, response headers, media lookups and the `wpdb` calls are verified by reading the code and by fixture-backed stand-ins. The C1 persistence fix in particular deserves one confirmation against a real `wpdb`.
5. **If the site's Custom Logo is an SVG there will be no logo** — deliberately, and the answer is a PNG Custom Logo, not a looser parser. Also: the media library holds a navy-on-light and an inverted white-on-dark family; the inverted one puts a dark block on a white invoice.
6. **Rendering happens in the request, uncached.** Fine for an operator-initiated preview; revisit before anything renders in bulk.
7. **M9 remains partially fixed** — the rollback plan is printed, not executable. **L2 and L6 remain open** — an exception message reaches an admin notice, and the audit event is written after the document is persisted.
8. **The failure path writes the exception message to the PHP error log**, which can include the capture directory path.

## 5. Local smoke test — no real email

This is the check nobody has run: the flow on a real WordPress. It is written to be safe, but it touches a database, so **run it on a local install only, never on geward.pl.**

### 5.1 Prerequisites — the operator's decisions, not done here

- A **local** WordPress with WooCommerce. PHP 7.4+ with `openssl`, `zlib` and `fileinfo` enabled. (`gd` is needed only by the offline harness fixtures, not by the plugin.)
- The plugin's tables must exist. That is a **schema migration**, which was deliberately not run in this work. Do it from the plugin's own admin screen, which refuses to migrate while `preflight()` reports a blocker.
- Take a database backup first. The migrate screen asks you to confirm you have one; nothing can verify that for you.

### 5.2 Configure — in `wp-config.php`, above the "stop editing" line

```php
define('COMMERCE_DOCUMENTS_ENCRYPTION_KEY', '<base64 of 32 random bytes>');
define('COMMERCE_DOCUMENTS_AUDIT_HMAC_KEY', '<a different base64 of 32 random bytes>');
define('COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR', 'C:\\cdk-sandbox-mail');
// Optional: default is 7 days; allowed range is 1–3650.
define('COMMERCE_DOCUMENTS_SANDBOX_RETENTION_DAYS', 7);
```

Generate each key separately and never reuse one for the other:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

**These are secrets. They belong in `wp-config.php` on that machine only — never in Git, never in the database, never in a ticket.** Losing the encryption key makes existing documents unreadable; changing the audit key breaks chain verification for existing events.

The capture directory **must be outside the web root** — that is now enforced, and the attempt fails with a message saying so. `C:\cdk-sandbox-mail` above is outside a typical Laragon docroot; adjust for your layout.

### 5.3 Belt and braces: make outgoing mail impossible for the duration

The plugin has no transport, so this changes nothing about its behaviour. It is there so that a mistake anywhere else on the test site still cannot send. Create `wp-content/mu-plugins/00-block-outgoing-mail.php` **on the local site only** — not in this repository:

```php
<?php
// Local smoke test only. Delete when finished.
add_filter('pre_wp_mail', static function () {
    error_log('Outgoing mail blocked during the Commerce Documents smoke test.');
    return false;
}, 0);
```

Confirm it works before going further: anything on the site that would normally email (a password reset, for instance) must produce no mail and one line in the PHP error log.

### 5.4 The run

| # | Do | Expect |
|---|---|---|
| 1 | Activate the plugin. Open **WooCommerce → Commerce Documents**. | The screen loads and says documents and captures stay local. |
| 2 | Fill in the seller block and save. | Settings persist; no warning about incomplete settings. |
| 3 | Take a **paid** WooCommerce order — a paid-class status *and* a gateway payment date. Generate a document for it. | One document appears in the list, typed `order_confirmation`, state `Issued`. A cancelled, failed or unpaid-COD order must produce nothing. |
| 4 | Click **View / print**. | HTML view, Polish text intact, audit trail shown as verified. |
| 5 | Click **Preview PDF**. | A PDF opens inline. Check: the site's Custom Logo top right, whole and undistorted; `ą ć ę ł ń ó ś ź ż` rendered as letters; the description column not touching the quantity column; the VAT summary columns not touching; totals correct. |
| 6 | Click **Create sandbox email**. | Green notice: *"Local sandbox .eml created. No email was sent."* |
| 7 | Look in `COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR`. | Exactly one new `*.eml`. Open it in a text editor: `From: sandbox@example.invalid`, `To:` the buyer's address from the document, a readable text part, and a base64 `application/pdf` attachment. Save the attachment and open it — it must be the same document as step 5. |
| 8 | Click **Create sandbox email** again. | A **second** file appears. Neither is overwritten. |
| 9 | Reopen the document view. | The audit trail lists `document.sandbox_stored` and the chain still verifies. |
| 10 | Check the mail log / mail queue of the local site. | Nothing. No outgoing message, no queued message, no error other than the mu-plugin's line if something else tried. |

### 5.5 The negative check worth doing once

Temporarily point `COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR` at something inside the web root, e.g. `.../wp-content/uploads/cdk-mail`, and click **Create sandbox email**.

Expect: a red notice, *no* file created, and a line in the PHP error log about the directory having to sit outside the web root. Then put the constant back. This is the fix from `6a16f60`; if it does not refuse, stop and report it.

### 5.6 Afterwards

- **Delete the `.eml` files.** They contain a real buyer's name, email address and invoice.
- Delete `wp-content/mu-plugins/00-block-outgoing-mail.php`.
- Record what you saw in step 5 — that is the visual confirmation this work could never produce itself.

## 6. What is deliberately not done

- **No real delivery.** No mailer wired to anything but the local capture; no `wp_mail`, SMTP, HTTP or socket anywhere in the plugin.
- **No automatic generation.** The automatic hook stays gated behind an option that is off, and legacy fiscal types (`invoice`, `proforma`, …) can be read but not issued.
- **No Fakturownia, no KSeF, no external provider.**
- **No install, migration, deploy, push, merge or release** was performed at any point.

## 7. Suggested next step

Run section 5 and report what step 5 and step 7 looked like. Everything else about this branch is verified as far as it can be without a WordPress runtime and a PDF rasteriser; those two observations are what remain.
