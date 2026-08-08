# Phase 0.5 Wallet Refund / Reversal Foundation Evidence

**Status:** evidence-complete bounded provider-independent `WAL-004` refund/reversal foundation.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` work while Phase `0.4.0` provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Requirement:** `WAL-004` with supporting `DAT-002`, `DAT-003`, `DAT-004`, `ACL-001`, `ACL-002`, `SEC-002`, `QUA-001`.  
**Implementation head:** `0237f94cae67ff2ca31047af55420fed0ffe578a`.  
**Implementation CI:** `31242702422` / `#1155` — success.  
**Evidence head:** `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`.  
**Evidence-head CI:** `31242888656` / `#1156` — success.  
**Traceability:** `docs/54-phase-0.5-wallet-refund-traceability.md`.

## Bounded scope

This increment implements a provider-independent refund/reversal foundation on top of the accepted immutable financial ledger. It does not own Payment Intent settlement, provider-native refund APIs, Order refund state, referral cancellation, or any Phase `0.6.0` lifecycle.

The accepted source transaction must declare refundability atomically before ledger finalization. That immutable snapshot contains an integer-IRR refundable cap and the default refund destination. A source may therefore have a captured total larger than its refundable total without inventing Payment Intent or provider-specific card-adjustment metadata.

## Production behavior

### Immutable source refundability

`LedgerPostingService` accepts an optional `LedgerRefundabilitySnapshot`. When present:

- the refundable total must be positive and cannot exceed the balanced captured total;
- refundability is included in the ledger command payload identity;
- wallet-default refundability requires an original wallet debit;
- manual-external default requires an original non-wallet debit;
- the snapshot is inserted after ledger entry totals are established but before finalization in the same database transaction;
- database triggers reject post-finalization retrofit, update, or deletion.

### Refund records and allocations

The migration adds:

- `ledger_refundability` — immutable source refund policy;
- `refunds` — unique refund key, canonical payload hash, immutable source/destination/amount/actor/reason/evidence/ledger-effect snapshot;
- `refund_allocations` — immutable mapping of refunded amounts back to exact source ledger entries.

Database checks, foreign keys, uniqueness and triggers prevent invalid destination/evidence combinations and mutation/deletion of accepted refund history.

### Refund execution

`WalletRefundService`:

- requires `refunds.approve` authorization and an explicit reason;
- locks the finalized source transaction and its immutable refundability snapshot before authorization of value;
- supports partial integer-IRR refunds;
- enforces both cumulative source refundable cap and per-source-entry allocation cap;
- uses a unique caller-supplied refund key plus canonical payload hash for exact replay/conflict;
- returns wallet refunds only to the exact original eligible wallet debit accounts/buckets;
- requires a sanitized payment reference plus evidence reference for `manual_external` and does not create a wallet credit for that destination;
- requires `refunds.override_destination` when actual destination differs from the immutable default;
- posts one balanced compensating `refund_reversal` ledger transaction instead of changing historical entries;
- records a safe administrator audit containing only bounded metadata and presence flags for manual evidence, never raw external reference values.

The `finance` role receives `refunds.approve`; destination override remains owner/explicit critical permission rather than an implicit finance capability.

## Executable verification

`tests/Feature/WalletRefundFoundationTest.php` contains **6 tests / 52 assertions** covering:

1. partial wallet refund to exact original cash/promotional buckets plus exact replay;
2. cumulative refund cap bounded by immutable refundable total even when captured total is larger;
3. manual-external evidence requirements, no wallet credit, and safe audit redaction;
4. destination override denial for ordinary finance and acceptance for owner using compatible original method entries;
5. materially changed replay conflict plus database immutability/update/delete guards;
6. refundability as part of ledger command identity and refusal to refund a source never declared refundable.

`tests/Feature/WalletRefundContentionVerificationTest.php` contains **2 tests / 17 assertions** using independent PHP processes and a deterministic `READY` / `GO` barrier against real CI MariaDB:

1. two concurrent `700,000 IRR` partial refunds against one `1,000,000 IRR` refundable source allow exactly one primary effect and reject the other without over-refund;
2. two concurrent identical `400,000 IRR` requests with one refund key resolve to one primary effect plus one exact replay, with one refund record, one compensating ledger effect and one audit event.

No sleep-based correctness mechanism or test-only lock replaces the production MariaDB transaction/locking contract.

## Exact implementation-head CI

Exact head `0237f94cae67ff2ca31047af55420fed0ffe578a`, run `31242702422` / `#1155`:

- Repository preflight / project control — **success**;
- Secret scan — **success**;
- PHP static quality — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **360 tests / 2099 assertions, success**;
- runner — `freedom-staging-runner`;
- PHP `8.4.23`, PCOV `1.0.12` for coverage;
- artifact `test-evidence-31242702422`, ID `9017545543`;
- GitHub uploader digest `sha256:be509996d098ee7f1354a9dc1fe949a224b2ead42c15ae145c2e12ad59890cdc`;
- independently recalculated SHA-256: `be509996d098ee7f1354a9dc1fe949a224b2ead42c15ae145c2e12ad59890cdc`.

## Exact evidence-head CI

Exact combined evidence head `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`, run `31242888656` / `#1156`:

- Repository preflight / project control — **success**;
- Secret scan — **success**;
- PHP static quality — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **360 tests / 2099 assertions, success**;
- runner — `freedom-staging-runner`;
- PHP `8.4.23`, PCOV `1.0.12` for coverage;
- artifact `test-evidence-31242888656`, ID `9017593626`;
- GitHub uploader digest `sha256:a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`;
- independently recalculated SHA-256: `a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`.

Independent inspection of both implementation and evidence-head artifacts observed exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

JUnit independently confirms the dedicated refund suites on both accepted boundaries as `6 tests / 52 assertions` and `2 tests / 17 assertions`, zero failures/errors/skips, while `test.log` records the complete `360 / 2099` suite.

## Safety conclusions

For this bounded foundation:

- accepted refund history is compensating and immutable rather than destructive;
- cumulative and per-entry caps are serialized against the source under MariaDB locking;
- concurrent partial refunds cannot exceed refundable value;
- a duplicate refund key cannot create a second primary financial effect;
- the refundable cap may explicitly exclude a non-refundable portion of capture without fabricating provider/card semantics;
- wallet refunds preserve original wallet bucket/account identity;
- manual-external refunds require evidence and cannot silently duplicate reimbursement into wallet balance;
- destination override is explicitly privileged and audited;
- raw manual external references are not copied into safe audit payloads.

## Explicit non-claims

This increment does **not** claim:

- provider-native refund capability or live provider acceptance;
- `WAL-001` external wallet top-up / Payment Intent settlement;
- `WAL-005` administrator correction/approval;
- exact card-adjustment computation before the owning Quote/Payment Intent metadata exists;
- Order refund-state ownership, provisioning rollback, referral reward cancellation or customer Telegram UX;
- Phase `0.4.0` closure, Phase `0.5.0` closure or release acceptance.

PasarGuard protected live execution remains the active Phase 0.4 human gate. Marzban deployment acceptance remains a final-release gate.

The next recommended independent financial increment is `WAL-005` administrator balance correction/approval using immutable compensating ledger entries, execution-time permission/approval controls, reason/audit, exact replay/conflict and dedicated concurrency verification.
