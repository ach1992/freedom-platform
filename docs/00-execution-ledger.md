# Execution Ledger

The authoritative product contract is `docs/specification/master-execution-prompt.md`. This ledger records accepted delivery boundaries and current continuation state; it does not replace the product contract.

For continuation, read `AGENTS.md`, `PROJECT_STATUS.md`, `docs/project-status.json`, `docs/development/continuation-runbook.md`, and `docs/52-current-continuation-handoff.md`. Always live-fetch Draft PR `#6` for the exact current head before every repository write.

## Current position

- working branch: `develop/v1.0.0-completion`;
- authoritative integration PR: Draft `#6`, base `main`;
- active phase: `0.4.0 — Catalog, Panels and Offerings`, Issue `#7`;
- active Phase 0.4 increment: **PasarGuard Controlled Live Execution**;
- default-branch dispatch bootstrap: Draft PR `#24`, `ops/provider-live-dispatch-bootstrap` → `main`, unresolved dependency-policy failure;
- safety snapshot `safety/main-2026-08-08-pre-provider-bootstrap` remains retained while PR `#24` is unresolved;
- Marzban `v0.8.4` deployment acceptance remains a final-release gate;
- Phase `0.5.0` / Issue `#8` remains open/not active, with **ten** accepted bounded foundations through deterministic Pricing / immutable Quote (`BUY-002`);
- next recommended independent work: **stored promotion/referral/pricing-rule resolution foundation** (`PRO-001` / bounded `REF-001` inputs), then `AGT-005`, then `PAY-001`/providers;
- current cross-phase handoff: `docs/52-current-continuation-handoff.md`.

`main` remains unchanged by the bootstrap/completion stream at the last inspected bootstrap base `1227cce28aedd2d799f2cd510891309deaacd0fb`. Phase `0.4.0` and Issue `#7` remain open; no provider Target is live-accepted or enabled from offline/harness evidence alone.

## Completed phases

### Phase 0.1.0 — Product specification and architecture

Completed/verified planning baseline.

### Phase 0.2.0 — Foundation, installer/runtime, operations and Telegram ingress

Completed/verified. Closure evidence: `evidence/0.2.0/PHASE-CLOSURE.md`.

### Phase 0.3.0 — Identity, Customers, Agents and ACL

- closure `979b0c99d79dbcc8cff273ccdfad2ea612796a0c`;
- CI `31035712555` / `#798` — success;
- 194 tests / 958 assertions;
- evidence `evidence/0.3.0/phase-closure-verification.md`.

## Phase 0.4 accepted chain and live gates

Accepted Phase 0.4 increments include Catalog/Product/Variant, Panel Connection/Target/Protocol inventory, Plan Offering, capacity/routing/fallback, Custom Plan, Trial/Fake adapter, pinned Marzban/PasarGuard contracts, Create-Equivalence Reconciliation, and the PasarGuard guarded live-acceptance harness.

Latest active-phase evidence-complete boundary:

- PasarGuard harness implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2`, CI `31226863010` / `#1013`;
- evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412`, CI `31227084007` / `#1015`;
- 317 tests / 1730 assertions;
- artifact `test-evidence-31227084007`, ID `9012490991`;
- digest `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`;
- evidence `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- traceability `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

### Current provider bootstrap boundary

Draft PR `#24` exists solely to make the guarded PasarGuard workflow available from the default branch without direct-pushing `main`.

Last inspected bootstrap state:

- head `f2d2b6d538b16fe08787f6248ec425dbd19c8321`;
- CI `31240183151` / `#1129`;
- Repository preflight — success;
- Secret scan — success;
- PHP static quality — success;
- MariaDB and Redis tests — success;
- Dependency and license policy — **failure** at `composer audit --locked --abandoned=fail`.

Do not merge PR `#24` or delete its branch/safety snapshot from this unresolved state. Resolve or deliberately replace the bootstrap path first. After bootstrap acceptance, protected manual PasarGuard dispatch remains a human action; coordinator adoption/idempotency, controlled fault/uncertainty, and explicit Target activation are subsequent live rows.

## Parallel Phase 0.5 accepted boundaries

Phase `0.5.0` / Issue `#8` is not closed. The following bounded foundations are accepted and reusable:

### 1 — Financial Ledger Foundation

- implementation `259e29e6f93c2b36cd36c2f40bf669789eaab62f`, CI `#1037`;
- evidence `76c00a1c458c12ccc07dc658ee2f69e14389e65c`, CI `#1039`;
- evidence `evidence/0.5.0/financial-ledger-foundation.md`;
- traceability `docs/45-phase-0.5-financial-ledger-traceability.md`.

### 2 — Wallet Holds / Available Balance / Capture / Release

- implementation `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`, CI `#1046`;
- evidence `781a2dd63d999b2e01d99cd6888f3c9cbfda9f26`, CI `#1048`;
- evidence `evidence/0.5.0/wallet-holds-capture-release.md`;
- traceability `docs/46-phase-0.5-wallet-holds-traceability.md`.

### 3 — Wallet Reconciliation Snapshots / Expired-Hold Cleanup

- implementation `c73eab20e1a875db8db1c4e60d73d0e3edaff96a`, CI `#1058`;
- evidence `57719972291b0553e76da6f8db50a8190a807044`, CI `#1060`;
- evidence `evidence/0.5.0/wallet-reconciliation-snapshots.md`;
- traceability `docs/48-phase-0.5-wallet-reconciliation-traceability.md`.

### 4 — Wallet Maintenance Operations

- implementation `87392cca0a1a00ee87a1b5074386dd691a4094c6`, CI `#1066`;
- evidence `fa0cc2056459f171c32e0260422a465891766629`, CI `#1068`;
- evidence `evidence/0.5.0/wallet-maintenance-operations.md`;
- traceability `docs/49-phase-0.5-wallet-maintenance-traceability.md`.

### 5 — Stable Wallet Transfer (`WAL-003`)

- implementation `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`, CI `31235232052` / `#1084` — 346 / 1995;
- evidence `68f06fbd4ae9bb1bdba004968e57c84a13871e15`, CI `31235552266` / `#1092` — 346 / 1995;
- evidence `evidence/0.5.0/wallet-transfer.md`;
- traceability `docs/51-phase-0.5-wallet-transfer-traceability.md`.

### 6 — Dedicated Wallet Contention Verification

- implementation `903f326040c9acd0b31645fe8fae3a75f8a9fd27`, CI `31240159777` / `#1128` — 352 / 2030;
- evidence `e7a0ae17d470beb40f4933e66c7b599e0837e120`, CI `31241459956` / `#1131` — 352 / 2030;
- evidence artifact ID `9017163993`, digest `sha256:58544b56477c708b4e798b2ea83e673995e22b0ab53c19213914d1c2609af294`;
- evidence `evidence/0.5.0/wallet-contention-verification.md`;
- traceability `docs/53-phase-0.5-wallet-contention-traceability.md`.

### 7 — Wallet Refund / Reversal Foundation (`WAL-004`)

- implementation `0237f94cae67ff2ca31047af55420fed0ffe578a`, CI `31242702422` / `#1155` — 360 / 2099;
- evidence `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`, CI `31242888656` / `#1156` — 360 / 2099;
- evidence artifact `test-evidence-31242888656`, ID `9017593626`, digest `sha256:a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`;
- evidence `evidence/0.5.0/wallet-refund-reversal-foundation.md`;
- traceability `docs/54-phase-0.5-wallet-refund-traceability.md`.

### 8 — Wallet Correction / Approval Foundation (`WAL-005`)

- implementation `fd3d579d9f38004310d7ea638e351813d2f46ef5`, CI `31260299072` / `#1186` — 371 / 2197;
- evidence `ef5504081a42687eb712e9cd47306cd9dcc9a864`, CI `31260549403` / `#1188` — 371 / 2197;
- evidence artifact `test-evidence-31260549403`, ID `9022665227`, digest `sha256:ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`;
- evidence `evidence/0.5.0/wallet-correction-approval-foundation.md`;
- traceability `docs/55-phase-0.5-wallet-correction-traceability.md`.

### 9 — Payment Intent + External Cash-Wallet Top-up (`PAY-002`, `PAY-003`, `WAL-001`)

- implementation `6db9dde7032114ab81c95fdf371530997e65f21c`, CI `31265449681` / `#1214` — 384 / 2292; dedicated 13 / 95;
- evidence `76ea847d4d1f626893b925bbfe5263e1dc1398e4`, CI `31265901691` / `#1220` — 384 / 2292;
- evidence artifact `test-evidence-31265901691`, ID `9024152227`;
- independent digest `sha256:dff70424074d138f59a8c9bfb03e5196f3827142fe964b043c09ef53e15392e7`;
- evidence `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`;
- traceability `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

Accepted: authoritative settled evidence before capture, one settlement, one clearing-to-cash ledger effect, exact replay/conflict, safe bounded evidence, and independent duplicate/cross-intent contention proof. No real gateway/provider or Order/provisioning effect is claimed.

### 10 — Deterministic Pricing / Immutable Quote (`BUY-002`)

Implementation:

- SHA `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`;
- CI `31267071664` / `#1233` — all mandatory jobs success;
- full suite **392 tests / 2355 assertions**;
- dedicated Quote suite **8 / 63**;
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

Accepted: immutable Quote request/key replay and conflict, integer-IRR base/override/discount/final arithmetic, Offering version/configuration identity snapshot, explicit validity, later Offering-change stability, DB immutability/forgery guards, and zero payment/Order/provisioning side effect from Quote creation.

No complete `PRO-001`, `REF-001`, `AGT-005`, `PAY-001`, `BUY-001`, provider execution, or Phase `0.6.0` ownership is claimed.

## Next bounded work — stored promotion/referral/pricing-rule resolution

Use `docs/52-current-continuation-handoff.md` as the current contract.

First boundary:

- inspect and reuse existing promotion/referral/agent-pricing structures before adding schema;
- typed stored discount/promotion rule identity, version/configuration, state, scope, audience, integer-IRR/percentage/cap/minimum/time/limit inputs;
- deterministic qualification and precedence with explicit no-match/zero-discount result;
- immutable resolved rule/config identity suitable for future Quote integration;
- fail-closed invalid configuration;
- replay/conflict, authorization, uniqueness, immutable history, and MariaDB controls as applicable;
- tests for precedence, scope/audience/time/amount boundaries, configuration mutation stability, replay/conflict, authorization, and DB guards;
- exact implementation/evidence CI/artifact/digest lifecycle.

Do not pull complete reserve/redeem/release, referral payout/reversal, most-specific `AGT-005`, `PAY-001`, provider execution, paid Order, provisioning, or Service effects into this first rule-resolution boundary unless separately scoped and evidenced.

## Explicit open items

- PR `#24` dependency-policy failure and bootstrap decision;
- protected PasarGuard live run and subsequent coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending transfers;
- promotion/referral/pricing-rule resolution, `AGT-005`, `PAY-001`, and payment providers;
- provider-native refund behavior beyond accepted provider-independent `WAL-004`;
- all Phase `0.6.0+` owned behavior.

## Non-negotiable delivery controls

- PR `#6` stays Draft; no merge, Ready, auto-merge, history rewrite, force-push, or direct `main` write;
- do not create new temporary branches; existing bootstrap/safety branches have explicit retention/cleanup rules in the current handoff;
- live head always comes from PR `#6`;
- every Actions job remains on `freedom-staging-runner` with the canonical self-hosted selector;
- no phase/increment closes from docs/schema/fake/interface presence alone;
- exact implementation CI/artifact and exact evidence-head CI/artifact plus independent digest are mandatory;
- provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls cannot be weakened by later phases;
- monetary IRR remains integer; immutable balanced ledger plus active holds remains financial authority; persisted snapshots are derived only;
- refund/correction compensates immutable history rather than mutating it;
- browser return never proves capture and no paid provisioning precedes authoritative capture;
- no retry/conflict may create or overwrite a second accepted financial or remote effect;
- secrets/protected provider material never enter repository text, Issues, PR comments, evidence, logs, or chat.

Phase `0.4.0` remains active/open and Phase `0.5.0` remains incomplete despite the ten accepted parallel foundations above.
