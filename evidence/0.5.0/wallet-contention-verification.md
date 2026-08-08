# Phase 0.5 Dedicated Wallet Contention Verification Evidence

**Status:** evidence-complete bounded concurrency verification for the accepted `WAL-002` foundations, with `WAL-003` duplicate-execution regression proof.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` work while Phase `0.4.0` provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Implementation verification head:** `903f326040c9acd0b31645fe8fae3a75f8a9fd27`.  
**Implementation CI:** `31240159777` / `#1128` — success.  
**Evidence head:** `e7a0ae17d470beb40f4933e66c7b599e0837e120`.  
**Evidence-head CI:** `31241459956` / `#1131` — success.  
**Traceability:** `docs/53-phase-0.5-wallet-contention-traceability.md`.

## Purpose and bounded scope

This verification-only increment closes the previously explicit multi-process MariaDB contention proof gap for the accepted `WAL-002` ledger/hold/reconciliation foundations and exercises the accepted `WAL-003` transfer path under duplicate execution.

No financial production behavior is broadened by this evidence boundary. The production services under test remain the already accepted `LedgerPostingService`, `WalletHoldService`, `WalletReconciliationService` and `WalletTransferService`.

The dedicated harness in `tests/Feature/WalletContentionVerificationTest.php` starts independent PHP processes against the real CI MariaDB connection, waits for every process to report `READY`, and releases them through an explicit `GO` barrier. It does not use timing sleeps as a correctness mechanism and does not weaken database isolation, row locking, uniqueness or terminal-state guards.

## Requirement mapping

- `WAL-002`: concurrent hold reservation, capture/release terminal race, duplicate ledger command and reconciliation-vs-mutation behavior are executable against MariaDB.
- `WAL-003`: duplicate concurrent transfer prepare/confirm is exercised and cannot create a second transfer, hold or ledger effect.
- `DAT-002`: every contention amount remains integer IRR.
- `DAT-003`: database transactions, uniqueness, foreign keys and row-lock ordering remain the final concurrency barrier.
- `DAT-004`: finalized ledger history and terminal financial records remain non-rewritten under contention.
- `QUA-001`: exact implementation and evidence-head mandatory CI, retained artifacts, executable counts and independent artifact inspection are complete for this bounded verification.

## Executable scenarios

The dedicated class contains six MariaDB-backed tests with 35 assertions:

1. **Competing same-wallet holds:** two `700,000 IRR` holds race against `1,000,000 IRR`; exactly one succeeds, one fails explicitly, total active reservation is `700,000 IRR`, and available balance is `300,000 IRR`.
2. **Capture versus release:** concurrent terminal actions on one hold produce exactly one successful terminal outcome; the final state is either `captured` or `released`, and ledger count changes only when capture wins.
3. **Duplicate ledger command:** two concurrent posts with the same command key both resolve through primary/replay semantics, but exactly one ledger transaction and its two entries exist.
4. **Duplicate transfer prepare and confirm:** concurrent preparation produces one transfer and one transfer hold; concurrent confirmation returns primary plus exact replay and leaves one completed transfer ledger effect and one captured transfer hold.
5. **Reconciliation versus mutation:** reconciliation racing with hold placement yields an internally consistent before-or-after snapshot where `available = ledger - active_holds`; the final authoritative balance reflects the accepted hold.
6. **Explicit contention failure:** the losing over-reservation operation returns a typed exception/message and is never counted as a primary effect.

These scenarios directly cover the previously documented contention exit criteria without claiming synthetic lock behavior or accepting silent retry-until-green semantics.

## Production concurrency controls exercised

### Wallet holds

`WalletHoldService` locks the selected wallet ledger account before calculating ledger-derived balance and active reservations. Placement rechecks the hold key under the serialized wallet account and rejects any reservation that would make available balance negative. Capture/release lock the hold and enforce a single terminal transition.

### Ledger posting

`LedgerPostingService` executes inside a database transaction, locks an existing command key when present, locks all participating accounts in numeric order, requires balanced integer-IRR entries, finalizes one immutable transaction and permits only exact unique-race replay.

### Reconciliation

`WalletReconciliationService` recalculates from finalized ledger entries plus active holds under the wallet-account lock and writes an append-only derived snapshot. A concurrent mutation can therefore produce a valid pre-mutation or post-mutation snapshot, never a mixed arithmetic state.

### Wallet transfer

`WalletTransferService` persists a unique transfer identity, reserves through one hold, revalidates execution-time state, and posts the sender/recipient/fee effect once. Concurrent duplicate confirmation is resolved by the transfer/ledger keys and stored effect verification rather than by a mutable balance cache.

## Exact implementation verification

Exact PR head `903f326040c9acd0b31645fe8fae3a75f8a9fd27`, CI run `31240159777` / `#1128`:

- Repository preflight / project-control — **success**;
- Secret scan — **success**;
- PHP static quality — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **352 tests / 2030 assertions, success**;
- dedicated `WalletContentionVerificationTest` — **6 tests / 35 assertions, 0 failures / 0 errors / 0 skipped**;
- runner — `freedom-staging-runner`, PHP `8.4.23`, PCOV `1.0.12` for coverage;
- artifact `test-evidence-31240159777`, ID `9016771279`;
- uploader digest and independently recalculated SHA-256: `4f32e7a5c7e4cc23b985b13aa2b6f291772b75c1f9a26cedcd620b9589b973b2`.

## Exact evidence-head verification

Exact combined evidence head `e7a0ae17d470beb40f4933e66c7b599e0837e120`, CI run `31241459956` / `#1131`:

- Repository preflight / project-control — **success**;
- Secret scan — **success**;
- PHP static quality — **success**;
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **352 tests / 2030 assertions, success**;
- dedicated contention class — **6 tests / 35 assertions, 0 failures / 0 errors / 0 skipped**;
- artifact `test-evidence-31241459956`, ID `9017163993`;
- uploader digest and independently recalculated SHA-256: `58544b56477c708b4e798b2ea83e673995e22b0ab53c19213914d1c2609af294`.

Independent inspection of each test artifact observed exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The inspected logs contain the expected test/service evidence. A bounded marker review found test names referring to secret-handling behavior but no credential value was copied into this repository evidence.

## Safety conclusions

For the accepted wallet foundations, the dedicated executable evidence demonstrates that:

- competing reservations cannot overdraw available wallet value;
- capture and release cannot both become accepted terminal effects;
- a duplicate ledger command cannot create two finalized primary transactions;
- duplicate transfer execution cannot create two transfer/hold/ledger primary effects;
- reconciliation remains arithmetic-consistent while a wallet mutation races with it;
- contention failures remain explicit and do not masquerade as successful financial effects.

This closes the previously documented dedicated multi-process contention verification gap for the current `WAL-002` foundation. It does not convert snapshots into authority and does not weaken the requirement for future concurrency tests on refunds, corrections, Payment Intents, callbacks or provider settlement.

## Explicit non-claims and next work

This evidence does **not** claim:

- `WAL-001` external wallet top-up or Payment Intent settlement;
- `WAL-004` refund/reversal;
- `WAL-005` administrator balance correction/approval;
- pricing/Quote, promotions/referrals/agent pricing or any payment provider;
- Order/provisioning behavior;
- automated scheduled cancellation of untouched expired pending transfers;
- PasarGuard or Marzban deployment acceptance;
- Phase `0.4.0` or `0.5.0` closure.

The next bounded financial increment is `WAL-004` refund/reversal using immutable compensating entries, exact replay/conflict, refundable-cap enforcement, method/destination policy and dedicated concurrency proof.
