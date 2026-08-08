# Phase 0.5 Wallet Reconciliation Snapshots and Expired-Hold Cleanup Evidence

**Status:** evidence-complete bounded Phase `0.5.0` foundation.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Implementation head:** `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`.  
**Evidence head:** `57719972291b0553e76da6f8db50a8190a807044`.

## Accepted bounded scope

This increment extends the immutable ledger and wallet-hold foundations with a derived reconciliation read model and explicit expired-hold cleanup:

- `wallet_balance_snapshots` is append-only and records ledger-derived balance, active-hold total, available balance, source coordinates, deterministic fingerprint, comparison state and calculation time;
- snapshots are non-authoritative and never authorize a debit, hold, capture or transfer;
- reconciliation always revalidates/locks the wallet account and derives state from finalized immutable ledger entries plus active holds;
- initial/unchanged/changed authoritative states append `initial`, `matched` or `refreshed` snapshots instead of mutating prior history;
- MariaDB enforces non-negative arithmetic, `available = ledger - active holds`, valid comparison state, immutability and non-deletion;
- inconsistent negative/over-held authoritative state fails closed and writes no snapshot;
- cleanup is bounded to active expired non-transfer holds, transitions through `WalletHoldService::release()`, is rerunnable and surfaces unsafe rows through `reviewHoldIds`;
- transfer holds are excluded from generic cleanup so a transfer reservation cannot be released without a matching transfer-state transition.

## Verification

Implementation CI on `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`:

- run `31233135374` / `#1058` — all mandatory jobs success;
- full suite: **334 tests / 1886 assertions**;
- artifact `test-evidence-31233135374`, ID `9014519274`;
- uploader and independently verified SHA-256 `fcad956d65cbcac41c4c103bf09e7d5d00e43e40ed6fcb398e02bbaada78f80f`.

Evidence-head CI on `57719972291b0553e76da6f8db50a8190a807044`:

- run `31233274800` / `#1060` — all mandatory jobs success;
- full suite: **334 tests / 1886 assertions**;
- artifact `test-evidence-31233274800`, ID `9014554428`;
- uploader and independently verified SHA-256 `dd7ff3f26d4d2ca549cae19763cea136b0245d7ac26069ffbafb8009c66e895f`.

Both inspected artifacts contained exactly the five expected test/coverage/service-log files and the bounded sensitive-marker scan was clean.

## Requirement status

- `WAL-002`: reconciliation snapshots and bounded expired-hold cleanup are accepted; dedicated multi-process contention/stress remains the explicit open verification item.
- `DAT-002`, `DAT-003`, `DAT-004`: satisfied within this bounded wallet snapshot/reconciliation schema and history.
- `QUA-001`: satisfied for this increment through exact implementation/evidence CI and retained artifacts.

Operational scheduling was completed later by the separate Wallet Maintenance Operations boundary in `evidence/0.5.0/wallet-maintenance-operations.md` / `docs/49-phase-0.5-wallet-maintenance-traceability.md`.

## Safety and non-claims

- immutable finalized ledger entries plus active holds remain authoritative;
- persisted snapshots are derived evidence/cache only;
- reconciliation never repairs accepted financial history by mutation;
- expired holds are not silently treated as free funds;
- no dedicated multi-process wallet contention result is claimed here;
- no `WAL-001`, `WAL-003`, `WAL-004`, `WAL-005`, pricing, promotion, payment-provider, Order/provisioning or live-panel acceptance is claimed by this boundary.

Phase `0.4.0` remains open. Current working head must always be fetched from Draft PR `#6`; the historical SHAs above identify only this accepted evidence boundary.
