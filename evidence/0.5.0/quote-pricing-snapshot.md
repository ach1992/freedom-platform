# Phase 0.5 Deterministic Pricing / Immutable Quote Snapshot Evidence

**Status:** implementation-verified evidence candidate; exact evidence-head CI remains required before acceptance.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` work while Phase `0.4.0` protected provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Requirement:** `BUY-002` with supporting `DAT-002`, `DAT-003`, `DAT-004`, `SEC-002`, `QUA-001`.  
**Implementation head:** `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`.  
**Implementation CI:** `31267071664` / `#1233` — success.  
**Traceability:** `docs/57-phase-0.5-quote-pricing-traceability.md`.

## Bounded scope

This increment implements the provider-independent immutable Quote pricing snapshot boundary. It snapshots the accepted pricing inputs and current Offering configuration into an append-only Quote without creating a paid Order, wallet/payment effect, provisioning request, Service lifecycle transition, promotion/referral side effect or provider operation.

The authoritative pricing order represented by this first boundary is:

1. Offering base price;
2. optional already-resolved account/tier/agent override input;
3. optional already-resolved eligible discount input;
4. deterministic final Quote amount.

Payment-method exact adjustment, promotion/referral eligibility, most-specific agent pricing resolution and purchase/route eligibility remain later owned increments. This Quote is a pricing snapshot, not payment or purchase authority.

## Production behavior

`QuoteService::create()` requires a caller-supplied Quote key, active customer/agent pricing subject, existing Plan Offering and explicit UTC validity. All monetary values are integer IRR. The service locks current subject and Offering state, reads the Offering version/configuration hash from immutable Offering history, validates any current tier/agent reference used by a pre-resolved override input, applies the override before discount and rejects discount greater than effective price.

The immutable Quote stores:

- user/account-type snapshot;
- Offering ID/code/version/configuration hash and discount-eligibility snapshot;
- base price;
- override source/reference/value;
- effective price after override;
- discount reference/value;
- final price;
- `IRR` currency;
- validity start/end;
- bounded canonical configuration snapshot/hash;
- creation correlation ID and caller Quote key.

The configuration snapshot also retains the Offering state/visibility observed at Quote time. The first `BUY-002` boundary deliberately does not interpret these values as purchase eligibility; later `BUY-001` Order flow must perform its own current authoritative sale/route eligibility checks before purchase execution.

## Replay and configuration-change semantics

The Quote key is unique and bound to the caller's immutable request payload: subject, Offering, explicit override/discount inputs and exact expiry. Exact key/request reuse returns the same accepted Quote. Changed reuse conflicts and cannot overwrite the first Quote.

The request hash deliberately does not re-hash mutable current Offering configuration. Once a Quote key has accepted a Quote, later Offering price/version/configuration changes cannot reinterpret it. Exact historical replay returns the stored Quote; a new Quote key snapshots the then-current Offering price/version/configuration.

`current()` validates Quote identity and explicit expiration. Expired Quotes remain immutable historical records but cannot be silently treated as a current Quote.

## Database authority and immutability

The `quotes` table uses relational columns for queryable pricing identity plus a bounded JSON configuration snapshot. Database checks independently enforce:

- subject type `customer|agent`;
- override source `none|account|tier|agent`;
- `IRR` currency;
- non-negative base/effective/override/discount/final values;
- effective price equals override price when present, otherwise base;
- discount does not exceed effective price;
- final price equals effective price minus discount;
- valid override/discount shapes;
- expiration strictly after validity start;
- fixed SHA-256 hash lengths;
- configuration snapshot must be a JSON object of at most 32 fields and 8192 bytes.

The insert trigger independently verifies an active matching pricing subject, exact current Offering ID/code/version/base/discount-eligibility plus the matching immutable Offering-history configuration hash, current tier/agent override reference where applicable, discount eligibility, and configuration snapshot hash. Update/delete triggers make accepted Quotes immutable and non-deletable.

No ledger, wallet hold, Payment Intent, payment settlement, Order paid state or provider table is mutated by Quote creation.

## Dedicated verification

`tests/Feature/QuotePricingSnapshotTest.php` — **8 tests / 63 assertions**:

1. base integer-IRR Quote snapshot, bounded configuration hash, no financial/payment effect and exact replay;
2. same Quote key with changed pricing or validity conflicts without overwrite;
3. explicit account override precedes discount and discount above effective price fails closed;
4. discount requires Offering eligibility and malformed/negative pricing inputs fail closed;
5. current tier/agent references are required for those resolved override sources; stale references fail;
6. an accepted Quote remains stable after later Offering price/version/configuration change while a new Quote uses the new configuration;
7. expired Quote is rejected as current while exact historical creation replay remains immutable;
8. database guards reject Quote update/delete and forged configuration-snapshot hashes.

The test uses the accepted Plan Offering service/history rather than inventing a second Offering versioning model. Draft/hidden Offering state in the test is intentional: `BUY-002` snapshots pricing; later purchase eligibility remains a distinct `BUY-001` execution-time gate.

## Exact implementation-head verification

Exact implementation head `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`, run `31267071664` / `#1233`:

- Repository preflight / project control — **success**;
- Secret scan — **success**;
- PHP static quality — **success** (`Pint` clean, PHPStan no errors, forbidden-pattern and architecture checks clean);
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **392 tests / 2355 assertions, success**;
- dedicated Quote suite — **8 tests / 63 assertions, zero failures/errors/skips**;
- runner — `freedom-staging-runner`;
- PHP `8.4.23`, PCOV `1.0.12` for coverage;
- artifact `test-evidence-31267071664`, ID `9024501375`;
- GitHub uploader digest: `sha256:899a26d7553f4fd37c2102da986fc876f0a8e8151b683ff36e2f300acb9ad754`;
- independently downloaded/recalculated SHA-256: `899a26d7553f4fd37c2102da986fc876f0a8e8151b683ff36e2f300acb9ad754`.

Independent artifact inspection found exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The independent JUnit read confirms **392 tests / 2355 assertions**, zero failures/errors/skips, and the dedicated Quote suite remains **8 / 63**.

A safe retained-artifact scan found zero known CI database/Redis credential values, zero known CI application-key payload values, zero bearer/basic authorization values and zero private-key headers. Generic words such as `token`, `secret`, `authorization`, `password` and `api_key` occur only in retained test/source metadata and do not establish a secret disclosure.

Run `#1225` is diagnostic only: functional tests were green but `Pint` exposed one migration quoting-style issue. The exact implementation head above includes that correction and is the only implementation run claimed for acceptance.

## Safety conclusions candidate

Pending exact evidence-head CI, the implementation proof supports these bounded conclusions:

- Quote money is deterministic integer IRR with no monetary float;
- accepted Quote identity and price components are immutable;
- override is applied before discount and final amount cannot be negative;
- same-key materially changed input cannot overwrite the accepted Quote;
- later Offering changes cannot reinterpret an accepted Quote;
- a new Quote snapshots the then-current Offering configuration;
- expired Quote cannot silently become current pricing authority;
- tier/agent resolved override references must still match current stored subject state at Quote creation;
- database guards independently reject forged/mutable Quote state;
- Quote creation has no wallet/payment/provider/provisioning effect.

## Explicit non-claims

This candidate does **not** claim:

- `BUY-001` purchase/Order eligibility or paid Order state;
- promotion/referral eligibility or redemption;
- `AGT-005` most-specific agent pricing rule resolution;
- account/tier/agent override policy computation beyond snapshotting an explicitly resolved input and validating tier/agent references;
- `PAY-001` payment-method eligibility or payment-method exact adjustment;
- real payment provider execution/refund;
- wallet debit/capture;
- provisioning or Service activation;
- customer/admin purchase UX;
- Phase `0.4.0`, Phase `0.5.0` or release closure.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment acceptance remains a final-release gate.

After exact evidence-head acceptance, continue independent Phase `0.5.0` pricing work with promotions/referrals and stored pricing-rule resolution, then most-specific agent pricing and payment-method/provider work without pulling Phase `0.6.0` Order/provisioning execution forward.
