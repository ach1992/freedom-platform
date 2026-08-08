# Phase 0.5 Stable Wallet Transfer Handoff

**Status:** implementation completed; superseded as continuation entry point once `WAL-003` evidence-head CI is accepted.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Authoritative integration PR:** Draft PR `#6`.

This document preserves the design/work package that produced the stable wallet-transfer boundary. New continuation should use `PROJECT_STATUS.md` and the newest handoff after evidence acceptance.

## Completed implementation boundary

- implementation head: `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`;
- CI: `31235232052` / `#1084` — all mandatory jobs success;
- suite: **346 tests / 1995 assertions**;
- artifact: `test-evidence-31235232052`, ID `9015155851`;
- uploader and independently verified SHA-256: `c85e5106f95e6c37745dbcf920d3728108d7f82f26624c6cfccc0684f5bb265b`;
- evidence: `evidence/0.5.0/wallet-transfer.md`;
- traceability: `docs/51-phase-0.5-wallet-transfer-traceability.md`.

## Delivered safety behavior

The implementation provides the intended `WAL-003` boundary:

- default-disabled explicit transfer policy;
- stable recipient public-ID -> invariant internal user-ID resolution;
- execution-time active-customer and wallet-account revalidation;
- integer-IRR min/max/daily/bucket/fee policy snapshots;
- two-step prepare/confirm with no transfer ledger effect during prepare;
- reservation of amount + fee through the accepted wallet-hold path;
- one atomic balanced sender/recipient/fee ledger effect at confirmation;
- exact prepare and confirmation replay plus conflict rejection;
- terminal cancellation through coordinated hold release;
- confirmation-expiry cancellation with no transfer ledger effect;
- generic cleanup exclusion for transfer holds so hold/transfer state cannot diverge;
- DB-enforced immutable policy/identity snapshots, guarded terminal state and non-deletion;
- cancelled-state DB hardening that rejects retained confirmation material.

## Remaining completion gate for this historical package

The implementation is green, but the evidence/traceability files must pass mandatory CI together on their exact evidence head before `WAL-003` is called evidence-complete. The current working SHA must always come from a live fetch of PR `#6`.

## Explicit carry-forward

- dedicated multi-process wallet contention/stress remains the explicit open `WAL-002` verification item;
- `WAL-001` external Payment Intent wallet top-up is not implemented here;
- `WAL-004` refund/reversal is not implemented here;
- `WAL-005` correction/approval is not implemented here;
- pricing/Quote/promotions/payment providers remain future Phase 0.5 work;
- Phase `0.4.0` remains open on protected PasarGuard live execution and carried Marzban final-release acceptance;
- real provider Targets remain disabled.
