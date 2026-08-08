# Phase 0.5 Stable Wallet Transfer Handoff

**Status:** active parallel Phase `0.5.0` bounded increment while Phase `0.4.0` provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Authoritative integration PR:** Draft PR `#6`.  
**Live head rule:** fetch PR `#6` before every write and use its exact `head_sha`.

## Accepted wallet foundation

The transfer increment may rely on the following evidence-complete foundations:

- balanced append-only ledger posting (`evidence/0.5.0/financial-ledger-foundation.md`, `docs/45-phase-0.5-financial-ledger-traceability.md`);
- wallet holds / available balance / capture-release (`evidence/0.5.0/wallet-holds-capture-release.md`, `docs/46-phase-0.5-wallet-holds-traceability.md`);
- non-authoritative reconciliation snapshots / expired-hold cleanup (`evidence/0.5.0/wallet-reconciliation-snapshots.md`, `docs/48-phase-0.5-wallet-reconciliation-traceability.md`);
- scheduled bounded maintenance (`evidence/0.5.0/wallet-maintenance-operations.md`, `docs/49-phase-0.5-wallet-maintenance-traceability.md`).

The maintenance evidence head is `fa0cc2056459f171c32e0260422a465891766629`, CI `31233817131` / `#1068` — success, 338 tests / 1905 assertions, artifact `test-evidence-31233817131`, ID `9014728109`, independently verified SHA-256 `0a0a75865e81e1f764f913edd795ec203acc50fb181615f21560f7878b0e14ba`.

## Requirement target

Primary requirement: `WAL-003`.

The implementation must provide:

- stable recipient identity;
- explicit transfer policy and limits;
- explicit wallet bucket selection;
- deterministic transfer fee calculation and immutable fee snapshot;
- explicit confirmation before financial effect;
- atomic balanced ledger updates for sender, recipient and fee account;
- final transfer state;
- exact replay / conflict handling with one final effect.

Supporting requirements: `DAT-002`, `DAT-003`, `DAT-004`, `QUA-001`.

## Safety design

### Stable recipient

The caller supplies an opaque stable recipient `users.public_id`. Preparation resolves it once to immutable internal `users.id` and persists both the invariant user ID and the public recipient reference. Mutable Telegram usernames, phone display strings or other handles must never be transfer authority.

Execution revalidates both sender and persisted recipient users by ID and requires active customer accounts; it must not resolve a different recipient from a mutable handle during confirmation.

### Fail-closed policy

Transfer policy belongs in explicit configuration and defaults to disabled. The policy must define, at minimum:

- enabled/disabled state;
- allowed buckets (`cash` / `promotional`);
- minimum and maximum amount in integer IRR;
- per-sender business-day limit in integer IRR;
- fixed fee and optional basis-point fee in integer arithmetic;
- confirmation expiry/TTL;
- system fee account code when fee is non-zero.

No arbitrary production transfer limits or fees may be invented in application code. Tests may override config deterministically.

### Two-step confirmation

Prepare and confirm are separate commands:

1. `prepare` resolves recipient/account identity, evaluates policy, calculates immutable amount/fee/total snapshots, checks the current business-day limit, places a wallet hold for total debit and records `pending_confirmation`.
2. `confirm` reauthorizes current sender/recipient/account/policy eligibility, locks the transfer and financial rows, proves the hold is still active/unexpired, posts the balanced transfer ledger effect once, marks the hold captured and marks the transfer completed in the same database transaction.

No ledger transfer effect may occur during prepare.

### Accounting

For a transfer amount `A` and fee `F`:

- sender wallet debit = `A + F`;
- recipient wallet credit = `A`;
- configured system fee/revenue account credit = `F` when `F > 0`.

All values are integer IRR. Sender and recipient use the same explicitly selected wallet bucket so promotional value cannot silently become cash through transfer.

### Replay/conflict

- transfer key is unique and stores a canonical payload hash;
- same-key/same-payload prepare replays the original transfer without another hold;
- same-key/different-payload prepare conflicts;
- confirmation has its own stable confirmation key/fingerprint;
- completed confirmation replay verifies the recorded ledger transaction/entries before returning success;
- a materially different confirmation or ledger mismatch conflicts/manual-review rather than creating a second effect.

### Cancellation

A pending transfer may be explicitly cancelled through the accepted hold release path. Cancellation is terminal and idempotent for the same reason. Completed transfers are not cancelable; refunds/reversals are separate `WAL-004` work and must use compensating entries.

## Required production surfaces

Expected bounded implementation:

- `config/wallet.php` transfer policy (disabled by default);
- `wallet_transfers` migration with immutable identity/policy snapshot and guarded terminal states;
- transfer status/policy/result value objects;
- `WalletTransferService` prepare/confirm/cancel behavior;
- deterministic fee policy using integer-safe arithmetic;
- authoritative sender/recipient/account revalidation;
- tests for recipient stability, policy, limits, bucket/fee, confirmation, atomic ledger effect, replay/conflict and cancellation.

## Required tests

At minimum prove:

1. disabled policy denies preparation with no hold/effect;
2. mutable display handles are irrelevant; persisted recipient ID/public ID stays stable;
3. recipient must exist, differ from sender and remain active at confirmation;
4. only configured wallet buckets are allowed;
5. min/max and business-day limits fail closed;
6. fee is deterministic integer IRR and is included in the sender hold/total debit;
7. prepare creates one pending transfer and one hold but no transfer ledger effect;
8. exact prepare replay returns the existing result; changed payload conflicts with no second hold;
9. confirm is required; successful confirm creates one balanced sender/recipient/fee ledger transaction and one terminal hold/transfer state;
10. same confirmation replay returns the accepted transaction and creates no duplicate entries;
11. changed confirmation/offset/account state or tampered ledger evidence fails closed;
12. insufficient available balance has no transfer/hold effect;
13. cancellation releases the reservation once and creates no transfer ledger transaction;
14. concurrent/duplicate logical execution cannot produce a second accepted transfer effect;
15. database guards reject identity mutation, terminal-state mutation and deletion.

## Explicit non-claims

This increment must not claim:

- `WAL-001` external Payment Intent top-up;
- `WAL-004` refund/reversal;
- `WAL-005` balance correction;
- pricing/Quote/promotions/payment-provider completion;
- Order/provisioning behavior;
- PasarGuard or Marzban live acceptance;
- Phase `0.4.0` closure.

## Carried provider gates

Phase `0.4.0` remains open. PasarGuard actual live execution still requires protected GitHub Actions Secret configuration and manual dispatch of the accepted harness. Marzban live acceptance remains owner-deferred to final project/release acceptance. Real provider Targets remain disabled.

## Completion policy

Treat transfer as its own bounded increment. Completion requires production implementation/tests, mandatory exact-implementation-SHA CI, retained artifact + independent digest verification, evidence/traceability with exact non-claims, and mandatory exact evidence-head CI.
