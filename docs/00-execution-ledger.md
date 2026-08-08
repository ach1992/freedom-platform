# Execution Ledger

The authoritative product contract is `docs/specification/master-execution-prompt.md`. This ledger records accepted delivery boundaries and current continuation state; it does not replace the product contract.

For continuation, read `AGENTS.md`, `PROJECT_STATUS.md`, `docs/project-status.json`, `docs/development/continuation-runbook.md`, and `docs/52-current-continuation-handoff.md`. Always live-fetch Draft PR `#6` for the exact current head before every repository write.

## Current position

- branch: `develop/v1.0.0-completion`;
- PR: Draft `#6`, base `main`;
- active phase: `0.4.0 — Catalog, Panels and Offerings`;
- authoritative Phase 0.4 Issue: `#7`;
- latest evidence-complete Phase 0.4 boundary: PasarGuard Guarded Live-Acceptance Harness;
- current Phase 0.4 increment: PasarGuard Controlled Live Execution — blocked on protected Actions Secrets plus manual workflow dispatch;
- Marzban `v0.8.4` live acceptance: owner-deferred to final project/release acceptance, not removed;
- Phase `0.5.0` remains open/not active, but five financial boundaries through `WAL-003` are evidence-complete under the parallel-continuation policy;
- next recommended independent work: Dedicated Wallet Contention Verification for the remaining explicit `WAL-002` proof;
- current cross-phase handoff: `docs/52-current-continuation-handoff.md`.

`main` remains unchanged by this completion stream. Phase `0.4.0` and Issue `#7` remain open; no provider Target is live-accepted or enabled from offline/harness evidence alone.

## Completed phases

### Phase 0.1.0 — Product specification and architecture

Completed/verified. The accepted planning baseline covers authoritative requirements, glossary/use cases/state machines/data model, permissions/threat model, ADR/module boundaries, test strategy, risk register and release/deployment planning.

### Phase 0.2.0 — Foundation, installer/runtime, operations and Telegram ingress

Completed/verified. Closure evidence: `evidence/0.2.0/PHASE-CLOSURE.md`.

Accepted foundations include Laravel/PHP 8.4, installer/finalization, MariaDB/authenticated Redis, Outbox/idempotency, release activation/rollback, worker/Scheduler health and authenticated Telegram ingress/recovery.

### Phase 0.3.0 — Identity, Customers, Agents and ACL

Completed/verified:

- closure `979b0c99d79dbcc8cff273ccdfad2ea612796a0c`;
- CI `31035712555` / `#798` — success;
- 194 tests / 958 assertions;
- evidence `evidence/0.3.0/phase-closure-verification.md`.

## Phase 0.4 accepted chain

Detailed proof is retained in the bounded evidence/traceability files. Accepted increments are:

1. Category/Product/Variant lifecycle — CI `#802/#803`;
2. secure Panel Connection and typed Protocol/Target/Sales Server foundations — through CI `#812`;
3. Plan Offering foundation — CI `#816/#817`;
4. capacity, availability, route selection and disclosed fallback — through CI `#827`;
5. Custom Plan policy/calculation snapshot — CI `#833/#834`;
6. Trial policy plus Panel Adapter offline/Fake foundation — CI `#931/#934`;
7. pinned Marzban `v0.8.4` / PasarGuard `v5.2.1` read contracts — CI `#959/#961`;
8. pinned provider mutation-contract mappings — CI `#978/#980`, runtime mutations still disabled;
9. provider Create-Equivalence Reconciliation — implementation `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43` / CI `#995`, evidence `a70b28cb984c23f1e219287e88d20014ee9f0310` / CI `#997`;
10. PasarGuard Guarded Live-Acceptance Harness — implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2` / CI `#1013`, evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412` / CI `#1015`.

The provider chain proves source/offline/harness safety only where stated. It does not prove the owner deployment until controlled live rows execute.

## Current Phase 0.4 live gates

### PasarGuard `v5.2.1`

Execution authority:

- `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
- `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`;
- `.github/workflows/provider-live-acceptance.yml`.

Actual execution requires protected repository Actions Secrets `PASARGUARD_TEST_ORIGIN` and `PASARGUARD_TEST_API_KEY`, then manual dispatch with the workflow's exact confirmation value. Current connector capabilities do not permit creating/updating those Secrets or initiating a fresh `workflow_dispatch`.

After the guarded provider sequence, coordinator-level adoption/idempotency, controlled timeout/5xx/429 uncertainty and explicit Target activation remain separately required live rows.

### Marzban `v0.8.4`

Live acceptance is intentionally carried to final project/release acceptance by owner decision on 2026-08-08. The requirement remains mandatory for `1.0.0`.

## Parallel Phase 0.5 accepted financial boundaries

Phase `0.5.0` / Issue `#8` is not closed. The following bounded foundations are evidence-complete and reusable.

### 1 — Financial Ledger Foundation

- implementation `259e29e6f93c2b36cd36c2f40bf669789eaab62f`, CI `31232006308` / `#1037`, 326 / 1777;
- evidence `76c00a1c458c12ccc07dc658ee2f69e14389e65c`, CI `31232151035` / `#1039`;
- `evidence/0.5.0/financial-ledger-foundation.md`;
- `docs/45-phase-0.5-financial-ledger-traceability.md`.

Accepted: integer IRR, balanced append-only ledger, account constraints, exact replay/conflict and DB immutability.

### 2 — Wallet Holds / Available Balance / Capture / Release

- implementation `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`, CI `31232610814` / `#1046`, 331 / 1834;
- evidence `781a2dd63d999b2e01d99cd6888f3c9cbfda9f26`, CI `31232750290` / `#1048`;
- `evidence/0.5.0/wallet-holds-capture-release.md`;
- `docs/46-phase-0.5-wallet-holds-traceability.md`.

Accepted: authoritative available balance = immutable ledger state minus active holds, exact hold replay/conflict, one capture effect, release without ledger rewrite and terminal hold history.

### 3 — Wallet Reconciliation Snapshots / Expired-Hold Cleanup

- implementation `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`, CI `31233135374` / `#1058`, 334 / 1886;
- evidence `57719972291b0553e76da6f8db50a8190a807044`, CI `31233274800` / `#1060`;
- `evidence/0.5.0/wallet-reconciliation-snapshots.md`;
- `docs/48-phase-0.5-wallet-reconciliation-traceability.md`.

Accepted: append-only non-authoritative snapshots, fresh ledger+active-hold reconciliation, fail-closed inconsistent state and bounded cleanup/review. Transfer holds are deliberately excluded from generic cleanup.

### 4 — Wallet Maintenance Operations

- implementation `87392cca0a1a00ee87a1b5074386dd691a4094c6`, CI `31233514751` / `#1066`, 338 / 1905;
- evidence `fa0cc2056459f171c32e0260422a465891766629`, CI `31233817131` / `#1068`;
- `evidence/0.5.0/wallet-maintenance-operations.md`;
- `docs/49-phase-0.5-wallet-maintenance-traceability.md`.

Accepted: bounded aggregate-safe maintenance command and single-Scheduler execution every five minutes with overlap/single-server guards.

### 5 — Stable Wallet Transfer (`WAL-003`)

Implementation:

- SHA `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`;
- CI `31235232052` / `#1084` — success;
- 346 tests / 1995 assertions;
- artifact ID `9015155851`;
- digest `sha256:c85e5106f95e6c37745dbcf920d3728108d7f82f26624c6cfccc0684f5bb265b`.

Evidence:

- SHA `68f06fbd4ae9bb1bdba004968e57c84a13871e15`;
- CI `31235552266` / `#1092` — success;
- 346 tests / 1995 assertions;
- artifact ID `9015258508`;
- digest `sha256:f1bee9300338b0d326e9c0cf47ea978147a6e72688e598c0717f7ae180b29a78`;
- `evidence/0.5.0/wallet-transfer.md`;
- `docs/51-phase-0.5-wallet-transfer-traceability.md`.

Accepted: default-disabled policy, stable recipient identity, execution-time eligibility, integer policy/fee snapshots, hold-based prepare with no transfer posting, one atomic sender/recipient/fee effect at confirmation, exact replay/conflict, terminal cancellation/expiry and DB-enforced immutable/terminal state.

## Next bounded work

`docs/52-current-continuation-handoff.md` defines the next recommended independent increment: **Dedicated Wallet Contention Verification** for the remaining explicit `WAL-002` proof.

Required concurrency evidence covers same-wallet over-reservation, capture/release races, duplicate ledger command keys, duplicate transfer prepare/confirm and reconciliation concurrent with mutation. Prefer deterministic subprocess/barrier coordination against real MariaDB; do not weaken locking/isolation to make tests pass.

After contention verification, recommended sequence is `WAL-004` refund/reversal, `WAL-005` correction/approval, Payment Intent + `WAL-001`, pricing/Quote, promotions/referrals/agent pricing and then payment providers.

## Explicit open items

- protected PasarGuard live run and subsequent coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- dedicated multi-process wallet contention verification;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- `WAL-001`, `WAL-004`, `WAL-005`, pricing/Quote/promotions/payment providers;
- all Phase `0.6.0+` owned behavior.

## Non-negotiable delivery controls

- PR `#6` stays Draft; no merge, Ready, auto-merge, history rewrite, force-push, temporary branch or direct `main` write;
- live head always comes from PR `#6`;
- no phase/increment closes from docs/schema/fake/interface presence alone;
- accepted bounded work requires exact implementation CI/artifact and exact evidence-head CI/artifact;
- real provider lookup/create/equivalence/uncertainty/TLS/redaction/Target controls cannot be weakened by later phases;
- monetary IRR remains integer; immutable balanced ledger plus active holds remains financial authority; persisted snapshots are derived only;
- no retry/conflict can create or overwrite a second accepted primary financial or remote effect;
- secrets/protected provider material never enter repository text, Issues, PR comments, evidence or ordinary logs.

Phase `0.4.0` remains active/open and Phase `0.5.0` remains incomplete despite the accepted parallel foundations above.
