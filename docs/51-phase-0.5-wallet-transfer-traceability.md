# Phase 0.5 Stable Wallet Transfer Traceability

**Scope:** bounded `WAL-003` stable wallet transfer.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Implementation boundary:** `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`.  
**Implementation CI:** `31235232052` / `#1084` — success, 346 tests / 1995 assertions.  
**Evidence:** `evidence/0.5.0/wallet-transfer.md`.

## Requirement-to-proof map

| Requirement | Boundary status | Design / implementation | Automated proof | Remaining scope |
|---|---|---|---|---|
| `WAL-003` | verified implementation; evidence-head pending | `wallet_transfers`, `WalletTransferService`, transfer status/policy/receipt objects, accepted ledger + hold services | transfer policy, prepare/confirm/cancel, expiry, replay/conflict, identity/account revalidation, DB bypass tests | dedicated multi-process contention is tracked under remaining `WAL-002` verification; no scheduled transfer-expiry sweep claimed |
| `DAT-002` | satisfied for this boundary | amount/fee/total/policy snapshots are integer IRR | deterministic fee/amount assertions | broader crypto/rate representation remains separate |
| `DAT-003` | satisfied for this schema | user/account/hold/ledger FKs, unique transfer/hold/ledger links, state/arithmetic checks and locks | MariaDB feature tests | broader Phase 0.5 schema remains separate |
| `DAT-004` | satisfied for accepted transfer history | immutable identity/policy snapshots, guarded terminal transitions, non-deletion, immutable ledger effect | direct DB bypass tests | refund/correction histories remain separate |
| `QUA-001` | satisfied for implementation; evidence-head pending | production code, deterministic tests, exact implementation CI, retained artifact, independent digest | mandatory CI #1084 | full Phase 0.5 closure remains open |

## Stable recipient proof

Transfer authority is not a mutable handle. Preparation canonicalizes the supplied `users.public_id`, resolves it once to internal `users.id`, and persists both values. Confirmation locks and revalidates the persisted sender/recipient IDs and verifies that the recipient public ID has not changed. Both users must still be active customers at execution time.

The tests prove that recipient public-ID drift and recipient suspension after preparation fail closed before any transfer ledger effect.

## Policy, bucket and fee proof

Transfer configuration is disabled by default. When enabled for tests or a future explicit product configuration, the service requires:

- an allowlisted `cash` / `promotional` wallet bucket;
- positive integer minimum/maximum/daily limits;
- integer fixed fee and basis-point fee;
- bounded confirmation TTL;
- an active system fee account when the calculated fee is non-zero.

Preparation snapshots those values into the transfer record. Sender and recipient use the same selected wallet bucket, so promotional value cannot silently become cash.

## Two-step financial-effect proof

`prepare` performs validation, daily-limit evaluation and one hold for `amount + fee`. It creates no transfer ledger effect.

`confirm` revalidates current users, wallet accounts, policy, transfer hold and expiry. It then posts one balanced immutable ledger transaction and, in the same database transaction, captures the hold and finalizes the transfer:

- sender wallet: debit `amount + fee`;
- recipient wallet: credit `amount`;
- system fee account: credit `fee` when non-zero.

No completed transfer can be cancelled. A refund/reversal is separate `WAL-004` compensating-entry work.

## Replay and conflict proof

- transfer key + canonical request hash make preparation exact-replay protected;
- same key/same payload returns the original transfer and hold;
- same key/different payload conflicts;
- completed confirmation replay requires the same confirmation key and verifies the stored ledger transaction totals, source reference, entry count and exact entry set;
- a changed confirmation or tampered ledger evidence fails closed rather than posting another effect;
- database uniqueness makes one transfer hold and one completed ledger transaction attach to a transfer.

## Cancellation and expiry proof

Explicit cancellation releases an active transfer hold through the accepted hold-release path, records a terminal cancelled transfer and posts no transfer ledger transaction.

Confirmation after TTL expiry follows the same coordinated cancellation path with the deterministic reason `transfer confirmation expired`; the hold becomes released, available balance is restored and the transfer remains without confirmation or ledger material.

Generic `ExpiredWalletHoldCleanupService` excludes `source_type = wallet_transfer`. This is intentional: a generic hold sweep must never free transfer funds while leaving the transfer record pending. This boundary does not claim an automated scheduler that cancels untouched expired pending transfers; expiry is authoritatively enforced at confirmation and explicit cancellation remains available.

## Database final barrier

MariaDB independently enforces:

- sender/recipient and sender/recipient-wallet distinction;
- amount/fee/total arithmetic and policy ranges;
- valid bucket/status/hash/public-ID shapes;
- valid pending/completed/cancelled state material;
- immutable transfer identity and policy snapshot;
- no mutation after terminal state;
- no transfer deletion;
- cancelled rows cannot retain `confirmation_key`, `confirmed_at`, `ledger_transaction_id` or `completed_at`.

## Exact implementation verification

Implementation head `8360d1ac99485d1bea146bf21e22e8a336cd8e7a` passed mandatory CI run `31235232052` / `#1084`:

- Repository preflight / project-control — success;
- Secret scan — success;
- Pint / PHPStan-Larastan / repository policy — success;
- Dependency and license policy — success;
- MariaDB + authenticated Redis full suite — **346 tests / 1995 assertions**.

Test artifact:

- `test-evidence-31235232052`;
- ID `9015155851`;
- uploader and independently verified digest `sha256:c85e5106f95e6c37745dbcf920d3728108d7f82f26624c6cfccc0684f5bb265b`;
- exactly five expected test/coverage/service-log files;
- bounded sensitive-marker scan clean.

## Explicit non-claims

This boundary does not claim:

- dedicated multi-process wallet contention/stress;
- automatic scheduled cancellation of untouched expired pending transfers;
- `WAL-001` Payment Intent top-up;
- `WAL-004` refund/reversal;
- `WAL-005` balance correction/approval;
- pricing/Quote/promotions/payment providers;
- Order/provisioning behavior;
- real PasarGuard/Marzban acceptance or Target activation;
- Phase `0.4.0` or Phase `0.5.0` closure.

Phase `0.4.0` remains open and real provider Targets remain disabled. The next recommended bounded financial increment is dedicated multi-process wallet contention verification, followed by `WAL-004` and `WAL-005` as separate evidence lifecycles.

## Evidence-head requirement

This traceability document and its evidence companion are accepted only after mandatory CI succeeds on their exact combined evidence head. Always live-fetch Draft PR `#6` for the current working SHA.
