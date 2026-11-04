# Deferred findings after confirmation beta checkpoint

These findings are intentionally separated from the current R1 fix and do not block the local two-document beta or the sandbox-email review. Re-open them as separate tasks; do not silently treat them as resolved.

## R2 — legacy `paid_statuses` compatibility is asymmetric

The legacy fallback is still accepted for older settings. Review whether it should populate both new matrices, only the payment matrix, or be migrated explicitly. Add a compatibility test before removing the fallback.

## R3 — empty order matrix warning

The admin UI has an explicit warning for an enabled empty payment matrix. Decide whether an enabled empty order matrix should get the same inline warning. This is an admin UX consistency task, not a delivery/security blocker.

## R5 — `cod_offline_methods` sanitization idempotency

Review whether repeated sanitization of the already-normalized value can change it, especially across WordPress option saves and double processing. Add a focused sanitize test before changing behavior.

## R6 — readiness model scope

The current readiness card covers the two implemented confirmation types and the seller profile. If a third document type or another automation path is added, extend the readiness model and tests in that same feature task.

## Current boundary

The current beta scope remains `order_confirmation` and `payment_confirmation`. Invoice/proforma issuance, Fakturownia/KSeF, real email and production delivery remain outside this checkpoint.
