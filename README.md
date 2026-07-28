# Commerce Documents Kit

Universal, framework-independent foundations for generating commercial
documents.

The project is designed to support separate runtimes, including:

- a manual WordPress document generator;
- an automatic WooCommerce proforma and invoice generator;
- future adapters for other commerce systems.

## Architecture rules

- The document core does not depend on WordPress, WooCommerce, a country,
  a payment provider, a PDF engine, or a specific business.
- Monetary calculations use integer minor units and never use floating point.
- Issued documents are immutable snapshots of their source data.
- Country-specific legal and tax behavior belongs in explicit policy adapters.
- A proforma, invoice, receipt, or other document is selected by policy and
  business events, not by assumptions embedded in the core.
- Production release and deployment are always separate, explicit operations.

## Current status

The repository now contains an isolated WooCommerce shadow-generation MVP:

- immutable document snapshots and exact integer monetary calculations;
- configurable order-status policies with no fiscal defaults;
- atomic idempotency, numbering, event logging, and WordPress database storage;
- native WooCommerce order mapping;
- Polish and English HTML rendering;
- a local, reproducible WooCommerce plugin ZIP build.

The integration is disabled by default. It writes a document snapshot only when
the `commerce_documents_wc_shadow_enabled` option is strictly `true` and a
complete `commerce_documents_wc_settings` option has been supplied. The MVP
does not send email, generate PDF files, submit documents to KSeF, or determine
whether an invoice is legally required. Those actions remain explicit adapters
and policy decisions.

## Local verification

Install development dependencies and run:

```text
composer validate --strict --no-check-publish
composer test
pwsh ./tools/build-woocommerce.ps1
```

The build writes the ZIP and its SHA-256 checksum to `dist/`. The archive is a
development artifact only; building it does not publish or deploy anything.

See [document lifecycle requirements](docs/document-lifecycle.md).
See [Poland policy boundary](docs/policies/poland.md).
