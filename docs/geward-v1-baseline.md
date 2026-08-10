# GEWARD v1 document module baseline

## Product boundary

The automatic workflow produces only an internal order confirmation:
`Zamówienie nr …`. It is not a fiscal invoice and does not submit to
Fakturownia or KSeF. Fakturownia remains the only official invoice system.

Online payments create the confirmation after a verified payment event. COD
orders may create it through a separately explicit policy, but the rendered
PDF must state `Nieopłacone — płatność przy odbiorze`.

`proforma` and `invoice` are not part of the GEWARD automatic workflow.
Future official document types must be added as new policies and templates;
they must never mutate an issued document.

## Invariants

- An order confirmation and a correction are immutable snapshots.
- A correction is a new linked document, never an edit or regenerated
  replacement of the original.
- Idempotency is unique by source type, source ID and document type. A policy
  version change must not create another document for the same order.
- Existing local test data (one `invoice` and its 2026 sequence) remains
  untouched until a separately approved migration decision.
- PDF and sensitive snapshot data are encrypted at rest. Searchable metadata
  is deliberately limited to document number, order ID, status, dates, amount,
  currency, and an HMAC of email. Keys stay outside the database and Git.
- No live email or external delivery may be enabled before an independent
  security review.

## Current local baseline

The Laragon plugin is version 0.2.2. It has a shadow-generation setting stored
as string `"1"`, while code compares it strictly with boolean `true`; automatic
generation is therefore currently disabled by accident. Its saved proforma and
invoice status lists include all WooCommerce statuses. Any repair of that
comparison must first disarm the legacy automatic policy.

The local store uses WooCommerce HPOS. The implementation must declare HPOS
compatibility and keep using WooCommerce CRUD APIs.

## Stage gates

1. Establish the repository baseline and a versioned migration plan.
2. Add an explicit disabled-by-default policy and protected schema migration.
3. Add immutable encrypted storage, correction links, audit events and admin
   views.
4. Add deterministic PDF generation and protected customer delivery.
5. Pass independent security review before email/PDF leaves the local system.

## Migration boundary

Schema version 2 introduces only additive infrastructure tables for document
links, delivery audit and migration history. Calling `migrateToCurrentVersion()`
is intentionally not wired to plugin boot. A future administrator-only action,
with a backup and explicit approval, must invoke it. The migration does not
delete, rewrite or encrypt the existing v0.2 snapshot rows.

## Non-secrets

Never commit seller credentials, actual NIP/address values, customer data,
documents, local database exports, encryption keys, or `wp-config.php`.
