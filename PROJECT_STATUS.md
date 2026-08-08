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
- current accepted Phase 0.5 concurrency evidence: `evidence/0.5.0/wallet-contention-verification.md`;
- current Phase 0.5 concurrency traceability: `docs/53-phase-0.5-wallet-contention-traceability.md`.

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

Phase `0.5.0` / Issue `#8` is not active/closed, but six bounded foundations are evidence-complete and reusable:

1. Financial Ledger Foundation — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. Wallet Holds / Available Balance / Capture / Release — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. Wallet Maintenance Operations — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. Stable Wallet Transfer (`WAL-003`) — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. Dedicated Wallet Contention Verification — `docs/53-phase-0.5-wallet-contention-traceability.md`.

### Latest accepted parallel boundary — Dedicated Wallet Contention Verification

Implementation verification:

- SHA `903f326040c9acd0b31645fe8fae3a75f8a9fd27`;
- CI `31240159777` / `#1128` — success;
- full suite 352 tests / 2030 assertions;
- dedicated contention class 6 tests / 35 assertions, zero failures/errors/skips;
- artifact `test-evidence-31240159777`, ID `9016771279`;
- independent digest `sha256:4f32e7a5c7e4cc23b985b13aa2b6f291772b75c1f9a26cedcd620b9589b973b2`.

Evidence head:

- SHA `e7a0ae17d470beb40f4933e66c7b599e0837e120`;
- CI `31241459956` / `#1131` — success;
- full suite 352 tests / 2030 assertions;
- artifact `test-evidence-31241459956`, ID `9017163993`;
- independent digest `sha256:58544b56477c708b4e798b2ea83e673995e22b0ab53c19213914d1c2609af294`;
- evidence `evidence/0.5.0/wallet-contention-verification.md`;
- traceability `docs/53-phase-0.5-wallet-contention-traceability.md`.

Accepted concurrency proof covers same-wallet over-reservation, capture/release terminal races, duplicate ledger command keys, duplicate transfer prepare/confirm and reconciliation concurrent with mutation. Persisted snapshots remain non-authoritative and MariaDB locking/isolation was not weakened.

## Next recommended parallel bounded increment

### `WAL-004` Refund / Reversal Foundation

Authoritative continuation handoff: `docs/52-current-continuation-handoff.md`.

Implement the provider-independent refund/compensating-ledger foundation first: immutable refund identity, integer partial amounts, cumulative refundable-cap locking, explicit destination, wallet compensation to the original eligible bucket, manual-external reference/evidence, exact replay/conflict, destination-override control/audit and dedicated concurrent refund proof.

Do not claim or implement provider-native refund APIs, Payment Intent settlement, Order refund-state ownership or exact-card-adjustment behavior before their owning metadata/integration boundaries exist.

After `WAL-004`: `WAL-005` correction/approval, Payment Intent + `WAL-001`, pricing/Quote, promotions/referrals/agent pricing, then payment providers.

## Current open items

- PasarGuard protected live execution and later coordinator/fault/Target-activation rows;
- Marzban live acceptance at final release acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- `WAL-001`, `WAL-004`, `WAL-005`, pricing/Quote/promotions/payment providers;
- all Phase `0.6.0+` owned behavior.

## Non-negotiable controls

- PR `#6` stays Draft; do not merge, mark Ready, auto-merge, rewrite history, force-push, create a temporary branch or push `main`;
- no phase/increment closes from docs/schema/fake/interface presence alone;
- exact implementation CI/artifact and exact evidence-head CI/artifact are mandatory;
- authoritative remote lookup precedes provider create; uncertainty requires discovery before retry; TLS is never disabled;
- monetary IRR remains integer; finalized balanced ledger history plus active holds is authority; persisted wallet snapshots/caches never authorize a financial effect;
- replay/conflict cannot create or overwrite a second accepted financial or remote effect;
- protected secrets and sensitive provider material never enter repository evidence/logs/chat.

Phase `0.4.0` is **not closed**. Phase `0.5.0` is **not closed**. The accepted parallel foundations may continue only without weakening or falsely closing the active provider gates.
