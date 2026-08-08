# Phase 0.5 Wallet Reconciliation Snapshots and Expired-Hold Cleanup Evidence

**Status:** implementation verified; evidence-head verification pending.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` foundation while Phase `0.4.0` provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Implementation head:** `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`.

## Accepted bounded scope

This increment extends the accepted immutable ledger and wallet-hold foundations with a non-authoritative reconciliation read model and explicit expired-hold cleanup:

- `wallet_balance_snapshots` is append-only and records ledger-derived balance, active-hold total, available balance, source coordinates, a deterministic source fingerprint, comparison state and calculation time;
- snapshots are never used to authorize a hold, capture or other financial effect; each reconciliation pass performs a fresh authoritative wallet-account validation/lock and derives state from finalized ledger entries plus active holds;
- the first snapshot is `initial`; an unchanged authoritative source produces a new `matched` snapshot; changed/stale source evidence produces a new `refreshed` snapshot rather than mutating prior history;
- snapshot source coordinates include the latest finalized ledger entry and latest active hold observed for the wallet account;
- MariaDB independently enforces non-negative snapshot arithmetic, `available = ledger - active holds`, active-holds-not-greater-than-ledger, valid comparison state, immutable snapshots and non-deletion;
- an authoritative state where debits exceed credits or active holds exceed ledger balance fails closed and does not create a snapshot;
- an inserted stale but structurally valid snapshot cannot change the authoritative wallet balance and is detected by the next reconciliation fingerprint comparison;
- expired-hold cleanup examines only bounded `active` rows with authoritative expiry at or before the current time;
- cleanup transitions eligible rows through the existing `WalletHoldService::release()` path, preserving terminal-state guards and never rewriting ledger history;
- cleanup is rerunnable, leaves future/captured/released holds untouched, and returns explicit `reviewHoldIds` for rows that cannot be safely released instead of silently manufacturing success.

## Requirement mapping

This increment materially advances the remaining reconciliation portion of `WAL-002` but does **not** close the whole requirement.

- `WAL-002` — immutable ledger, cash/promotional buckets, holds/capture/release, ledger-derived available balance, append-only reconciliation snapshots and bounded expired-hold cleanup are now executable. Dedicated multi-process contention/stress and operational scheduling/observability remain open.
- `DAT-002` — all persisted wallet snapshot amounts are integer IRR at this boundary.
- `DAT-003` — snapshot/hold FKs, checks, indexes, state constraints and transaction/lock boundaries are executable.
- `DAT-004` — snapshot history is database-enforced immutable/non-deletable and no reconciliation path mutates accepted financial history.
- `QUA-001` — production code, exact-head tests/CI and retained artifacts exist for this bounded increment; full Phase 0.5 closure remains open.

This increment does not claim `WAL-001`, `WAL-003`, `WAL-004`, `WAL-005`, pricing, promotions or payment providers.

## Implementation surfaces

- `database/migrations/2026_08_08_002500_create_wallet_balance_snapshots.php`
- `app/Modules/Wallet/Domain/WalletReconciliationStatus.php`
- `app/Modules/Wallet/Application/WalletReconciliationResult.php`
- `app/Modules/Wallet/Application/WalletReconciliationService.php`
- `app/Modules/Wallet/Application/ExpiredWalletHoldCleanupResult.php`
- `app/Modules/Wallet/Application/ExpiredWalletHoldCleanupService.php`
- existing accepted `WalletHoldService` and `LedgerPostingService`

## Executable verification

Primary coverage: `tests/Feature/WalletReconciliationTest.php`.

The test suite proves:

1. first reconciliation writes an `initial` snapshot with exact ledger/hold/available values;
2. unchanged state creates a `matched` append-only snapshot referencing the previous snapshot without a financial effect;
3. a new active hold changes the authoritative source fingerprint and creates a `refreshed` snapshot while prior snapshots remain unchanged;
4. a manually inserted stale but structurally valid snapshot never changes `WalletHoldService::balance()` and the next authoritative reconciliation refreshes from real ledger/hold state;
5. snapshot update/delete attempts are rejected by MariaDB triggers;
6. active holds exceeding ledger balance fail closed and no reconciliation snapshot is written;
7. cleanup respects a caller-supplied batch bound, releases only expired active rows, is safe to rerun and creates no ledger transaction;
8. future active, captured and released holds are untouched by cleanup;
9. an unsafe cleanup row is surfaced in `reviewHoldIds` while other eligible rows can still complete; the unsafe row remains active for later review;
10. cleanup input bounds fail closed.

## Exact implementation CI

GitHub Actions run `31233135374` / run `#1058` on exact implementation head `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`:

- Repository preflight / planning / project-control verification — **success**;
- Secret scan — **success**;
- PHP static quality: Pint, PHPStan/Larastan and repository policy — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **334 tests, 1886 assertions, success**.

Retained test artifact:

- name: `test-evidence-31233135374`;
- artifact ID: `9014519274`;
- uploader SHA-256: `fcad956d65cbcac41c4c103bf09e7d5d00e43e40ed6fcb398e02bbaada78f80f`;
- independently calculated SHA-256: `fcad956d65cbcac41c4c103bf09e7d5d00e43e40ed6fcb398e02bbaada78f80f`.

Independent artifact inspection observed exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The bounded sensitive-marker scan found no PasarGuard API-key marker, protected PasarGuard secret-variable marker, Bearer authorization material or private-key marker.

## Safety properties

- immutable finalized ledger entries remain the source of truth;
- active holds remain the only reservation input to available balance;
- persisted snapshots are derived evidence/cache only and cannot authorize a financial mutation;
- reconciliation records new history instead of repairing prior financial history by mutation;
- expired active holds are never silently treated as free funds; they remain reserved until the explicit cleanup release succeeds;
- a cleanup failure remains visible and retriable/reviewable rather than disappearing from the batch result;
- no provider credentials, provider artifacts or live-provider mutations are involved in this financial increment.

## Explicit non-claims and remaining WAL-002 work

This boundary does **not** claim:

- a dedicated multi-process contention/stress result for concurrent hold placement/capture/release/reconciliation;
- a production scheduler/command or operational alerting for expired-hold cleanup/reconciliation;
- a mutable cached balance as authoritative;
- wallet top-up through an actual Payment Intent/payment provider;
- transfer, refund or balance-correction workflows;
- pricing/Quote/promotion/payment-provider completion;
- Order/provisioning behavior;
- PasarGuard or Marzban live acceptance.

The next safe work may add a guarded maintenance command/schedule plus contention verification as a separate bounded increment, then continue Phase 0.5 requirements without weakening the carried Phase 0.4 provider gates.

## Evidence-head gate

This evidence and its traceability companion require mandatory CI on their exact combined evidence head before the increment is evidence-complete.
