# Project Status

This is the single human-readable current-state entry point. Detailed history belongs in bounded evidence/traceability/risk documents and GitHub Task Contracts.

**Last status review:** 2026-08-09  
**Target release:** `1.0.0`  
**Active phase:** `0.4.0 — Catalog, Panels and Offerings`  
**Authoritative Phase 0.4 Issue:** `#7`  
**Parallel Phase 0.5 Issue:** `#8`  
**Authoritative integration PR:** Draft PR `#6`  
**Integration branch:** `develop/v1.0.0-completion`  
**PR base:** `main`

Current control documents:

- multi-agent contract: `docs/development/multi-agent-orchestration.md`;
- current cross-phase handoff: `docs/52-current-continuation-handoff.md`;
- active Phase 0.4 live-execution handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`;
- traceability overlay: `docs/32-current-traceability-overlay.md`;
- risk overlay: `docs/33-current-risk-overlay.md`;
- machine status: `docs/project-status.json`.

## Live-state rule

Do not treat any SHA written here as the current working head. Before every MASTER repository write, Worker dispatch, review or integration decision, fetch PR `#6`, require it to remain open/Draft on `develop/v1.0.0-completion` with base `main`, and use its exact `head_sha`. Inspect exact-head CI. Chat history is not project state.

## MASTER transition checkpoint

The W-002/W-003 ordered integration wave is accepted. The product integration checkpoint immediately after those merges is:

- integration merge baseline `fae391569e50a2f318e2ca06aa522605d385ce2a`;
- Draft PR `#6` post-merge CI `31295225638` / `#1313` — all five mandatory jobs successful;
- full suite **425 tests / 2623 assertions**;
- artifact `test-evidence-31295225638`, ID `9032748596`;
- independent artifact digest `sha256:1014ff3150301174f4637f99528652fabd5ab197d9ac9ce9fb3bc361764ab6d4`.

Focused MASTER-owned project-control commits after that product checkpoint may advance the live PR `#6` head. Therefore a replacement MASTER must fetch PR `#6` and verify the final project-control CI rather than using `fae391...` as a dispatch base.

No implementation Worker is intentionally dispatched during this transition checkpoint. Dynamic task state must be recovered from GitHub before creating the next wave.

## Multi-agent throughput direction

The repository keeps one active Task Contract, branch, isolated writable environment and PR per Worker, but future contracts should normally be **larger coherent capability slices**, not artificially small micro-tasks. The operational target is **4–5 concurrent implementation Workers** whenever the fresh dependency/conflict graph contains that many genuinely READY tasks with LOW/MEDIUM pairwise conflict.

- bundle tightly coupled domain/application/schema/tests/evidence work when it shares one authority and one modification surface;
- do not split a capability merely to occupy Worker slots;
- do not serialize genuinely independent READY work;
- review/integrate a Worker as soon as it becomes ready rather than waiting for the entire wave;
- if fewer than five safe tasks are READY, stabilize the smallest shared prerequisite that unlocks meaningful parallel work rather than manufacturing filler tasks;
- High/Critical financial/security/concurrency/schema gates remain unchanged.

The stable policy is in `docs/development/multi-agent-orchestration.md`.

## Active Phase 0.4 boundary and blocker

The latest evidence-complete active-phase boundary remains **PasarGuard Guarded Live-Acceptance Harness**. The active increment is **PasarGuard Controlled Live Execution** and remains blocked on the protected/default-branch path documented in `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`.

Draft PR `#24` (`ops/provider-live-dispatch-bootstrap` -> `main`) remains open/Draft and separate from normal Worker integration. Its last live inspection at this checkpoint showed head `f2d2b6d538b16fe08787f6248ec425dbd19c8321`, base `1227cce28aedd2d799f2cd510891309deaacd0fb`, and `mergeable=true`. Its older CI `#1129` had a dependency-policy failure while the other major jobs passed. Re-fetch PR `#24` before any decision. Keep the bootstrap/safety branches until their documented cleanup condition is satisfied. Protected provider secrets never enter Chat, Issues, PR text or evidence.

Phase `0.4.0` / Issue `#7` is therefore still open. PasarGuard protected live execution and later Target activation remain human/protected gates; Marzban `v0.8.4` deployment acceptance remains a final-release gate.

## Parallel Phase 0.5 accepted chain

Phase `0.5.0` / Issue `#8` remains open. Thirteen bounded foundations are accepted and reusable:

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
11. Stored promotion/referral pricing-rule resolution (`W-001`) — `docs/58-phase-0.5-promotion-referral-pricing-rule-traceability.md`;
12. Promotion usage reservation/capacity + explicit release (`W-002`, bounded `PRO-001`) — `docs/60-phase-0.5-promotion-usage-reservation-traceability.md`;
13. Stored most-specific agent-pricing resolver (`W-003`, bounded `AGT-005`) — `docs/phase-0.5-agent-pricing-resolution-traceability.md`.

### Latest verified bounded evidence — Agent Pricing Resolution Foundation (AGT-005 resolver)

Machine status intentionally points to the exact W-003 implementation/evidence lifecycle:

- implementation `552fa7e30f3f575e1697f176d07fe66d0372e2ee`, CI `31289482939` / `#1293`;
- evidence `cfeb56a837a6ae85c098605b7e37a532a7ddb65d`, CI `31289665318` / `#1296`;
- evidence-head full suite **407 tests / 2543 assertions**;
- artifact `test-evidence-31289665318`, ID `9030991362`;
- digest `b4d4535d7fcce4d17e003f8d2b670dc29f6d74ec4d8c5daa6656ff6b51679dd7`;
- evidence `evidence/0.5.0/agent-pricing-resolution-foundation.md`;
- traceability `docs/phase-0.5-agent-pricing-resolution-traceability.md`.

The later target-sync and history-preserving integration were independently verified and culminated in the `fae391...` / CI `#1313` product checkpoint above.

Accepted W-003 behavior is bounded to stored/versioned agent-pricing profile/rule identity, deterministic most-specific action/offering/server/product resolution, fail-closed equal-specificity ambiguity, explicit no-match, active-agent/current-profile authorization, integer-IRR override, snapshotted discount-combination policy, immutable accepted resolution and replay/integrity guards. Quote does **not** yet consume this resolver; complete end-to-end `AGT-005` remains open.

W-002 is also intentionally partial: stable-rule promotion capacity/reservation and explicit release are accepted, but payment-success redeem/finalize and automatic Payment Intent-driven release remain deferred until genuine purchase-bound payment authority exists.

## Remaining Phase 0.5 work

A replacement MASTER must recompute the graph from the live post-transition baseline. Candidate areas include, without pre-dispatch commitment:

- full `AGT-005` Quote consumption/integration;
- `PAY-001` payment-method eligibility;
- remaining `PRO-001` authoritative payment-success redemption/release orchestration only when the required purchase-payment authority exists;
- `PRO-002` gift/service/wallet code lifecycle;
- `REF-001` reward pending/release/reversal/limits/anti-abuse/notification lifecycle;
- payment-method/provider packages and provider-native reconciliation/refund paths;
- other Issue `#8` gates proven independent by the fresh dependency/conflict graph.

`BUY-001` Order flow, provisioning and Service lifecycle remain Phase `0.6.0` ownership and must not be pulled forward merely to increase Worker count.

## Current open items

- active Phase `0.4.0` protected provider gate and PR `#24` bootstrap decision;
- PasarGuard protected live execution, uncertainty/idempotency rows and Target activation;
- Marzban final-release deployment acceptance;
- Phase `0.5.0` remaining pricing/promotion/referral/payment/provider capability work listed above;
- all Phase `0.6.0+` owned behavior.

## Non-negotiable controls

- PR `#6` stays Draft; do not merge, mark Ready, auto-merge, rewrite history, force-push or push `main`;
- Worker branches follow `docs/development/multi-agent-orchestration.md`; uncontracted temporary branches are forbidden;
- exact implementation/evidence CI/artifacts, independent review and post-merge integration CI remain mandatory for accepted Worker increments;
- IRR remains integer; financial history is immutable and replay/conflict cannot create a second accepted effect;
- browser return never proves payment and no paid provisioning occurs before authoritative capture;
- protected secrets and provider-sensitive material never enter repository evidence/logs/Chat.

The active increment remains `PasarGuard Controlled Live Execution` with handoff `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`. Continue from `docs/52-current-continuation-handoff.md` after live-fetching PR `#6`.
