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
- Phase `0.5.0` / Issue `#8` remains open/not active, but eight bounded financial foundations through `WAL-005` correction/approval are evidence-complete under the parallel-continuation policy;
- next recommended independent work: **Payment Intent + `WAL-001` External Cash-Wallet Top-up Settlement**;
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

## Phase 0.4 accepted chain and live gates

Accepted Phase 0.4 increments include Catalog/Product/Variant, Panel Connection/Target/Protocol inventory, Plan Offering, capacity/routing/fallback, Custom Plan, Trial/Fake adapter, pinned Marzban/PasarGuard read/mutation contracts, Create-Equivalence Reconciliation, and the PasarGuard guarded live-acceptance harness.

Latest active-phase evidence-complete boundary:

- PasarGuard harness implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2`, CI `#1013`;
- evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412`, CI `#1015`;
- 317 tests / 1730 assertions;
- evidence `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- traceability `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

PasarGuard actual deployment execution requires protected repository Actions Secrets plus manual dispatch; coordinator adoption/idempotency, controlled fault/uncertainty and explicit Target activation remain separate live rows. Marzban deployment-specific live acceptance is intentionally carried to final release acceptance.

## Parallel Phase 0.5 accepted financial boundaries

Phase `0.5.0` / Issue `#8` is not closed. These bounded foundations are evidence-complete and reusable:

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

- implementation `903f326040c9acd0b31645fe8fae3a75f8a9fd27`, CI `31240159777` / `#1128` — 352 tests / 2030 assertions;
- evidence `e7a0ae17d470beb40f4933e66c7b599e0837e120`, CI `31241459956` / `#1131` — 352 / 2030;
- evidence artifact ID `9017163993`, digest `sha256:58544b56477c708b4e798b2ea83e673995e22b0ab53c19213914d1c2609af294`;
- `evidence/0.5.0/wallet-contention-verification.md`;
- `docs/53-phase-0.5-wallet-contention-traceability.md`.

Accepted: independent-process MariaDB proof for same-wallet over-reservation, capture/release terminal races, duplicate ledger/transfer commands and reconciliation racing mutation.

### 7 — Wallet Refund / Reversal Foundation (`WAL-004`)

- implementation `0237f94cae67ff2ca31047af55420fed0ffe578a`, CI `31242702422` / `#1155` — 360 tests / 2099 assertions;
- evidence `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`, CI `31242888656` / `#1156` — 360 / 2099;
- evidence artifact `test-evidence-31242888656`, ID `9017593626`, digest `sha256:a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`;
- `evidence/0.5.0/wallet-refund-reversal-foundation.md`;
- `docs/54-phase-0.5-wallet-refund-traceability.md`.

Accepted: immutable capture-time refundability, partial/cumulative caps, exact original-wallet-bucket compensation, manual-external evidence without duplicate wallet credit, privileged destination override, exact replay/conflict and MariaDB over-refund/duplicate-key concurrency proof.

### 8 — Wallet Correction / Approval Foundation (`WAL-005`)

Implementation:

- SHA `fd3d579d9f38004310d7ea638e351813d2f46ef5`;
- CI `31260299072` / `#1186` — success;
- 371 tests / 2197 assertions;
- dedicated correction tests `11 / 98`;
- artifact `test-evidence-31260299072`, ID `9022602206`;
- digest `sha256:476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`.

Evidence:

- SHA `ef5504081a42687eb712e9cd47306cd9dcc9a864`;
- CI `31260549403` / `#1188` — success;
- 371 / 2197;
- artifact `test-evidence-31260549403`, ID `9022665227`;
- independent digest `sha256:ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`;
- `evidence/0.5.0/wallet-correction-approval-foundation.md`;
- `docs/55-phase-0.5-wallet-correction-traceability.md`.

Accepted: immutable correction preview/confirmation, fresh balance/hold authority, policy-driven execution-time authorization and independent approval, negative-availability denial, exact approval/correction replay, immutable compensating ledger effects, DB guards and independent-process debit/duplicate/approval contention proof.

## Next bounded work — Payment Intent + `WAL-001`

Use `docs/52-current-continuation-handoff.md` as the current contract.

Initial provider-independent scope:

- reuse existing `PaymentIntentState` and `PaymentProvider` contracts;
- immutable top-up intent creation key + canonical payload hash;
- one active owned cash-wallet target and positive integer IRR amount;
- browser/user return never proves payment;
- normalized authoritative provider evidence is mandatory for capture;
- unique provider event/transaction identity and one captured settlement per intent;
- exactly one balanced ledger top-up credit after authoritative capture;
- transaction/locks/uniqueness plus exact replay/conflict;
- no Order/provisioning/service effect in this top-up-only boundary;
- safe append-only payment/evidence/audit history;
- independent-process duplicate-capture proof;
- exact implementation and evidence CI/artifact/digest lifecycle.

After `WAL-001`: pricing/Quote, promotions/referrals/agent pricing, then payment providers/provider-native refunds.

## Explicit open items

- protected PasarGuard live run and subsequent coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- `WAL-001`, pricing/Quote/promotions/payment providers;
- provider-native refund behavior beyond accepted `WAL-004`;
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
- browser return never proves capture and no paid provisioning precedes authoritative capture;
- no retry/conflict may create or overwrite a second accepted financial or remote effect;
- secrets/protected provider material never enter repository text, Issues, PR comments, evidence or ordinary logs.

Phase `0.4.0` remains active/open and Phase `0.5.0` remains incomplete despite the accepted parallel foundations above.
