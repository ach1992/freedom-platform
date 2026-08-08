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
- latest Phase 0.5 traceability: `docs/55-phase-0.5-wallet-correction-traceability.md`.

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

## Parallel Phase 0.5 accepted financial chain

Phase `0.5.0` / Issue `#8` is not active/closed, but eight bounded financial foundations are evidence-complete and reusable:

1. Financial Ledger Foundation — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. Wallet Holds / Available Balance / Capture / Release — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. Wallet Maintenance Operations — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. Stable Wallet Transfer (`WAL-003`) — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. Dedicated Wallet Contention Verification — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. Wallet Refund / Reversal Foundation (`WAL-004`) — `docs/54-phase-0.5-wallet-refund-traceability.md`;
8. Wallet Correction / Approval Foundation (`WAL-005`) — `docs/55-phase-0.5-wallet-correction-traceability.md`.

### Latest accepted parallel boundary — `WAL-005` Correction / Approval

Implementation verification:

- SHA `fd3d579d9f38004310d7ea638e351813d2f46ef5`;
- CI `31260299072` / `#1186` — success;
- full suite 371 tests / 2197 assertions;
- dedicated correction suites: `6 / 57`, `3 / 28`, `2 / 13`, zero failures/errors/skips;
- artifact `test-evidence-31260299072`, ID `9022602206`;
- independent digest `sha256:476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`.

Evidence head:

- SHA `ef5504081a42687eb712e9cd47306cd9dcc9a864`;
- CI `31260549403` / `#1188` — success;
- full suite 371 tests / 2197 assertions;
- artifact `test-evidence-31260549403`, ID `9022665227`;
- independent digest `sha256:ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`;
- evidence `evidence/0.5.0/wallet-correction-approval-foundation.md`;
- traceability `docs/55-phase-0.5-wallet-correction-traceability.md`.

Accepted `WAL-005` behavior: immutable correction key/payload and preview/confirmation, fresh finalized-ledger plus active-hold authority, debit negative-availability denial, execution-time permission/policy revalidation, policy-driven independent approval with exact approval replay binding, balanced compensating correction entries, immutable DB guards, safe audit, and independent-process MariaDB proof for competing debit/duplicate/approval races.

This does not claim Payment Intent settlement, provider-native payment/refund behavior, Order/provisioning ownership or correction UX.

## Next recommended parallel bounded increment

### Payment Intent + `WAL-001` External Cash-Wallet Top-up Settlement

Build the provider-independent Payment Intent settlement boundary before pricing/providers. Preserve existing `PaymentProvider` contracts and do not invent Order/provisioning ownership in Phase `0.5.0`.

Required initial properties:

- explicit Payment Intent state machine and immutable creation/idempotency identity;
- wallet-top-up purpose bound to one user cash bucket and positive integer IRR amount;
- browser return/user redirect is never authoritative payment proof;
- normalized authoritative provider evidence/event is required for capture;
- one captured settlement per intent and exactly one balanced cash-wallet ledger credit after capture;
- provider transaction/event uniqueness plus internal idempotency prevents duplicate capture/top-up;
- fresh transaction/locks and database uniqueness remain the final concurrency barrier;
- append-only safe provider/payment evidence; no secret/raw sensitive payload persistence;
- no Order/provisioning side effect in this top-up-only first boundary;
- exact implementation/evidence CI, retained artifact/digest and independent-process duplicate-capture proof.

After `WAL-001`: deterministic pricing/Quote, promotions/referrals/agent pricing, then payment provider implementations and provider-native refund integrations.

## Current open items

- PasarGuard protected live execution and later coordinator/fault/Target-activation rows;
- Marzban live acceptance at final release acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- `WAL-001`, pricing/Quote/promotions/payment providers;
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
- no paid provisioning before authoritative capture;
- protected secrets and sensitive provider material never enter repository evidence/logs/chat.

Phase `0.4.0` is **not closed**. Phase `0.5.0` is **not closed**. The accepted parallel foundations may continue only without weakening or falsely closing active provider gates.
