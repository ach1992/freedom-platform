# Phase 0.5 Wallet Correction / Approval Foundation Traceability

**Status:** accepted bounded Phase `0.5.0` foundation after exact implementation-head and evidence-head verification.  
**Authoritative Phase 0.5 tracker:** Issue `#8`.  
**Requirement:** `WAL-005`.  
**Implementation head:** `fd3d579d9f38004310d7ea638e351813d2f46ef5`.  
**Implementation CI:** `31260299072` / `#1186` — success, 371 tests / 2197 assertions.  
**Evidence head:** `ef5504081a42687eb712e9cd47306cd9dcc9a864`.  
**Evidence CI:** `31260549403` / `#1188` — success, 371 tests / 2197 assertions.  
**Evidence artifact:** `test-evidence-31260549403`, ID `9022665227`, independently verified SHA-256 `ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`.  
**Evidence:** `evidence/0.5.0/wallet-correction-approval-foundation.md`.

## Requirement-to-proof map

| Requirement / invariant | Accepted proof | Remaining scope |
|---|---|---|
| `WAL-005` explicit correction fields | unique correction key, active owned wallet bucket, positive integer amount, credit/debit enum, required reason/note, optional `ticket`/`order`/`payment` reference | admin HTTP/Telegram UX remains later surface work |
| deterministic preview | immutable ledger/hold/available snapshot plus resulting balances and canonical payload hash | cached balance never becomes authority |
| explicit confirmation | SHA-256 token binds payload, authoritative balance snapshot, resulting balances and approval requirement | UI presentation remains later scope |
| correction does not rewrite history | one balanced `wallet_correction` compensating transaction against `system.wallet.correction.offset`; prior ledger remains untouched | later payment/order flows must preserve this invariant |
| debit cannot create negative availability | fresh finalized-ledger + active-hold calculation under target-wallet row lock; debit capped by available balance | later debit-like financial paths require equivalent proof |
| execution-time authorization | immutable preview/requester lock followed by current `wallet.corrections.create` authorization inside execution transaction | endpoint policy remains presentation scope |
| large-correction policy | Owner exemption; configurable integer-IRR threshold for non-owner administrators; policy recomputed at execution; drift fails closed | policy-management UI remains later scope |
| dual approval | existing `SensitiveActionApprovalService`, `wallet.corrections.large`, exact action/preview target, distinct approver, expiry, atomic consumption | no alternative correction approval engine introduced |
| exact approval replay | approved correction replays only with exact committed approval ID; missing/conflicting approval rejects | external API envelope remains later surface work |
| exact correction replay/conflict | unique correction key + canonical payload hash; exact replay returns prior result; changed reuse conflicts | Payment Intent/provider idempotency remains separate |
| immutable accepted correction | DB update/delete guards plus exact preview/approval/ledger insert guard | later migrations must preserve guards |
| safe audit | preview/execute audit contains bounded wallet/amount/state/reference identifiers and no provider secrets | centralized reporting is later scope |
| `DAT-002` | amounts/thresholds are integer IRR through `IrrMoney`/BIGINT | crypto precision remains provider scope |
| `DAT-003` | MariaDB transactions, locks, unique keys, FKs, checks and triggers | payment schemas need equivalent concurrency proof |
| `DAT-004` | finalized ledger and correction records remain append-only; replay cannot overwrite accepted effect | future settlement/provider paths must preserve authority |
| `ACL-001` / `ACL-002` | current permission authorization plus existing independent sensitive approval, requester/approver binding and reason context | UI/API surfaces remain later |
| `SEC-002` | bounded identifiers/reason/note validation; audit avoids sensitive provider material | no provider credentials introduced by this boundary |
| `QUA-001` | exact implementation/evidence CI, retained JUnit/Clover/service artifacts, independent digests, real-MariaDB concurrency suites | satisfied for this bounded `WAL-005` increment |

## Identity, preview and execution authority

Financial identity starts with a unique correction key plus a canonical payload containing target owner/account, direction, amount, actor, reason, note and optional related reference. Operational correlation/request fingerprints cannot redefine the correction amount or target.

The preview is immutable evidence, not perpetual authority. Execution re-reads and locks the wallet, fails if ledger balance/holds/available balance changed, re-authorizes the requester, and recomputes the current dual-approval policy. Any policy drift requires a new preview.

## Approval model

`wallet.corrections.create` authorizes correction creation/execution. `wallet.corrections.large` is critical and approval-required for policy-selected non-owner corrections. Independent approval reuses the existing access-control workflow and is bound to the exact preview/requester, cannot self-approve, expires, and is consumed inside the correction transaction.

Committed approved replay is accepted only with the exact original approval ID.

## Ledger and database integrity

A correction row is accepted only when the database can prove:

- identity/requester/before/after values equal the immutable preview;
- approval presence equals the preview requirement;
- required approval has exact permission/action/target/requester, distinct approver, approved state and requester consumption before expiry;
- linked ledger transaction is finalized, balanced, `wallet_correction` typed and equal to the preview amount;
- exact wallet-side entry uses preview direction/amount/account;
- exact offset entry uses `system.wallet.correction.offset` with opposite direction and same amount.

Preview and correction rows are update/delete guarded; finalized ledger history remains immutable.

## Concurrency and replay proof

`WalletCorrectionContentionVerificationTest` uses independent PHP processes against CI MariaDB with a deterministic `READY` / `GO` barrier:

1. two concurrent `700,000 IRR` debits against one `1,000,000 IRR` wallet serialize; one succeeds, the stale competitor rejects, leaving `300,000 IRR` available;
2. two simultaneous exact duplicate executions create one correction, one ledger effect and one audit event; the other returns replay;
3. two simultaneous duplicate executions using one approved sensitive action consume it once and resolve to one primary effect plus one replay.

No sleep-based correctness mechanism or test-only lock replaces production MariaDB locking/idempotency.

## Dedicated tests

- `WalletCorrectionFoundationTest` — **6 tests / 57 assertions**.
- `WalletCorrectionContentionVerificationTest` — **3 tests / 28 assertions**.
- `WalletCorrectionPolicyReplayTest` — **2 tests / 13 assertions**.
- Accepted total — **11 tests / 98 assertions**, zero failures/errors/skips.

## Exact verification

Implementation head `fd3d579d9f38004310d7ea638e351813d2f46ef5`:

- CI `31260299072` / `#1186` — all mandatory jobs success;
- full suite `371 / 2197`;
- artifact `test-evidence-31260299072`, ID `9022602206`;
- independently verified SHA-256 `476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`.

Evidence head `ef5504081a42687eb712e9cd47306cd9dcc9a864`:

- CI `31260549403` / `#1188` — all mandatory jobs success;
- full suite `371 / 2197`;
- artifact `test-evidence-31260549403`, ID `9022665227`;
- uploader and independent SHA-256 `ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`;
- artifact contains exactly JUnit, full test log, Clover coverage and two dependency-service evidence files.

## Risk disposition

Controlled for this accepted `WAL-005` boundary:

- `FIN-05` correction portion — immutable compensating history replaces destructive ledger mutation;
- `FIN-11` correction portion — preview/confirmation, execution-time authorization, immutable action and safe audit are verified;
- `FIN-12` correction portion — large non-owner correction uses distinct approval, exact approval replay binding and current balance/concurrency proof.

Payment/provider/order consequences remain future gates and are not closed by this correction acceptance.

## Explicit non-claims and next boundary

No claim is made for `WAL-001` top-up/Payment Intent settlement, provider-native payment/refund operations, pricing/Quote/promotions/referrals/agent pricing, Orders/provisioning/service lifecycle, correction UX, Phase `0.4.0` closure, Phase `0.5.0` closure or release acceptance.

PasarGuard protected live execution remains the active Phase `0.4.0` human gate. Marzban deployment-specific acceptance remains a final-release gate.

The next independent Phase `0.5.0` boundary is Payment Intent plus `WAL-001` external wallet top-up settlement.
