# Project Status

This is the single human-readable current-state entry point. Detailed history belongs in evidence, traceability, risk, audit, and bounded handoff documents.

**Last status review:** 2026-08-09  
**Target release:** `1.0.0`  
**Active phase:** `0.4.0 — Catalog, Panels and Offerings`  
**Authoritative Phase 0.4 Issue:** `#7`  
**Authoritative integration PR:** Draft `#6`  
**Allowed branch:** `develop/v1.0.0-completion`  
**PR base:** `main`

Current control documents:

- current cross-phase handoff: `docs/52-current-continuation-handoff.md`;
- active Phase 0.4 live-execution handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`;
- traceability overlay: `docs/32-current-traceability-overlay.md`;
- risk overlay: `docs/33-current-risk-overlay.md`;
- execution ledger: `docs/00-execution-ledger.md`;
- latest accepted Phase 0.5 evidence: `evidence/0.5.0/quote-pricing-snapshot.md`;
- latest accepted Phase 0.5 traceability: `docs/57-phase-0.5-quote-pricing-traceability.md`.

## Live-state rule

Do not treat any SHA written here as the current working head. Before **every repository write**, fetch PR `#6`, require it to remain open/Draft on `develop/v1.0.0-completion` with base `main`, and use its exact `head_sha`. Inspect exact-head CI before deciding the next action. All Actions jobs remain on the owner-controlled self-hosted runner.

## Active Phase 0.4 boundary and blocker

The latest evidence-complete active-phase boundary remains **PasarGuard Guarded Live-Acceptance Harness**:

- implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2`, CI `31226863010` / `#1013`;
- evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412`, CI `31227084007` / `#1015`;
- 317 tests / 1730 assertions;
- artifact `test-evidence-31227084007`, ID `9012490991`;
- digest `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`;
- evidence `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- traceability `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

The active increment remains **PasarGuard Controlled Live Execution**. The immediate repository-side blocker is the default-branch workflow bootstrap:

- Draft PR `#24`: `ops/provider-live-dispatch-bootstrap` → `main`;
- bootstrap head last inspected: `f2d2b6d538b16fe08787f6248ec425dbd19c8321`;
- CI `31240183151` / `#1129`: preflight, secret scan, static quality, and MariaDB/Redis tests passed; `Dependency and license policy` failed because `composer audit --locked --abandoned=fail` exited non-zero;
- `main` is still unchanged from bootstrap base `1227cce28aedd2d799f2cd510891309deaacd0fb`;
- safety snapshot `safety/main-2026-08-08-pre-provider-bootstrap` is still retained;
- bootstrap branch `ops/provider-live-dispatch-bootstrap` is still required while PR `#24` is open/unresolved.

Do **not** delete either bootstrap/safety branch yet. Cleanup condition: only after PR `#24` is deliberately merged or explicitly abandoned/replaced, default-branch dispatch availability/rollback is resolved, and the safety snapshot is no longer needed.

After bootstrap acceptance, the owner must manually dispatch `Provider Live Acceptance - PasarGuard` on `develop/v1.0.0-completion` with the exact guarded confirmation. Protected secret values remain outside repository/chat. After that guarded run, coordinator adoption/idempotency, controlled timeout/5xx/429 uncertainty, and explicit Target activation remain separate live rows. Marzban `v0.8.4` deployment acceptance remains mandatory at final release acceptance.

Phase `0.4.0` / Issue `#7` is therefore still open.

## Parallel Phase 0.5 accepted chain

Phase `0.5.0` / Issue `#8` is not active/closed. Ten bounded foundations are evidence-complete and reusable:

1. Financial Ledger Foundation — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. Wallet Holds / Available Balance / Capture / Release — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. Wallet Maintenance Operations — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. Stable Wallet Transfer (`WAL-003`) — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. Dedicated Wallet Contention Verification — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. Wallet Refund / Reversal Foundation (`WAL-004`) — `docs/54-phase-0.5-wallet-refund-traceability.md`;
8. Wallet Correction / Approval Foundation (`WAL-005`) — `docs/55-phase-0.5-wallet-correction-traceability.md`;
9. Payment Intent + External Cash-Wallet Top-up (`PAY-002`, `PAY-003`, `WAL-001`) — `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`;
10. Deterministic Pricing / Immutable Quote (`BUY-002`) — `docs/57-phase-0.5-quote-pricing-traceability.md`.

### Latest accepted parallel boundary — `BUY-002`

Implementation:

- SHA `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`;
- CI `31267071664` / `#1233` — all mandatory jobs success;
- full suite **392 tests / 2355 assertions**;
- dedicated Quote suite **8 tests / 63 assertions**;
- artifact `test-evidence-31267071664`, ID `9024501375`;
- independent digest `sha256:899a26d7553f4fd37c2102da986fc876f0a8e8151b683ff36e2f300acb9ad754`.

Evidence:

- SHA `07840d417e64eb30bf31f17bb9e26c1fe549eef3`;
- CI `31267346707` / `#1236` — all mandatory jobs success;
- full suite **392 / 2355**;
- dedicated Quote suite **8 / 63**;
- artifact `test-evidence-31267346707`, ID `9024577868`, size `116261` bytes;
- independent digest `sha256:ba2710a544ccd07c72a56c616f7215406175ca8fdc93bc770c47ad474b275e83`;
- evidence `evidence/0.5.0/quote-pricing-snapshot.md`;
- traceability `docs/57-phase-0.5-quote-pricing-traceability.md`.

Accepted behavior: immutable Quote key/request replay/conflict; integer-IRR base/override/discount/final components; override-before-discount arithmetic; Offering ID/code/version/configuration-hash snapshot; current tier/agent reference validation for resolved override inputs; bounded configuration snapshot/hash; explicit expiry; historical stability across later Offering changes; database update/delete/forged-snapshot rejection; no wallet/payment/provider/paid-Order/provisioning effect.

This boundary deliberately separates price snapshot from purchasability. `BUY-001`, complete `PRO-001`, `REF-001`, `AGT-005`, `PAY-001`, provider execution, and Phase `0.6.0` ownership remain unclaimed.

## Next recommended parallel bounded increment

Start a **stored promotion/referral/pricing-rule resolution foundation** without pulling later effects forward. Initial scope should be bounded around `PRO-001` / `REF-001` rule definition, deterministic qualification/resolution, immutable rule/config identity for future Quote integration, replay/conflict, authorization, and DB integrity. Do not claim full promotion redemption/release, referral payout/reversal, most-specific `AGT-005`, payment-method `PAY-001`, provider execution, or `BUY-001` Order flow until separately implemented and verified.

After that foundation: most-specific agent pricing (`AGT-005`), then `PAY-001` payment-method eligibility/provider implementations and provider-native refund integration.

## Current open items

- PR `#24` dependency-policy failure and default-branch PasarGuard workflow bootstrap decision;
- PasarGuard protected live run plus coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- promotion/referral/pricing-rule resolution, `AGT-005`, `PAY-001`, and payment providers;
- provider-native refund/payment behavior beyond accepted provider-independent wallet foundations;
- all Phase `0.6.0+` owned behavior.

## Non-negotiable controls

- PR `#6` stays Draft; do not merge, mark Ready, auto-merge, rewrite history, force-push, or push `main`;
- no temporary branch should be created; the existing bootstrap/safety branches are explicit retained exceptions with cleanup conditions above;
- no phase/increment closes from docs/schema/fake/interface presence alone;
- exact implementation CI/artifact and exact evidence-head CI/artifact are mandatory;
- authoritative remote lookup precedes provider create; uncertainty requires discovery before retry; TLS is never disabled;
- monetary IRR remains integer; finalized balanced ledger history plus active holds is authority; persisted wallet snapshots/caches never authorize a financial effect;
- replay/conflict cannot create or overwrite a second accepted financial or remote effect;
- refund/correction compensates immutable history rather than altering it;
- browser return never proves payment and no paid provisioning occurs before authoritative capture;
- protected secrets and sensitive provider material never enter repository evidence/logs/chat.

Phase `0.4.0` is **not closed**. Phase `0.5.0` is **not closed**. Continue from `docs/52-current-continuation-handoff.md` after live-fetching PR `#6`.
