# Phase 0.5 Payment Intent / External Cash-Wallet Top-up Evidence

**Status:** implementation-verified evidence candidate; exact evidence-head CI remains required before acceptance.  
**Phase:** parallel `0.5.0 — Ledger, Pricing, Promotions and Payment Providers` work while Phase `0.4.0` protected provider live gates remain open.  
**Authoritative Phase 0.5 Issue:** `#8`.  
**Requirements:** `PAY-002`, `PAY-003`, `WAL-001` with supporting `DAT-002`, `DAT-003`, `DAT-004`, `SEC-002`, `QUA-001`.  
**Implementation head:** `6db9dde7032114ab81c95fdf371530997e65f21c`.  
**Implementation CI:** `31265449681` / `#1214` — success.  
**Traceability:** `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

## Bounded scope

This increment implements the provider-independent Payment Intent settlement boundary for external cash-wallet top-up. It deliberately does not implement gateway-specific transport, payment-method eligibility/routing, pricing/Quote, Order ownership, provisioning, service activation, Telegram UX, provider-native refund, or live provider acceptance.

A top-up intent is bound immutably to one user, one active owned IRR `cash` wallet, one provider code, one positive integer-IRR amount/currency and one caller-supplied creation key. Exact creation-key/payload reuse replays; material key reuse conflicts.

A browser return or user redirect is never accepted as payment proof. Wallet credit requires normalized `PaymentEvidence` with `Success`, `Authoritative`, `Settled`, a settlement timestamp, exact immutable provider/amount/currency identity and bounded safe evidence.

## Production behavior

`WalletTopUpPaymentService::create()` validates immutable identity, locks and revalidates the target cash wallet, double-checks the creation key under the transaction, persists the intent plus append-only state history and advances it to `awaiting_user_action`. Query-race recovery can return only an exact stored replay.

`capture()` locks the Payment Intent, verifies provider identity and exact amount/currency, records the provider event under `(provider_code, provider_event_id)` uniqueness, and replays an already accepted settlement before re-authorizing current wallet availability. This preserves exact replay of an accepted historical financial effect even if the wallet is later disabled.

First capture locks/revalidates the owned cash wallet, persists the authoritative provider transaction under `(provider_code, provider_transaction_id)` uniqueness, locks `system.wallet.external_top_up.clearing`, posts one finalized balanced `wallet_external_top_up` ledger transaction, records one immutable capture attempt, advances the intent through the existing `PaymentIntentState` transitions to `captured`, persists one unique `wallet_top_up_settlement`, and writes a bounded safe audit event.

The ledger effect is exactly:

- debit `system.wallet.external_top_up.clearing` by the captured integer-IRR amount;
- credit the immutable user cash wallet by the same amount.

No promotional bucket can be targeted. No accepted capture can post a second top-up ledger transaction.

## Database authority and evidence controls

Database checks/triggers independently enforce:

- top-up purpose, positive integer-IRR amount and `IRR` currency;
- immutable Payment Intent financial identity and legal state transitions;
- non-deletable intents and append-only state history/attempt/provider event/provider transaction records;
- provider transactions must derive from an authoritative settled provider event for the same intent/provider/transaction/amount/currency;
- a wallet-top-up settlement must link the exact captured intent, authoritative provider transaction, active owned cash wallet and one finalized balanced two-entry `wallet_external_top_up` ledger transaction;
- accepted settlements are immutable/non-deletable;
- provider safe evidence must be a JSON object with at most 32 fields and at most 8192 bytes.

Application validation additionally rejects sensitive safe-evidence keys such as credentials, authorization/cookie/signature/private material and raw payload/body fields, rejects control-character or oversized values, and stores only normalized sorted safe evidence.

## Dedicated verification

`tests/Feature/WalletTopUpPaymentIntentTest.php` — **6 tests / 51 assertions**:

1. cash-only intent creation, exact replay and creation-key conflict;
2. non-authoritative browser-like evidence cannot capture or credit;
3. authoritative settled evidence credits exactly once and replays;
4. duplicate provider transaction/new event replay plus event/cross-intent conflict safety;
5. amount mismatch and sensitive safe evidence fail closed;
6. Payment Intent and settlement financial identity are database immutable.

`tests/Feature/WalletTopUpPaymentDatabaseAuthorityTest.php` — **2 tests / 8 assertions** verifying direct database inserts cannot manufacture provider authority or a settlement without the exact accepted financial chain.

`tests/Feature/WalletTopUpPaymentEvidenceBoundsTest.php` — **2 tests / 11 assertions** verifying provider safe evidence rejects more than 32 fields or more than 8192 bytes without persisting provider/settlement/ledger effect.

`tests/Feature/WalletTopUpPaymentReplayStabilityTest.php` — **1 test / 6 assertions** verifying an already accepted settlement can exact-replay after the cash wallet is later disabled, without creating a second ledger effect.

`tests/Feature/WalletTopUpPaymentContentionVerificationTest.php` — **2 tests / 19 assertions**, using independent PHP processes against real CI MariaDB with a deterministic `READY` / `GO` barrier, bounded worker watchdog and bounded InnoDB lock wait:

1. concurrent exact duplicate authoritative capture resolves to one primary settlement/ledger/audit effect plus one exact replay;
2. the same provider event/transaction cannot capture two different intents concurrently; exactly one settlement/top-up ledger effect is accepted.

Dedicated `PAY-002` / `PAY-003` / `WAL-001` verification totals **13 tests / 95 assertions**, zero failures/errors/skips on the exact implementation head.

## Exact implementation-head verification

Exact implementation head `6db9dde7032114ab81c95fdf371530997e65f21c`, run `31265449681` / `#1214`:

- Repository preflight / project control — **success**;
- Secret scan — **success**;
- PHP static quality — **success** (`Pint` clean, PHPStan no errors, forbidden-pattern and architecture checks clean);
- Dependency and license policy — **success**;
- MariaDB and authenticated Redis suite — **384 tests / 2292 assertions, success**;
- runner — `freedom-staging-runner`;
- PHP `8.4.23`, PCOV `1.0.12` for coverage;
- artifact `test-evidence-31265449681`, ID `9024026199`;
- GitHub uploader digest: `sha256:3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`;
- independently downloaded/recalculated SHA-256: `3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`.

Independent artifact inspection found exactly five expected files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The independent JUnit read confirms the five dedicated suites as `2 / 19`, `2 / 8`, `2 / 11`, `6 / 51`, and `1 / 6`, with zero failures/errors/skips. The full suite is `384 / 2292`.

A safe artifact scan found no embedded known CI credential value, bearer/basic authorization value, or private-key header. Keyword hits such as `password`, `authorization`, `token`, `secret`, and `api_key` were confined to JUnit/Clover test or source metadata; no raw provider secret material was retained.

Earlier runs `#1210`, `#1211`, and `#1213` are diagnostic development runs only and are not acceptance evidence. The bounded worker watchdog converted a prior unbounded subprocess hang into deterministic failures, which exposed and allowed correction of test cleanup, the one-cash-wallet-per-user invariant, and expected fail-closed conflict ordering before the exact green implementation run.

## Safety conclusions candidate

The exact implementation proof supports these bounded conclusions, pending exact evidence-head CI:

- browser/user-return data cannot authorize a cash-wallet credit;
- only normalized authoritative settled evidence matching immutable intent identity can capture;
- provider event and transaction uniqueness prevents reuse from creating a second accepted financial effect;
- exact accepted replay remains stable without depending on current wallet activation state;
- first capture requires current active-owned-cash-wallet authority under transaction locks;
- one captured top-up creates exactly one balanced clearing-to-cash ledger transaction;
- direct database writes cannot manufacture the accepted settlement chain;
- persisted provider evidence is append-only, sensitive-field filtered and bounded by field count and byte size;
- independent-process MariaDB contention proof demonstrates duplicate and cross-intent provider-event races do not duplicate top-up effects.

## Explicit non-claims

This candidate does **not** claim:

- `PAY-001` Payment Method eligibility/rule-engine implementation;
- any real gateway/provider implementation, health check or live provider acceptance;
- browser-return/webhook transport adapters beyond the normalized evidence boundary;
- provider-native refund/reversal operations;
- deterministic pricing/Quote snapshots, promotions/referrals or agent pricing;
- Order/provisioning/service lifecycle ownership;
- customer/admin Telegram or HTTP payment UX;
- Phase `0.4.0` closure, Phase `0.5.0` closure or release acceptance.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment acceptance remains a final-release gate.

After exact evidence-head acceptance, the next independent Phase `0.5.0` increment is deterministic Pricing / Quote snapshot (`BUY-002`) before promotions/referrals/agent pricing and provider-specific payment/refund work.
