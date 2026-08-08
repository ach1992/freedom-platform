# Current Continuation Handoff

**Status:** authoritative continuation checkpoint after accepted `BUY-002` and during the isolated multi-agent governance migration.  
**Date:** 2026-08-09.  
**Integration PR:** Draft PR `#6`, base `main`, head branch `develop/v1.0.0-completion`.  
**Live-head rule:** before every MASTER repository write or Worker dispatch, fetch PR `#6` and use its exact live `head_sha`; never continue from a copied SHA.

## Read first in a new MASTER session

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/multi-agent-orchestration.md`;
5. `docs/development/continuation-runbook.md`;
6. `docs/development/github-actions-runner-policy.md` and `docs/development/ci-runner-contract.md`;
7. this file;
8. active Phase 0.4 gate: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` and `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
9. current overlays: `docs/32-current-traceability-overlay.md` and `docs/33-current-risk-overlay.md`;
10. latest accepted Phase 0.5 boundary: `evidence/0.5.0/quote-pricing-snapshot.md` and `docs/57-phase-0.5-quote-pricing-traceability.md`;
11. Issues `#7` and `#8`, open PRs including `#24`, active `agent/*` branches, and any Task Contract Issues/Worker PRs.

Then inspect exact-head CI before deciding whether the next action is governance, Worker dispatch, review, integration, evidence or a human-only blocker.

## Integration and Worker invariants

- PR `#6` remains open and Draft; base `main`, head `develop/v1.0.0-completion`;
- never merge PR `#6`, mark it Ready, enable auto-merge, rewrite history, force-push or push directly to `main`;
- substantial implementation uses contracted `agent/<issue-number>-<short-slug>` branches created from a recorded live integration `BASE_SHA`;
- every implementation Worker has one Task Contract, isolated writable worktree/equivalent environment and one PR targeting `develop/v1.0.0-completion`;
- Workers never push directly to integration/main, never self-merge and never share writable worktrees;
- uncontracted temporary branches are forbidden;
- existing `ops/provider-live-dispatch-bootstrap` and `safety/main-2026-08-08-pre-provider-bootstrap` remain explicit retained exceptions until PR `#24` cleanup conditions are satisfied;
- every Actions job uses `runs-on: [self-hosted, Linux, X64, freedom-staging, php84]`;
- generic Worker PR CI is same-repository, secret-free and non-mutating;
- provider/staging/live secret workflows remain separate, manual and guarded;
- exact implementation/evidence CI, retained artifacts/digests, MASTER review, history-preserving Worker merge, and post-merge integration CI remain mandatory;
- secrets/credentials/private provider material never enter repository text, Issues, PR descriptions/comments, evidence, logs or Chat;
- no later work may weaken provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls;
- IRR remains integer; financial history is immutable; browser/customer return never proves capture; no paid provisioning precedes authoritative capture.

## Active Phase 0.4 human-controlled gate

Phase `0.4.0` / Issue `#7` remains active and is **not closed**.

PasarGuard `v5.2.1` guarded harness is accepted at implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2` / CI `31226863010` (`#1013`) and evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412` / CI `31227084007` (`#1015`), with 317 tests / 1730 assertions.

Actual deployment execution remains blocked by the default-branch bootstrap:

- Draft PR `#24`: `ops/provider-live-dispatch-bootstrap` -> `main`;
- last documented head `f2d2b6d538b16fe08787f6248ec425dbd19c8321`;
- CI `31240183151` / `#1129`: preflight, secret scan, static and MariaDB/Redis success; Dependency and license policy failed at `composer audit --locked --abandoned=fail`;
- `main` remained at bootstrap base `1227cce28aedd2d799f2cd510891309deaacd0fb`;
- the safety branch is retained.

Do not broaden or delete this bootstrap/safety path from stale assumptions. Re-fetch PR `#24` before any decision. Once bootstrap is deliberately accepted, PasarGuard live execution remains a protected manual workflow action using existing secret references; do not request their values. Coordinator adoption/idempotency, timeout/5xx/429 uncertainty and Target activation remain separate acceptance rows. Marzban `v0.8.4` deployment acceptance remains a final-release gate.

## Accepted parallel Phase 0.5 chain

Phase `0.5.0` / Issue `#8` is not closed. Accepted bounded foundations are:

1. `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. `docs/51-phase-0.5-wallet-transfer-traceability.md` (`WAL-003`);
6. `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. `docs/54-phase-0.5-wallet-refund-traceability.md` (`WAL-004`);
8. `docs/55-phase-0.5-wallet-correction-traceability.md` (`WAL-005`);
9. `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md` (`PAY-002`, `PAY-003`, `WAL-001`);
10. `docs/57-phase-0.5-quote-pricing-traceability.md` (`BUY-002`).

Latest accepted `BUY-002` boundary:

- implementation `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`, CI `31267071664` / `#1233`, **392 tests / 2355 assertions**, Quote suite **8 / 63**, artifact `test-evidence-31267071664`, ID `9024501375`, digest `899a26d7553f4fd37c2102da986fc876f0a8e8151b683ff36e2f300acb9ad754`;
- evidence `07840d417e64eb30bf31f17bb9e26c1fe549eef3`, CI `31267346707` / `#1236`, **392 / 2355**, artifact `test-evidence-31267346707`, ID `9024577868`, digest `ba2710a544ccd07c72a56c616f7215406175ca8fdc93bc770c47ad474b275e83`.

Accepted only: immutable Quote identity/request replay/conflict; integer-IRR base/override/discount/final arithmetic; Offering/configuration snapshot; explicit expiry; stable historical snapshot; DB immutability/forgery guards; no payment/provider/Order/provisioning effect.

No complete `PRO-001`, `REF-001`, `AGT-005`, `PAY-001`, `BUY-001`, provider execution or Phase `0.6.0` behavior is claimed.

## Next dependency-safe implementation boundary

The next recommended Worker increment remains **stored promotion/referral/pricing-rule resolution foundation** under parent Issue `#8`.

Scope:

1. inspect existing discount/referral/agent-pricing models, migrations, policies and tests before introducing schema;
2. define typed stored discount/promotion rule identity, version/config identity, state, scope, audience, fixed/percentage amount, cap, minimum, time and limit inputs using integer IRR;
3. define deterministic qualification/resolution and explicit fail-closed invalid configuration;
4. make no-match/zero-discount an explicit result;
5. preserve immutable resolved rule/config identity for future Quote integration so later mutable policy cannot reinterpret an accepted Quote;
6. provide replay/conflict, uniqueness, authorization, immutable-history and MariaDB correctness controls appropriate to the bounded rule-definition/resolution scope;
7. test precedence, scope, audience, time/amount bounds, configuration mutation stability, replay/conflict, invalid state, authorization and DB guards;
8. explicitly exclude full discount reserve/redeem/release, referral payout/reversal, most-specific `AGT-005`, `PAY-001`, provider execution, Order and provisioning from this first boundary;
9. use the exact implementation/evidence CI/artifact/digest lifecycle.

Because promotion/referral/pricing resolution shares a central pricing-policy/schema surface, do not split it into concurrent Workers merely to use capacity. After it merges and integration CI passes, recompute readiness for `AGT-005`, then `PAY-001`/provider work.

## Governance migration checkpoint

The repository is migrating from the legacy single-branch agent rule to the isolated Worker model. Stable policy is now defined in `docs/development/multi-agent-orchestration.md`, `AGENTS.md`, `CONTRIBUTING.md`, `PROJECT_STATUS.md`, and CI/project-control checks. Generic CI is being adapted to validate same-repository PRs targeting `develop/v1.0.0-completion` without protected secrets.

No implementation Worker may be considered READY for dispatch until the final governance migration head has mandatory green CI and project-control verification. Intermediate governance commits are not product acceptance evidence.

## Mandatory continuation order

1. live-fetch PR `#6` and inspect its current exact head/CI;
2. finish/reconcile any governance migration drift before Worker dispatch;
3. ensure generic Worker PR CI target support and same-repository/secret-free guards remain intact;
4. inspect Issue `#8` for duplicate bounded work; create a smaller Task Contract Issue only if no equivalent exists;
5. record Task Contract Revision, Worker ID, dependencies, allowed/protected scope and live `BASE_SHA` in GitHub;
6. create the Worker branch from exactly that `BASE_SHA` and provide isolated worktree setup through the human relay;
7. on `READY_FOR_REVIEW`, independently inspect the actual Worker PR; never trust the Worker summary;
8. after a safe Worker merge, re-fetch integration head, require PR `#6` integration CI and recompute dependencies/conflicts;
9. persist all dynamic assignment/review state in GitHub before ending the MASTER cycle.

A future MASTER must be able to reconstruct the program without Chat history.
