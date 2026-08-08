# Phase 0.5 Stable Wallet Transfer Traceability

**Status:** evidence-complete bounded `WAL-003` transfer increment.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Implementation:** `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`, CI `31235232052` / `#1084` — 346 tests / 1995 assertions.  
**Evidence head:** `68f06fbd4ae9bb1bdba004968e57c84a13871e15`, CI `31235552266` / `#1092` — 346 tests / 1995 assertions.  
**Evidence:** `evidence/0.5.0/wallet-transfer.md`.

## Requirement-to-proof map

| Requirement | Status | Accepted proof | Remaining scope |
|---|---|---|---|
| `WAL-003` | verified | stable recipient, explicit policy/limits/bucket/fee, prepare/confirm/cancel, expiry, exact replay/conflict, atomic balanced posting, DB terminal guards | no scheduled sweep for untouched expired pending transfers is claimed |
| `DAT-002` | satisfied for this boundary | amount/fee/total/policy snapshots are integer IRR | broader crypto/rate representation |
| `DAT-003` | satisfied for this schema | user/account/hold/ledger FKs, unique links, checks and locks | broader Phase 0.5 schema |
| `DAT-004` | satisfied for accepted transfer history | immutable identity/policy snapshots, guarded terminal transitions, non-deletion, immutable ledger effect | refund/correction histories |
| `QUA-001` | satisfied for this increment | exact implementation/evidence CI, retained artifacts and independent digest checks | full Phase 0.5 closure |

## Stable recipient and execution-time proof

Preparation canonicalizes `users.public_id`, resolves it once to internal `users.id`, and persists both. Confirmation locks/revalidates the persisted sender/recipient IDs, verifies the recipient public ID has not changed and requires both users to remain active customers. Tests prove public-ID drift and post-prepare recipient suspension fail before any transfer ledger effect.

## Policy, bucket and fee proof

Configuration is disabled by default. When explicitly enabled it requires an allowlisted cash/promotional bucket, integer minimum/maximum/daily limits, integer fixed/basis-point fee, bounded TTL and a valid active fee account for non-zero fee. Policy inputs are snapshotted into the transfer record and sender/recipient use the same selected bucket.

## Two-step financial-effect proof

`prepare` validates, evaluates daily policy and places exactly one hold for `amount + fee`; it creates no transfer ledger effect.

`confirm` revalidates current users, wallet accounts, policy, hold integrity and expiry, then atomically:

- debits sender `amount + fee`;
- credits recipient `amount`;
- credits system fee account `fee` when non-zero;
- captures the transfer hold;
- finalizes the transfer.

No completed transfer can be cancelled; refund/reversal is separate `WAL-004` compensating-entry work.

## Replay, cancellation and expiry proof

- same transfer key/same payload replays original preparation without another hold; changed payload conflicts;
- completed-confirmation replay requires the same confirmation key and verifies the stored ledger transaction totals/source/entry set before returning success;
- materially changed confirmation or tampered ledger evidence fails closed;
- explicit cancellation releases the active transfer hold, finalizes cancellation and posts no transfer ledger effect;
- confirmation after TTL expiry follows coordinated cancellation with reason `transfer confirmation expired`, releases the hold, restores available balance and posts no transfer ledger effect;
- generic expired-hold cleanup excludes `wallet_transfer` holds so maintenance cannot release transfer funds while leaving transfer state pending.

## Database final barrier

MariaDB independently enforces sender/recipient and wallet distinction, amount/fee/total arithmetic, policy ranges, valid bucket/status/hash/public-ID shapes, valid pending/completed/cancelled material, immutable identity/policy snapshot, terminal immutability and non-deletion. Cancelled rows cannot retain `confirmation_key`, `confirmed_at`, `ledger_transaction_id` or `completed_at`.

## Exact verification record

Implementation artifact:

- `test-evidence-31235232052`, ID `9015155851`;
- SHA-256 `c85e5106f95e6c37745dbcf920d3728108d7f82f26624c6cfccc0684f5bb265b`.

Evidence-head artifact:

- `test-evidence-31235552266`, ID `9015258508`;
- SHA-256 `f1bee9300338b0d326e9c0cf47ea978147a6e72688e598c0717f7ae180b29a78`.

Both mandatory pipelines passed preflight/project-control, secret scan, static/repository policy, dependency/license and MariaDB/authenticated Redis tests. Both artifacts contained exactly five expected test/coverage/service-log files and bounded sensitive-marker scans were clean.

## Explicit non-claims

This boundary does not claim dedicated multi-process wallet contention/stress, automatic scheduled cancellation of untouched expired pending transfers, `WAL-001`, `WAL-004`, `WAL-005`, pricing/Quote/promotions/payment providers, Order/provisioning, real PasarGuard/Marzban acceptance, Target activation, or Phase `0.4.0` / `0.5.0` closure.

The next recommended bounded financial increment is dedicated multi-process wallet contention verification, followed by `WAL-004` refund and `WAL-005` correction as separate evidence lifecycles.

Always live-fetch Draft PR `#6` for the current working SHA; the SHAs above are historical accepted boundaries.
