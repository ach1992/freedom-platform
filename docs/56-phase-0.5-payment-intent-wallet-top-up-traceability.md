# Phase 0.5 Payment Intent / External Cash-Wallet Top-up Traceability

**Status:** parallel-verified bounded increment; Phase `0.5.0` remains open.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Requirements:** `PAY-002`, `PAY-003`, `WAL-001`.  
**Implementation head:** `6db9dde7032114ab81c95fdf371530997e65f21c`.  
**Implementation CI:** `31265449681` / `#1214` — success, 384 tests / 2292 assertions.  
**Evidence head:** `76ea847d4d1f626893b925bbfe5263e1dc1398e4`.  
**Evidence CI:** `31265901691` / `#1220` — success, 384 tests / 2292 assertions.  
**Evidence:** `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`.

## Requirement-to-proof map

| Requirement / invariant | Accepted proof | Remaining scope |
|---|---|---|
| `PAY-002` controlled Payment Intent | immutable creation key/payload, existing `PaymentIntentState`, append-only histories, exact create replay/conflict | payment-method eligibility/routing and external API/Telegram UX remain separate |
| browser return never proves payment | `PaymentEvidence::authorizesCapture()` requires `Success + Authoritative + Settled`; non-authoritative settled-looking evidence is rejected before persistence | gateway/webhook/browser transport adapters are provider-specific later work |
| immutable payment identity | user, cash-wallet ID, provider code, integer-IRR amount/currency and purpose are payload-hashed and DB-update guarded | later order/payment linkage must bind rather than overwrite this identity |
| `PAY-003` provider event idempotency | unique `(provider_code, provider_event_id)` plus exact immutable replay validation | provider-specific event parsers remain later |
| `PAY-003` provider transaction idempotency | unique `(provider_code, provider_transaction_id)`; transaction must link authoritative settled event; duplicate transaction/new event can only exact-replay accepted settlement | native provider refund/reversal identifiers remain later |
| cross-intent provider reuse prevention | accepted provider event is bound to one Payment Intent; concurrent cross-intent reuse yields only one settlement/top-up effect | provider-specific discovery/reconciliation remains later |
| `WAL-001` cash-only top-up | create/capture target must be one active owned IRR `cash` wallet; promotional bucket is rejected | customer wallet UX remains later |
| exactly one wallet top-up | one unique settlement per intent, one deterministic ledger command key, finalized ledger uniqueness and DB settlement guard | no Order/provisioning side effect is included |
| balanced ledger effect | debit `system.wallet.external_top_up.clearing`, credit immutable cash wallet, exact amount, two entries, finalized transaction | provider clearing reconciliation/reporting remains later |
| accepted replay stability | accepted settlement is checked/replayed before current wallet revalidation; disabled-later wallet still returns the same accepted IDs without new effect | new capture still requires current active cash wallet |
| `DAT-002` | all payment/top-up amounts are positive integer IRR in `Money`/`IrrMoney` and BIGINT | non-IRR/crypto precision remains outside this boundary |
| `DAT-003` | MariaDB transactions, row locks, uniqueness, FKs, checks, triggers and independent-process contention proof | later pricing/provider schemas need equivalent controls |
| `DAT-004` | intent histories, attempts, provider events/transactions, settlement and finalized ledger are append-only/guarded | future refunds compensate rather than rewrite accepted settlement |
| safe provider evidence | normalized key/value validation plus forbidden-sensitive-field filter, DB JSON-object check, <=32 fields and <=8192 bytes | provider adapters must supply only normalized safe evidence |
| `SEC-002` | no raw provider body/credential/token/cookie/signature/private material is accepted into safe evidence; both accepted artifacts scan clean for protected values | protected provider credentials remain Actions/environment-only |
| `QUA-001` | exact implementation-head and evidence-head full CI, retained JUnit/Clover/service artifacts, independent digests, dedicated real-MariaDB subprocess contention tests | later increments require their own lifecycle |

## Payment Intent identity and lifecycle

The top-up purpose is `wallet_top_up`. A caller supplies a creation key whose canonical payload binds user ID, wallet account ID, provider code, positive integer-IRR amount and currency. Operational correlation IDs are audit/state-transition context and do not redefine the financial identity.

Creation locks and validates the active owned IRR cash wallet, double-checks the creation key inside the same transaction, persists the immutable intent, records `created` history and advances through the existing domain state machine to `awaiting_user_action`. No second state machine is introduced.

Capture accepts only normalized authoritative settled provider evidence. Amount, currency and provider must match the immutable intent before an accepted first settlement can be created.

## Provider evidence and idempotency

Provider event identity is unique by provider/event ID. Replay validates intent ID, event payload hash, provider transaction ID, evidence payload hash, authority, transaction status, amount and currency.

A persisted provider transaction is unique by provider/transaction ID and is accepted only when its source provider event is authoritative, settled and exactly matches the immutable intent/provider/amount/currency. Database triggers independently enforce this relationship so direct insert cannot manufacture settlement authority.

An already captured settlement can exact-replay from immutable accepted records. It does not require the cash wallet to remain active later because replay is not a new financial authorization. A new first capture still locks and revalidates the wallet.

## Ledger and database integrity

One accepted settlement must point to one finalized `wallet_external_top_up` transaction whose source is the Payment Intent public ID, expected/debit/credit totals all equal the immutable top-up amount and entry count is exactly two.

The exact entries are one debit to `system.wallet.external_top_up.clearing` and one credit to the immutable cash wallet. The settlement trigger verifies intent/provider/wallet/ledger linkage. Settlement, provider transaction, provider event, attempt/history and captured financial identity cannot be updated/deleted to rewrite history.

Provider safe evidence is deliberately smaller than a raw provider payload: valid sorted scalar metadata only, no forbidden sensitive keys, max 32 fields and max 8192 persisted bytes.

## Concurrency proof

`WalletTopUpPaymentContentionVerificationTest` uses independent PHP processes against CI MariaDB with a deterministic `READY` / `GO` barrier. Workers set a bounded InnoDB lock wait and the harness has a bounded output watchdog; no sleep timing is used as the correctness mechanism.

Accepted scenarios:

1. two simultaneous exact duplicate authoritative captures resolve to one primary settlement, one finalized top-up ledger effect, one capture audit, and one exact replay returning the same settlement/ledger IDs;
2. one provider event/transaction presented concurrently to two distinct intents can authorize only one intent; the other conflicts/fails and total accepted wallet credit remains one provider amount.

The bounded watchdog ensures an unresolved lock/process condition surfaces as a deterministic test failure rather than silently hanging CI.

## Dedicated tests

- `WalletTopUpPaymentIntentTest` — **6 tests / 51 assertions**;
- `WalletTopUpPaymentDatabaseAuthorityTest` — **2 / 8**;
- `WalletTopUpPaymentEvidenceBoundsTest` — **2 / 11**;
- `WalletTopUpPaymentReplayStabilityTest` — **1 / 6**;
- `WalletTopUpPaymentContentionVerificationTest` — **2 / 19**.

Dedicated total: **13 tests / 95 assertions**, zero failures/errors/skips on implementation CI `#1214` and evidence CI `#1220`.

## Exact accepted verification lifecycle

Implementation head `6db9dde7032114ab81c95fdf371530997e65f21c`:

- CI `31265449681` / `#1214` — all mandatory jobs success;
- full MariaDB/authenticated Redis suite `384 tests / 2292 assertions`;
- `Pint` clean, PHPStan no errors, forbidden-pattern and architecture checks clean;
- artifact `test-evidence-31265449681`, ID `9024026199`;
- GitHub uploader and independent SHA-256 `3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`.

Evidence head `76ea847d4d1f626893b925bbfe5263e1dc1398e4`:

- CI `31265901691` / `#1220` — all mandatory jobs success;
- full MariaDB/authenticated Redis suite `384 tests / 2292 assertions`;
- artifact `test-evidence-31265901691`, ID `9024152227`;
- GitHub uploader and independent SHA-256 `dff70424074d138f59a8c9bfb03e5196f3827142fe964b043c09ef53e15392e7`.

Both independently inspected artifacts contain exactly JUnit, full test log, Clover coverage and two dependency-service evidence files. The evidence-head JUnit confirms 384 tests / 2292 assertions and the five dedicated suites remain 13 / 95 with zero failures/errors/skips. Independent safe scanning found no known CI credential values, bearer/basic authorization values, private-key headers or raw provider secret material.

## Accepted risk disposition

This bounded lifecycle directly controls these top-up risks:

- duplicate provider callback/event/transaction cannot create duplicate wallet credit;
- browser/user-return state cannot be mistaken for payment authority;
- direct DB insertion cannot bypass authoritative provider-evidence linkage;
- accepted financial records remain immutable and exact-replayable;
- concurrent duplicate/cross-intent provider reuse is serialized/unique at the database boundary;
- provider safe evidence is explicitly bounded and excludes secret/raw sensitive payload fields.

Provider-specific transport/availability/refund/order consequences remain future gates and are not implied by this acceptance.

## Explicit non-claims

No claim is made for:

- `PAY-001` payment-method eligibility or gateway rule engine;
- real gateway/provider API implementation, health or live compatibility;
- provider-native refunds/reversals;
- pricing/Quote (`BUY-002`), promotions/referrals or agent pricing;
- Orders/provisioning/services;
- payment customer/admin UX;
- Phase `0.4.0` closure, Phase `0.5.0` closure or final release acceptance.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment-specific acceptance remains mandatory at final release acceptance.

The next independent Phase `0.5.0` boundary is deterministic Pricing / immutable Quote snapshot (`BUY-002`).
