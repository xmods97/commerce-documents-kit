# Independent security review — local sandbox `.eml` delivery

**Reviewer:** Claude (independent reviewer, read-only).
**Repository:** `commerce-documents-kit`
**Branch:** `agent/geward-document-module-stage0`
**Commit reviewed:** `85fd155affe3d1c6c094039ff4c364725cff02d9` (`fix: clarify disabled automation readiness`)
**Date:** 2026-08-20
**Runtime changes made:** none. Only files under `review/claude/` were added.

---

## 1. Verdict

**PASS WITH CONDITIONS.**

The sandbox delivery path does what it claims: there is no mail transport of any kind
anywhere in the runtime code, the trigger is an authenticated, per-document, capability-gated
admin POST, the recipient and the PDF both come from the same immutable snapshot, hostile
snapshot values cannot break out of the MIME headers or the attachment filename, and the audit
chain is verified before anything is written. Manual Edge evidence (`Local sandbox .eml
created. No email was sent.`, multipart MIME with a PDF attachment) is consistent with the
static and harness results, and was not relied on as a substitute for them.

The conditions are four defects that do not create a delivery path but do affect integrity and
personal-data handling:

- **F1** a short write produces a truncated capture that is committed and audited as a success;
- **F2** the "outside the web root" guard is measured against `ABSPATH`, which is not the served
  document root in a subdirectory install;
- **F3** the default capture directory sits in the shared system temp directory and is reused
  without an ownership, mode or symlink check;
- **F4** **there is no retention policy at all** — captures containing buyer PII accumulate
  without limit and nothing ever removes them.

F4 is the most consequential for a module whose whole purpose is handling invoice data.

---

## 2. Scope

| In scope | Files |
|---|---|
| Admin trigger | `packages/woocommerce/src/AdminController.php` (`sandboxEmail`, `authorize`, `sandboxMailDirectory`) |
| Orchestration | `packages/document-core/src/Application/DeliverDocument.php` |
| Capture writer | `packages/wordpress/src/SandboxMailer.php` |
| Hook surface | `packages/woocommerce/src/Plugin.php` |
| Audit gate | `packages/wordpress/src/AuditChainVerifier.php`, `packages/wordpress/src/WpdbEventLogger.php` |
| Snapshot integrity | `packages/document-core/src/Party.php`, `DocumentSnapshot.php` |
| Secret / path hygiene | `.gitignore`, `packages/wordpress/src/ConfigKeyProvider.php` |

Out of scope and untouched: the main Geward site, any migration, any branch operation beyond
reading, and every runtime file.

---

## 3. What was checked, and how

### 3.1 Admin endpoint

| Requirement | Result | Evidence |
|---|---|---|
| Correct capability | **PASS** | `authorize()` calls `current_user_can('manage_woocommerce')` and `wp_die(403)` before touching the nonce — `AdminController.php:741-747`. |
| Capability checked *before* nonce | **PASS** | Source-order assertion in both harnesses. |
| Nonce bound to the document ID | **PASS** | `self::authorize('commerce_documents_sandbox_email_' . $documentId)` — `AdminController.php:484`; the form mints the matching nonce at `AdminController.php:314`. A nonce for document A does not authorise document B. |
| No `admin_post_nopriv` | **PASS** | Repo-wide grep over `packages/` and `plugins/`: no `admin_post_nopriv`, no `wp_ajax`, no `register_rest_route`, no `wp_schedule_*`. |
| Unknown / non-existent document refused | **PASS** | `self::find()` returns `null` → `redirect('failed', 'Document not found.')` — `AdminController.php:486-489`. |
| Unreadable (undecryptable) document refused | **PASS** | `WpdbDocumentRepository::hydrate()` authenticates AES-GCM or `content_hash`; a failure yields `null`, and the button is only rendered for `readable` rows (`AdminController.php:309-316`). |
| IDOR | **PASS, with a scope note** | Documents are store-wide, not user-owned, so there is no per-user object to confuse. Any holder of `manage_woocommerce` may capture any document — that is the intended admin scope, recorded as **F8**. |

`AdminController::boot()` runs only under `is_admin()` (`Plugin.php:33-35`), and
`admin_post_commerce_documents_sandbox_email` (`AdminController.php:41`) is the only registration.

### 3.2 Source of data

| Requirement | Result | Evidence |
|---|---|---|
| Recipient comes only from the immutable snapshot | **PASS** | `$recipient = trim((string) (($data['buyer']['email'] ?? '')));` — `AdminController.php:491`. The method reads exactly one request field, `$_POST['document_id']`, and nothing else (asserted). |
| Live order data cannot substitute for the snapshot | **PASS** | `sandboxEmail()` never calls `wc_get_order()` or any `get_billing_*` accessor (asserted). |
| PDF built from the same protected snapshot | **PASS** | `DeliverDocument::execute()` renders `$this->pdf->render($snapshot)` and stages that exact binary with that exact `$snapshot` — `DeliverDocument.php:50-54`. |
| Empty / invalid address handled | **PASS** | Rejected twice: `AdminController.php:492-494` before any rendering, and again in `SandboxMailer::stage()` (`SandboxMailer.php:110`). A third layer exists at snapshot construction — `Party::__construct` refuses a non-`FILTER_VALIDATE_EMAIL` address (`Party.php:29`), so CRLF can never be stored in the snapshot in the first place. |
| Hostile input | **PASS** | Traversal, Windows traversal, CRLF, NUL, quote-escape and whitespace in `document_id` are all refused by `preg_match('/^[A-Za-z0-9_-]+$/D', $id)` (`SandboxMailer.php:114`). CR, LF and bare-CR subjects are refused. This matters because `DocumentSnapshot` itself only requires `document_id` to be non-empty — the mailer's charset guard is the real defence, and it holds. |

### 3.3 Absence of real delivery

| Requirement | Result |
|---|---|
| No `wp_mail`, `mail()`, PHPMailer, SMTP, socket, `curl_*`, `wp_remote_*` | **PASS** — repo-wide grep over all runtime PHP returns nothing but comments and test assertions. |
| No outbound HTTP of any kind | **PASS** — no `file_get_contents('http…')`, `fopen('http…')`, `get_headers`, `dns_get_record`. |
| No hidden transport, cron or order hook | **PASS** — `Plugin::boot()` registers `init`, `woocommerce_checkout_order_processed` and `woocommerce_order_status_changed` only; neither order path references `sandboxEmail`, `DeliverDocument` or `SandboxMailer`. No scheduler API is used anywhere. |
| `.eml` created only by an explicit admin action | **PASS** — the single construction of `SandboxMailer` in runtime code is inside `sandboxEmail()` (`AdminController.php:508`). |
| Sender cannot be delivered even if it escaped | **PASS** — default `sandbox@example.invalid`; `.invalid` is reserved by RFC 2606 and can never resolve. |

The audit event name is deliberately `document.sandbox_stored`, distinct from `document.sent`,
so a capture can never be mistaken for a real send in the trail (`AdminController.php:514`).

### 3.4 Directory safety

| Requirement | Result | Note |
|---|---|---|
| Refuses a directory inside `ABSPATH` | **PASS** | `assertOutsideWebRoot()` — `SandboxMailer.php:59-80`; both the root itself and any descendant are refused. |
| Refuses traversal that resolves back inside | **PASS** | `realpath()` is applied before the check and before the path is stored (`SandboxMailer.php:42-48`), so `..` cannot decide the write location later. |
| Refuses a non-resolving path | **PASS** | `SandboxMailer.php:43-45`. |
| Symlink capture of an individual file | **PASS** | `fopen($path, 'xb')` uses `O_CREAT|O_EXCL`, which fails on an existing path including a dangling symlink (`SandboxMailer.php:173`). |
| Secret / config path kept out of Git | **PASS** | The directory comes from the `COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR` wp-config constant; `.gitignore` excludes `*.eml`, `*.pdf` and `sandbox-mail/`. No wp-config, key or capture is tracked. |
| Safe file names | **PASS** | `{document_id}-{UTC timestamp}-{6 random bytes}.eml`; `document_id` is charset-constrained and is not PII. |
| Safe permissions | **PASS on POSIX, unverified here** | `chmod 0600` before the first byte is written (`SandboxMailer.php:179`), directory `mkdir(0700)`. Windows has no meaningful POSIX mode, so the harness skips this assertion — it must be confirmed on the target host. |
| No overwrite | **PASS** | O_EXCL on stage; `file_exists()` guard plus a "must be `.pending`" precondition on commit (`SandboxMailer.php:141-153`). Verified: committing onto an existing name is refused and the pre-existing file is left byte-identical; an already committed file cannot be committed twice; an artifact outside the capture directory is refused (`ownedPath()`, `SandboxMailer.php:191-207`). |
| Race conditions | **PARTIAL** | The filename carries 6 random bytes, so two concurrent captures of the same document produce two files rather than one. But see **F5** (check-then-act between `file_exists` and `rename`). |
| Partial write | **FAIL** | See **F1**. |
| Directory reuse | **FAIL** | See **F3**. |
| Guard is the real web root | **FAIL** | See **F2**. |

### 3.5 MIME and attachment

All verified by decoding the produced capture, not by string-matching headers:

- Correct `multipart/mixed` with a random 24-hex-char boundary; the boundary appears exactly
  four times (declaration, two separators, closing delimiter) — no encoded content collides
  with it, even when the PDF bytes deliberately contain a boundary look-alike.
- A PDF containing NUL, CR, LF and high bytes round-trips through strict base64 byte for byte.
- The text part decodes back to its original lines with CRLF endings; multi-line bodies are
  normalised rather than rejected (`normalizeBody`, `SandboxMailer.php:209`).
- Every base64 line stays within the 76-character limit; the message ends with `--boundary--`.
- `Content-Disposition: attachment; filename="…"` — always quoted, always
  `^[A-Za-z0-9_-]+\.pdf$`.
- A non-ASCII subject is RFC 2047 base64-encoded rather than emitted raw
  (`encodeHeader`, `SandboxMailer.php:214`); every header line parses as a single well-formed
  field. Hostile buyer/document values cannot reach a header: CRLF is refused outright and the
  encoder cannot emit a bare CR or LF.
- **No active content:** no `text/html`, no `multipart/related`, no `<script`, no `<html`, no
  `http://`/`https://`, no `cid:` reference. Opening a capture cannot fetch or execute anything.
- **Failure leaves nothing behind:** a renderer failure aborts before any file exists; a
  refused commit leaves the pending file removable and removed; an audit-logger failure
  discards the already-committed capture (`DeliverDocument.php:61-65`).

The one defect in this area is **F1** — a *short* write is not a failure the code notices.

### 3.6 Audit chain

| Requirement | Result | Evidence |
|---|---|---|
| Chain verified before the `.eml` is created | **PASS** | `AuditChainVerifier::verify()` runs at `AdminController.php:496-504`, and `!$verification['valid']` short-circuits to `redirect('failed', …)` before `new SandboxMailer` / `new DeliverDocument` at line 506. Asserted by source order. |
| Event recorded only after a successful write | **PASS** | `stage()` → `commit()` → `events->record()` in that order — `DeliverDocument.php:54-60`. |
| File removed on chain/audit failure | **PASS** | The `catch` discards the committed artifact and rethrows (`DeliverDocument.php:61-66`); the existing wiring harness asserts the directory is empty afterwards. |
| Legacy unhashed events cannot be used | **PASS** | A legacy row with an empty `event_hash` fails recomputation, so `valid` is false and the capture is refused — consistent with the banner in `view()` (`AdminController.php:392-393`). |
| Repeat runs do not damage the document | **PASS** | The action never writes to the documents table. Verified: two consecutive captures leave `DocumentSnapshot::toArray()` byte-identical, produce two distinct complete files, and append exactly one audit event each. |
| Empty chain | **INFO** | A document with zero events verifies as `valid: true` (`AuditChainVerifier.php:77-83`). Not reachable today — generation always writes an event — but the gate would not stop a document whose events had been deleted wholesale. Recorded as **F9**. |

### 3.7 Retention and PII

A capture holds the buyer's name, postal address, email address and the complete rendered
document. Where they live and for how long:

- **Where:** `COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR` if defined, otherwise
  `sys_get_temp_dir()/commerce-documents-sandbox-mail` (`AdminController.php:546-556`).
- **How long:** **indefinitely.** Nothing in the runtime deletes a committed capture. The only
  `unlink()` in the whole runtime tree is the failure rollback in `SandboxMailer::discard()`.
  No runtime file enumerates the capture directory (`glob`, `scandir`, directory iterators are
  absent), so nothing could age captures out even accidentally. There is no cap, no dedup, no
  TTL setting, and no documented policy.
- **Accumulation:** every click on *Create sandbox email* writes another file. The same
  document can be captured any number of times, each producing a new file and a new audit
  event. Unbounded in both the filesystem and the events table.

This is recorded as **F4**. No retention policy is proposed here — defining one is the owner's
decision, not the reviewer's.

**Observed evidence of the accumulation, in this repository:** earlier review sessions left
**43 `.eml` captures, 179.8 KB, dated 2026-08-11 through 2026-08-14**, still sitting in 22
directories under `review/claude/evidence/`. Nothing removed them, because nothing can. They
are harmless here — every recipient uses an RFC 2606 reserved domain (`example.invalid`,
`example.test`), no real customer data is involved, and `.gitignore` keeps all of them
untracked (`git check-ignore` confirms `.gitignore:17:*.eml`) — but they are exactly the
pattern F4 describes, already visible after four days of developer use. On a live store the
same pattern produces real invoices. They were left in place, as instructed.

Secondary PII notes: the audit context stores `sha256(lower(email))` rather than the address
(`DeliverDocument.php:59`), which is pseudonymisation, not anonymisation (**F7**); and no buyer
data reaches the browser on failure, because the sandbox failure branch redirects with a fixed
string and logs the exception server-side (`AdminController.php:525-526`).

---

## 4. Findings

Severity reflects impact on this local-beta module. None of these is a remote-exploitable
delivery path.

### F1 — Medium — a short write is committed and audited as a complete capture

**File:** `packages/wordpress/src/SandboxMailer.php:180-184`

```
$written = fwrite($handle, $contents);
fclose($handle);
if ($written === false) { throw new RuntimeException(...); }
```

`fwrite()` returns the number of bytes written and only returns `false` on outright failure. On
a full disk, a quota boundary or an interrupted write it returns a count smaller than
`strlen($contents)` — which this code treats as success. `fclose()`'s return value, which is
where a buffered flush failure surfaces, is not checked either.

**Failure scenario:** the capture directory fills mid-write. The `.pending` file is truncated
part-way through the base64 PDF. `commit()` renames it to `.eml`, `DeliverDocument` records
`document.sandbox_stored`, and the administrator sees *"Local sandbox .eml created. No email
was sent."* The audit trail now asserts a capture that is not a valid MIME message and whose
attachment cannot be reconstructed. Silent data loss with a positive audit record.

**Pinned by:** `verify-sandbox-email-security.php` — *FINDING F1 pinned: a short fwrite is not
detected* / *the fclose result is not checked either*.

### F2 — Medium — web-root containment is measured against `ABSPATH`, not the document root

**File:** `packages/wordpress/src/SandboxMailer.php:59-80`

The guard compares the capture directory against `realpath(ABSPATH)`. In a WordPress
subdirectory install — docroot `/var/www/html`, `ABSPATH = /var/www/html/wp/` — a capture
directory at `/var/www/html/mail` is *outside* `ABSPATH` and therefore accepted, while still
being served over HTTP. The same holds for Bedrock-style layouts where the served root is the
parent of the WordPress core directory. The guard also stands aside entirely when `ABSPATH` is
undefined (lines 61-64) or when `realpath(ABSPATH)` fails (lines 65-68) — both reasonable for
CLI, but they mean the check is advisory rather than absolute. `WP_CONTENT_DIR`,
`wp_upload_dir()` and `$_SERVER['DOCUMENT_ROOT']` are not consulted.

**Failure scenario:** an operator on a subdirectory install points the constant at a path one
level above `ABSPATH`. The module accepts it, and every capture — buyer name, address, email
and full invoice — becomes directly downloadable to anyone who can guess or list the path.

**Pinned and demonstrated by:** `verify-sandbox-email-security.php` — *FINDING F2 demonstrated:
a directory in the ABSPATH parent is accepted although a subdirectory install serves it*.

### F3 — Medium — the default capture directory is reused without an ownership, mode or symlink check

**Files:** `packages/woocommerce/src/AdminController.php:546-556`,
`packages/wordpress/src/SandboxMailer.php:31-37`

With no constant configured, captures go to
`sys_get_temp_dir()/commerce-documents-sandbox-mail`. The constructor creates it with `0700`
if absent, but if the path **already exists** it is accepted on `is_dir() && is_writable()`
alone. Ownership, mode and whether the path is a symlink are never examined; `realpath()`
silently follows a symlink, and the containment check then judges the *target*, so any target
outside `ABSPATH` passes.

**Failure scenario:** on shared hosting with a common `/tmp`, an unprivileged local user
pre-creates `/tmp/commerce-documents-sandbox-mail` mode `0777`, or as a symlink to a directory
they own. Individual files are still written `0600`, but the attacker owns the containing
directory and can therefore unlink, rename or replace entries, and can enumerate filenames.
On a symlinked directory they control the destination outright.

**Pinned by:** `verify-sandbox-email-security.php` — *FINDING F3 pinned: a pre-existing capture
directory is reused without an ownership or mode check* / *the default directory is the shared
system temp directory*.

### F4 — Medium — no retention policy; captures containing buyer PII accumulate without limit

**Files:** whole path; `packages/wordpress/src/SandboxMailer.php` (only `discard()` deletes)

Nothing removes a committed `.eml`. No TTL, no cap, no pruning, no setting, no documentation,
and no runtime code that even lists the directory. Each capture is a plaintext file on disk
containing the buyer's name, postal address, email address and the complete rendered invoice.
Repeat clicks on the same document produce additional copies and additional audit events.

**Failure scenario:** after months of beta use, the temp or configured directory holds hundreds
of plaintext invoices for real customers. Nobody deletes them, they are picked up by a backup
or a host migration, and there is no record of what the retention period was supposed to be.
For a module handling Polish invoice data this is a personal-data governance gap, not merely
housekeeping.

**Not proposing a policy** — the owner must decide the lifetime, the cap and who is responsible
for deletion. What is missing today is that *no* answer exists in code or docs.

**Pinned by:** `verify-sandbox-email-security.php` — *FINDING F4 pinned: no runtime code
enumerates or deletes committed captures* / *no capture lifetime, cap or expiry setting is
defined*.

### F5 — Low — check-then-act between `file_exists()` and `rename()`

**File:** `packages/wordpress/src/SandboxMailer.php:149`

`if (file_exists($target) || !@rename($source, $target))`. POSIX `rename()` overwrites an
existing target silently, so the `file_exists()` call is the entire overwrite guard, and there
is a window between the two. Exploitation requires an attacker who can both write in the
capture directory and learn the pending filename (12 hex characters of randomness), so this is
practically unreachable on a correctly owned directory — but it is exactly the guard that F3
weakens. Worth noting together.

**Pinned by:** *FINDING F5 pinned: commit guards the target with file_exists before rename*.

### F6 — Low — a relative capture directory is resolved against the process working directory

**File:** `packages/woocommerce/src/AdminController.php:546-552`

A relative value of `COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR` is passed through unchanged and
resolved against the current working directory rather than being refused. The CWD differs
between an admin request, WP-CLI and cron, so the same configuration writes captures to
different places depending on how WordPress was entered — and under an admin request the CWD is
typically inside the web root, where the containment guard would then refuse it, producing a
confusing intermittent failure rather than a clear configuration error.

**Verified:** a relative directory name is accepted (harness check *FINDING F6 pinned*).

### F7 — Informational — the audit recipient hash is an unsalted SHA-256

**File:** `packages/document-core/src/Application/DeliverDocument.php:59`

`hash('sha256', strtolower($recipient))` is a substantial improvement over storing the address,
but an email address has low entropy: anyone with the events table and a candidate list can
confirm addresses by brute force. It is pseudonymisation, and should be described as such
rather than as anonymisation, in a table that is retained indefinitely.

### F8 — Informational — `manage_woocommerce` is enough to write buyer PII to the filesystem

**File:** `packages/woocommerce/src/AdminController.php:743`

The gate is `manage_woocommerce`, which the Shop Manager role holds. That is consistent with the
rest of the plugin's admin surface, so it is not an inconsistency — but it means a
non-administrator can materialise customer personal data as files on the server. Worth an
explicit decision rather than an inherited default, especially alongside F4.

### F9 — Informational — an empty audit chain verifies as valid

**File:** `packages/wordpress/src/AuditChainVerifier.php:47-83`

`verifyRows()` returns `valid: true` for zero rows. Not reachable through normal generation, but
it means the sandbox gate is "the chain is not *broken*" rather than "the chain *exists*".

### F10 — Informational — a refused capture directory is created before it is refused

**File:** `packages/wordpress/src/SandboxMailer.php:32` vs `:46`

`mkdir(..., 0700, true)` runs before `assertOutsideWebRoot()`. Pointing the constant at a path
inside the web root therefore creates the (empty) directory tree there and *then* throws. No
capture is ever written into it, so there is no data exposure — only a stray empty directory
left inside the web root after a misconfiguration.

---

## 5. Static versus live

### Verified statically or by harness (this review)

Hook registration and the absence of anonymous entry points; capability and nonce ordering;
nonce-to-document binding; snapshot-only data flow; absence of every transport symbol; MIME
structure and byte-exact base64 through a hostile binary payload; header-injection resistance
for recipient, sender, subject and attachment filename; absence of active content; overwrite,
collision, foreign-path and double-commit refusals; renderer-failure and audit-failure rollback;
immutability across repeated captures; web-root containment for `ABSPATH` and for traversal;
and the absence of any retention mechanism.

### Cannot be verified without a live WordPress + WooCommerce install

1. **Real capability resolution** — that `manage_woocommerce` maps to the roles the operator
   expects on this site, and that no plugin grants it more widely.
2. **Real nonce lifecycle** — expiry, the 12/24-hour tick, and behaviour after a session change.
   `check_admin_referer()` is called correctly; whether it *passes* is a live property.
3. **Actual `ABSPATH` and the actual served document root** — F2 turns entirely on the
   relationship between them on the target host. Static analysis can show the guard is
   ABSPATH-relative; only the host shows whether that equals the docroot.
4. **POSIX permission bits** — this review ran on Windows, where `chmod`/`fileperms` are not
   meaningful. `0600` on captures and `0700` on the directory must be confirmed on the server.
5. **Behaviour of `sys_get_temp_dir()` on the host** — its value, its mode, whether it is shared
   between accounts, and whether `open_basedir` or systemd `PrivateTmp` applies. F3's severity
   depends on this.
6. **Real database interaction** — `AuditChainVerifier` and `WpdbEventLogger` were reasoned
   about statically; the unique `chain_position` key and the retry-on-contention behaviour need
   a live MySQL to exercise.
7. **Genuine short-write behaviour (F1)** — reproducing a truncated capture requires a real full
   or quota-limited filesystem; here it is established by code reading and pinned at source level.
8. **PHPUnit** — `vendor/` is absent and nothing may be installed from the network, so the unit
   suite (including `SandboxMailerLocationTest`) **was not executed** in this review and must not
   be counted as passing.

---

## 6. Manual smoke tests (to run on the live beta site)

Run as a user with `manage_woocommerce`. None of these sends an email.

| # | Test | Expected |
|---|---|---|
| 1 | Click **Create sandbox email** on a readable document. | Notice: *Local sandbox .eml created. No email was sent.* Exactly one new `*.eml` in the configured directory. |
| 2 | Open the new file in a text editor. | `From: sandbox@example.invalid`, a `To:` line, one `text/plain` part, one base64 `application/pdf` part, closing `--boundary--`. No `text/html`, no URL. |
| 3 | Save the attachment and open it. | Byte-identical to the document from **Preview PDF**. |
| 4 | Check the directory's own permissions and owner. | Directory `0700`, file `0600`, both owned by the web-server user. Confirms F3/F1 posture on the real host. |
| 5 | Confirm the directory is not web-reachable: request its path over HTTP. | 403/404 — never a directory listing or a file. |
| 6 | Point `COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR` inside the web root, e.g. `…/wp-content/uploads/cdk-mail`, and click the button. | Failure notice; no `.eml` created. Note whether an empty directory was left behind (F10). Restore the constant afterwards. |
| 7 | **F2 check:** determine the served document root and compare it with `ABSPATH`. If they differ, set the constant to a directory under the docroot but above `ABSPATH`. | If the module accepts it, F2 is confirmed live on this host. Restore the constant immediately. |
| 8 | Click **Create sandbox email** twice in a row on the same document. | Two distinct `.eml` files, both complete. The document row is unchanged; the audit trail gains two `document.sandbox_stored` events. Confirms F4's accumulation. |
| 9 | Open a document whose buyer has no email address. | Failure notice *The document buyer has no valid email address.*; no file created. |
| 10 | On a copy of the database, alter one `context` value in `wp_commerce_document_events` for a document, then click the button. | Failure notice *The document audit chain is not verified; no sandbox file was created.*; no file created. |
| 11 | Check the events table after a successful capture. | Event name `document.sandbox_stored` (never `document.sent`); `context` carries `recipient_hash`, never the address. |
| 12 | Fill the capture volume (or set a tight quota) and click the button. | **F1 reproduction:** observe whether a truncated `.eml` is left behind together with a success notice. |
| 13 | Count the files in the capture directory and note the oldest date. | **F4:** whatever number you find is the current, unmanaged retention. |

---

## 7. Retention risk (summary for the owner)

Every sandbox capture is an unencrypted file containing one customer's name, postal address,
email address and complete invoice. Today: no lifetime, no cap, no deletion, no owner, no
documentation, and — by default — a location in the shared system temp directory. The module
already treats this data carefully everywhere else (AES-GCM at rest, hashed recipients in the
audit trail, `.gitignore` coverage, a web-root guard), which makes the absence of any retention
answer the weakest link in an otherwise careful design.

A decision is needed on: maximum lifetime, maximum count or total size, who deletes, whether
captures should be encrypted at rest like the snapshots they come from, and whether the feature
should be usable at all outside a developer machine. That decision is the owner's; this review
records only that it has not yet been made.

---

## 8. Statement of independence

No runtime file was modified, no dependency was installed, no email was sent, no migration was
applied, no worktree was created, and nothing was pushed, merged or deployed. No secret was
read or reproduced. No `.eml`, buyer address or PDF content appears anywhere in this report or
in the evidence files. Existing untracked review artifacts were left untouched.

Commands, exact counts and exit codes: [review-sandbox-email-results.md](review-sandbox-email-results.md).
