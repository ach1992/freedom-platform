# Project Status

This is the single human-readable current-state entry point. Detailed history belongs in evidence, traceability, risk, audit, and bounded handoff documents.

**Last status review:** 2026-08-08  
**Target release:** `1.0.0`  
**Active phase:** `0.4.0 — Catalog, Panels and Offerings`  
**Authoritative phase Issue:** `#7`  
**Authoritative integration PR:** `#6`  
**Allowed branch:** `develop/v1.0.0-completion`  
**PR base/state:** `main` / Draft

Current control documents:

- traceability: `docs/32-current-traceability-overlay.md`;
- risks: `docs/33-current-risk-overlay.md`;
- execution ledger: `docs/00-execution-ledger.md`;
- current cross-phase continuation handoff: `docs/52-current-continuation-handoff.md`;
- active Phase 0.4 continuation handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`;
- latest accepted Phase 0.5 evidence: `evidence/0.5.0/wallet-correction-approval-foundation.md`;
- current Phase 0.5 evidence candidate: `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`;
- current Phase 0.5 traceability candidate: `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

## Live-state rule

Do not treat any SHA written here as the current working head. Before every repository write, fetch PR `#6`, require it to remain Draft/open on `develop/v1.0.0-completion`, use its exact `head_sha`, and inspect exact-head CI. All GitHub Actions jobs must remain on the owner-controlled self-hosted runner.

## Active Phase 0.4 boundary and blocker

Last evidence-complete active-phase boundary remains **PasarGuard Guarded Live-Acceptance Harness**:

- implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2`, CI `31226863010` / `#1013`;
- evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412`, CI `31227084007` / `#1015`;
- 317 tests / 1730 assertions;
- artifact `test-evidence-31227084007`, ID `9012490991`;
- digest `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`;
- evidence `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- traceability `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

The active increment remains **PasarGuard Controlled Live Execution**, status **blocked** on protected Actions secret configuration plus manual workflow dispatch. Authoritative handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`.

After the guarded run, coordinator-level adoption/idempotency, controlled timeout/5xx/429 uncertainty and explicit Target activation remain separate live rows. Marzban `v0.8.4` deployment acceptance remains mandatory at final release acceptance. Phase `0.4.0` / Issue `#7` is therefore still open.

## Parallel Phase 0.5 financial chain

Phase `0.5.0` / Issue `#8` is not active/closed. Eight bounded financial foundations are evidence-complete and reusable:

1. Financial Ledger Foundation — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. Wallet Holds / Available Balance / Capture / Release — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. Wallet Maintenance Operations — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. Stable Wallet Transfer (`WAL-003`) — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. Dedicated Wallet Contention Verification — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. Wallet Refund / Reversal Foundation (`WAL-004`) — `docs/54-phase-0.5-wallet-refund-traceability.md`;
8. Wallet Correction / Approval Foundation (`WAL-005`) — `docs/55-phase-0.5-wallet-correction-traceability.md`.

A ninth bounded financial increment, **Payment Intent + `WAL-001` External Cash-Wallet Top-up**, is implementation-verified and is now in the exact evidence-head gate. It is **not accepted yet** until the final evidence/status head passes mandatory CI/artifact verification.

### Latest accepted parallel boundary — `WAL-005` Correction / Approval

- implementation `fd3d579d9f38004310d7ea638e351813d2f46ef5`, CI `31260299072` / `#1186` — 371 tests / 2197 assertions;
- evidence `ef5504081a42687eb712e9cd47306cd9dcc9a864`, CI `31260549403` / `#1188` — 371 / 2197;
- evidence `evidence/0.5.0/wallet-correction-approval-foundation.md`;
- traceability `docs/55-phase-0.5-wallet-correction-traceability.md`.

### Current implementation-verified candidate — `PAY-002` / `PAY-003` / `WAL-001`

Implementation verification:

- SHA `6db9dde7032114ab81c95fdf371530997e65f21c`;
- CI `31265449681` / `#1214` — success;
- full suite **384 tests / 2292 assertions**;
- dedicated Payment Intent/top-up suites **13 tests / 95 assertions**, zero failures/errors/skips;
- artifact `test-evidence-31265449681`, ID `9024026199`;
- uploader and independently recalculated digest `sha256:3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`;
- evidence candidate `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`;
- traceability candidate `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

Verified implementation behavior: immutable top-up intent creation identity and exact replay/conflict; active owned cash-wallet-only target; browser/non-authoritative evidence cannot capture; normalized authoritative settled evidence is required; provider event/transaction uniqueness and DB authority guards; one captured settlement and one balanced clearing-to-cash ledger effect; accepted replay remains stable after later wallet deactivation; safe evidence is forbidden-field filtered and DB-bounded to 32 fields / 8192 bytes; real-MariaDB independent-process duplicate/cross-intent contention proves one primary top-up effect.

This implementation does not claim `PAY-001` payment-method eligibility, a real gateway/provider, provider-native refund, pricing/Quote, promotions/referrals/agent pricing, Order/provisioning ownership, payment UX, Phase `0.4.0` closure or Phase `0.5.0` closure.

## Next recommended parallel bounded increment

After exact `WAL-001` evidence-head acceptance, continue with **deterministic Pricing / immutable Quote snapshot (`BUY-002`)**.

Required initial properties:

- deterministic integer-IRR price calculation only; no monetary float or implicit rounding;
- immutable Quote identity and exact replay/conflict behavior;
- snapshot base price, account/agent override input, discount input/result, final price, currency, validity window and configuration/version identity;
- final price cannot be negative and every monetary input/output is bounded integer IRR;
- accepted Quote cannot be reinterpreted by later Offering/pricing configuration changes;
- pricing/Quote remains non-authoritative for wallet/payment settlement until a later owned payment/order flow consumes it under fresh eligibility/authorization;
- no promotions/referrals/provider/Order/provisioning behavior is pulled into this first pricing boundary;
- exact implementation/evidence CI, retained artifact/digest and DB immutability/replay tests are mandatory.

After Pricing/Quote: promotions/referrals/agent pricing, then payment-method eligibility/provider implementations and provider-native refund integrations.

## Current open items

- PasarGuard protected live execution and later coordinator/fault/Target-activation rows;
- Marzban live acceptance at final release acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- exact evidence-head acceptance for current `WAL-001` candidate;
- `BUY-002` Pricing/Quote, promotions/referrals/agent pricing and payment providers;
- provider-native refund/payment behavior beyond accepted provider-independent wallet foundations;
- all Phase `0.6.0+` owned behavior.

## Non-negotiable controls

- PR `#6` stays Draft; do not merge, mark Ready, auto-merge, rewrite history, force-push, create a temporary branch or push `main`;
- no phase/increment closes from docs/schema/fake/interface presence alone;
- exact implementation CI/artifact and exact evidence-head CI/artifact are mandatory;
- authoritative remote lookup precedes provider create; uncertainty requires discovery before retry; TLS is never disabled;
- monetary IRR remains integer; finalized balanced ledger history plus active holds is authority; persisted wallet snapshots/caches never authorize a financial effect;
- replay/conflict cannot create or overwrite a second accepted financial or remote effect;
- refund/correction must compensate immutable history rather than alter it;
- browser return never proves payment and no paid provisioning occurs before authoritative capture;
- protected secrets and sensitive provider material never enter repository evidence/logs/chat.

Phase `0.4.0` is **not closed**. Phase `0.5.0` is **not closed**. The accepted parallel foundations and implementation-verified `WAL-001` candidate may continue only without weakening or falsely closing active provider gates.
