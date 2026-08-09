# Current Continuation Handoff

**Status:** MASTER transition checkpoint after accepted W-002 and W-003 integration.  
**Date:** 2026-08-09.  
**Integration PR:** Draft PR `#6`, base `main`, head branch `develop/v1.0.0-completion`.  
**Live-head rule:** before every MASTER repository write, Worker dispatch, review or merge, fetch PR `#6` and use its exact live `head_sha`; never continue from a copied SHA in this file or Chat.

## Read first in a new MASTER session

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/multi-agent-orchestration.md`;
5. `docs/development/continuation-runbook.md`;
6. `docs/development/github-actions-runner-policy.md` and `docs/development/ci-runner-contract.md`;
7. this file;
8. `docs/32-current-traceability-overlay.md` and `docs/33-current-risk-overlay.md`;
9. active Phase 0.4 gate: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md` and `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
10. latest bounded Phase 0.5 evidence/traceability, especially `docs/58-phase-0.5-promotion-referral-pricing-rule-traceability.md`, `docs/60-phase-0.5-promotion-usage-reservation-traceability.md`, `docs/phase-0.5-agent-pricing-resolution-traceability.md` and their evidence files;
11. live GitHub Issues `#7` and `#8`, Draft PR `#24`, open PRs/branches, and recent closed Worker Issues/PRs `#25/#26`, `#27/#30`, `#28/#29`.

Then inspect exact-head PR `#6` CI before deciding whether implementation dispatch is allowed.

## Transition checkpoint facts

The product integration checkpoint immediately after the W-003 merge is:

- `develop/v1.0.0-completion` product baseline `fae391569e50a2f318e2ca06aa522605d385ce2a`;
- Draft PR `#6` post-merge CI `31295225638` / `#1313` — all five mandatory jobs success;
- full suite **425 tests / 2623 assertions**;
- artifact `test-evidence-31295225638`, ID `9032748596`;
- independent SHA-256 `1014ff3150301174f4637f99528652fabd5ab197d9ac9ce9fb3bc361764ab6d4`, matching uploader digest.

After that product checkpoint the outgoing MASTER intentionally made project-control documentation/policy commits on the integration branch. Therefore **`fae391...` is not the next Worker `BASE_SHA`**. A replacement MASTER must fetch PR `#6`, discover the final transition-control head, require its latest five-job CI to be green, and use only that live accepted head for future dispatch.

No implementation Worker is intentionally active/dispatched at the transition checkpoint. Do not infer Worker state from old branches alone; reconcile GitHub Issues, PRs, comments, branches and CI.

## Integration and Worker invariants

- PR `#6` remains open and Draft; base `main`, head `develop/v1.0.0-completion`;
- never merge PR `#6`, mark it Ready, enable auto-merge, rewrite history, force-push or push directly to `main`;
- substantial implementation uses contracted `agent/<issue-number>-<short-slug>` branches created from a recorded live integration `BASE_SHA`;
- each Worker has one active Task Contract, one isolated writable worktree/equivalent environment, one branch and one PR targeting `develop/v1.0.0-completion`;
- Workers never self-merge or share writable worktrees;
- uncontracted temporary branches are forbidden;
- `ops/provider-live-dispatch-bootstrap` and `safety/main-2026-08-08-pre-provider-bootstrap` remain retained exceptions until PR `#24` cleanup conditions are satisfied;
- generic Worker CI is same-repository, self-hosted, secret-free and non-mutating;
- protected provider/live workflows remain separate, manual and guarded;
- accepted Worker increments require exact reviewed HEAD, mandatory CI/evidence, MASTER review, history-preserving merge and post-merge integration CI;
- High/Critical changes require explicit owner approval unless that exact risk was pre-authorized;
- final PR `#6` -> `main` always requires explicit owner release acceptance;
- secrets/credentials/private provider material never enter repository text, Issues, PR descriptions/comments, evidence, logs or Chat.

## Throughput mode for the replacement MASTER

The repository is now explicitly configured for safe higher throughput in `docs/development/multi-agent-orchestration.md`.

Operational direction:

- target **4–5 concurrent implementation Workers** when the fresh graph contains that many genuinely independent READY capabilities;
- preserve one active Task Contract per Worker, but make each contract a **larger coherent capability slice** rather than a micro-task where safe;
- bundle tightly coupled domain/application/schema/authorization/tests/evidence work under one Worker ownership boundary when separation creates no real parallel value;
- do not split work simply to fill Worker slots, and do not serialize truly independent READY work;
- review/integrate a Worker immediately when it becomes ready; do not wait for the rest of a wave;
- after one merge, revalidate only Workers materially affected by the changed target; avoid unnecessary rebases/target-sync churn;
- if fewer than five tasks are safely READY, stabilize the smallest shared prerequisite that unlocks the next meaningful parallel wave instead of manufacturing filler tasks;
- do not weaken financial, security, authorization, concurrency, migration, provider or release gates for speed;
- the single self-hosted runner can serialize CI while 4–5 Workers still implement concurrently.

If repeated cycles mostly produce contracts/scaffolding/evidence with little capability progress, the MASTER should enlarge the next safe Task Contracts rather than continuing orchestration fragmentation.

## Active Phase 0.4 protected/human gate

Phase `0.4.0` / Issue `#7` remains active and **not closed**.

PasarGuard `v5.2.1` guarded harness is accepted. Actual deployment execution remains behind the default-branch bootstrap:

- Draft PR `#24`: `ops/provider-live-dispatch-bootstrap` -> `main`;
- checkpoint inspection: open/Draft/mergeable, head `f2d2b6d538b16fe08787f6248ec425dbd19c8321`, base `1227cce28aedd2d799f2cd510891309deaacd0fb`;
- older CI `31240183151` / `#1129` passed preflight, secret scan, static and MariaDB/Redis but failed dependency/license policy;
- safety branch remains retained.

Do not merge, abandon, rewrite or delete this path from stale assumptions. Re-fetch PR `#24` before any decision. Once bootstrap is deliberately accepted, PasarGuard live execution remains a protected manual workflow action using existing secret references; never request values. Coordinator adoption/idempotency, timeout/5xx/429 uncertainty and Target activation remain separate acceptance rows. Marzban `v0.8.4` deployment acceptance remains a final-release gate.

## Accepted parallel Phase 0.5 chain

Phase `0.5.0` / Issue `#8` remains open. The accepted bounded chain is:

1. Financial Ledger Foundation — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. Wallet Holds / Available Balance / Capture / Release — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. Wallet Maintenance Operations — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. Stable Wallet Transfer (`WAL-003`) — `docs/51-phase-0.5-wallet-transfer-traceability.md`;
6. Dedicated Wallet Contention Verification — `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. Wallet Refund / Reversal Foundation (`WAL-004`) — `docs/54-phase-0.5-wallet-refund-traceability.md`;
8. Wallet Correction / Approval Foundation (`WAL-005`) — `docs/55-phase-0.5-wallet-correction-traceability.md`;
9. Payment Intent + external cash-wallet top-up (`PAY-002`, `PAY-003`, `WAL-001`) — `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`;
10. Deterministic Pricing / Immutable Quote (`BUY-002`) — `docs/57-phase-0.5-quote-pricing-traceability.md`;
11. W-001 stored promotion/referral pricing-rule resolution — `docs/58-phase-0.5-promotion-referral-pricing-rule-traceability.md`;
12. W-002 promotion usage reservation/capacity + explicit release — `docs/60-phase-0.5-promotion-usage-reservation-traceability.md`;
13. W-003 stored most-specific agent-pricing resolver — `docs/phase-0.5-agent-pricing-resolution-traceability.md`.

### W-001 accepted boundary

Stored typed promotion/referral pricing-rule identity and immutable versions/resolutions, deterministic qualification/precedence, explicit no-match, administrator-authorized rule management, legitimate customer/agent subject authorization, exact replay/conflict and MariaDB integrity/immutability are accepted. Caller-observed usage values are not authoritative counters. Promotion usage lifecycle and referral reward effects were not accepted by W-001.

### W-002 accepted boundary

Issue `#27` / PR `#30` Contract Revision 2 is completed. Stable `pricing_rule_id` is the authoritative promotion capacity/serialization domain across immutable versions. Reservation and explicit release, cross-user fail-closed behavior, Quote read-only binding and real-MariaDB final-slot/cross-version contention are accepted.

Do **not** claim successful-payment promotion redemption/finalization or automatic Payment Intent-driven release. The current accepted Payment Intent is authoritative for `wallet_top_up` only and cannot be repurposed as purchase authority. Genuine purchase payment is Order-bound in the target architecture.

### W-003 accepted boundary

Issue `#28` / PR `#29` Contract Revision 1 is completed. Stored/versioned agent-pricing profile/rule identity, deterministic most-specific action/offering/server/product override selection, integer IRR, equal-specificity fail-closed behavior, explicit no-match, active-agent/current-profile authorization, discount-combination snapshot and immutable accepted resolution are verified.

The exact W-003 implementation/evidence lifecycle remains documented as implementation `552fa7e30f3f575e1697f176d07fe66d0372e2ee` / CI `#1293` and evidence `cfeb56a837a6ae85c098605b7e37a532a7ddb65d` / CI `#1296`, evidence-head **407 / 2543**, artifact `9030991362`, digest `b4d4535d7fcce4d17e003f8d2b670dc29f6d74ec4d8c5daa6656ff6b51679dd7`.

Quote does **not** yet consume the W-003 resolver, so complete end-to-end `AGT-005` remains open.

## Remaining Phase 0.5 capability areas

Do not pre-assign these from this document. The new MASTER must recompute dependencies/conflicts from the live final transition head. Candidate capability areas include:

- full `AGT-005` Quote consumption/integration;
- `PAY-001` gateway/payment-method eligibility and fallback policy;
- remaining `PRO-001` successful-payment redemption/release orchestration, but only after the correct purchase-payment authority exists;
- `PRO-002` gift/service/wallet code lifecycle;
- `REF-001` reward pending/release/reversal/payout/limits/anti-abuse/notification lifecycle;
- provider/payment-method packages, callbacks, reconciliation and provider-native refund behavior;
- other Issue `#8` work proven independent by the live graph.

`BUY-001` Order creation/payment sequence, provisioning and Service lifecycle remain Phase `0.6.0` ownership. Do not pull them into Phase 0.5 solely to create Worker parallelism.

## Mandatory recovery sequence for a brand-new MASTER chat

1. invoke the `multi-agent-project-orchestrator` skill and operate as MASTER only;
2. read `AGENTS.md`, `PROJECT_STATUS.md`, `docs/project-status.json`, `docs/development/multi-agent-orchestration.md`, `docs/development/continuation-runbook.md`, this handoff and the current overlays;
3. fetch PR `#6`; verify it is open/Draft, base `main`, head `develop/v1.0.0-completion`, and record its exact live `head_sha`;
4. inspect CI associated with that exact head/PR merge candidate and require all five mandatory jobs green before implementation dispatch;
5. inspect Issues `#7` and `#8`, PR `#24`, all open Worker PRs/issues/branches, and recent completed W-001/W-002/W-003 records;
6. reconcile any stale/inconsistent GitHub or repository state; do not trust this handoff's copied SHA as live truth;
7. build the fresh dependency graph, pairwise conflict graph and risk classification for remaining Phase 0.5 work while preserving the Phase 0.4 protected gate and Phase 0.6 ownership boundary;
8. select up to five genuinely READY **coherent capability slices**, preferring 4–5 parallel Workers if safe;
9. create Task Contract Issues/comments and branches only when dispatching, all from the exact accepted live `BASE_SHA`; do not pre-create branches for blocked tasks;
10. generate exact **NEW WORKER CHAT** prompts for the human relay;
11. as Workers finish, review and integrate each ready Worker without waiting for the whole wave; after every merge, verify PR `#6` integration CI and recompute only affected readiness/conflict edges;
12. persist a durable checkpoint so another MASTER can recover with zero Chat history.

The replacement MASTER may proceed from recovery directly into dispatch of the safe next wave without another planning-only confirmation, but High/Critical merge approvals remain human gates and final PR `#6` is never merged without explicit final release acceptance.

## Recovery success test

A new MASTER with no access to the previous Chat must be able to answer from repository + GitHub alone:

- what is the exact current integration head and CI status?;
- which phases remain open and why?;
- what bounded Phase 0.5 foundations are accepted and what do they explicitly not prove?;
- are any Workers actually active, blocked, in review or merge-ready?;
- what is the current PR `#24` protected gate state?;
- which remaining capability slices are independently READY and what files/contracts could conflict?;
- what is the exact `BASE_SHA` for the next dispatch?;
- why PR `#6` must remain Draft?;
- how to target 4–5 Workers without creating micro-task fragmentation or weakening safety?

If any of those cannot be reconstructed, reconcile durable state before dispatching implementation work.
