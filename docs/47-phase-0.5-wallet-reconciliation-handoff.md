# Phase 0.5 Wallet Snapshot, Reconciliation and Expired-Hold Cleanup Handoff

**Status:** active parallel Phase `0.5.0` bounded increment while Phase `0.4.0` provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Authoritative integration PR:** Draft PR `#6`.  
**Live head rule:** fetch PR `#6` before every write and use its exact `head_sha`.

## Start with

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/continuation-runbook.md`;
5. `evidence/0.5.0/financial-ledger-foundation.md`;
6. `docs/45-phase-0.5-financial-ledger-traceability.md`;
7. `evidence/0.5.0/wallet-holds-capture-release.md`;
8. `docs/46-phase-0.5-wallet-holds-traceability.md`;
9. this handoff.

## Accepted parallel Phase 0.5 boundaries

### Financial Ledger Foundation

Implementation:

- SHA: `259e29e6f93c2b36cd36c2f40bf669789eaab62f`;
- CI: `31232006308` / `#1037` — success;
- suite: 326 tests / 1777 assertions;
- artifact: `test-evidence-31232006308`, ID `9014138405`;
- independently verified digest: `sha256:cfda302c54c7089dbb15eb0c83a546f63bd37a142c99e5919b8408dfa85b7610`.

Evidence:

- SHA: `76c00a1c458c12ccc07dc658ee2f69e14389e65c`;
- CI: `31232151035` / `#1039` — success;
- suite: 326 tests / 1777 assertions;
- artifact: `test-evidence-31232151035`, ID `9014190245`;
- independently verified digest: `sha256:8354c7fc431a48390ea26211e30fafa69957e75690d91c1dc14e1df8de046510`;
- evidence: `evidence/0.5.0/financial-ledger-foundation.md`;
- traceability: `docs/45-phase-0.5-financial-ledger-traceability.md`.

### Wallet Holds, Available Balance, Capture and Release

Implementation:

- SHA: `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`;
- CI: `31232610814` / `#1046` — success;
- suite: 331 tests / 1834 assertions;
- artifact: `test-evidence-31232610814`, ID `9014323613`;
- independently verified digest: `sha256:9a5154dd6cb0baeb52880c8fbc86e06fcdccd98b9bd62cc41eed42ca745f06f1`.

Evidence:

- SHA: `781a2dd63d999b2e01d99cd6888f3c9cbfda9f26`;
- CI: `31232750290` / `#1048` — success;
- suite: 331 tests / 1834 assertions;
- artifact: `test-evidence-31232750290`, ID `9014371764`;
- independently verified digest: `sha256:9f925e3e22c50384dcce39034b861cadc5368a58e9e76ef03de8a8e45af90367`;
- evidence: `evidence/0.5.0/wallet-holds-capture-release.md`;
- traceability: `docs/46-phase-0.5-wallet-holds-traceability.md`.

## Current accepted wallet invariants

Preserve all of the following:

- immutable balanced ledger is authoritative; no mutable balance column may become source of truth;
- IRR values are integers at wallet/ledger boundaries;
- cash and promotional wallet accounts are separate user-owned liability buckets;
- active holds reduce available balance without rewriting ledger history;
- available balance may never be returned negative; inconsistent state is reconciliation-required;
- hold placement is unique-key/replay protected and cannot reserve more than current authoritative available balance;
- hold capture posts one balanced immutable ledger effect and transitions the hold once;
- hold release transitions once and creates no ledger rewrite;
- captured/released holds are terminal; holds are non-deletable;
- expired active holds remain reserved until explicit cleanup; expiry alone never frees funds;
- same logical command replay cannot create a second financial effect;
- conflicting replay cannot overwrite an accepted financial effect.

## Next bounded work

Complete the remaining reconciliation-oriented portion of `WAL-002` without pulling transfer/refund/correction/payment-provider work forward.

### 1. Non-authoritative persisted wallet snapshots

Add a safe persisted snapshot/read-model that records, at minimum:

- wallet ledger account identity;
- authoritative ledger-derived balance;
- active hold total;
- available balance;
- deterministic ledger/hold source coordinates sufficient to identify what state was observed;
- calculation timestamp;
- whether the snapshot was produced by an accepted reconciliation pass.

Snapshots are derived/cache evidence only. They must never authorize a debit/hold/capture without a fresh authoritative transaction/lock-based read.

### 2. Reconciliation service

Build an application service that, in a transaction-safe way:

- locks/validates the wallet account;
- recomputes ledger debit/credit totals from finalized immutable entries;
- recomputes active holds;
- refuses negative ledger or negative available state;
- compares the latest persisted snapshot/read model with authoritative values;
- records a new reconciliation snapshot/result rather than rewriting financial history;
- returns a safe structured matched/mismatch/reconciliation-required result;
- never repairs financial history by mutation.

### 3. Expired active-hold cleanup

Add bounded cleanup semantics that:

- selects only `active` holds whose expiry is authoritatively in the past;
- transitions them through the existing release path with a deterministic system reason;
- is idempotent and safe to rerun;
- has a bounded batch size;
- cannot release captured/released/non-expired holds;
- leaves any failed/ambiguous row for later reconciliation instead of skipping it silently.

A scheduled command may call this service only after the core service has deterministic tests. Scheduling must use existing one-scheduler conventions and `withoutOverlapping` / `onOneServer` where applicable.

### 4. Verification

Required tests should cover:

- snapshot values exactly match ledger + active holds;
- stale/tampered snapshot never changes authoritative balance and reconciliation identifies mismatch;
- repeated reconciliation records deterministic safe results without financial effect;
- negative/inconsistent derived state fails closed;
- expired active hold cleanup releases only eligible rows and is rerunnable;
- non-expired/captured/released holds are untouched;
- cleanup failure on one row is visible/reconcilable and does not manufacture success;
- database constraints preserve snapshot/result integrity;
- if practical in the bounded increment, add contention verification; otherwise keep dedicated multi-process stress explicitly open.

## Explicitly out of scope for this increment

- `WAL-001` external Payment Intent wallet top-up;
- `WAL-003` wallet transfer;
- `WAL-004` refund;
- `WAL-005` balance correction approval;
- pricing/Quote/promotion/payment-provider implementation;
- Order/provisioning behavior;
- PasarGuard/Marzban live-provider acceptance or Target activation.

## Carried Phase 0.4 gates

Phase `0.4.0` remains the active phase in project-control status. PasarGuard actual live execution still requires protected GitHub Actions Secret configuration and manual dispatch of the accepted guarded harness. Marzban live acceptance remains owner-deferred to final project/release acceptance. No financial increment may weaken those gates or enable real provider Targets.

## Completion policy

Treat this as a separate bounded increment. Completion requires:

1. production implementation and tests;
2. mandatory CI on the exact implementation SHA;
3. retained artifact and independent digest verification;
4. evidence + traceability with exact non-claims;
5. mandatory CI on the exact evidence-head SHA.
