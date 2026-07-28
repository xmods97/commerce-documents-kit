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

The first isolated foundation contains immutable `Currency`, `Money`, and
`TaxRate` value objects with unit tests. It is not connected to any production
WordPress runtime.

See [document lifecycle requirements](docs/document-lifecycle.md).
