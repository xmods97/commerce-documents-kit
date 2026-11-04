# Release candidate 0.3.5 — standard WooCommerce PDF attachments

CHANGE_ID: GEWARD-20261104-CUSTOM-DOCUMENTS-EMAIL-ATTACHMENTS-001
TASK_ID: GEWARD-PREOPEN-LOCAL-REMEDIATION-2026-10-10
Status: source candidate; owner approved an ordinary commit and branch-only push. No tag, release, installation or deployment is authorized.
Source: https://github.com/xmods97/commerce-documents-kit
Base: published v0.3.4, commit 7e8966c6efad56b5e74bac7e6068f63abc194918.
Local branch: agent/geward-email-pdf-20261104.
Local clone: D:/Codex/Geward-rebuild/.ai/candidates/commerce-documents-kit-email.

## Source changes (exact five paths)

- packages/woocommerce/src/Plugin.php: boot attachment provider; version0.3.5.
- packages/woocommerce/src/EmailAttachments.php: new standard email attachment provider.
- plugins/commerce-documents-woocommerce/commerce-documents-woocommerce.php: version0.3.5 header.
- tools/verify-email-attachments.php: standalone no-network/no-mail boundary verifier; excluded from plugin ZIP.
- docs/release-candidate-0.3.5.md: this internal release note; excluded from plugin ZIP.

customer_on_hold_order receives an order-confirmation PDF. customer_processing_order and customer_completed_order receive a payment-confirmation PDF only under existing eligibility/settings. Uses the latest verified active snapshot, including rebuilds, preserves unrelated attachments, deduplicates its own files, and removes private temporary PDFs at process shutdown. Does not call wp_mail or change email addresses/body/subject. No fiscal types, calculations, settings, stock or existing order modifications are part of this change.

## Evidence

- PHP8.3 lint: changed PHP files and standalone verifier PASS.
- php tools/verify-email-attachments.php:17 behavioral checks PASS, including mapping, settings, latest snapshot, deduplication, error handling, privacy and cleanup. Uses explicit process-local framework stubs, not a full WooCommerce test.
- Separate actual local WooCommerce verification on already marked order325:17 get_attachments checks PASS; no send invoked, no persisted status/options change; order retained cancelled. See GEWARD docs/custom-documents-audit-2026-11-04.md.
- Existing tools/build-woocommerce.ps1 produced dist/commerce-documents-woocommerce.zip (201633 bytes) plus .sha256 artifact. Only80 plugin files; no docs/tools/tests/.git/wp-config/PDF/EML artifacts. Version0.3.5 and attachment provider present.
- Extracted package passed existing tools/verify-plugin-bootstrap.php.
- git diff --check PASS for tracked changes; tracked diff contains only boot and two version changes. New provider/verifier validated by lint and behavioral tests.
- Full Composer/PHPUnit suite and PHP7.4 execution not run locally; the release workflow requires these checks. Do not claim CI/release success from this candidate.

## Publication gate

.github/workflows/release.yml publishes on v*.*.* tag pushes. GitHubReleaseUpdater::autoUpdate() returns true for this plugin. Publishing a tag can therefore lead to automatic installation on sites running this updater; current Production behavior was not inspected. Treat publication as potentially Production-affecting: exact plan and standalone ПРОД required before any such tag/release operation. No tags, commits, push, PR, release, deploy, settings or emails were performed here.

The candidate ZIP has not been installed over Laragon or Production. Laragon remains the previously tested modified0.3.4 copy. Future release must use this separate plugin repository and package; do not silently add the entire plugin to the GEWARD code manifest. Test scripts/artifacts and this nested .ai clone are excluded from the GEWARD release.

## Required-check follow-up
Follow-up 2026-11-04 13:59:24 +01:00 Europe/Warsaw: existing locked Composer dev dependencies installed only in ignored candidate/vendor (28 packages; PHPUnit9.6.35). composer validate --strict --no-check-publish PASS; full existing PHPUnit suite PASS171 tests/652 assertions on PHP8.3.30; repository PHP lint PASS102 files. Earlier not-run statement is superseded for full local suite; PHP7.4 and GitHub CI still not run. No product code or ZIP changed by these checks, no publication.

## Branch publication approval
2026-11-04 14:09:18 +01:00 Europe/Warsaw: owner explicitly approved commit and ordinary push of the exact five source paths on agent/geward-email-pdf-20261104. This supersedes earlier no-commit/no-push checkpoint statements only for that branch operation. Tags, releases, PRs, Production and sends remain excluded. Release workflow is tag-triggered; branch-only push does not publish the package.
