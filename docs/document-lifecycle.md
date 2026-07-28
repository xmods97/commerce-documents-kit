# Document lifecycle requirements

Status: initial, country-neutral requirements.

## Source context

Stakeholder discussions established the following business distinction:

- a proforma expresses an intention or request to pay;
- an invoice or fiscal document is associated with a legally or commercially
  significant event such as confirmed payment, shipment, or another event
  required by the applicable jurisdiction;
- online and manager-assisted sales may use different triggers;
- the applicable legal rules must be configurable by jurisdiction.

The original conversation contained personal contact information. Only the
anonymized business requirements are recorded here; screenshots and personal
data are intentionally excluded from the repository.

## Universal lifecycle

The core recognizes events and document intents without deciding local law:

1. An order or commercial agreement is created.
2. A policy may allow or require a proforma.
3. Payment, shipment, acceptance, or another source event occurs.
4. A jurisdiction/business policy decides whether an invoice is required.
5. The generator creates an immutable document snapshot.
6. Repeated source events must not create accidental duplicates.
7. Sending, downloading, replacement, and correction are recorded as events.

## Policy boundary

Country and business adapters are responsible for:

- which document types are legally available;
- which source event triggers each document;
- numbering rules and series;
- issue and due dates;
- required seller and buyer identifiers;
- tax labels and required tax breakdown;
- language and mandatory wording;
- correction, cancellation, and replacement rules;
- whether a receipt or other fiscal document is required for an individual.

The core must not contain a default country, currency, tax rate, language,
invoice trigger, or payment provider.

## WooCommerce intent

The future WooCommerce adapter should:

- map an order to a normalized immutable snapshot;
- use WooCommerce as the source of payment links and payment status;
- create documents only through an explicit generation policy;
- use an idempotency key based on source, document type, policy, and version;
- preserve the original snapshot when an order changes later;
- support automatic and manager-triggered generation;
- record generation and delivery events.

## Open legal decision

Polish fiscal and invoicing requirements must be verified separately before
enabling production automation. External services may be used by an adapter,
but the universal core must not depend on a particular service.
