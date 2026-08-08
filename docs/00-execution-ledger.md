# Execution Ledger

The authoritative product contract is `docs/specification/master-execution-prompt.md`. This ledger records accepted delivery boundaries and current continuation state; it does not replace the product contract.

For continuation, read `AGENTS.md`, `PROJECT_STATUS.md`, `docs/project-status.json`, `docs/development/continuation-runbook.md`, and `docs/52-current-continuation-handoff.md`. Always live-fetch Draft PR `#6` for the exact current head before every repository write.

## Current position

- branch: `develop/v1.0.0-completion`;
- PR: Draft `#6`, base `main`;
- active phase: `0.4.0 — Catalog, Panels and Offerings`;
- authoritative Phase 0.4 Issue: `#7`;
- active Phase 0.4 increment: **PasarGuard Controlled Live Execution**, blocked on protected Actions Secrets plus manual workflow dispatch;
- Marzban `v0.8.4` deployment live acceptance: owner-carried to final project/release acceptance, not removed;
- Phase `0.5.0` / Issue `#8` remains open/not active, but six bounded financial foundations through dedicated wallet contention are evidence-complete under the parallel-continuation policy;
- next recommended independent work: **`WAL-004` Refund / Reversal Foundation**;
- current cross-phase handoff: `docs/52-current-continuation-handoff.md`.

`main` remains unchanged by this completion stream. Phase `0.4.0` and Issue `#7` remain open; no provider Target is live-accepted or enabled from offline/harness evidence alone.

## Completed phases

### Phase 0.1.0 — Product specification and architecture

Completed/verified planning baseline.

### Phase 0.2.0 — Foundation, installer/runtime, operations and Telegram ingress

Completed/verified. Closure evidence: `evidence/0.2.0/PHASE-CLOSURE.md`.

### Phase 0.3.0 — Identity, Customers, Agents and ACL

Completed/verified:

- closure `979b0c99d79dbcc8cff273ccdfad2ea612796a0c`;
- CI `31035712555` / `#798` — success;
- 194 tests / 958 assertions;
- evidence `evidence/0.3.0/phase-closure-verification.md`.

## Phase 0.4 accepted chain

Detailed proof remains in bounded evidence/traceability files. Accepted increments include Catalog/Product/Variant, Panel Connection/Target/Protocol inventory, Plan Offering, capacity/routing/fallback, Custom Plan, Trial/Fake adapter, pinned Marzban/PasarGuard read/mutation contracts, Create-Equivalence Reconciliation, and the PasarGuard guarded live-acceptance harness.

Latest active-phase evidence-complete boundary:

- PasarGuard harness implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2`, CI `#1013`;
- evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412`, CI `#1015`;
- 317 tests / 1730 assertions;
- evidence `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- traceability `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

The provider chain proves only the source/offline/harness scope stated by each boundary. Protected deployment execution remains mandatory where documented.

## Current Phase 0.4 live gates

### PasarGuard `v5.2.1`

Execution authority:

- `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
- `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`;
- `.github/workflows/provider-live-acceptance.yml`.

Actual execution requires protected repository Actions Secrets configured outside repository text, then manual workflow dispatch. The available connector cannot create/update those Secrets or initiate a fresh dispatch. After the guarded sequence, coordinator adoption/idempotency, controlled fault/uncertainty and explicit Target activation remain separate live rows.

### Marzban `v0.8.4`

Deployment-specific live acceptance is intentionally carried to final project/release acceptance. The requirement remains mandatory for `1.0.0`.

## Parallel Phase 0.5 accepted financial boundaries

Phase `0.5.0` / Issue `#8` is not closed. The following bounded foundations are evidence-complete and reusable.

### 1 — Financial Ledger Foundation

- implementation `259e29e6f93c2b36cd36c2f40bf669789eaab62f`, CI `#1037`;
- evidence `76c00a1c458c12ccc07dc658ee2f69e14389e65c`, CI `#1039`;
- `evidence/0.5.0/financial-ledger-foundation.md`;
- `docs/45-phase-0.5-financial-ledger-traceability.md`.

Accepted: integer IRR, balanced append-only ledger, account constraints, exact replay/conflict and DB immutability.

### 2 — Wallet Holds / Available Balance / Capture / Release

- implementation `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`, CI `#1046`;
- evidence `781a2dd63d999b2e01d99cd6888f3c9cbfda9f26`, CI `#1048`;
- `evidence/0.5.0/wallet-holds-capture-release.md`;
- `docs/46-phase-0.5-wallet-holds-traceability.md`.

Accepted: authoritative available balance = immutable ledger state minus active holds, one capture effect, release without ledger rewrite and terminal hold history.

### 3 — Wallet Reconciliation Snapshots / Expired-Hold Cleanup

- implementation `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`, CI `#1058`;
- evidence `57719972291b0553e76da6f8db50a8190a807044`, CI `#1060`;
- `evidence/0.5.0/wallet-reconciliation-snapshots.md`;
- `docs/48-phase-0.5-wallet-reconciliation-traceability.md`.

Accepted: append-only non-authoritative snapshots, fresh ledger+active-hold reconciliation and bounded fail-closed cleanup/review.

### 4 — Wallet Maintenance Operations

- implementation `87392cca0a1a00ee87a1b5074386dd691a4094c6`, CI `#1066`;
- evidence `fa0cc2056459f171c32e0260422a465891766629`, CI `#1068`;
- `evidence/0.5.0/wallet-maintenance-operations.md`;
- `docs/49-phase-0.5-wallet-maintenance-traceability.md`.

Accepted: bounded maintenance command and single-Scheduler execution with overlap/single-server guards.

### 5 — Stable Wallet Transfer (`WAL-003`)

- implementation `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`, CI `31235232052` / `#1084` — 346 tests / 1995 assertions;
- evidence `68f06fbd4ae9bb1bdba004968e57c84a13871e15`, CI `31235552266` / `#1092` — 346 / 1995;
- `evidence/0.5.0/wallet-transfer.md`;
- `docs/51-phase-0.5-wallet-transfer-traceability.md`.

Accepted: stable recipient, default-disabled policy, hold-only prepare, execution-time revalidation, atomic sender/recipient/fee posting, exact replay/conflict and terminal cancellation/expiry.

### 6 — Dedicated Wallet Contention Verification

Implementation verification:

- SHA `903f326040c9acd0b31645fe8fae3a75f8a9fd27`;
- CI `31240159777` / `#1128` — success;
- 352 tests / 2030 assertions;
- dedicated contention class 6 tests / 35 assertions;
- artifact `test-evidence-31240159777`, ID `9016771279`;
- digest `sha256:4f32e7a5c7e4cc23b985b13aa2b6f291772b75c1f9a26cedcd620b9589b973b2`.

Evidence head:

- SHA `e7a0ae17d470beb40f4933e66c7b599e0837e120`;
- CI `31241459956` / `#1131` — success;
- 352 tests / 2030 assertions;
- artifact `test-evidence-31241459956`, ID `9017163993`;
- digest `sha256:58544b56477c708b4e798b2ea83e673995e22b0ab53c19213914d1c2609af294`;
- `evidence/0.5.0/wallet-contention-verification.md`;
- `docs/53-phase-0.5-wallet-contention-traceability.md`.

Accepted: real independent-process MariaDB proof for same-wallet over-reservation, capture/release terminal races, duplicate ledger command, duplicate transfer prepare/confirm and reconciliation racing mutation. No locking/isolation weakening was introduced.

## Next bounded work — `WAL-004` Refund / Reversal Foundation

Use `docs/52-current-continuation-handoff.md` as the current contract.

Initial provider-independent scope:

- immutable refund record with unique refund key + canonical payload hash;
- lock finalized refundable source before authorizing a refund;
- integer partial amounts and cumulative refundable-cap enforcement under concurrency;
- explicit wallet/manual-external destination policy;
- wallet refund posts a compensating balanced ledger transaction to the original eligible wallet bucket/account;
- manual external refund requires sanitized evidence/reference and must not also manufacture wallet credit;
- exact replay/conflict and destination-override permission/reason/audit;
- DB immutability/terminal guards;
- deterministic multi-process concurrent partial/duplicate refund proof.

Do not pull provider-native refund APIs, Payment Intent state, exact-card-adjustment metadata or Order state ownership into this first boundary.

After refund: `WAL-005`, Payment Intent + `WAL-001`, pricing/Quote, promotions/referrals/agent pricing, then payment providers.

## Explicit open items

- protected PasarGuard live run and subsequent coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- `WAL-001`, `WAL-004`, `WAL-005`, pricing/Quote/promotions/payment providers;
- all Phase `0.6.0+` owned behavior.

## Non-negotiable delivery controls

- PR `#6` stays Draft; no merge, Ready, auto-merge, history rewrite, force-push, temporary branch or direct `main` write;
- live head always comes from PR `#6`;
- every Actions job remains on `freedom-staging-runner` with the canonical self-hosted selector;
- no phase/increment closes from docs/schema/fake/interface presence alone;
- exact implementation CI/artifact and exact evidence-head CI/artifact are mandatory;
- provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls cannot be weakened by later phases;
- monetary IRR remains integer; immutable balanced ledger plus active holds remains financial authority; persisted snapshots are derived only;
- refund/correction must compensate immutable history rather than mutate it;
- no retry/conflict may create or overwrite a second accepted financial or remote effect;
- secrets/protected provider material never enter repository text, Issues, PR comments, evidence or ordinary logs.

Phase `0.4.0` remains active/open and Phase `0.5.0` remains incomplete despite the accepted parallel foundations above.
