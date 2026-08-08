# Phase 0.5 Wallet Maintenance Operations Evidence

**Status:** implementation verified; evidence-head verification pending.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` foundation while Phase `0.4.0` provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Implementation head:** `87392cca0a1a00ee87a1b5074386dd691a4094c6`.

## Accepted bounded scope

This increment exposes the already accepted wallet reconciliation and expired-hold cleanup services through the repository's single Scheduler path without changing financial authority:

- `WalletMaintenanceService` runs bounded expired-hold cleanup and bounded wallet reconciliation as one operational pass;
- each operation remains delegated to the accepted `ExpiredWalletHoldCleanupService` and `WalletReconciliationService`, so immutable ledger entries plus active holds remain authoritative;
- active wallet accounts are selected only from active IRR user-owned `cash` / `promotional` liability buckets;
- wallet and hold batch limits are independently bounded to `1..500`;
- one failing/review-required hold or wallet is counted and surfaced instead of being silently omitted;
- `wallet:maintenance` exposes safe human/JSON output containing counts and health/review state only;
- JSON output never includes hold keys, source identifiers, user identifiers, wallet account identifiers, monetary source metadata, credentials or provider artifacts;
- invalid bounds fail closed with a stable safe error code;
- the command is explicitly registered in `bootstrap/app.php`;
- the existing single Laravel Scheduler runs `wallet:maintenance` every five minutes with fixed bounded options, JSON-only output, `withoutOverlapping()` and `onOneServer()`;
- no per-task system Cron is introduced and no snapshot is used to authorize a financial effect.

## Requirement mapping

- `WAL-002` — provides bounded operational execution for accepted reconciliation and expired-hold cleanup; dedicated multi-process financial contention/stress remains open.
- `RUN-003` — the task runs through the existing single Laravel Scheduler rather than a new Cron entry.
- `RUN-004` — this bounded task has overlap prevention, single-server execution, idempotent/reconcilable underlying operations and explicit review-required output. General project-wide timeout/history/metrics/dead-letter requirements remain broader operations scope.
- `QUA-001` — production code, command/schedule tests, exact-head CI and retained artifact exist for this increment.

## Implementation surfaces

- `app/Modules/Wallet/Application/WalletMaintenanceResult.php`
- `app/Modules/Wallet/Application/WalletMaintenanceService.php`
- `app/Modules/Wallet/Presentation/Console/WalletMaintenanceCommand.php`
- `bootstrap/app.php`
- `routes/console.php`
- `tests/Feature/WalletMaintenanceCommandTest.php`

## Executable verification

`WalletMaintenanceCommandTest` proves:

1. healthy JSON maintenance releases an expired active hold, reconciles the wallet, appends a snapshot and emits only aggregate counts;
2. a cleanup row requiring review returns command failure with `review_required` and a count, while the raw unsafe hold identifier is not emitted;
3. wallet account batching is bounded independently from hold cleanup batching;
4. invalid limits return a stable invalid-input result and non-success exit status;
5. the command is registered in the Laravel schedule;
6. the maintenance pass does not create a second financial transaction while releasing an expired reservation or reconciling state.

The lower-level accepted wallet reconciliation and hold tests remain part of the full suite and continue to prove append-only snapshots, stale-snapshot non-authority, active-hold reservation, capture/release idempotency and database immutability.

## Exact implementation CI

GitHub Actions run `31233514751` / run `#1066` on exact implementation head `87392cca0a1a00ee87a1b5074386dd691a4094c6`:

- Repository preflight / planning / project-control verification — **success**;
- Secret scan — **success**;
- PHP static quality: Pint, PHPStan/Larastan and repository policy — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **338 tests, 1905 assertions, success**.

Retained test artifact:

- name: `test-evidence-31233514751`;
- artifact ID: `9014696797`;
- uploader SHA-256: `f90aac3ae57325d345b3e49b0a4c060d69cf49997b49dd8c0234bf086e6f5b22`;
- independently calculated SHA-256: `f90aac3ae57325d345b3e49b0a4c060d69cf49997b49dd8c0234bf086e6f5b22`.

Independent artifact inspection observed exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The bounded sensitive-marker scan found no PasarGuard API-key marker, protected PasarGuard secret-variable marker, Bearer authorization material or private-key marker.

## Safety and non-claims

- the scheduler never reads a persisted wallet snapshot as authority for a debit, hold or capture;
- the command output is intentionally aggregate-only and cannot be used as a customer/account data export;
- a review-required pass returns failure status rather than silently claiming maintenance health;
- no Payment Intent, transfer, refund, correction, pricing, promotion or payment-provider behavior is claimed;
- no provider mutation/Target capability is enabled;
- PasarGuard actual live execution remains behind protected Actions secrets/manual dispatch and Marzban live acceptance remains owner-deferred to final release acceptance;
- dedicated multi-process wallet contention/stress remains an explicit later verification item.

## Evidence-head gate

This evidence and its traceability companion require mandatory CI on their exact combined evidence head before this increment is evidence-complete.
