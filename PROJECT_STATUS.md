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
- provider live matrix: `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
- active Phase 0.4 continuation handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`;
- accepted PasarGuard harness evidence: `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- accepted PasarGuard harness traceability: `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`;
- accepted Phase 0.5 ledger evidence/traceability: `evidence/0.5.0/financial-ledger-foundation.md`, `docs/45-phase-0.5-financial-ledger-traceability.md`;
- accepted wallet-hold evidence/traceability: `evidence/0.5.0/wallet-holds-capture-release.md`, `docs/46-phase-0.5-wallet-holds-traceability.md`;
- accepted wallet-reconciliation evidence/traceability: `evidence/0.5.0/wallet-reconciliation-snapshots.md`, `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
- accepted wallet-maintenance evidence/traceability: `evidence/0.5.0/wallet-maintenance-operations.md`, `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
- accepted wallet-transfer evidence/traceability: `evidence/0.5.0/wallet-transfer.md`, `docs/51-phase-0.5-wallet-transfer-traceability.md`.

## Live-state rule

Do not treat a SHA written in this document as the current working head. Before every repository write, fetch PR `#6` and use its exact `head_sha`, then inspect workflow runs for that SHA. Follow `AGENTS.md`, `docs/development/continuation-runbook.md`, and `docs/52-current-continuation-handoff.md`.

## Last evidence-complete active-phase boundary

### Phase 0.4 increment 10 — PasarGuard Guarded Live-Acceptance Harness

Implementation boundary:

- SHA: `e18460357d306789cbbf85721f61a4e3a3bbb0e2`;
- CI: `31226863010` / run `#1013` — success;
- suite: 317 tests / 1730 assertions;
- artifact: `test-evidence-31226863010`, ID `9012421937`;
- digest: `sha256:72d10f54f0347ac743471c78ea4401a4b9268a763e6653cb2a3c17bf4e2608e6`.

Evidence boundary:

- SHA: `71ca4b39df41bc9fcf725c30e9caba3285ee5412`;
- CI: `31227084007` / run `#1015` — success;
- suite: 317 tests / 1730 assertions;
- artifact: `test-evidence-31227084007`, ID `9012490991`;
- digest: `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`;
- evidence: `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- traceability: `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

The boundary proves the guarded PasarGuard `v5.2.1` live harness and its fail-closed/TLS/redaction/version/lookup/equivalence controls. It does **not** prove successful live connectivity or remote mutation against the owner deployment.

## Active Phase 0.4 increment

### PasarGuard Controlled Live Execution

Status: **blocked on protected Actions secret configuration and manual workflow dispatch**.  
Authoritative handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`.  
Execution matrix: `docs/41-phase-0.4-provider-live-acceptance-matrix.md`.

The current GitHub integration can inspect Actions runs/artifacts and retry existing failed jobs, but cannot create/update repository Actions Secrets or initiate a fresh `workflow_dispatch`.

Required protected configuration outside repository text:

- `PASARGUARD_TEST_ORIGIN`;
- `PASARGUARD_TEST_API_KEY`.

After those Secrets are configured, manually dispatch `Provider Live Acceptance - PasarGuard` on `develop/v1.0.0-completion` with the workflow's exact confirmation value. Do not put secret values in workflow inputs, commits, Issues, PR comments, evidence, logs, or chat.

Owner scheduling decision on 2026-08-08: Marzban `v0.8.4` live acceptance is deferred to final project/release acceptance. It remains a mandatory `1.0.0` requirement. Phase `0.4.0` / Issue `#7` therefore remains open.

## Parallel Phase 0.5 accepted financial boundaries

Phase `0.5.0` / Issue `#8` is **not active or complete**. The following independent foundations are evidence-complete under the parallel-continuation policy while Phase `0.4.0` remains open.

### 1 — Financial Ledger Foundation

- implementation `259e29e6f93c2b36cd36c2f40bf669789eaab62f`, CI `31232006308` / `#1037`, 326 tests / 1777 assertions;
- evidence `76c00a1c458c12ccc07dc658ee2f69e14389e65c`, CI `31232151035` / `#1039`;
- evidence `evidence/0.5.0/financial-ledger-foundation.md`;
- traceability `docs/45-phase-0.5-financial-ledger-traceability.md`.

Accepted: integer-IRR money, balanced append-only ledger posting, account constraints, exact replay/conflict, transaction/row locks and DB-enforced ledger immutability.

### 2 — Wallet Holds, Available Balance, Capture and Release

- implementation `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`, CI `31232610814` / `#1046`, 331 tests / 1834 assertions;
- evidence `781a2dd63d999b2e01d99cd6888f3c9cbfda9f26`, CI `31232750290` / `#1048`;
- evidence `evidence/0.5.0/wallet-holds-capture-release.md`;
- traceability `docs/46-phase-0.5-wallet-holds-traceability.md`.

Accepted: active holds reduce authoritative ledger-derived available balance, exact placement replay/conflict, one capture ledger effect, release without ledger rewrite and terminal/non-deletable hold history.

### 3 — Wallet Reconciliation Snapshots and Expired-Hold Cleanup

- implementation `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`, CI `31233135374` / `#1058`, 334 tests / 1886 assertions;
- evidence `57719972291b0553e76da6f8db50a8190a807044`, CI `31233274800` / `#1060`;
- evidence `evidence/0.5.0/wallet-reconciliation-snapshots.md`;
- traceability `docs/48-phase-0.5-wallet-reconciliation-traceability.md`.

Accepted: append-only non-authoritative snapshots, authoritative ledger+active-hold reconciliation, fail-closed inconsistent state and bounded cleanup/review. Transfer holds are excluded from generic cleanup.

### 4 — Wallet Maintenance Operations

- implementation `87392cca0a1a00ee87a1b5074386dd691a4094c6`, CI `31233514751` / `#1066`, 338 tests / 1905 assertions;
- evidence `fa0cc2056459f171c32e0260422a465891766629`, CI `31233817131` / `#1068`;
- evidence `evidence/0.5.0/wallet-maintenance-operations.md`;
- traceability `docs/49-phase-0.5-wallet-maintenance-traceability.md`.

Accepted: bounded `wallet:maintenance`, aggregate-safe output and every-five-minutes Laravel Scheduler execution with `withoutOverlapping()` and `onOneServer()`; snapshots remain non-authoritative.

### 5 — Stable Wallet Transfer (`WAL-003`)

Implementation:

- SHA `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`;
- CI `31235232052` / `#1084` — success;
- 346 tests / 1995 assertions;
- artifact `test-evidence-31235232052`, ID `9015155851`;
- independent digest `sha256:c85e5106f95e6c37745dbcf920d3728108d7f82f26624c6cfccc0684f5bb265b`.

Evidence:

- SHA `68f06fbd4ae9bb1bdba004968e57c84a13871e15`;
- CI `31235552266` / `#1092` — success;
- 346 tests / 1995 assertions;
- artifact `test-evidence-31235552266`, ID `9015258508`;
- independent digest `sha256:f1bee9300338b0d326e9c0cf47ea978147a6e72688e598c0717f7ae180b29a78`;
- evidence `evidence/0.5.0/wallet-transfer.md`;
- traceability `docs/51-phase-0.5-wallet-transfer-traceability.md`.

Accepted: default-disabled transfer policy, stable recipient identity, execution-time account revalidation, integer policy/fee snapshots, prepare reservation without transfer ledger effect, atomic sender/recipient/fee posting at confirmation, exact replay/conflict, terminal cancellation/expiry, transfer-hold cleanup isolation and DB terminal/immutability barriers.

## Next recommended parallel bounded increment

### Dedicated Wallet Contention Verification (`WAL-002` remaining proof)

Authoritative continuation handoff: `docs/52-current-continuation-handoff.md`.

The next safe independent task is real multi-process MariaDB contention evidence for same-wallet holds, capture/release, duplicate ledger keys, duplicate transfer prepare/confirm and reconciliation concurrent with mutation. Use deterministic subprocess/barrier coordination rather than timing sleeps. No lock/isolation weakening is allowed to make the test pass.

After contention verification, recommended Phase 0.5 order is `WAL-004` refund/reversal, `WAL-005` correction/approval, Payment Intent + `WAL-001`, then pricing/Quote, promotions/referrals/agent pricing and payment providers.

## Current open items

- PasarGuard protected live execution and later coordinator/fault/Target-activation rows;
- Marzban live acceptance at final release acceptance;
- final Phase `0.4.0` closure audit;
- dedicated multi-process wallet contention verification;
- no automated scheduled cancellation sweep is claimed for untouched expired pending wallet transfers;
- `WAL-001`, `WAL-004`, `WAL-005`, pricing/Quote/promotions/payment providers and all later phase-owned work.

## Parallel continuation policy

Later-phase work may continue while the provider human gates remain carried only if:

- Phase `0.4.0` is not marked closed;
- Issue `#7` stays open;
- no later work weakens lookup-before-create, provider-specific create equivalence, idempotency, uncertainty discovery, TLS, redaction or Target activation controls;
- no release/production-readiness claim is made before carried provider gates pass;
- real provider Targets stay disabled until explicit live acceptance.

## Non-negotiable remote-effect and financial rules

- authoritative remote lookup before every create; unavailable lookup means no create;
- automatic adoption requires provider-specific accepted `createEquivalenceHash`; missing proof means Manual Review/no create;
- mismatch means conflict/no overwrite; uncertainty means authoritative discovery before retry;
- conflicting idempotency-key reuse never overwrites the original primary effect;
- TLS verification is never disabled and protected material never enters repository evidence/logs;
- monetary IRR values remain integers at financial boundaries;
- immutable balanced ledger history plus active holds is authoritative; persisted snapshots/caches never authorize financial effects;
- no replay/conflict may create or overwrite a second accepted financial effect.

## Phase completion state

Phase `0.4.0` is **not closed**. PasarGuard live execution awaits protected secret configuration/manual dispatch and Marzban live acceptance is carried to final project/release acceptance. Phase `0.5.0` is also **not closed**; its accepted financial boundaries above are reusable foundations only.
