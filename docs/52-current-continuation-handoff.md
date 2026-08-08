# Current Continuation Handoff

**Status:** authoritative continuation checkpoint after evidence-complete Wallet Refund / Reversal Foundation.  
**Date:** 2026-08-08.  
**Integration PR:** Draft PR `#6`, base `main`, head branch `develop/v1.0.0-completion`.  
**Live-head rule:** before every repository write, fetch PR `#6` and use its exact `head_sha`; never continue from a copied SHA.

## Read first in a new session

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/continuation-runbook.md`;
5. this file;
6. active Phase 0.4 gate: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` and `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
7. current overlays: `docs/32-current-traceability-overlay.md` and `docs/33-current-risk-overlay.md`;
8. Phase 0.5 evidence/traceability through `docs/54-phase-0.5-wallet-refund-traceability.md`.

## Repository invariants

- PR `#6` stays open and Draft; base `main`, head `develop/v1.0.0-completion`;
- do not merge, mark Ready, enable auto-merge, rewrite history, force-push, create temporary branches or push `main`;
- every GitHub Actions job uses the owner-controlled `freedom-staging-runner` selector only;
- every accepted bounded increment requires exact implementation CI/artifact plus exact evidence-head CI/artifact and independent digest inspection;
- never place credentials, tokens, API keys, passwords, subscription material or raw sensitive provider bodies in repository text, Issues, PR comments, evidence, ordinary logs or chat;
- no later phase may weaken accepted provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls;
- monetary IRR is integer; finalized balanced immutable ledger history plus active holds is wallet authority; persisted balance snapshots are derived evidence/cache only;
- refund/correction never edits prior ledger history; accepted changes are compensating transactions with immutable business records.

## Active Phase 0.4 human-controlled gate

Phase `0.4.0` / Issue `#7` remains the authoritative active phase and is **not closed**.

### PasarGuard `v5.2.1`

The guarded harness is evidence-complete. Actual deployment execution still requires protected repository Actions Secrets configured outside repository text and manual dispatch of `Provider Live Acceptance - PasarGuard` on `develop/v1.0.0-completion` with the workflow's exact confirmation value.

The available connector cannot create/update those protected Secrets or initiate a fresh `workflow_dispatch`. Do not request or copy secret values into chat.

After the guarded run, coordinator-level adoption/idempotency, controlled timeout/5xx/429 uncertainty and explicit Target activation remain separate live rows.

### Marzban `v0.8.4`

Owner decision on 2026-08-08 carries deployment-specific live acceptance to final project/release acceptance. It remains mandatory for `1.0.0`.

## Accepted parallel Phase 0.5 chain

Phase `0.5.0` / Issue `#8` is not closed. Seven bounded financial foundations are accepted:

1. **Financial Ledger Foundation** — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. **Wallet Holds / Available Balance / Capture / Release** — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. **Wallet Reconciliation Snapshots / Expired-Hold Cleanup** — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. **Wallet Maintenance Operations** — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. **Stable Wallet Transfer (`WAL-003`)** — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. **Dedicated Wallet Contention Verification** — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. **Wallet Refund / Reversal Foundation (`WAL-004`)** — `docs/54-phase-0.5-wallet-refund-traceability.md` and `evidence/0.5.0/wallet-refund-reversal-foundation.md`.

### Latest accepted boundary — `WAL-004`

Implementation verification:

- SHA `0237f94cae67ff2ca31047af55420fed0ffe578a`;
- CI `31242702422` / `#1155` — success;
- full suite `360 tests / 2099 assertions`;
- `WalletRefundFoundationTest`: `6 tests / 52 assertions`;
- `WalletRefundContentionVerificationTest`: `2 tests / 17 assertions`;
- artifact `test-evidence-31242702422`, ID `9017545543`;
- independent digest `sha256:be509996d098ee7f1354a9dc1fe949a224b2ead42c15ae145c2e12ad59890cdc`.

Evidence head:

- SHA `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`;
- CI `31242888656` / `#1156` — success;
- full suite `360 tests / 2099 assertions`;
- artifact `test-evidence-31242888656`, ID `9017593626`;
- independent digest `sha256:a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`.

Accepted `WAL-004` behavior includes immutable capture-time refundability, partial/cumulative caps, exact original-wallet-bucket reversal, manual-external evidence without wallet duplication, privileged destination override, exact replay/conflict, immutable compensating entries and independent-process MariaDB proof for over-refund and duplicate-key races.

The source refundable total may be lower than the captured total, allowing a future owning Payment Intent/Quote boundary to mark exact non-round card adjustment non-refundable without this foundation inventing card/provider computation now.

## Next bounded increment — `WAL-005` Balance Correction / Approval Foundation

Authoritative requirement: balance correction requires permission, bucket/amount/reason/preview/confirmation, optional policy-driven dual approval, and compensating entries. Master §14.6 additionally requires direction (`debit`/`credit`), explanatory note, related ticket/order/payment when applicable, and Owner/dual approval for large corrections. Prior ledger entries must never be edited/deleted.

### Initial safe boundary

Implement a provider-independent administrator correction application/domain foundation. Do not add Telegram/HTTP UI in the first increment; preserve preview/confirmation as explicit immutable application-stage data and use the existing authorization/approval primitives.

Required behavior:

1. immutable caller-supplied correction key plus canonical payload hash for exact replay/conflict;
2. target a stable owned wallet account/bucket and integer-IRR positive amount plus explicit `credit` or `debit` direction;
3. require `wallet.corrections.create` (or repository-consistent permission), reason code, explanatory note and optional typed related reference (`ticket`, `order`, `payment`); reject arbitrary sensitive/raw reference payloads;
4. generate a deterministic preview snapshot before financial execution containing target user/account/bucket, direction, amount, resulting ledger-derived/available balance and whether approval is required;
5. require explicit confirmation bound to the exact preview/payload before execution; changed amount/bucket/direction/reason after preview must conflict or require a new correction key/preview;
6. policy for large corrections must be immutable/configurable enough to decide whether Owner or dual approval is required; reuse `SensitiveActionApprovalService` rather than inventing a second approval system;
7. approval must be execution-time authoritative, distinct from requester where dual control is required, unexpired/unconsumed, and bound to the exact correction fingerprint;
8. credit posts a balanced compensating ledger transaction from a dedicated system correction account to the original wallet bucket; debit posts the inverse and must fail closed if it would make available balance negative;
9. never mutate/delete prior ledger entries or accepted correction identity/preview/approval/effect links;
10. exact replay returns the accepted correction; materially changed reuse conflicts and cannot create a second ledger effect;
11. deterministic multi-process tests must cover duplicate correction execution, concurrent debits against one wallet, and approval consumption/replay where applicable;
12. safe audit must record actor, direction, amount, bucket, related-reference type/ID if safe, approval requirement/result and compensating ledger transaction ID without secrets.

### Recommended implementation order

- inspect existing `SensitiveActionApprovalService`, administrator permission/override rules, ledger account conventions and available-balance service;
- define the smallest correction direction/state/preview value objects and migration with FKs/unique keys/checks/immutability triggers;
- seed correction permissions without granting critical approval bypass broadly;
- implement preview then confirm/execute service using transaction + wallet account lock + fresh authoritative ledger/hold calculation;
- route all financial effects through `LedgerPostingService`;
- add MariaDB feature tests for credit/debit, bucket identity, preview tamper/conflict, negative-availability denial, exact replay/conflict, related references, authorization and dual approval;
- add independent-process contention tests;
- run exact implementation CI/artifact/digest; then evidence/traceability and exact evidence-head CI/artifact/digest.

### Explicit non-claims for this boundary

Do not claim customer-facing correction UX, arbitrary provider/payment corrections, Payment Intent settlement, Order ownership, or Phase `0.5.0` closure. Corrections are administrator financial controls only and must not become a shortcut around refund/payment/order state machines.

## After `WAL-005`

Recommended order:

1. Payment Intent foundation + `WAL-001` top-up settlement;
2. deterministic offering pricing / immutable Quote snapshots;
3. promotions/referrals/agent pricing;
4. payment methods/providers and provider-native refund integrations.

Do not pull Order/provisioning ownership from Phase `0.6.0` forward.

## Current open items

- PasarGuard protected live run plus coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending wallet transfers;
- `WAL-001`, `WAL-005`, pricing/Quote/promotions/payment providers;
- provider-native refund/payment behavior beyond the accepted `WAL-004` foundation;
- all Phase `0.6.0+` owned behavior.

If the protected PasarGuard gate becomes available, follow `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` without exposing secrets. Otherwise continue with the bounded `WAL-005` sequence above.
