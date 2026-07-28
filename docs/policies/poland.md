# Poland policy boundary

Status: architecture guidance, verified 2026-07-28. This is not legal or tax
advice. Production settings must be approved by the seller's accountant or
legal adviser.

## Verified facts relevant to the adapter

1. A proforma is not an invoice within the meaning of the Polish VAT rules and
   is not submitted to KSeF.
2. Mandatory KSeF rollout is phased:
   - from 2026-02-01 for taxpayers whose 2024 gross sales exceeded PLN 200m;
   - from 2026-04-01 for other taxpayers;
   - until 2026-12-31, taxpayers whose monthly gross sales documented by the
     relevant invoices do not exceed PLN 10,000 may continue issuing outside
     KSeF; the mandatory date for that group is 2027-01-01.
3. Receiving invoices through KSeF became mandatory from 2026-02-01 for
   entities within scope.
4. Consumer invoices in KSeF are voluntary. If a seller issues one through
   KSeF, the consumer must receive access in an agreed form.
5. Structured invoices use the FA(3) logical structure. KSeF identifiers,
   acceptance state, QR codes, offline modes, and transaction confirmations
   are infrastructure concerns, not fields that the universal document core
   may invent.
6. Until the end of 2026, transitional rules remain for cash-register invoices
   and receipts with a buyer NIP treated as simplified invoices.

## Authoritative sources

- [Mandatory KSeF scope and excluded documents](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/zakres-obowiazkowego-ksef/)
- [KSeF implementation stages](https://ksef.podatki.gov.pl/etapy-wdrozenia-ksef/)
- [Issuing and receiving invoices, including consumers](https://ksef.podatki.gov.pl/ksef-news/wystawianie-i-otrzymywanie-faktur/)
- [Legal basis and transitional rules](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/podstawy-prawne-oraz-kluczowe-terminy/)
- [KSeF 2.0 information and FA(3)](https://ksef.podatki.gov.pl/informacje-ogolne-ksef-20/)

## Required adapter decisions

Before enabling Polish automation, configuration must explicitly record:

- seller KSeF obligation start date and exemption status;
- B2B, B2C, or foreign-customer transaction classification;
- whether a fiscal receipt/cash register flow applies;
- whether a consumer invoice is issued through KSeF;
- configured proforma and invoice order statuses;
- treatment of advances, full advance payments, shipment, corrections, and
  cancellations;
- KSeF online/offline/failure mode;
- credential and authorization mechanism outside Git;
- FA(3) schema version;
- delivery/access method for consumers;
- accounting approval timestamp and policy version.

## Architecture consequences

- `proforma` remains a commercial document and never calls a KSeF gateway.
- `invoice` generation and KSeF submission are separate states. A local
  snapshot is not represented as accepted by KSeF until the gateway returns a
  verified result.
- The WooCommerce order status alone is insufficient to encode Polish law.
  Status mapping must be combined with a versioned seller/jurisdiction policy.
- Receipt/fiscalization behavior belongs in a separate fiscal gateway adapter.
- KSeF credentials, tokens, certificates, production XML, invoices, and
  customer data must never be committed.
- Changes in law or seller classification create a new policy version and
  therefore a new idempotency namespace; they do not mutate old snapshots.

## Deliberately not implemented yet

- KSeF authentication or API calls;
- FA(3) XML generation;
- cash-register/e-receipt integration;
- legally binding default triggers;
- default Polish VAT rates;
- automatic production submission.

These require seller-specific confirmation and integration tests against the
appropriate official test environment.
