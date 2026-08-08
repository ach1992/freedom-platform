# Phase 0.5 Wallet Holds, Available Balance, Capture and Release Evidence

**Status:** implementation verified; evidence-head verification pending.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` foundation while Phase `0.4.0` live-provider gate remains open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Implementation head:** `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`.

## Accepted bounded scope

This increment extends the accepted immutable ledger foundation with wallet hold lifecycle semantics required by `WAL-002`:

- separate immutable-lifecycle `wallet_holds` records with `active`, `captured`, and `released` states;
- cash/promotional wallet accounts are validated as active IRR liability buckets owned by the subject user;
- wallet ledger balance is derived from finalized balanced ledger entries rather than a mutable balance column;
- active holds reduce available balance without rewriting ledger history;
- placing a hold is transactional, row-lock protected, unique-key protected and fails closed if available balance would become negative;
- same-key/same-payload hold placement is an exact replay and same-key/different-payload placement is a conflict with no second hold effect;
- capture is permitted once from an unexpired active hold and posts exactly one balanced immutable ledger transaction before transitioning the hold to `captured` in the same database transaction;
- capture replay verifies the recorded ledger effect and exact offset account; a materially different replay conflicts without a second ledger effect;
- release is permitted once from an active hold, restores available balance without deleting or rewriting prior ledger entries, and same-reason replay is idempotent;
- capture after release and release after capture fail closed;
- an expired active hold remains reserved until explicit release; it cannot be captured merely because time passed;
- MariaDB triggers independently enforce immutable hold identity, terminal-state immutability, valid state field combinations and non-deletion.

## Requirement mapping

This increment advances but does **not** close `WAL-002`.

- `WAL-002` — cash/promotional bucket ledger basis, active holds, available-balance protection, capture-once and release-once are now executable. Persisted balance snapshots, automated reconciliation, expired-hold sweeps and dedicated multi-process contention stress remain open.
- `DAT-002` — hold and captured-ledger amounts are integer IRR at this boundary.
- `DAT-003` — foreign keys, uniqueness, checks, indexes, state constraints and row-lock transaction boundaries are executable.
- `DAT-004` — hold identity/history and resulting ledger records are database-enforced non-deletable/immutable.
- `QUA-001` — this bounded increment has production code, automated tests, exact-head CI and retained evidence; full Phase 0.5 traceability remains open.

This increment does not claim `WAL-001`, `WAL-003`, `WAL-004`, or `WAL-005`.

## Implementation surfaces

- `database/migrations/2026_08_08_002400_create_wallet_holds.php`
- `app/Modules/Wallet/Domain/WalletHoldStatus.php`
- `app/Modules/Wallet/Application/WalletBalanceSnapshot.php`
- `app/Modules/Wallet/Application/WalletHoldReceipt.php`
- `app/Modules/Wallet/Application/WalletHoldService.php`
- existing accepted `LedgerPostingService` used for capture effects

## Executable verification

Primary coverage: `tests/Feature/WalletHoldLifecycleTest.php`.

The test suite proves:

1. a funded wallet derives its ledger balance from finalized entries; placing a hold reduces available balance while leaving ledger balance unchanged;
2. exact placement replay creates no second hold, changed payload conflicts, and an over-available hold is rejected with no negative available balance;
3. capture creates exactly one ledger transaction/two entries, reduces ledger balance once and clears the active reservation;
4. same capture replay creates no duplicate ledger effect, while a replay targeting a different offset account conflicts;
5. release restores available balance without creating a ledger transaction, exact same-reason release replays, and changed-reason release conflicts;
6. captured holds cannot be released and released holds cannot be captured;
7. expired active holds stay reserved and cannot be captured until explicitly released;
8. direct database attempts to mutate hold identity, mutate terminal state or delete hold rows are rejected by MariaDB guards.

A dedicated multi-process contention stress harness is not claimed by this increment. Concurrency protection here is established by transaction scope, wallet-account/hold row locks, unique hold keys and the existing ledger command-key uniqueness barrier; stress evidence remains later `WAL-002` work.

## Exact implementation CI

GitHub Actions run `31232610814` / run `#1046` on exact implementation head `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`:

- Repository preflight / planning / project-control verification — **success**;
- Secret scan — **success**;
- PHP static quality: Pint, PHPStan/Larastan and repository policy — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **331 tests, 1834 assertions, success**.

Retained test artifact:

- name: `test-evidence-31232610814`;
- artifact ID: `9014323613`;
- uploader SHA-256: `9a5154dd6cb0baeb52880c8fbc86e06fcdccd98b9bd62cc41eed42ca745f06f1`;
- independently calculated SHA-256: `9a5154dd6cb0baeb52880c8fbc86e06fcdccd98b9bd62cc41eed42ca745f06f1`.

Independent artifact inspection observed exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The bounded sensitive-marker scan found no PasarGuard API-key marker, PasarGuard protected secret-variable marker, Bearer authorization material or private-key marker.

## Safety and phase-boundary notes

- no mutable wallet balance field becomes the source of truth;
- capture uses compensating/append-only ledger semantics and release does not erase financial history;
- no external payment provider, Payment Intent, transfer, refund, correction or Order/provisioning behavior is claimed;
- no Phase 0.4 provider capability or Target is activated by this financial work;
- PasarGuard controlled live execution remains a separate protected gate and Marzban live acceptance remains owner-deferred to final release acceptance;
- no provider credential or sensitive provider artifact is contained in this evidence.

## Remaining WAL-002 work

The next safe bounded work is ledger-derived persisted snapshots/reconciliation and explicit expired-hold cleanup, followed by dedicated contention/duplicate-effect stress where appropriate. Any cached balance snapshot must remain non-authoritative and reconciled against immutable ledger plus active holds.

## Evidence-head gate

This document and its traceability companion require mandatory CI on their exact combined evidence head before this increment is evidence-complete.
