# Results — separate checkout and payment confirmations

Commit reviewed: **`9b5afa5`** on `agent/geward-document-module-stage0`, working tree clean.
Findings and verdict: `review-confirmations-checkpoint.md`.

Offline only. No install, no migration, no database change, no email, no network, no subagents. Runtime code was not modified. One temporary probe was created under `review/claude/` and deleted after the run.

## Commands and results

```
git status --short                     (clean)
git log --oneline -6                   9b5afa5, 58f6001, 96ab610, 768cfd4, 25e0e1b, 7baa9d2
git rev-parse HEAD                     9b5afa5b2a0048d4f49f387c9ba4962ea2450a43
git show --stat 58f6001                15 files
git show --stat 9b5afa5                2 files

php -l  (files changed 25e0e1b..HEAD)  21 files, 0 errors
php -l  (whole tree, excluding vendor) 108 files, 0 errors

php review/claude/verify-beta-runtime.php            checks=20 pass=20 fail=0   exit 0
php review/claude/verify-pdf-engine.php <fonts>      checks=87 pass=87 fail=0   exit 0
php review/claude/verify-logo-security.php <logo>    checks=69 pass=69 fail=0   exit 0
php review/claude/verify-admin-preview.php           checks=34 pass=34 fail=0   exit 0
php review/claude/verify-fixes.php                   PASS 59  FAIL 0            exit 0
php review/claude/verify-sandbox-admin-wiring.php    checks=19 pass=19 fail=0   exit 0

git diff --check                       clean
```

**PHPUnit was not run.** `vendor/` is absent and installing it needs network access. Nothing here claims the suite passes; the assertions that matter were reproduced through the harnesses and the probe below, which do execute.

The checkpoint note records `verify-pdf-engine.php` as 86/86. On this commit it is **87/87**.

## Reproduction probe

A temporary script drove `OrderConfirmationPolicy` and `PaidOrderPolicy` through `OrderMapper` and `GenerateDocument` against an in-memory repository, number generator and event logger, then rendered the results and read the text back out of the PDF. Verbatim output:

```
=== 1. checkout, unpaid ===
  ok=true type=order_confirmation number=ORDER_CONFIRMATION/2026/000001
        badge=NIEOPŁACONE — płatność niepotwierdzona
=== 2. same hook runs again (idempotency) ===
  ok=true number=ORDER_CONFIRMATION/2026/000001  documents=1  numbers issued=1
=== 3. the same order is later paid ===
  ok=true type=payment_confirmation number=PAYMENT_CONFIRMATION/2026/000002
        badge=OPŁACONE — płatność potwierdzona
  documents for this order: 2  keys: 2
=== 4. order-confirmation policy on an ALREADY PAID order ===
  ok=false  This order does not qualify for a document under the active policy.
=== 5. payment-confirmation with a paid status but NO payment date ===
  ok=false  This order does not qualify for a document under the active policy.
=== 6. payment date but a status the operator did not select ===
  ok=false  This order does not qualify for a document under the active policy.
=== 7. reversed hook order on a fresh order ===
  order confirmations stored: 1  numbers issued: 1
=== 8. idempotency keys are type-scoped ===
  order_confirmation   9d3c63d630f687ba
  payment_confirmation 593bae7c18533b15
  distinct: true
=== 9. badges as printed on the PDF ===
  unpaid  -> NIEOPŁACONE — płatność niepotwierdzona
  paid    -> OPŁACONE — płatność potwierdzona
=== 10. issuable types ===
  order_confirmation, payment_confirmation, correction
=== 11. PaidOrderPolicy::decision() on an order it declines ===
  payment_confirmed=no payment_status=no_payment_date payment_badge=paid
        notice=OPŁACONE — płatność potwierdzona
=== 12. empty payment-confirmation status list ===
  constructed with no statuses; qualifies a paid order: false
  OrderConfirmationPolicy refused an empty list: Order confirmation statuses and policy identity are required.
```

What each line settles:

| # | Question | Answer |
|---|---|---|
| 1–3 | Are the two documents genuinely separate, and idempotent? | Yes. One order, two documents, two keys, two badges, one number each. A repeated hook returns the existing document and burns no number. |
| 4 | Can a false unpaid confirmation be created after payment? | No. The policy declines once `paidAt` is set. |
| 5–6 | Can a payment confirmation exist without confirmed payment? | No. Both a selected status and a real payment date are required. |
| 7 | Do the hooks race? | Either order produces exactly one document and one number. |
| 8 | Why can both coexist? | The key includes the document type. |
| 9 | Does the right badge reach the page? | Yes, both, read back out of the rendered PDF. |
| 10 | Any route to invoice or proforma? | No. Three issuable types, neither of them fiscal. |
| 11 | — | **Finding M2**: the decision array stamps a paid badge even when it says the payment is unconfirmed. Not reachable today; see the report. |
| 12 | — | **Finding M3**: an empty payment-confirmation status list is accepted and quietly qualifies nothing, while the other policy refuses one. |

## Static confirmations

| Question | Method | Answer |
|---|---|---|
| Do the master toggles drive the hooks? | Compared registration, rendering and read sites | Yes — `AdminController.php:74,81,175-176,190-191` against `Plugin.php:65-72,102`. The earlier `..._shadow_enabled` mismatch is gone. |
| Do the status matrices reach the policies? | Same | Yes — `AdminController.php:184,199` → `AdminSettings.php:58-69` → `Plugin.php:212-217,231-237`. |
| Concurrency backstop? | Schema read | `UNIQUE KEY idempotency_key` — `SchemaDefinition.php:33`. |
| Capability and nonce on every admin action? | Read all eight handlers | Yes. The two new ones: `AdminController.php:497` and `:520`, both via `authorize()` (capability, then nonce). No `admin_post_nopriv_*` anywhere. |
| Any runtime construction of invoice or proforma? | `git grep` | None. |
| Any transport? | `git grep wp_mail\|wp_remote_\|fsockopen\|curl_exec -- packages` | None. |
| Fakturownia or KSeF? | `git grep -i` | Comments and one UI sentence only. |
| Migration reachable from a hook? | `git grep migrateToCurrentVersion` | Only from the nonce-guarded admin action. |

## Not checked

- **No WordPress or WooCommerce runtime.** Hook registration, capability checks, nonces, options and `wpdb` are verified by reading and by in-memory stand-ins. The real firing order of `woocommerce_checkout_order_processed` versus `woocommerce_order_status_changed` for each gateway is assumed from WooCommerce's documented behaviour, not observed.
- **No true concurrency test.** The unique index is read from the schema, not exercised against MySQL.
- **No rendered page was looked at.** Badge text was recovered from the PDF's own `/ToUnicode` CMap; nobody has seen the page.
- **PHPUnit did not run.**
- **The inline script was not executed in a browser.** Its placement and CSP behaviour are assessed from the markup it emits.
