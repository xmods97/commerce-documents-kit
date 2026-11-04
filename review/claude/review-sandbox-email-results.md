# Sandbox `.eml` delivery review — commands and results

**Commit:** `85fd155affe3d1c6c094039ff4c364725cff02d9`
**Branch:** `agent/geward-document-module-stage0`
**Date:** 2026-08-20
**PHP used:** `D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` — PHP 8.3.30 (cli), ZTS
Visual C++ 2019 x64. Existing binary; **nothing was installed**, no network access was used.
**Platform:** Windows 11. POSIX permission bits are not meaningful here — see the skipped check.

Report: [review-sandbox-email.md](review-sandbox-email.md).

---

## 1. Repository state

```
git rev-parse HEAD   -> 85fd155affe3d1c6c094039ff4c364725cff02d9
git branch --show-current -> agent/geward-document-module-stage0
git diff --check     -> (no output), exit 0
```

`git status --porcelain` — **no tracked file is modified**; every entry is an untracked addition
under `review/claude/`:

| Added by this review | |
|---|---|
| `review/claude/review-sandbox-email.md` | the report |
| `review/claude/review-sandbox-email-results.md` | this file |
| `review/claude/verify-sandbox-email-security.php` | new harness |
| `review/claude/evidence/verify-sandbox-email-security-85fd155.txt` | its output |
| `review/claude/evidence/verify-sandbox-admin-wiring-85fd155.txt` | existing harness output at this commit |
| `review/claude/evidence/verify-beta-runtime-85fd155.txt` | existing harness output at this commit |

Pre-existing untracked review artifacts from earlier sessions were **not** deleted, moved or
overwritten.

---

## 2. PHP lint

```bash
php -l packages/wordpress/src/SandboxMailer.php
php -l packages/document-core/src/Application/DeliverDocument.php
php -l packages/woocommerce/src/AdminController.php
php -l packages/woocommerce/src/Plugin.php
php -l packages/wordpress/src/AuditChainVerifier.php
php -l packages/wordpress/src/WpdbEventLogger.php
php -l packages/document-core/src/Contracts/StagedMailer.php
php -l packages/document-core/src/Party.php
php -l review/claude/verify-sandbox-email-security.php
```

Result: **`No syntax errors detected` for all 9 files.**

---

## 3. Harnesses

| # | Command | Result | Exit | Evidence |
|---|---|---|---|---|
| 1 | `php review/claude/verify-sandbox-admin-wiring.php` | **checks=19 pass=19 fail=0** | 0 | `evidence/verify-sandbox-admin-wiring-85fd155.txt` |
| 2 | `php review/claude/verify-beta-runtime.php` | **checks=25 pass=25 fail=0** | 0 | `evidence/verify-beta-runtime-85fd155.txt` |
| 3 | `php review/claude/verify-sandbox-email-security.php` *(new, this review)* | **checks=77 pass=77 fail=0**, 1 skip | 0 | `evidence/verify-sandbox-email-security-85fd155.txt` |

The single skip is `posix permission bits are not meaningful on this platform` — the `0600`
capture / `0700` directory assertion is compiled into the harness and runs on POSIX, but cannot
be evaluated on Windows. It is listed as a manual smoke test instead.

### 3.1 What the new harness covers

Written to complement `verify-sandbox-admin-wiring.php`, not repeat it:

- **Endpoint (7 checks):** nonce string is document-bound on both the trigger and the rendered
  form; capability precedes the nonce and `wp_die`s; no `admin_post_nopriv`, no `wp_ajax`, no
  `register_rest_route`, no `wp_schedule_*` anywhere in `packages/` or `plugins/`.
- **Data source (6 checks):** audit verification precedes construction of the mailer and the
  delivery service (source-order); the action reads exactly one request field, `document_id`;
  no `wc_get_order`/`get_billing_*`; the failure branch returns a fixed message rather than the
  exception text; the PDF is rendered from the same `$snapshot` that is staged.
- **Hostile input (17 checks):** six hostile `document_id` shapes (POSIX and Windows traversal,
  CRLF, quote break-out of the `filename=` parameter, NUL byte, whitespace); three CRLF/CR/LF
  subjects; four hostile recipients; a sender carrying a header break; and the assertion that
  no file of any kind was produced by any refusal.
- **MIME (16 checks):** RFC 2047 encoding of a non-ASCII subject with a decode-back assertion;
  every header line parses as one well-formed field; the boundary is 24 random hex chars and
  occurs exactly four times; exactly two parts; a PDF containing NUL, CR, LF, `0xff` and a
  boundary look-alike decodes byte for byte under strict base64; the text part round-trips with
  CRLF endings; the 76-character base64 line limit; a quoted, charset-restricted attachment
  filename; no `text/html`, `multipart/related`, `<script`, `<html`, `http(s)://` or `cid:`;
  local unroutable `From:` and `Message-ID`; one audit event carrying `recipient_hash` and never
  the address.
- **Overwrite, failure, repetition (14 checks):** committing onto an existing capture name is
  refused and the pre-existing file stays byte-identical; the pending file is removable
  afterwards; an artifact outside the capture directory is refused and not consumed; a
  non-existent artifact is refused; an already committed capture cannot be committed twice; a
  renderer failure leaves the directory empty; two consecutive captures give two distinct
  complete files, one audit event each, and a byte-identical snapshot.
- **Directory posture (3 checks + 1 skip):** relative directory acceptance (F6), unusable path
  refusal, blank path refusal.
- **Finding pins (12 checks):** source-level assertions that reproduce F1, F2, F3, F4, F5, F7
  and F8 without needing a live host, plus a live demonstration of F2 — a directory in the
  `ABSPATH` **parent** is accepted while a directory inside `ABSPATH` is refused.
- **`ABSPATH` block runs last**, because the constant cannot be undefined once set.

The harness prints no address, no PDF byte and no capture body, and removes every temporary
directory it creates (recursively — an early version left two empty scratch directories, which
were cleaned up manually and the cleanup made recursive).

---

## 4. Repo-wide greps (read-only)

| Pattern | Scope | Hits in runtime code |
|---|---|---|
| `wp_mail`, `mail(`, `PHPMailer`, `smtp`, `sendmail` | all PHP excl. `vendor/` | **0** (only comments, tests and review harnesses) |
| `fsockopen`, `stream_socket*`, `socket_create` | all PHP | **0** |
| `curl_*`, `wp_remote_*`, `get_headers`, `dns_get_record`, `file_get_contents('http…')` | all PHP | **0** |
| `admin_post_nopriv`, `wp_ajax`, `register_rest_route` | `packages/`, `plugins/` | **0** |
| `wp_schedule_*`, `wp_cron`, `wp_next_scheduled` | `packages/`, `plugins/` | **0** |
| `retention`, `prune`, `purge`, `expire`, `cleanup` | runtime, excl. `review/` | **1** — an unrelated comment in `DocumentType.php:13`; no retention mechanism |
| `unlink(` | `packages/`, `plugins/` | **1** — the failure rollback in `SandboxMailer::discard()` |
| `glob(`, `scandir(`, directory iterators | `packages/`, `plugins/` | **0** — no runtime code lists the capture directory |
| `COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR` | all | `AdminController.php:548-549` + docs/tests only; the value is a wp-config constant and is not in Git |

`.gitignore` confirmed to exclude `*.eml`, `*.pdf` and `sandbox-mail/`.

### 4.1 Captures already present in the working tree (F4 field evidence)

```
Get-ChildItem -Recurse -Filter *.eml review/claude/evidence
  count      = 43
  size       = 179.8 KB across 22 directories
  oldest     = 2026-08-11 16:03
  newest     = 2026-08-14 22:55
  tracked    = (none)  # git ls-files returns nothing
  ignored by = .gitignore:17:*.eml   # git check-ignore -v
```

Every `To:` header uses an RFC 2606 reserved domain (`example.invalid`, `example.test`) — these
are synthetic harness captures, **no real customer data**, and no address is reproduced here.
They were left in place as instructed. They are cited in the report as observed evidence for
**F4**: four days of developer use already produced 43 uncollected captures, because nothing in
the module can remove one.

---

## 5. PHPUnit — NOT RUN

```
Test-Path vendor -> False
```

`vendor/` is absent and installing dependencies from the network was out of scope, so
`phpunit.xml.dist` could not be executed. The unit suite — including
`tests/unit/SandboxMailerLocationTest.php`, `RenderingAndDeliveryTest.php` and
`PluginIsolationTest.php`, all of which cover parts of this path — **must not be counted as
passing for this review.** Its assertions were read and reasoned about, not executed.

---

## 6. Verdict

**PASS WITH CONDITIONS.**

Passing: no transport of any kind; correct capability, document-bound nonce and no anonymous
entry; snapshot-only data flow with the PDF built from the same snapshot; no header injection
reachable from any snapshot value; no active content in the capture; correct audit ordering with
rollback on failure; documents remain immutable across repeated captures; web-root and traversal
refusals hold for `ABSPATH`.

Conditions — the four defects that should be resolved or explicitly accepted before this leaves
a developer machine:

| ID | Severity | One line |
|---|---|---|
| F1 | Medium | `SandboxMailer.php:180-184` — a short `fwrite` yields a truncated capture that is committed and audited as a success. |
| F2 | Medium | `SandboxMailer.php:59-80` — containment is judged against `ABSPATH`, not the served document root. |
| F3 | Medium | `AdminController.php:546-556` — the default directory is shared system temp and is reused without an ownership, mode or symlink check. |
| F4 | Medium | whole path — **no retention policy**; captures holding buyer PII accumulate without limit and nothing ever deletes them. |
| F5 | Low | `SandboxMailer.php:149` — `file_exists()` then `rename()` is check-then-act. |
| F6 | Low | `AdminController.php:546-552` — a relative capture directory is resolved against the CWD instead of refused. |
| F7 | Info | `DeliverDocument.php:59` — unsalted SHA-256 of an address is pseudonymisation, not anonymisation. |
| F8 | Info | `AdminController.php:743` — `manage_woocommerce` (Shop Manager) suffices to write buyer PII to disk. |
| F9 | Info | `AuditChainVerifier.php:77-83` — an empty audit chain verifies as valid. |
| F10 | Info | `SandboxMailer.php:32` vs `:46` — a refused directory is created before it is refused. |

No runtime fix was applied. Full analysis, failure scenarios, static-versus-live boundary and
the manual smoke-test list are in [review-sandbox-email.md](review-sandbox-email.md).
