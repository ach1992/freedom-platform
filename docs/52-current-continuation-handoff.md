# Current Continuation Handoff

**Status:** authoritative continuation checkpoint after accepted `BUY-002` deterministic Pricing / immutable Quote snapshot.  
**Date:** 2026-08-09.  
**Integration PR:** Draft PR `#6`, base `main`, head branch `develop/v1.0.0-completion`.  
**Live-head rule:** before every repository write, fetch PR `#6` and use its exact `head_sha`; never continue from a copied SHA.

## Read first in a new session

Read in this order before changing anything:

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/continuation-runbook.md`;
5. `docs/development/github-actions-runner-policy.md`;
6. this file;
7. active Phase 0.4 gate: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` and `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
8. current overlays: `docs/32-current-traceability-overlay.md` and `docs/33-current-risk-overlay.md`;
9. latest accepted Phase 0.5 boundary: `evidence/0.5.0/quote-pricing-snapshot.md` and `docs/57-phase-0.5-quote-pricing-traceability.md`;
10. Issue `#8` recent comments when continuing the parallel Phase 0.5 chain.

Then fetch exact-head workflow runs and inspect every mandatory job before deciding whether the next action is code, documentation, infrastructure, or a human-only blocker.

## Repository invariants

- PR `#6` stays open and Draft; base `main`, head `develop/v1.0.0-completion`;
- do not merge PR `#6`, mark Ready, enable auto-merge, rewrite history, force-push, or push directly to `main`;
- do not create new temporary branches;
- the existing `ops/provider-live-dispatch-bootstrap` and `safety/main-2026-08-08-pre-provider-bootstrap` branches are explicit retained bootstrap/safety exceptions, not permission to create more branches;
- every Actions job uses `runs-on: [self-hosted, Linux, X64, freedom-staging, php84]` and expected runner `freedom-staging-runner`;
- every accepted bounded increment requires exact implementation CI/artifact plus exact evidence-head CI/artifact and an independently recalculated digest;
- secrets, credentials, API keys, passwords, provider payloads, subscription material, and private files never enter repository text, Issues, PR comments, evidence, logs, or chat;
- no later work may weaken provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls;
- IRR is integer; immutable finalized balanced ledger history plus active holds is wallet authority; persisted wallet snapshots remain derived evidence/cache only;
- refund/correction uses compensating immutable history rather than editing prior ledger entries;
- browser return/customer submission never proves payment capture;
- no paid provisioning occurs before authoritative capture;
- Order/provisioning/Service execution remains Phase `0.6.0` ownership unless a separately bounded shared foundation explicitly says otherwise.

## Active Phase 0.4 human-controlled gate

Phase `0.4.0` / Issue `#7` remains the authoritative active phase and is **not closed**.

### PasarGuard `v5.2.1`

The guarded live-acceptance harness is evidence-complete. Actual deployment execution still needs the default-branch workflow bootstrap and then a manual guarded dispatch.

Current bootstrap state last inspected:

- Draft PR `#24`: `ops/provider-live-dispatch-bootstrap` → `main`;
- head `f2d2b6d538b16fe08787f6248ec425dbd19c8321`;
- three-file scope: provider workflow, self-hosted `main` CI adaptation, and self-hosted toolchain bootstrap script;
- CI `31240183151` / `#1129`:
  - Repository preflight — success;
  - Secret scan — success;
  - PHP static quality — success;
  - MariaDB and Redis tests — success;
  - Dependency and license policy — **failure** because `composer audit --locked --abandoned=fail` exited non-zero;
- `main` remains unchanged at bootstrap base `1227cce28aedd2d799f2cd510891309deaacd0fb`;
- the connector has not merged PR `#24` and no direct `main` write is authorized.

Do not broaden PR `#24` casually just to make it green. Diagnose the dependency audit against the old `main` lock state and choose a deliberate bootstrap resolution before merge.

Once the bootstrap is accepted on `main`, the owner must manually dispatch `Provider Live Acceptance - PasarGuard` on branch `develop/v1.0.0-completion` with exact confirmation:

`MUTATE_DISPOSABLE_PASARGUARD_V5_2_1`

Protected secret values must remain external to repository/chat. After the guarded run, coordinator adoption/idempotency, controlled timeout/5xx/429 uncertainty, and explicit Target activation remain separate live rows.

### Marzban `v0.8.4`

Deployment-specific live acceptance is intentionally carried to final release acceptance and remains mandatory for `1.0.0`.

## Bootstrap/safety branch retention and cleanup

Both existing branches are still needed **now**:

- `ops/provider-live-dispatch-bootstrap` is the head of open Draft PR `#24` and contains the unresolved default-branch dispatch bootstrap;
- `safety/main-2026-08-08-pre-provider-bootstrap` preserves the exact pre-bootstrap `main` state while PR `#24` remains unresolved.

Do not delete either branch until one of these conditions is explicitly reached:

1. PR `#24` is deliberately merged, default-branch workflow availability is verified, and the safety snapshot is no longer required; or
2. PR `#24` is deliberately abandoned/replaced by another accepted bootstrap path, with rollback state preserved elsewhere.

The currently available GitHub connector does not expose branch-ref deletion. When cleanup is actually safe, deletion will require GitHub UI or an authenticated local command such as `git push origin --delete <branch>` after re-verifying the condition above.

## Accepted parallel Phase 0.5 chain

Phase `0.5.0` / Issue `#8` is not closed. Ten bounded foundations are accepted:

1. Financial Ledger Foundation — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. Wallet Holds / Available Balance / Capture / Release — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. Wallet Maintenance Operations — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. Stable Wallet Transfer (`WAL-003`) — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. Dedicated Wallet Contention Verification — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. Wallet Refund / Reversal Foundation (`WAL-004`) — `docs/54-phase-0.5-wallet-refund-traceability.md`;
8. Wallet Correction / Approval Foundation (`WAL-005`) — `docs/55-phase-0.5-wallet-correction-traceability.md`;
9. Payment Intent / External Cash-Wallet Top-up (`PAY-002`, `PAY-003`, `WAL-001`) — `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`;
10. Deterministic Pricing / Immutable Quote (`BUY-002`) — `docs/57-phase-0.5-quote-pricing-traceability.md`.

## Latest accepted boundary — `BUY-002`

Implementation:

- SHA `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`;
- CI `31267071664` / `#1233` — all mandatory jobs success;
- full suite **392 tests / 2355 assertions**;
- dedicated Quote suite **8 tests / 63 assertions**;
- artifact `test-evidence-31267071664`, ID `9024501375`;
- independent SHA-256 `899a26d7553f4fd37c2102da986fc876f0a8e8151b683ff36e2f300acb9ad754`.

Evidence:

- SHA `07840d417e64eb30bf31f17bb9e26c1fe549eef3`;
- CI `31267346707` / `#1236` — all mandatory jobs success;
- full suite **392 / 2355**;
- dedicated Quote suite **8 / 63**;
- artifact `test-evidence-31267346707`, ID `9024577868`, size `116261` bytes;
- independent SHA-256 `ba2710a544ccd07c72a56c616f7215406175ca8fdc93bc770c47ad474b275e83`.

Independent evidence-head inspection found exactly JUnit, full test log, Clover coverage, compose state, and compose log. JUnit confirms zero failures/errors/skips globally and for the Quote suite. A bounded safe scan found no known CI credential values, known CI application-key payload, bearer/basic authorization values, PasarGuard protected-secret values, or private-key headers.

Accepted `BUY-002` behavior:

- immutable caller Quote key and canonical request binding;
- exact replay returns the prior Quote, materially changed reuse conflicts;
- integer-IRR base/override/discount/final arithmetic with override before discount;
- Offering ID/code/version/configuration-hash and bounded configuration snapshot;
- current tier/agent reference validation for explicitly resolved override inputs;
- explicit validity/expiry;
- later Offering mutation cannot reinterpret an accepted Quote;
- database update/delete/forged-snapshot rejection;
- Quote creation causes no wallet, Payment Intent, provider, paid-Order, provisioning, or Service effect.

No `BUY-001`, complete `PRO-001`, `REF-001`, `AGT-005`, `PAY-001`, provider execution, or Phase `0.6.0` behavior is claimed.

## Current bounded increment — promotion/referral/pricing-rule resolution foundation

The next independent Phase `0.5.0` increment should establish deterministic stored rule resolution before integrating those rules into future Quote or payment execution.

Start with a deliberately bounded subset around `PRO-001` and `REF-001`:

1. inspect existing discount/referral/agent-pricing models, migrations, policies, and tests before introducing new schema;
2. define typed stored promotion/discount rule identity, version/configuration identity, state, scope, audience, amount/percentage/cap/minimum/time/limit inputs using integer IRR only;
3. define deterministic qualification/resolution with explicit precedence and fail-closed invalid configuration;
4. preserve zero-discount/no-match as an explicit result rather than an implicit mutable fallback;
5. snapshot the resolved rule/config identity so a later Quote can consume it without reinterpreting historical pricing;
6. define replay/conflict, uniqueness, authorization, immutable history, and MariaDB transaction/locking constraints where mutable counters/reservations are introduced;
7. add focused tests for precedence, scope, audience, time/amount bounds, configuration mutation stability, replay/conflict, invalid rule state, authorization, and DB guards;
8. keep full redemption/reserve/release, referral reward payout/reversal, most-specific `AGT-005`, `PAY-001`, provider execution, Order, and provisioning outside this first rule-resolution boundary unless separately scoped and evidenced;
9. use the exact implementation/evidence CI/artifact/digest lifecycle before calling the boundary accepted.

After this foundation, continue to most-specific `AGT-005` agent pricing, then `PAY-001` payment-method eligibility/provider implementations and provider-native refund integration.

## Current open items

- PR `#24` dependency-policy failure and bootstrap decision;
- PasarGuard protected live run plus coordinator/fault/Target-activation rows;
- Marzban final-release live acceptance;
- Phase `0.4.0` closure audit;
- no automated scheduled sweep is claimed for untouched expired pending wallet transfers;
- current promotion/referral/pricing-rule resolution foundation;
- `AGT-005`, `PAY-001`, payment providers, and provider-native refund behavior;
- all Phase `0.6.0+` owned behavior.

## Mandatory continuation order

In a fresh chat/session:

1. live-fetch PR `#6` and verify open/Draft/base/head;
2. read the files listed at the top of this handoff;
3. inspect exact-head CI and reconcile any project-control drift first;
4. inspect PR `#24` only if working the active provider bootstrap; do not merge it from stale assumptions;
5. if the PasarGuard human gate is not immediately executable, continue the bounded Phase 0.5 promotion/referral/pricing-rule resolution increment above;
6. before every write, re-fetch PR `#6` and use its exact new head;
7. before stopping, update `PROJECT_STATUS.md`, this handoff, overlays, ledger, evidence/traceability, and the relevant Issue comment.

No new session should rely on prior chat memory or any SHA copied from this file as the current head.
