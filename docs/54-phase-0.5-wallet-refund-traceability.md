# Phase 0.5 Wallet Refund / Reversal Foundation Traceability

**Status:** evidence-complete provider-independent `WAL-004` foundation.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Requirement:** `WAL-004`.  
**Implementation head:** `0237f94cae67ff2ca31047af55420fed0ffe578a`.  
**Implementation CI:** `31242702422` / `#1155` — success, 360 tests / 2099 assertions.  
**Evidence head:** `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`.  
**Evidence-head CI:** `31242888656` / `#1156` — success, 360 tests / 2099 assertions.  
**Evidence:** `evidence/0.5.0/wallet-refund-reversal-foundation.md`.

## Requirement-to-proof map

| Requirement / invariant | Accepted proof | Remaining scope |
|---|---|---|
| `WAL-004` partial refund | immutable `refunds` + source allocations; integer partial amount; compensating ledger posting | provider-native integrations and Order ownership remain later boundaries |
| cumulative refunds cannot exceed refundable capture | source transaction/refundability lock + aggregate accepted refund cap + per-source-entry cap | each future payment/provider path must declare authoritative refundable metadata |
| non-refundable capture portion | immutable `refundable_total_irr` can be lower than captured total and is bound before source finalization | actual exact-card-adjustment calculation waits for Quote/Payment Intent metadata |
| wallet refund destination | only original wallet liability debit entries are eligible; reversal credits the exact same account/bucket | no migration of historical bucket identity is claimed |
| manual external destination | only original external asset debit entries; payment/evidence references required; reversal never credits a wallet | provider-native destination remains unavailable in this boundary |
| destination override | `refunds.override_destination` plus existing administrator authorization and required reason/audit context | no new HTTP/Telegram admin UX is claimed |
| idempotency | unique refund key + canonical payload hash; exact replay returns accepted refund; changed reuse conflicts | provider callback idempotency remains later provider scope |
| immutable history | prior ledger entries never change; refund/refundability/allocation rows are database-guarded against update/delete | `WAL-005` must use the same compensating-history principle |
| `DAT-002` | all refund/cap/allocation amounts are integer IRR through `IrrMoney` and BIGINT schema | non-IRR/crypto precision remains separate payment scope |
| `DAT-003` | MariaDB transaction, source row lock, FKs, unique keys, checks, triggers, ordered ledger locks | later financial schemas need equivalent proof |
| `DAT-004` | source and refund ledger histories are append-only; source links and terminal refund effect immutable | future correction/provider settlement must preserve this invariant |
| `ACL-001` / `ACL-002` | execution-time `refunds.approve`; critical override permission; reason/audit actor context | UI/API policy surfaces remain separate |
| `SEC-002` | manual evidence is validated; safe audit stores presence flags instead of raw references | external evidence storage/access policy belongs to owning operational/provider boundary |
| `QUA-001` | exact implementation/evidence-head full CI, retained JUnit/Clover/service artifacts and independent digest inspection | full Phase 0.5 closure remains open |

## Source refundability is capture-time metadata

Refundability is not inferred later from mutable configuration. `LedgerRefundabilitySnapshot` is part of `LedgerPostingService` command identity and is inserted inside the source ledger transaction before finalization. Database guards reject direct post-finalization retrofit or later modification/deletion.

This is the bounded mechanism that lets a captured source declare `refundable_total_irr < captured_total_irr`. It proves the refund engine will honor an immutable non-refundable portion without pretending that Payment Intent/card-adjustment computation already exists.

## Destination model

Supported destinations in this foundation:

- `wallet` — value returns only to exact original wallet debit entries, preserving bucket/account identity;
- `manual_external` — value returns only through exact original external asset debit entries and requires a manual payment reference plus evidence reference.

`manual_external` evidence values are persisted as refund evidence but are not copied into the safe audit payload. The audit records only presence booleans plus bounded identifiers/amount/destination metadata.

Provider-native destination is intentionally not declared as runnable until the owning payment provider integration exists.

## Exact replay and conflict

Refund identity consists of a unique caller-supplied refund key plus a canonical payload containing source transaction, destination, sorted source-entry allocations, actor, reason and manual evidence identifiers. Correlation/request fingerprints are operational retry metadata rather than financial identity.

An exact duplicate can return the already accepted immutable refund after verifying:

- stored refund payload hash;
- source/refundable snapshot bounds;
- destination and override consistency;
- final compensating ledger transaction type/source/amount/balance/entry count;
- allocation debit/credit totals and count.

Materially changed reuse of the same refund key fails closed.

## Concurrency proof

`tests/Feature/WalletRefundContentionVerificationTest.php` starts independent PHP workers against real CI MariaDB and uses a deterministic `READY` / `GO` parent barrier.

Accepted scenarios:

1. two distinct concurrent `700,000 IRR` refunds against one `1,000,000 IRR` source result in exactly one accepted refund and one explicit bounded rejection; accepted cumulative amount remains `700,000 IRR`;
2. two identical concurrent `400,000 IRR` requests with the same refund key resolve to one primary effect and one exact replay; one refund, one compensating ledger transaction and one audit event remain.

The source ledger transaction/refundability lock serializes cumulative authorization. Per-source-entry allocation sums additionally prevent an individual original payment/bucket entry from being refunded beyond its accepted amount.

## Dedicated tests

`WalletRefundFoundationTest` — **6 tests / 52 assertions**:

- exact original multi-bucket wallet reversal;
- immutable refundable-cap versus larger capture;
- manual-external evidence and no wallet credit;
- destination override authorization;
- replay conflict and database immutability;
- atomic source refundability identity/non-retrofit.

`WalletRefundContentionVerificationTest` — **2 tests / 17 assertions**:

- concurrent cumulative cap;
- concurrent duplicate idempotency.

Both exact implementation and evidence-head artifacts independently confirm these counts with zero failures/errors/skips.

## Exact verification record

Implementation head `0237f94cae67ff2ca31047af55420fed0ffe578a`:

- CI `31242702422` / `#1155` — mandatory jobs all success;
- full MariaDB/authenticated Redis suite `360 tests / 2099 assertions`;
- runner `freedom-staging-runner`, PHP `8.4.23`, PCOV `1.0.12` for coverage;
- artifact `test-evidence-31242702422`, ID `9017545543`;
- uploader and independent SHA-256 `be509996d098ee7f1354a9dc1fe949a224b2ead42c15ae145c2e12ad59890cdc`.

Evidence head `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`:

- CI `31242888656` / `#1156` — mandatory jobs all success;
- full MariaDB/authenticated Redis suite `360 tests / 2099 assertions`;
- runner `freedom-staging-runner`, PHP `8.4.23`, PCOV `1.0.12` for coverage;
- artifact `test-evidence-31242888656`, ID `9017593626`;
- uploader and independent SHA-256 `a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`.

Each artifact contains exactly the expected JUnit, test log, Clover and two dependency-service evidence files.

## Risk disposition

This accepted lifecycle controls the identified `FIN-09` concurrent over-refund risk and `FIN-10` destination/double-reimbursement risk for the provider-independent `WAL-004` foundation.

It does not close payment-provider refund risk. Provider-native refunds, callback uncertainty, Payment Intent state, Order/referral consequences, and customer-facing flows each require their own exact implementation/evidence lifecycle.

## Explicit non-claims

No claim is made for:

- `WAL-001` top-up/Payment Intent settlement;
- `WAL-005` correction/approval;
- provider-native refund APIs;
- actual exact-card adjustment calculation;
- Orders/provisioning/referral cancellation;
- Phase `0.4.0` closure, Phase `0.5.0` closure or final release acceptance.

PasarGuard protected live execution remains the active Phase 0.4 human gate. Marzban deployment-specific acceptance remains mandatory at final release acceptance.

The next recommended bounded financial increment is `WAL-005` administrator balance correction/approval with immutable compensating history, dual-control where required, execution-time authorization, reason/audit, exact replay/conflict and dedicated concurrency evidence.
