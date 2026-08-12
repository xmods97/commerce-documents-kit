# Local sandbox delivery checkpoint

The WooCommerce admin controller now has one explicit `admin_post` action for a local sandbox capture. It reads the buyer email from the immutable snapshot, renders the current PDF preview (including the WordPress Custom Logo when safe), and writes an RFC 2045 `.eml` file through `SandboxMailer`.

This is not real delivery: the action does not call `wp_mail`, SMTP, HTTP, sockets, queues, schedulers, or order hooks. The audit event is `document.sandbox_stored`, deliberately distinct from `document.sent`. The capture directory is supplied by `COMMERCE_DOCUMENTS_SANDBOX_MAIL_DIR` when configured, otherwise a dedicated system-temp directory is used. The generated file is ignored by Git.

Authorization is capability plus a nonce bound to the document ID. A missing or invalid buyer email fails before rendering. The sender is the non-delivery address `sandbox@example.invalid`.

Verification: `review/claude/verify-sandbox-admin-wiring.php` 10/10; `verify-pdf-engine.php` 86/86; PHP lint passed for changed PHP files; `git diff --check` passed. The existing review harnesses that write evidence under the OneDrive review directory may report Windows/OneDrive ACL warnings; the focused sandbox harness writes to a unique system-temp directory and passes.
