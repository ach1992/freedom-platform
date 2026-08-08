# Phase 0.5 Wallet Maintenance Operations Traceability

**Scope:** bounded operational execution for accepted wallet reconciliation and expired-hold cleanup.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Implementation boundary:** `87392cca0a1a00ee87a1b5074386dd691a4094c6`.  
**Implementation CI:** `31233514751` / `#1066` — success, 338 tests / 1905 assertions.  
**Evidence:** `evidence/0.5.0/wallet-maintenance-operations.md`.

## Requirement-to-proof map

| Requirement | Foundation status | Design / implementation | Automated proof | Remaining scope |
|---|---|---|---|---|
| `WAL-002` | partial; operational reconciliation/cleanup accepted after evidence-head CI | `WalletMaintenanceService`, `WalletMaintenanceCommand`, accepted reconciliation/cleanup services | healthy/review/limit/scheduler command tests plus prior wallet feature tests | dedicated multi-process contention/stress; broader wallet workflows |
| `RUN-003` | satisfied for this task | registered Laravel command executed by existing single Scheduler | `schedule:list` includes `wallet:maintenance` | project-wide scheduler/runtime remains broader operations scope |
| `RUN-004` | partial, task-level | bounded limits, safe review status, underlying idempotent/reconcilable operations, `withoutOverlapping`, `onOneServer` | command behavior and schedule registration tests | project-wide timeout/history/metrics/dead-letter/alerting remain broader scope |
| `QUA-001` | satisfied for this bounded implementation after evidence-head CI | production code, tests, exact CI, retained artifact, independent digest | mandatory CI #1066 | full Phase 0.5 closure remains open |

## Operational authority proof

The command is coordination only. It does not implement an alternative financial path:

- expired holds are transitioned only through `ExpiredWalletHoldCleanupService` -> `WalletHoldService::release()`;
- wallet state is reconciled only through `WalletReconciliationService`, which derives fresh state from finalized immutable ledger entries and active holds;
- persisted snapshots remain non-authoritative;
- the command never posts a debit/credit, places a hold, captures a hold or changes a snapshot directly.

## Safe output proof

`wallet:maintenance --json` emits only:

- overall `healthy` / `review_required` state;
- expired-hold examined/released/replayed/review counts;
- wallet examined/reconciled/initial/matched/refreshed/review counts.

It emits no row IDs, hold keys, source IDs, customer IDs, wallet account IDs, amounts, credentials or integration artifacts. Invalid limits return only the stable `wallet_maintenance_invalid_input` code in JSON mode.

## Scheduler proof

The schedule uses one existing Laravel Scheduler entry:

- command: `wallet:maintenance`;
- cadence: every five minutes;
- fixed hold batch bound: 100;
- fixed wallet batch bound: 200;
- JSON output enabled;
- `withoutOverlapping()` enabled;
- `onOneServer()` enabled.

No new OS/system Cron is introduced, preserving the project's single Scheduler Cron contract.

## Failure/review semantics

A maintenance pass returns failure/review-required if either cleanup or wallet reconciliation reports review work. The command does not print raw review-row identifiers. A failed row therefore remains available in authoritative storage for later inspection/reconciliation while normal logs stay aggregate-only.

This task-level behavior does not claim the full global `RUN-004` operations framework for history, metrics, timeouts, alert deduplication or dead-letter queues; those remain broader operations-center work.

## Explicit non-claims

This traceability does not claim:

- dedicated multi-process wallet contention/stress;
- `WAL-001` Payment Intent top-up;
- `WAL-003` wallet transfer;
- `WAL-004` refund;
- `WAL-005` balance correction;
- pricing/Quote, promotion or payment-provider behavior;
- Order/provisioning behavior;
- PasarGuard or Marzban live acceptance.

Phase `0.4.0` remains open. This operational wallet task cannot activate provider Targets or weaken provider lookup/idempotency/TLS/redaction controls.

## Verification record

Implementation CI on `87392cca0a1a00ee87a1b5074386dd691a4094c6`:

- run `31233514751` / `#1066`;
- mandatory jobs all success;
- full suite `338 tests, 1905 assertions`;
- test artifact `test-evidence-31233514751`, ID `9014696797`;
- uploader and independently verified SHA-256 `f90aac3ae57325d345b3e49b0a4c060d69cf49997b49dd8c0234bf086e6f5b22`;
- artifact contained exactly five expected test/coverage/service-log files and the bounded sensitive-marker scan was clean.

## Evidence-head requirement

This traceability document and its evidence companion are accepted only after mandatory CI succeeds on their exact combined evidence head. Until then, implementation is green but the documentation boundary remains pending.
