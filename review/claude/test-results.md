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

## 6. Environment note (not a product finding)

On the second harness run, `SandboxMailer::__construct()` threw `Sandbox mail directory is not writable` for a directory it had itself created on the previous run. This is a Windows/OneDrive ACL artifact of the review sandbox, not a defect in the mailer; the harness was changed to use a unique directory per run and the check then passed. I mention it only so the log is not misread.
