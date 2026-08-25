# Review — separate checkout and payment confirmations

**Verdict: PASS WITH CONDITIONS.** No critical risk. Nothing here blocks moving on; the conditions are operational, not architectural.

| | |
|---|---|
| Reviewed commit | `9b5afa5` (`fix: toggle manual seller fields in admin`), on top of `58f6001` (`feat: separate checkout and payment confirmations`) |
| Branch | `agent/geward-document-module-stage0`, working tree clean |
| Reviewer | independent; runtime code was not modified |
| Files changed by this review | `review/claude/` only |

The substance of the checkpoint — two document types, two independent toggles, two independent status matrices, and a payment gate that cannot be talked into lying — holds up. I drove it rather than read it: the twelve scenarios and their output are in `review-confirmations-checkpoint-results.md`.

---

## 1. Two documents, and idempotency — **correct**

The idempotency key is `sha256(sourceType ⏎ sourceId ⏎ documentType)` (`packages/document-core/src/IdempotencyKey.php:35-39`), so the two types occupy separate slots for one order and neither can displace the other.

Reproduced end to end on one order id:

- checkout while unpaid → `order_confirmation`, number `ORDER_CONFIRMATION/2026/000001`, badge `NIEOPŁACONE — płatność niepotwierdzona`;
- the same hook again → **the same document**, one number consumed, one row stored;
- the order is later paid → `payment_confirmation`, a **second** document, badge `OPŁACONE — płatność potwierdzona`;
- two documents, two distinct keys, for one order.

Concurrency is settled below the application: `UNIQUE KEY idempotency_key` on the documents table (`packages/wordpress/src/SchemaDefinition.php:33`) makes a duplicate insert fail rather than succeed twice.

## 2. Races between the checkout hook and the status hook — **no defect found**

`woocommerce_checkout_order_processed` (priority 20) and `woocommerce_order_status_changed` both route to `Plugin::generateOrderConfirmationForOrder()`. Running them in either order produces exactly one document and consumes exactly one number — verified with the hooks reversed.

Two properties make that safe, and both are load-bearing:

1. the key does not include the policy, the status or the timestamp, so a second run cannot mint a second document;
2. the existence check runs before the sequence number is allocated (`packages/document-core/src/Application/GenerateDocument.php`), so a repeat costs no number.

**Residual, not a defect:** if two requests genuinely race (a checkout request and a gateway callback in flight together), both may pass the existence check, both allocate a number, and the loser's insert fails on the unique index. The result is one document with a gap in the sequence. That is the correct trade — a gap is cheaper than a duplicate — but it means numbering is not guaranteed contiguous.

## 3. `payment_confirmation` without confirmed payment — **not reachable**

`PaidOrderPolicy::isPaid()` (`packages/woocommerce/src/PaidOrderPolicy.php:75-88`) requires a selected status **and** a real `date_paid`. The only escape — `COD_POLICY_STATUS_ONLY` with enrolled offline gateways — is hard-coded shut on every path that can reach it: `Plugin::paidPolicy()` (`packages/woocommerce/src/Plugin.php:212-224`) passes `COD_POLICY_NEVER` and an empty gateway list, and the manual button routes through the same method.

Reproduced: a `processing` order with no payment date is refused; a payment date on a status the operator did not select is refused.

## 4. A false unpaid `order_confirmation` after payment — **not reachable**

`OrderConfirmationPolicy::documentTypeFor()` (`packages/woocommerce/src/OrderConfirmationPolicy.php:32-41`) returns `null` when `paidAt !== ''`. Reproduced: the order-confirmation policy applied to an already-paid order is refused, so the status hook cannot backdate an "unpaid" document onto an order whose money has arrived.

Combined with idempotency, an order that was confirmed unpaid at checkout and later paid ends up with exactly one of each document, each carrying the badge that was true when it was issued. That is the right shape for an immutable document.

## 5. Do the admin controls actually drive the hooks — **yes**

Both master options are registered, rendered and read under the same names, and the two status matrices post to the settings keys the policies read:

| Control | Registered | Rendered | Read by |
|---|---|---|---|
| `commerce_documents_wc_order_confirmation_enabled` | `AdminController.php:74` | `:175-176` | `Plugin.php:65-68`, `:102` |
| `commerce_documents_wc_payment_confirmation_enabled` | `AdminController.php:81` | `:190-191` | `Plugin.php:69-72` |
| `order_confirmation_statuses` | sanitised `AdminSettings.php:58-64` | `AdminController.php:184` | `Plugin.php:231-237` |
| `payment_confirmation_statuses` | sanitised `AdminSettings.php:65-69` | `AdminController.php:199` | `Plugin.php:212-217` |

The name mismatch flagged in the previous review (a registered `..._shadow_enabled` that gated nothing) is gone. Each hook returns early when its own toggle is off, and `observeOrderStatus` returns immediately when both are off.

## 6. Invoice, proforma, external email, Fakturownia, KSeF, migrations — **none reachable**

- Issuable types are exactly `order_confirmation`, `payment_confirmation`, `correction` (reproduced). `invoice` and `proforma` remain constructible for reading historical rows and are refused at issue by `DocumentType::assertIssuable()`.
- No runtime construction of `DocumentType::INVOICE` or `PROFORMA` anywhere in `packages/`.
- No `wp_mail`, `wp_remote_*`, `fsockopen` or `curl_exec` in `packages/`. Delivery is still the local `.eml` capture only.
- No Fakturownia or KSeF integration; the only matches are comments and one UI sentence.
- `Installer::migrateToCurrentVersion()` is reachable only from the nonce-guarded admin migrate action; no hook calls it.

## 7. Capability and nonce on the new manual actions — **correct**

Every `admin_post_*` handler is guarded, and the two new ones are no exception:

| Action | Guard |
|---|---|
| `commerce_documents_generate_order_confirmation` | `self::authorize('commerce_documents_generate_order_confirmation')` — `AdminController.php:497` |
| `commerce_documents_generate_cod` | `self::authorize('commerce_documents_generate_cod')` — `AdminController.php:520` |

`authorize()` checks `current_user_can('manage_woocommerce')` first and then `check_admin_referer()`. No `admin_post_nopriv_*` variant exists, so an anonymous request cannot reach any of them.

## 8. The inline seller-source script

It is a static string with no interpolated data, so there is no injection surface, and it does the right thing functionally — the user confirmed the toggle works. Three qualifications, all Low, in the findings below: it is emitted inside a `<table>`, it is inline with no CSP nonce, and the state change is not announced to assistive technology.

One detail worth crediting: `tax_identifier` is deliberately excluded from the toggle (`AdminController.php:868`), which matches `AdminSettings::resolveSeller()` keeping exactly that field when the source is WooCommerce. The client behaviour and the server behaviour agree, which is not always the case with this pattern.

---

## Findings by severity

### Medium

**M1 — routine non-qualification is reported as a failure**
`packages/woocommerce/src/OrderMapper.php:19-21`, `packages/woocommerce/src/Plugin.php:80-96`

`OrderMapper::map()` throws when a policy declines, and the hook catches it into `error_log()` plus `do_action('commerce_documents_generation_failed', …)`. Declining is the *normal* outcome: with both toggles on, every status change on an unpaid order records "payment confirmation failed", and every status change on a paid order records "order confirmation failed".

*Reproducible:* enable both toggles, place an order, move it through statuses; each transition writes a failure line for the type that does not apply.

*Beta:* yes. The log fills with non-events and a genuine failure stops standing out. Any listener on `commerce_documents_generation_failed` gets false alarms.

*Suggested:* ask the policy first (`documentTypeFor() === null` → return quietly) and keep the failure path for real errors.

**M2 — `PaidOrderPolicy::decision()` stamps "OPŁACONE" unconditionally**
`packages/woocommerce/src/PaidOrderPolicy.php:106-112`

`payment_badge => 'paid'` and `payment_notice => 'OPŁACONE — płatność potwierdzona'` are set regardless of `isPaid()`, in the same array where `payment_confirmed` *is* computed conditionally.

*Reproduced:* `decision()` on a `processing` order with no payment date returns `payment_confirmed=no`, `payment_status=no_payment_date`, **`payment_badge=paid`**, **`payment_notice=OPŁACONE — płatność potwierdzona`**.

Not reachable today — `OrderMapper::map()` throws before `decision()` is called, and I verified that ordering. But the badge is exactly what the renderer prints (`packages/document-core/src/Rendering/EmbeddedFontPdfRenderer.php:693-700`), the guarantee is undocumented, and an immutable document that says a customer paid when they have not is the worst output this system can produce.

*Beta:* latent. *Production:* one refactor away from real.

*Suggested:* derive both fields from `isPaid($order)`, as `payment_confirmed` already is.

**M3 — an empty payment-confirmation status list is accepted and silently disables the feature**
`packages/woocommerce/src/AdminSettings.php:47-50` (`cleanStatuses` returns `[]`), `:114-123` (`isComplete()` checks only the order-confirmation list), `packages/woocommerce/src/PaidOrderPolicy.php:63`

*Reproduced:* `new PaidOrderPolicy([], …)` constructs happily and then qualifies nothing, including a properly paid order.

*Scenario:* the operator unticks every payment-confirmation status, saves, and leaves the master checkbox on. Settings report complete, no payment confirmation is ever produced, and the only trace is the M1 log noise.

The two sides are asymmetric: `OrderConfirmationPolicy::__construct()` (`OrderConfirmationPolicy.php:20-22`) refuses an empty list outright.

*Beta:* yes. *Suggested:* require a non-empty list in `isComplete()` when the matching toggle is on, or make `PaidOrderPolicy` refuse an empty list too.

### Low

**L1 — the badge is frozen into the snapshot, so it is never translated**
`OrderConfirmationPolicy.php:53`, `PaidOrderPolicy.php:110`, printed at `EmbeddedFontPdfRenderer.php:693-700`

The notice text is stored in the immutable metadata rather than resolved from `TemplateCatalog` at render time, so an `en` document still prints the Polish badge. Freezing it is defensible for immutability; it just means the language setting does not reach it. The box is also a fixed 20 pt single line with no wrapping, so a longer notice would overflow.

**L2 — the inline script is emitted inside a `<table>`**
`AdminController.php:168` (call site), `:880-890` (the script)

`sellerSourceScript()` is echoed between two `<tr>` rows, so a `<script>` element becomes a direct child of the table. The HTML parser foster-parents it out — which is why it works — but the markup is invalid and the behaviour depends on parser recovery.

**L3 — the script is inline with no CSP nonce**
`AdminController.php:880-890`

Under a `script-src` policy without `'unsafe-inline'` — common on hardened hosts and with security plugins — it is blocked. Degradation is safe but confusing: the fields keep whatever state the server rendered from the *saved* `seller_source`, so switching the radio appears to do nothing until the page is saved and reloaded. Moving it to `admin_enqueue_scripts` with `wp_add_inline_script()` fixes L2 and L3 together.

**L4 — `readonly` fields still submit, so unused seller values persist**
`AdminController.php:865-878`, `AdminSettings.php:95-113`

`readonly` posts its value, unlike `disabled`. In WooCommerce mode the manual seller values are stored in the option even though `resolveSeller()` ignores every one of them except `tax_identifier`. No effect on any document — the stored option simply contains data that is not used, which can mislead a later reader.

**L5 — the toggle is not announced to assistive technology**
`AdminController.php:880-890`

Only the `readOnly` property changes; there is no `aria-readonly` and no visible cue beyond default browser styling, so a screen-reader user switching the radio gets no feedback. Using `readonly` rather than `disabled` is the right call — the fields stay focusable and still submit — this is the small step left.

### Informational

The checkpoint note records `verify-pdf-engine.php` as 86/86; it is **87/87** on this commit.

---

## What to fix before the next stage

1. **M1** — stop reporting routine non-qualification as a failure. Do this first: the sandbox-email review will read the same error log, and the noise will get in its way.
2. **M3** — make an empty payment-confirmation status list either impossible or loud.
3. **M2** — make the paid badge conditional. Cheap now, and it removes the only path in this checkpoint that could ever put a false "OPŁACONE" on an immutable document.

L1–L5 can wait; L2 and L3 are one change together.

## What must be checked by hand in WordPress

None of this can be settled offline — there is no WordPress runtime here and no PDF rasteriser.

1. **BACS, the case the checkout hook exists for.** Place a BACS order. Confirm one `order_confirmation` appears with `NIEOPŁACONE — płatność niepotwierdzona`, and that the later move to `on-hold` does **not** create a second one.
2. **A card gateway.** Place an order that is paid inside the checkout request. Confirm that **no** unpaid `order_confirmation` is created and that a `payment_confirmation` appears with `OPŁACONE — płatność potwierdzona`.
3. **The two-document sequence.** BACS order → mark paid manually with a payment date → confirm both documents exist for the one order, each with its own number and its own badge, and that the first is unchanged.
4. **Each toggle alone.** Turn on only the order confirmation, then only the payment confirmation, and confirm nothing is produced for the other.
5. **Status matrices.** Untick a status and confirm no document is produced for it.
6. **The error log during all of the above** — this is where M1 will be visible, and it is worth seeing the volume before deciding how urgently to fix it.
7. **The seller toggle without JavaScript** (block scripts, or use a CSP) and confirm the page is still usable.
8. **Open one generated PDF and look at it** — the badge box, the logo, the table. Nothing offline has seen a rendered page.

## Verdict on moving to the sandbox-email review

**Yes.** Nothing in this checkpoint blocks it: delivery is still the local `.eml` capture, no transport exists in `packages/`, and the sandbox action already refuses to write unless the audit chain verifies. Fix **M1** first, because that review will be reading the same log.
