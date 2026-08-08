# Phase 0.5 Deterministic Pricing / Immutable Quote Snapshot Evidence

**Status:** accepted parallel boundary.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` work while Phase `0.4.0` protected provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Requirement:** `BUY-002` with supporting `DAT-002`, `DAT-003`, `DAT-004`, `SEC-002`, `QUA-001`.  
**Implementation head:** `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`.  
**Implementation CI:** `31267071664` / `#1233` — success.  
**Evidence head:** `07840d417e64eb30bf31f17bb9e26c1fe549eef3`.  
**Evidence CI:** `31267346707` / `#1236` — success.  
**Traceability:** `docs/57-phase-0.5-quote-pricing-traceability.md`.

## Accepted boundary

This increment implements the provider-independent immutable Quote pricing snapshot required by `BUY-002`. It snapshots accepted pricing inputs and current Offering configuration into an append-only Quote without creating a paid Order, wallet/payment effect, provisioning request, Service lifecycle transition, promotion/referral side effect, or provider operation.

The bounded pricing order represented by this first Quote boundary is:

1. Offering base price;
2. optional already-resolved account/tier/agent override input;
3. optional already-resolved eligible discount input;
4. deterministic final Quote amount.

Payment-method adjustment, promotion/referral qualification and redemption, most-specific agent pricing resolution, purchase eligibility, provider execution, and Order/provisioning ownership remain later requirements.

## Production behavior

`QuoteService::create()` requires a caller-supplied Quote key, active customer/agent pricing subject, existing Plan Offering, explicit resolved pricing input, correlation ID, and UTC expiry. Monetary values are integer IRR only.

The service locks current subject and Offering state, reads the exact Offering version/configuration hash from immutable Offering history, validates current tier/agent references where those resolved override sources are used, applies override before discount, and rejects discount above the effective price.

The immutable Quote stores the subject/account-type snapshot, Offering ID/code/version/configuration hash and discount eligibility, base price, override source/reference/value, effective price, discount reference/value, final price, `IRR`, validity, bounded configuration snapshot/hash, correlation ID, and caller Quote key.

The configuration snapshot also retains the Offering state/visibility observed at Quote time. This does not make the Quote purchasable. Later `BUY-001` execution must perform fresh authoritative purchase/route/customer eligibility.

## Replay and configuration-change semantics

The Quote key is unique and bound to the immutable caller request payload: subject, Offering, explicit override/discount inputs, and exact expiry.

- exact key/request reuse returns the same accepted Quote;
- materially changed reuse conflicts and cannot overwrite the first Quote;
- later Offering price/version/configuration changes cannot reinterpret an accepted Quote;
- a new Quote key snapshots the then-current Offering configuration;
- expired Quotes remain immutable historical records but `current()` rejects them as current pricing authority.

Mutable Offering configuration is deliberately not folded into the replay request hash after acceptance. Historical exact replay returns the stored Quote instead of silently re-pricing it.

## Database authority and immutability

The `quotes` table uses relational pricing identity plus a bounded JSON configuration snapshot. Database checks independently enforce:

- subject type `customer|agent`;
- override source `none|account|tier|agent`;
- `IRR` currency;
- non-negative base/effective/override/discount/final values;
- effective price equals override price when present, otherwise base;
- discount does not exceed effective price;
- final price equals effective price minus discount;
- valid override/discount shapes;
- expiry after validity start;
- fixed SHA-256 hash lengths;
- configuration snapshot JSON object bounded to 32 fields and 8192 bytes.

The insert trigger independently verifies an active matching pricing subject, exact current Offering ID/code/version/base/discount-eligibility plus matching immutable Offering-history configuration hash, current tier/agent override reference where applicable, discount eligibility, and configuration snapshot hash. Update/delete triggers make accepted Quotes immutable and non-deletable.

Quote creation does not mutate ledger, wallet holds, Payment Intents, settlements, provider state, paid Orders, provisioning, or Services.

## Dedicated verification

`tests/Feature/QuotePricingSnapshotTest.php` contains **8 tests / 63 assertions** covering:

1. base integer-IRR snapshot, bounded configuration hash, no financial/payment effect, and exact replay;
2. changed same-key pricing/validity conflict without overwrite;
3. override-before-discount and discount-over-effective rejection;
4. Offering discount eligibility plus malformed/negative pricing rejection;
5. current tier/agent reference validation and stale-reference failure;
6. historical stability across later Offering price/version/configuration change while a new Quote uses the new configuration;
7. expired-current rejection while exact historical creation replay remains stable;
8. database update/delete guards and forged configuration-snapshot rejection.

The tests reuse accepted Plan Offering/history behavior rather than creating a second Offering version model. Draft/hidden Offering state in these tests is intentional because this boundary proves pricing snapshot semantics, not `BUY-001` purchase eligibility.

## Exact implementation-head verification

Exact implementation head `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`, run `31267071664` / `#1233`:

- Repository preflight / project control — success;
- Secret scan — success;
- PHP static quality — success;
- Dependency and license policy — success;
- MariaDB and authenticated Redis suite — **392 tests / 2355 assertions**, success;
- dedicated Quote suite — **8 tests / 63 assertions**, zero failures/errors/skips;
- runner `freedom-staging-runner`;
- PHP `8.4.23`, PCOV `1.0.12`;
- artifact `test-evidence-31267071664`, ID `9024501375`;
- uploader and independent SHA-256 `899a26d7553f4fd37c2102da986fc876f0a8e8151b683ff36e2f300acb9ad754`.

Run `#1225` is diagnostic only: functional tests were green but `Pint` exposed one migration quoting-style issue. The implementation head above includes that correction and is the only implementation run accepted.

## Exact evidence-head verification

Exact evidence head `07840d417e64eb30bf31f17bb9e26c1fe549eef3`, run `31267346707` / `#1236`:

- all five mandatory CI jobs completed successfully on the self-hosted runner;
- full MariaDB/authenticated Redis suite — **392 tests / 2355 assertions**, zero failures/errors/skips;
- dedicated `QuotePricingSnapshotTest` — **8 tests / 63 assertions**, zero failures/errors/skips;
- artifact `test-evidence-31267346707`, ID `9024577868`, size `116261` bytes;
- GitHub uploader digest `sha256:ba2710a544ccd07c72a56c616f7215406175ca8fdc93bc770c47ad474b275e83`;
- independently downloaded/recalculated SHA-256 `ba2710a544ccd07c72a56c616f7215406175ca8fdc93bc770c47ad474b275e83`.

Independent artifact inspection found exactly:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The independent JUnit read confirms **392 / 2355** globally and **8 / 63** for the Quote suite. A bounded retained-artifact scan found no known CI database/Redis credential values, known CI application-key payload, bearer/basic authorization values, PasarGuard protected-secret names/values, or private-key headers.

## Accepted safety conclusions

The accepted evidence supports these bounded conclusions:

- Quote money is deterministic integer IRR with no monetary float;
- accepted Quote identity and price components are immutable;
- override is applied before discount and final amount cannot be negative;
- same-key materially changed input cannot overwrite the accepted Quote;
- later Offering changes cannot reinterpret an accepted Quote;
- a new Quote snapshots the then-current Offering configuration;
- expired Quote cannot silently become current pricing authority;
- tier/agent resolved override references must match current stored subject state at Quote creation;
- database guards independently reject forged/mutable Quote state;
- Quote creation has no wallet/payment/provider/provisioning effect.

## Explicit non-claims

This accepted boundary does **not** claim:

- `BUY-001` purchase/Order eligibility or paid Order state;
- `PRO-001` promotion qualification/reservation/redemption/release;
- `REF-001` referral reward lifecycle;
- `AGT-005` most-specific agent pricing rule resolution;
- stored account/tier/agent override-policy computation beyond validating and snapshotting an explicitly resolved input;
- `PAY-001` payment-method eligibility or payment-method exact adjustment;
- real payment provider execution/refund;
- wallet debit/capture;
- provisioning or Service activation;
- customer/admin purchase UX;
- Phase `0.4.0`, Phase `0.5.0`, or release closure.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment acceptance remains a final-release gate.

## Continuation boundary

The next independent Phase `0.5.0` work is a bounded promotion/referral/pricing-rule resolution foundation, starting from `PRO-001` and `REF-001` without prematurely claiming redemption, most-specific `AGT-005` pricing, `PAY-001`, provider execution, or Phase `0.6.0` Order/provisioning behavior. The current handoff is `docs/52-current-continuation-handoff.md`.
