# Phase 0.5 Wallet Correction / Approval Foundation Traceability

**Status:** implementation-verified evidence candidate; exact evidence-head CI remains required before acceptance.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Requirement:** `WAL-005`.  
**Implementation head:** `fd3d579d9f38004310d7ea638e351813d2f46ef5`.  
**Implementation CI:** `31260299072` / `#1186` — success, 371 tests / 2197 assertions.  
**Evidence:** `evidence/0.5.0/wallet-correction-approval-foundation.md`.

## Requirement-to-proof map

| Requirement / invariant | Implementation proof | Remaining scope |
|---|---|---|
| `WAL-005` explicit correction fields | unique correction key, active owned wallet bucket, positive integer amount, credit/debit enum, required reason/note, optional `ticket`/`order`/`payment` reference | admin HTTP/Telegram UX remains later surface work |
| deterministic preview | immutable ledger/hold/available snapshot plus resulting balances and canonical payload hash | no cached balance becomes authority |
| explicit confirmation | SHA-256 token binds payload, authoritative balance snapshot, resulting balances and approval requirement | UI presentation remains later scope |
| correction does not rewrite history | one balanced `wallet_correction` compensating ledger transaction against `system.wallet.correction.offset`; prior ledger remains untouched | later payment/order flows must preserve the same invariant |
| debit cannot create negative availability | fresh finalized-ledger + active-hold calculation under target wallet row lock; debit amount capped by current available balance | each later debit-like financial path needs equivalent proof |
| execution-time authorization | immutable preview/requester lock followed by current `wallet.corrections.create` authorization inside the execution transaction | endpoint/middleware policy remains separate presentation scope |
| large-correction policy | Owner exemption; configurable integer-IRR threshold for non-owner administrators; policy recomputed at execution and drift fails closed | future policy UI/config management remains separate |
| dual approval | existing `SensitiveActionApprovalService`, `wallet.corrections.large`, exact action/preview target, distinct approver, expiry, atomic consumption | no alternative approval engine introduced |
| exact approval replay | committed approved correction can replay only with the exact committed approval ID; missing/conflicting approval input rejects | external API idempotency envelope remains future surface work |
| exact correction replay/conflict | unique correction key + canonical payload hash; exact replay returns one immutable preview/effect; changed key reuse conflicts | Payment Intent/provider idempotency remains separate |
| immutable accepted correction | database update/delete guards on preview/correction plus exact preview/approval/ledger insert guard | later migrations must preserve guards |
| safe audit | preview and execute audit events contain bounded wallet/amount/state/reference identifiers and no secret/provider material | centralized operational reporting is later phase scope |
| `DAT-002` | correction/balance/threshold amounts are integer IRR through `IrrMoney`/BIGINT | crypto/non-IRR precision remains payment-provider scope |
| `DAT-003` | MariaDB transactions, wallet/preview/administrator locks, unique keys, FKs, checks and triggers | later payment schemas need equivalent concurrency proof |
| `DAT-004` | finalized ledger plus immutable correction records remain append-only; replay cannot overwrite accepted effect | future settlement/refund/provider paths must preserve authority |
| `ACL-001` / `ACL-002` | current permission authorization plus existing independent sensitive approval, requester/approver binding and reason context | UI/API policy surfaces remain later |
| `SEC-002` | strict bounded identifiers/reason/note validation; audit avoids sensitive provider material | no provider credentials are introduced by this boundary |
| `QUA-001` | exact implementation-head full CI, retained JUnit/Clover/service artifact, independent digest, dedicated multi-process concurrency suites | exact evidence-head CI/artifact remains mandatory before acceptance |

## Correction identity and preview

Financial identity starts with a caller-supplied unique correction key plus a canonical payload containing target owner/account, direction, amount, actor, reason code/reason, note and optional related reference. Operational correlation/request fingerprints are not allowed to redefine the correction amount or target.

The preview is immutable evidence rather than financial authority after creation. Execution still performs a fresh transaction/lock-based read of the wallet and fails if ledger balance, active holds or available balance changed. A confirmation token additionally binds the original preview values, deterministic resulting balances and approval requirement.

## Approval model

`wallet.corrections.create` authorizes correction creation/execution. `wallet.corrections.large` is a critical permission used for policy-selected large non-owner corrections and is configured to require sensitive approval.

Independent approval reuses the existing access-control workflow rather than introducing correction-specific approval tables. The approval is bound to the exact preview and requester, cannot be self-approved when independence is required, expires under the existing bounded TTL contract, and is consumed during the same outer correction transaction.

Execution recomputes the current owner/threshold policy. If a preview that did not require approval would now require it, or vice versa, the old preview cannot execute; a new preview is required. This prevents a stale policy snapshot from silently authorizing a different financial control level.

## Ledger and database integrity

Accepted correction rows are valid only when the database trigger can prove all of the following:

- correction identity/requester/before/after values equal the immutable preview;
- approval presence equals the preview requirement;
- when approval is required, the referenced sensitive approval has the exact correction permission/action/target/requester, a distinct deciding administrator, approved state, execution fingerprint, requester consumption and pre-expiry consumption;
- the linked ledger transaction is finalized, balanced, `wallet_correction` typed, source-bound to the correction key and equal to the preview amount;
- one exact wallet-side entry uses the preview direction/amount/account;
- one exact offset entry uses `system.wallet.correction.offset` with the opposite direction and same amount.

Preview and accepted correction rows are update/delete guarded. Historical ledger entries remain under the existing finalized-ledger immutability contract.

## Concurrency and replay proof

`WalletCorrectionContentionVerificationTest` uses independent PHP processes against CI MariaDB with a deterministic `READY` / `GO` barrier.

Accepted implementation scenarios:

1. two distinct concurrent `700,000 IRR` debit corrections against one `1,000,000 IRR` wallet serialize on the target account; one succeeds and the stale competitor rejects, leaving `300,000 IRR` ledger/available balance;
2. two simultaneous exact duplicate executions create one correction, one compensating ledger effect and one audit event; the other worker returns the same accepted IDs as replay;
3. two simultaneous exact duplicate executions using one independently approved sensitive action consume that approval once and still resolve to one primary effect plus one replay.

No sleep-based correctness mechanism or test-only lock replaces production MariaDB locking/idempotency.

## Dedicated tests

`WalletCorrectionFoundationTest` — **6 tests / 57 assertions**:

- owner credit preview/execute/replay;
- debit compensation and negative-availability rejection;
- preview/confirmation/staleness guards;
- independent approval/self-approval/consumption;
- approval threshold boundary;
- related-reference and database immutability guards.

`WalletCorrectionContentionVerificationTest` — **3 tests / 28 assertions**:

- concurrent debit serialization;
- concurrent duplicate exact replay;
- concurrent approved duplicate/one consumption.

`WalletCorrectionPolicyReplayTest` — **2 tests / 13 assertions**:

- approval policy drift fail-closed;
- exact committed approval binding on replay.

Implementation candidate total: **11 dedicated tests / 98 assertions**, zero failures/errors/skips.

## Exact implementation verification

Implementation head `fd3d579d9f38004310d7ea638e351813d2f46ef5`:

- CI `31260299072` / `#1186` — mandatory jobs all success;
- full MariaDB/authenticated Redis suite `371 tests / 2197 assertions`;
- runner `freedom-staging-runner`, PHP `8.4.23`, PCOV `1.0.12` for coverage;
- artifact `test-evidence-31260299072`, ID `9022602206`;
- uploader and independent SHA-256 `476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`;
- artifact contains exactly JUnit, full test log, Clover coverage and two dependency-service evidence files.

## Risk disposition candidate

This implementation candidate directly addresses current overlay risks:

- `FIN-05` — correction uses immutable compensating history rather than destructive ledger mutation;
- `FIN-11` — correction requires explicit preview/confirmation, execution-time authorization, immutable record and safe audit;
- `FIN-12` — large non-owner correction uses distinct sensitive approval, exact approval replay binding and current available-balance/concurrency proof.

These risks should move to controlled-for-`WAL-005` only after exact evidence-head CI/artifact passes. Payment/provider/order consequences remain future gates.

## Explicit non-claims

No claim is made for:

- `WAL-001` top-up/Payment Intent settlement;
- provider-native payment/refund operations or live provider compatibility;
- pricing/Quote/promotions/referrals/agent pricing;
- Orders/provisioning/referral lifecycle;
- correction customer/admin UX;
- Phase `0.4.0` closure, Phase `0.5.0` closure or final release acceptance.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment-specific acceptance remains mandatory at final release acceptance.

After evidence-head acceptance, the next independent Phase `0.5.0` boundary is Payment Intent plus `WAL-001` external wallet top-up settlement.
