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
- latest accepted Phase 0.5 evidence: `evidence/0.5.0/wallet-refund-reversal-foundation.md`;
- latest Phase 0.5 traceability: `docs/54-phase-0.5-wallet-refund-traceability.md`.

## Live-state rule

Do not treat any SHA written here as the current working head. Before every repository write, fetch PR `#6`, require it to remain Draft/open on `develop/v1.0.0-completion`, use its exact `head_sha`, and inspect exact-head CI. All GitHub Actions jobs must remain on the owner-controlled self-hosted runner.

## Active Phase 0.4 boundary and blocker

Last evidence-complete active-phase boundary is **PasarGuard Guarded Live-Acceptance Harness**:

- implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2`, CI `31226863010` / `#1013`;
- evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412`, CI `31227084007` / `#1015`;
- 317 tests / 1730 assertions;
- artifact `test-evidence-31227084007`, ID `9012490991`;
- digest `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`;
- evidence `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- traceability `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

The active increment remains **PasarGuard Controlled Live Execution**, status **blocked** on protected Actions secret configuration plus manual workflow dispatch. Authoritative handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`.

The guarded harness does not prove successful connectivity/mutation against the owner deployment. After the guarded run, coordinator-level adoption/idempotency, controlled timeout/5xx/429 uncertainty and explicit Target activation remain separate live rows. Marzban `v0.8.4` live acceptance is owner-scheduled for final release acceptance and remains mandatory for `1.0.0`.

Phase `0.4.0` / Issue `#7` therefore remains open.

## Parallel Phase 0.5 accepted financial chain

Phase `0.5.0` / Issue `#8` is not active/closed, but seven bounded foundations are evidence-complete and reusable:

1. Financial Ledger Foundation — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. Wallet Holds / Available Balance / Capture / Release — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. Wallet Maintenance Operations — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. Stable Wallet Transfer (`WAL-003`) — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. Dedicated Wallet Contention Verification — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. Wallet Refund / Reversal Foundation (`WAL-004`) — `docs/54-phase-0.5-wallet-refund-traceability.md`.

### Latest accepted parallel boundary — `WAL-004` Refund / Reversal

Implementation verification:

- SHA `0237f94cae67ff2ca31047af55420fed0ffe578a`;
- CI `31242702422` / `#1155` — success;
- full suite 360 tests / 2099 assertions;
- dedicated refund suites `6 / 52` and `2 / 17`, zero failures/errors/skips;
- artifact `test-evidence-31242702422`, ID `9017545543`;
- independent digest `sha256:be509996d098ee7f1354a9dc1fe949a224b2ead42c15ae145c2e12ad59890cdc`.

Evidence head:

- SHA `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`;
- CI `31242888656` / `#1156` — success;
- full suite 360 tests / 2099 assertions;
- artifact `test-evidence-31242888656`, ID `9017593626`;
- independent digest `sha256:a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`;
- evidence `evidence/0.5.0/wallet-refund-reversal-foundation.md`;
- traceability `docs/54-phase-0.5-wallet-refund-traceability.md`.

Accepted behavior: immutable capture-time refundability, integer partial/cumulative refund caps, original wallet-bucket compensation, manual-external evidence without duplicate wallet credit, privileged destination override, exact replay/conflict, DB immutability and deterministic concurrent over-refund/duplicate-key proof on MariaDB.

The foundation can represent a refundable total smaller than capture, but it does not calculate exact card adjustment or claim provider-native refunds before the owning Quote/Payment Intent/provider boundaries exist.

## Next recommended parallel bounded increment

### `WAL-005` Balance Correction / Approval Foundation

Authoritative continuation handoff: `docs/52-current-continuation-handoff.md`.

Implement administrator corrections as immutable compensating ledger effects: explicit target bucket, positive integer amount, debit/credit direction, reason/note/related reference, deterministic preview plus confirmation, execution-time permission, policy-driven Owner/dual approval for large changes, exact replay/conflict, negative-available-balance prevention for debits, safe audit and independent-process concurrency proof.

Reuse the existing authorization and `SensitiveActionApprovalService`; do not create a correction shortcut around refund/payment/order state machines or pull customer UI/Order ownership forward.

After `WAL-005`: Payment Intent + `WAL-001`, pricing/Quote, promotions/referrals/agent pricing, then payment providers.

## Current open items

- PasarGuard protected live execution and later coordinator/fault/Target-activation rows;
- Marzban live acceptance at final release acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- `WAL-001`, `WAL-005`, pricing/Quote/promotions/payment providers;
- provider-native refund behavior beyond the accepted `WAL-004` foundation;
- all Phase `0.6.0+` owned behavior.

## Non-negotiable controls

- PR `#6` stays Draft; do not merge, mark Ready, auto-merge, rewrite history, force-push, create a temporary branch or push `main`;
- no phase/increment closes from docs/schema/fake/interface presence alone;
- exact implementation CI/artifact and exact evidence-head CI/artifact are mandatory;
- authoritative remote lookup precedes provider create; uncertainty requires discovery before retry; TLS is never disabled;
- monetary IRR remains integer; finalized balanced ledger history plus active holds is authority; persisted wallet snapshots/caches never authorize a financial effect;
- replay/conflict cannot create or overwrite a second accepted financial or remote effect;
- refund/correction must compensate immutable history rather than alter it;
- protected secrets and sensitive provider material never enter repository evidence/logs/chat.

Phase `0.4.0` is **not closed**. Phase `0.5.0` is **not closed**. The accepted parallel foundations may continue only without weakening or falsely closing the active provider gates.
