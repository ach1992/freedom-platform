# Phase 0.5 Stable Wallet Transfer Evidence

**Status:** evidence-complete bounded `WAL-003` foundation.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` work while Phase `0.4.0` provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Implementation head:** `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`.  
**Evidence head:** `68f06fbd4ae9bb1bdba004968e57c84a13871e15`.

## Accepted bounded scope

This increment implements `WAL-003` as a fail-closed two-step wallet transfer on the accepted immutable-ledger and hold foundations:

- transfer policy is disabled by default and must explicitly configure allowed bucket, integer-IRR min/max/daily limits, fee, TTL and fee account;
- recipient is supplied by stable `users.public_id`, resolved once to invariant internal `users.id`, and both are persisted;
- sender/recipient must differ, remain active customers at execution, and have active IRR liability wallet accounts in the same selected bucket;
- preparation snapshots amount, fee, total debit and policy values, applies the sender business-day limit and reserves `amount + fee` through one hold;
- preparation creates no transfer ledger effect;
- confirmation revalidates current users/accounts/policy/hold/expiry before any financial effect;
- successful confirmation posts one balanced immutable transaction: sender debit `amount + fee`, recipient credit `amount`, fee-account credit `fee` when non-zero;
- hold capture and transfer completion are committed in the same database transaction as the ledger post;
- exact prepare/confirm replay returns the accepted effect while materially changed replay conflicts;
- cancellation releases the reservation once, creates no transfer ledger effect and is terminal;
- expired confirmation follows coordinated transfer cancellation, releases the hold and creates no transfer ledger effect;
- generic expired-hold maintenance excludes `source_type = wallet_transfer`, so a transfer hold cannot be freed without a matching transfer-state transition;
- database constraints/triggers make identity/policy snapshots immutable, restrict terminal transitions, prevent deletion and reject confirmation material on cancelled rows.

## Requirement mapping

- `WAL-003`: stable recipient, limits/bucket/fee snapshot, explicit confirmation, atomic sender/recipient/fee posting, terminal state, replay/conflict and cancellation are verified.
- `DAT-002`: all transfer monetary values are integer IRR.
- `DAT-003`: FKs, uniqueness, checks, transaction locks and terminal-state constraints are executable.
- `DAT-004`: accepted transfer identity/policy/final state is DB-guarded; completed ledger history remains append-only.
- `QUA-001`: exact implementation/evidence CI, retained artifacts and independent digest checks exist for this bounded increment.

## Key implementation surfaces

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

Tests cover default-disabled policy, allowed bucket/min/max/daily limits, deterministic fee, stable recipient identity, execution-time recipient suspension, no ledger effect during prepare, exact replay/conflict, balanced confirmation effect, completed replay verification, cancellation, insufficient balance, expiry cancellation/restored available balance, and direct DB bypass rejection for identity/policy/terminal-state/deletion/cancelled-confirmation tampering.

### Implementation CI

Exact head `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`, run `31235232052` / `#1084`:

- all mandatory jobs — **success**;
- MariaDB/authenticated Redis suite — **346 tests / 1995 assertions**;
- artifact `test-evidence-31235232052`, ID `9015155851`;
- uploader and independent SHA-256 `c85e5106f95e6c37745dbcf920d3728108d7f82f26624c6cfccc0684f5bb265b`.

### Evidence-head CI

Exact evidence head `68f06fbd4ae9bb1bdba004968e57c84a13871e15`, run `31235552266` / `#1092`:

- Repository preflight / project-control — **success**;
- Secret scan — **success**;
- Pint / PHPStan-Larastan / repository policy — **success**;
- Dependency and license policy — **success**;
- MariaDB/authenticated Redis suite — **346 tests / 1995 assertions, success**;
- artifact `test-evidence-31235552266`, ID `9015258508`;
- uploader and independently calculated SHA-256 `f1bee9300338b0d326e9c0cf47ea978147a6e72688e598c0717f7ae180b29a78`.

Independent inspection of both test artifacts observed exactly five expected test/coverage/service-log files. The bounded sensitive-marker scan was clean.

## Safety properties

- immutable finalized ledger entries plus active holds remain authoritative;
- persisted balance snapshots never authorize a transfer effect;
- current identity/account eligibility is revalidated at confirmation;
- exact retry cannot create a second accepted transfer effect;
- cancellation/expiry never rewrite accepted ledger history;
- transfer holds are released only through coordinated transfer state or explicit transfer cancellation;
- transfer policy remains disabled until explicitly configured.

## Explicit non-claims and next work

This boundary does **not** claim:

- dedicated multi-process contention/stress proof for simultaneous holds/transfers/reconciliation;
- automatic scheduled cancellation of untouched expired pending transfers;
- `WAL-001` external Payment Intent wallet top-up;
- `WAL-004` refund/reversal;
- `WAL-005` balance correction/approval;
- pricing/Quote, promotions or payment-provider completion;
- Order/provisioning behavior;
- PasarGuard or Marzban live acceptance;
- Phase `0.4.0` or `0.5.0` closure.

The next safest bounded financial work is dedicated multi-process wallet contention verification to close the remaining explicit `WAL-002` verification gap, followed by `WAL-004` refund and `WAL-005` correction as separate evidence lifecycles.

Current/live working head must always be fetched from Draft PR `#6`; the SHAs above identify historical accepted boundaries only.
