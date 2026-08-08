# Phase 0.5 Wallet Reconciliation Snapshots and Expired-Hold Cleanup Traceability

**Status:** evidence-complete bounded `WAL-002` reconciliation increment.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Implementation:** `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`, CI `31233135374` / `#1058` — 334 tests / 1886 assertions.  
**Evidence head:** `57719972291b0553e76da6f8db50a8190a807044`, CI `31233274800` / `#1060` — 334 tests / 1886 assertions.  
**Evidence:** `evidence/0.5.0/wallet-reconciliation-snapshots.md`.

## Requirement-to-proof map

| Requirement | Status | Accepted proof | Remaining scope |
|---|---|---|---|
| `WAL-002` | partial but evidence-complete for reconciliation/snapshot/cleanup | append-only `wallet_balance_snapshots`; `WalletReconciliationService`; bounded `ExpiredWalletHoldCleanupService`; feature/database bypass tests | dedicated multi-process contention/stress |
| `DAT-002` | satisfied for this boundary | integer snapshot/ledger/hold amounts | broader financial representations remain separate |
| `DAT-003` | satisfied for this schema | FKs, self-reference, arithmetic/state checks, indexes, transaction/lock boundaries | broader Phase 0.5 schema |
| `DAT-004` | satisfied for reconciliation history | append-only snapshots, update/delete rejection, no repair-by-mutation | other financial/audit families |
| `QUA-001` | satisfied for this increment | exact implementation/evidence CI, retained artifacts, independently checked digests | full Phase 0.5 closure |

## Authoritative balance separation

Authoritative wallet state is always recalculated from finalized immutable ledger entries plus active holds under current validation/locking. `wallet_balance_snapshots` is a derived append-only read model only. A stale or tampered-but-structurally-valid snapshot cannot authorize a debit/hold/capture or change available balance.

Reconciliation appends `initial`, `matched` or `refreshed` evidence based on a deterministic source fingerprint and never rewrites prior financial history. Impossible negative or over-held state fails closed and writes no snapshot.

## Cleanup boundary

Cleanup selects only bounded active expired holds, routes eligible rows through `WalletHoldService::release()`, is rerunnable and returns unsafe rows for review rather than manufacturing success. `wallet_transfer` holds are excluded from generic cleanup so transfer state and its reservation cannot diverge.

Operational command/scheduling was completed by the later Wallet Maintenance Operations boundary; see `docs/49-phase-0.5-wallet-maintenance-traceability.md`.

## Verification record

Implementation artifact:

- `test-evidence-31233135374`, ID `9014519274`;
- SHA-256 `fcad956d65cbcac41c4c103bf09e7d5d00e43e40ed6fcb398e02bbaada78f80f`.

Evidence-head artifact:

- `test-evidence-31233274800`, ID `9014554428`;
- SHA-256 `dd7ff3f26d4d2ca549cae19763cea136b0245d7ac26069ffbafb8009c66e895f`.

Both mandatory pipelines passed preflight/project-control, secret scan, static/repository policy, dependency/license and MariaDB/authenticated Redis tests.

## Explicit non-claims

This boundary does not claim dedicated multi-process wallet contention/stress, `WAL-001`, `WAL-003`, `WAL-004`, `WAL-005`, pricing/Quote/promotions/payment providers, Order/provisioning, or live PasarGuard/Marzban acceptance. Later accepted boundaries may satisfy some of those items independently; this historical boundary must not be retroactively broadened.

Phase `0.4.0` remains open. Always live-fetch Draft PR `#6` for the current head.
