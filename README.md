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

The repository now contains an isolated WooCommerce test MVP:

- immutable document snapshots and exact integer monetary calculations;
- configurable order-status policies with no fiscal defaults;
- atomic idempotency, numbering, event logging, and WordPress database storage;
- native WooCommerce order mapping;
- Polish and English HTML rendering;
- a WooCommerce admin page for seller data, trigger statuses, manual generation,
  document history, preview, printing, and browser-based PDF saving;
- a local, reproducible WooCommerce plugin ZIP build.

The integration is disabled by default. After installation, open
**WooCommerce → Commerce Documents**, enter the seller details, choose explicit
WooCommerce statuses for proformas and invoices, and enable automatic test
generation. Documents may also be generated manually for an existing order
whose current status matches the configured policy.

The MVP stores immutable snapshots locally and does not send email or submit
documents to KSeF. The preview can be printed or saved as PDF by the browser.
Whether an invoice is legally required remains an explicit merchant policy
decision.

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
