# Phase 0.5 Stable Wallet Transfer Evidence

**Status:** implementation verified; evidence-head verification pending.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` foundation while Phase `0.4.0` provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Implementation head:** `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`.

## Accepted bounded scope

This increment implements `WAL-003` as a fail-closed, two-step wallet transfer on top of the accepted immutable ledger and wallet-hold foundations:

- transfers are disabled by default and require explicit configured wallet buckets, integer-IRR minimum/maximum/daily limits, fee policy, confirmation TTL and fee account;
- the caller identifies the recipient by stable `users.public_id`; preparation resolves and persists the invariant internal recipient `users.id` plus the public reference;
- sender and recipient must be different active customer accounts and both must own an active IRR liability wallet account in the same explicitly selected bucket;
- preparation snapshots amount, fee, total debit and policy values, applies the sender business-day limit, and reserves `amount + fee` through one wallet hold;
- preparation creates no transfer ledger transaction;
- confirmation revalidates current sender/recipient/account eligibility, transfer policy, hold integrity and expiry before any financial effect;
- a successful confirmation posts one balanced immutable transaction: sender debit `amount + fee`, recipient credit `amount`, and fee-account credit `fee` when non-zero;
- the transfer hold is captured and the transfer is completed in the same database transaction as the ledger post;
- exact prepare replay returns the accepted transfer without a second hold; changed payload conflicts;
- exact completed-confirmation replay verifies the recorded ledger transaction and entries before returning success; a changed confirmation conflicts and cannot create a second effect;
- explicit cancellation releases the reservation once and creates no transfer ledger effect; cancellation is terminal;
- expired confirmation cancels the transfer through its own lifecycle, releases the transfer hold, records a stable expiry reason and creates no transfer ledger effect;
- generic expired-hold maintenance deliberately excludes `source_type = wallet_transfer`, preventing a transfer hold from being released without a matching transfer-state transition;
- database constraints/triggers make transfer identity and policy snapshots immutable, restrict transitions to pending -> completed/cancelled, reject deletion and reject confirmation material on cancelled rows.

## Requirement mapping

- `WAL-003` — stable recipient, policy/limits/bucket/fee snapshot, explicit confirmation, atomic sender/recipient/fee posting, final state, replay/conflict and cancellation are executable.
- `DAT-002` — transfer, fee, debit and policy monetary values are integer IRR; no monetary float is accepted at this boundary.
- `DAT-003` — transfer/user/account/hold/ledger foreign keys, uniqueness, checks, transaction locks and terminal-state constraints are executable.
- `DAT-004` — accepted transfer identity/policy/final state is database-guarded against mutation/deletion; completed ledger history remains append-only.
- `QUA-001` — production implementation, deterministic tests, exact-head CI and retained artifact exist for this bounded increment; full Phase 0.5 closure remains open.

## Implementation surfaces

- `config/wallet.php`
- `database/migrations/2026_08_08_002600_create_wallet_transfers.php`
- `database/migrations/2026_08_08_002610_harden_wallet_transfer_state_constraint.php`
- `app/Modules/Wallet/Domain/WalletTransferStatus.php`
- `app/Modules/Wallet/Application/WalletTransferPolicySnapshot.php`
- `app/Modules/Wallet/Application/WalletTransferReceipt.php`
- `app/Modules/Wallet/Application/WalletTransferService.php`
- `app/Modules/Wallet/Application/ExpiredWalletHoldCleanupService.php`
- `tests/Feature/WalletTransferTest.php`
- `tests/Feature/WalletTransferStateConstraintTest.php`
- `tests/Feature/WalletTransferExpiryAndRevalidationTest.php`

## Executable verification

The transfer feature tests prove, among other cases:

1. default-disabled policy creates no transfer or hold;
2. configured bucket/minimum/maximum/daily limits fail closed;
3. deterministic integer fee is included in the sender reservation;
4. stable recipient identity is persisted and mutable identity drift fails confirmation;
5. current recipient account suspension after preparation fails confirmation with no ledger effect;
6. preparation creates one pending transfer and one hold but no transfer ledger transaction;
7. exact preparation replay creates no second hold and changed payload conflicts;
8. successful confirmation creates exactly one balanced sender/recipient/fee effect and captures the hold;
9. exact confirmation replay returns the accepted effect and a changed confirmation conflicts;
10. cancellation releases the hold once, is exact-replay protected and creates no transfer ledger transaction;
11. insufficient available balance leaves no partial transfer/hold effect;
12. expiry at confirmation cancels the transfer, releases the hold, restores available balance and creates no transfer ledger transaction;
13. database bypass attempts cannot mutate transfer identity/policy, mutate terminal state, delete a transfer or retain confirmation material on a cancelled row.

## Exact implementation CI

GitHub Actions run `31235232052` / run `#1084` on exact implementation head `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`:

- Repository preflight / planning / project-control verification — **success**;
- Secret scan — **success**;
- PHP static quality: Pint, PHPStan/Larastan and repository policy — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **346 tests, 1995 assertions, success**.

Retained test artifact:

- name: `test-evidence-31235232052`;
- artifact ID: `9015155851`;
- uploader SHA-256: `c85e5106f95e6c37745dbcf920d3728108d7f82f26624c6cfccc0684f5bb265b`;
- independently calculated SHA-256: `c85e5106f95e6c37745dbcf920d3728108d7f82f26624c6cfccc0684f5bb265b`.

Independent artifact inspection observed exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The bounded sensitive-marker scan found no protected PasarGuard secret-variable marker, Bearer authorization material or private-key marker.

## Safety properties

- immutable finalized ledger entries remain authoritative for balances;
- active holds reserve value before confirmation; no transfer debit is authorized from a persisted balance snapshot;
- prepare/confirm/cancel are explicit state transitions, not implicit side effects;
- current identity/account eligibility is revalidated at confirmation;
- a timeout/retry at the caller cannot turn exact replay into a second accepted transfer effect;
- cancellation/expiry never rewrites accepted ledger history;
- transfer holds are not released by generic maintenance outside transfer-state coordination;
- transfer policy remains disabled until explicit product/business configuration enables it.

## Explicit non-claims and remaining work

This boundary does **not** claim:

- dedicated multi-process contention/stress proof for simultaneous holds/transfers/reconciliation;
- automatic scheduled expiry cancellation for pending transfers; expiry is enforced when confirmation is attempted and explicit cancellation remains available;
- `WAL-001` external Payment Intent wallet top-up;
- `WAL-004` refund/reversal;
- `WAL-005` balance correction/approval;
- pricing/Quote, promotions or payment-provider completion;
- Order/provisioning behavior;
- PasarGuard or Marzban live acceptance;
- Phase `0.4.0` or Phase `0.5.0` closure.

The next safest bounded financial work is dedicated multi-process wallet contention verification to close the remaining explicit `WAL-002` verification gap before moving to `WAL-004` refund and `WAL-005` correction as separate increments.

## Evidence-head gate

This evidence and its traceability companion require mandatory CI on their exact combined evidence head before this increment is evidence-complete. Current/live working head must always be fetched from Draft PR `#6`, never inferred from this document.
