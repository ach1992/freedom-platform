# Phase 0.5 Dedicated Wallet Contention Verification Traceability

**Status:** evidence-complete dedicated concurrency verification for the accepted `WAL-002` foundation.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Implementation verification boundary:** `903f326040c9acd0b31645fe8fae3a75f8a9fd27`.  
**Implementation CI:** `31240159777` / `#1128` — success, 352 tests / 2030 assertions.  
**Evidence head:** `e7a0ae17d470beb40f4933e66c7b599e0837e120`.  
**Evidence-head CI:** `31241459956` / `#1131` — success, 352 tests / 2030 assertions.  
**Evidence:** `evidence/0.5.0/wallet-contention-verification.md`.

## Requirement-to-proof map

| Requirement | Status | Accepted executable proof | Remaining scope |
|---|---|---|---|
| `WAL-002` | verified for the currently implemented ledger/hold/snapshot/reconciliation foundation | real-MariaDB multi-process same-wallet hold race, capture-vs-release terminal race, duplicate ledger command, reconciliation-vs-mutation; production row locks/unique keys remain enabled | future refund/correction/payment contention belongs to those requirements |
| `WAL-003` | parallel-verified with dedicated concurrency regression | duplicate concurrent prepare leaves one transfer/hold; duplicate concurrent confirm resolves primary + exact replay with one ledger effect | untouched-expired-transfer scheduling remains a separate operational gap |
| `DAT-002` | satisfied for this boundary | all test amounts and production wallet/ledger values are integer IRR | project-wide crypto/future payment decimal rules remain separate |
| `DAT-003` | satisfied for this boundary | MariaDB transactions, deterministic account lock ordering, unique command/hold/transfer keys and state constraints are exercised under independent processes | broader Phase 0.5 schemas remain separate |
| `DAT-004` | satisfied for this boundary | contention never rewrites finalized ledger history; terminal hold/transfer effects remain singular | refund/correction require compensating-history proof |
| `QUA-001` | satisfied for this increment | exact implementation/evidence-head mandatory CI, retained JUnit/Clover/service artifacts, independent digest checks and six-test contention suite | full Phase 0.5 closure remains open |

## Deterministic process coordination

`tests/Feature/WalletContentionVerificationTest.php` contains a CLI worker entry point used only by the test harness. Each worker boots the real Laravel application in its own PHP process, connects through the configured CI database, reports `READY`, and waits for parent input. The parent starts all workers, waits for every `READY`, then writes `GO` to release the barrier.

This proves independent connection/process contention rather than two calls on one PHP request transaction. There is no sleep-based ordering contract and no replacement of MariaDB locking with a test-only mutex.

## Scenario-to-invariant map

| Scenario | Expected invariant | Implementation surface |
|---|---|---|
| two `700,000 IRR` holds against `1,000,000 IRR` | one accepted reservation, one explicit rejection, available `300,000 IRR`, never negative | `WalletHoldService::place()` wallet-account lock + authoritative balance |
| capture racing release | exactly one terminal state/effect; capture winner posts one ledger transaction, release winner posts none | `WalletHoldService::capture()` / `release()` locked hold transition |
| duplicate ledger post | one finalized transaction; second caller receives exact replay | `LedgerPostingService::post()` command-key uniqueness + payload hash |
| duplicate transfer prepare/confirm | one transfer, one transfer hold, one transfer ledger transaction | `WalletTransferService` transfer key, hold key, ledger command key and stored effect verification |
| reconciliation racing hold mutation | snapshot is a complete valid before-or-after state where `available = ledger - holds` | `WalletReconciliationService` + wallet-account lock |
| losing competing hold | failure includes exception/message and creates no second primary effect | fail-closed available-balance check / transaction rollback |

## Locking and duplicate-effect reasoning

### Same-wallet reservations

`WalletHoldService::place()` locks the requested owned IRR wallet account before calculating the finalized-ledger balance and active-hold sum. Every competing hold on that wallet therefore observes serialized reservation state. The second operation cannot authorize itself from a stale persisted snapshot.

### Hold terminal race

Capture and release both begin from a locked hold row and allow transition only from `active`. The database transaction boundary ensures only one path can commit the terminal transition. Capture's ledger posting occurs within the same transaction scope; a losing release cannot subsequently erase or reinterpret that effect.

### Ledger command race

`LedgerPostingService` uses the unique command key and canonical payload hash as the idempotency identity. It locks participating accounts in sorted numeric order before inserting/finalizing entries. A unique race can return only an exact accepted payload; materially different reuse remains conflict.

### Transfer race

Preparation persists one transfer identity and one reservation. Confirmation revalidates stored participants/accounts/hold, posts the balanced transfer effect once, marks the hold captured and finalizes the transfer in one database transaction. Repeated confirmation must match the accepted confirmation identity and stored ledger effect.

### Reconciliation race

Reconciliation derives authority from the immutable finalized ledger plus active holds while the wallet account is locked. Therefore a concurrent hold can be seen wholly before or wholly after its commit, but the snapshot cannot combine pre-mutation holds with post-mutation arithmetic.

## Exact verification record

Implementation head `903f326040c9acd0b31645fe8fae3a75f8a9fd27`:

- CI `31240159777` / `#1128` — mandatory jobs all success;
- full MariaDB/authenticated Redis suite: `352 tests / 2030 assertions`;
- dedicated contention class: `6 tests / 35 assertions`, zero failures/errors/skips;
- artifact `test-evidence-31240159777`, ID `9016771279`;
- independently recalculated SHA-256 `4f32e7a5c7e4cc23b985b13aa2b6f291772b75c1f9a26cedcd620b9589b973b2`.

Evidence head `e7a0ae17d470beb40f4933e66c7b599e0837e120`:

- CI `31241459956` / `#1131` — mandatory jobs all success;
- full MariaDB/authenticated Redis suite: `352 tests / 2030 assertions`;
- dedicated contention class: `6 tests / 35 assertions`, zero failures/errors/skips;
- artifact `test-evidence-31241459956`, ID `9017163993`;
- independently recalculated SHA-256 `58544b56477c708b4e798b2ea83e673995e22b0ab53c19213914d1c2609af294`.

Both test artifacts contain exactly the expected JUnit, test log, Clover and two service-evidence files.

## Risk disposition

The accepted implementation/evidence lifecycle retires the previously identified **dedicated contention verification gap** for the currently implemented `WAL-002` ledger/hold/snapshot/reconciliation foundation. `FIN-01` can therefore be treated as controlled for this foundation rather than an open verification blocker.

This does not mean all future wallet/payment concurrency risk is closed. `WAL-004` refund, `WAL-005` correction, `WAL-001` top-up/Payment Intent and provider callbacks must each add their own race/idempotency proof. Persisted wallet snapshots remain non-authoritative.

## Explicit non-claims

This boundary does not claim `WAL-001`, `WAL-004`, `WAL-005`, pricing/Quote, promotions/referrals/agent pricing, payment providers, Orders/provisioning, live provider compatibility, Target activation, Phase `0.4.0` closure or Phase `0.5.0` closure.

PasarGuard controlled live execution remains the active Phase 0.4 human gate. Marzban live acceptance remains a final-release gate.

The next recommended bounded financial increment is `WAL-004` refund/reversal with compensating immutable entries, exact replay/conflict, refundable-cap enforcement, destination policy, audit/authorization and dedicated concurrency evidence.
