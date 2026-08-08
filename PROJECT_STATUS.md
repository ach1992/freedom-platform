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
- latest accepted Phase 0.5 evidence: `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`;
- latest accepted Phase 0.5 traceability: `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

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

Phase `0.5.0` / Issue `#8` is not active/closed. Nine bounded financial foundations are evidence-complete and reusable:

1. Financial Ledger Foundation — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. Wallet Holds / Available Balance / Capture / Release — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. Wallet Maintenance Operations — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. Stable Wallet Transfer (`WAL-003`) — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. Dedicated Wallet Contention Verification — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. Wallet Refund / Reversal Foundation (`WAL-004`) — `docs/54-phase-0.5-wallet-refund-traceability.md`;
8. Wallet Correction / Approval Foundation (`WAL-005`) — `docs/55-phase-0.5-wallet-correction-traceability.md`;
9. Payment Intent + External Cash-Wallet Top-up (`PAY-002`, `PAY-003`, `WAL-001`) — `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

### Latest accepted parallel boundary — `PAY-002` / `PAY-003` / `WAL-001`

Implementation:

- SHA `6db9dde7032114ab81c95fdf371530997e65f21c`;
- CI `31265449681` / `#1214` — success;
- full suite **384 tests / 2292 assertions**;
- dedicated top-up verification **13 tests / 95 assertions**;
- artifact `test-evidence-31265449681`, ID `9024026199`;
- digest `sha256:3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`.

Evidence:

- SHA `76ea847d4d1f626893b925bbfe5263e1dc1398e4`;
- CI `31265901691` / `#1220` — success;
- full suite **384 / 2292**;
- artifact `test-evidence-31265901691`, ID `9024152227`;
- independent digest `sha256:dff70424074d138f59a8c9bfb03e5196f3827142fe964b043c09ef53e15392e7`;
- evidence `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`;
- traceability `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

Accepted behavior: immutable top-up intent identity and exact replay/conflict; active owned cash-wallet-only target; browser/non-authoritative evidence cannot capture; normalized authoritative settled evidence is required; provider event/transaction uniqueness and DB authority guards; exactly one captured settlement and one balanced clearing-to-cash ledger effect; accepted replay remains stable after later wallet deactivation; safe evidence is forbidden-field filtered and DB-bounded to 32 fields / 8192 bytes; real-MariaDB independent-process duplicate/cross-intent contention proves one primary top-up effect.

This accepted boundary does not claim `PAY-001` payment-method eligibility, a real gateway/provider, provider-native refund, pricing/Quote, promotions/referrals/agent pricing, Order/provisioning ownership, payment UX, Phase `0.4.0` closure or Phase `0.5.0` closure.

## Current parallel bounded increment — `BUY-002` deterministic Pricing / immutable Quote

The next independent increment is now **deterministic Pricing / immutable Quote snapshot (`BUY-002`)**.

Required first-boundary properties:

- all monetary values are bounded integer IRR; no monetary float or implicit rounding;
- immutable Quote/idempotency identity with canonical request/config payload hash; exact replay returns the prior Quote and changed reuse conflicts;
- snapshot base Offering price and immutable Offering/configuration identity;
- represent account/agent override input explicitly and deterministically rather than inferring mutable state later;
- represent discount input/result explicitly, including zero discount; final price is deterministic and non-negative;
- persist base, override, discount, final amount, `IRR`, validity and configuration snapshots as required by `BUY-002`;
- accepted Quote cannot be reinterpreted by later Offering/pricing configuration changes;
- Quote expiration is explicit and does not silently create current pricing/payment authority;
- no wallet debit/capture, Payment Intent capture, Order paid state, provisioning or service activation occurs from Quote creation alone;
- promotions/referrals/provider execution remain later increments;
- exact implementation/evidence CI, retained artifact/digest and DB immutability/replay tests are mandatory.

Reuse accepted `PlanOfferingDefinition`, custom-plan snapshot/arithmetic and existing agent-pricing foundations where applicable; do not invent a second incompatible pricing formula.

After Pricing/Quote: promotions/referrals/agent pricing, then payment-method eligibility/provider implementations and provider-native refund integrations.

## Current open items

- PasarGuard protected live execution and later coordinator/fault/Target-activation rows;
- Marzban live acceptance at final release acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- current `BUY-002` Pricing/Quote increment;
- promotions/referrals/agent pricing and `PAY-001` payment-method eligibility/providers;
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

Phase `0.4.0` is **not closed**. Phase `0.5.0` is **not closed**. Accepted parallel foundations may continue only without weakening or falsely closing active provider gates.
