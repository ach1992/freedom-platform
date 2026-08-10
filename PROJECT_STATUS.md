# Project Status

This is the human-readable current-state entry point. GitHub is authoritative for dynamic task/PR state; Chat history is disposable.

**Last status review:** 2026-08-10  
**Target release:** `1.0.0`  
**Active Phase 0.4 Issue:** `#7`  
**Parallel Phase 0.5 Issue:** `#8`  
**Authoritative integration PR:** Draft PR `#6`  
**Integration branch:** `develop/v1.0.0-completion`  
**PR base:** `main`

## Live-state rule

Before every MASTER write, dispatch, review or merge, fetch PR `#6` and use its exact live `head_sha`. Require PR `#6` to remain open/Draft and verify the exact-head mandatory CI. Never use a copied SHA from this file or Chat as live authority.

## Clean continuation checkpoint

The last accepted **product** integration baseline before this handoff-control documentation refresh is:

- `76ed06bbb272dbed971697587ca76f1785313dd3`;
- PR `#6` exact-head CI `31345041706` / `#1402`: all five mandatory jobs PASS;
- full suite: **463 tests / 3057 assertions**, zero failures/errors;
- artifact `test-evidence-31345041706`, ID `9047072891`;
- independently verified SHA-256 `785c67bc39fba3565f861e35f6e2e599d6309420004ecf01329f840c2d86f6f2`.

Machine-status evidence for that boundary is `evidence/0.5.0/benefit-code-lifecycle.md`; traceability is `docs/phase-0.5-benefit-code-lifecycle-traceability.md`.

The documentation cleanup commit(s) after that product baseline may advance PR `#6`. A new MASTER must fetch PR `#6` and verify the latest control-plane head/CI before dispatching work.

## Machine control synchronization

The current machine status intentionally identifies **PasarGuard Controlled Live Execution** as the active increment, with handoff `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`.

Current project-control entry points include:

- `docs/32-current-traceability-overlay.md`;
- `docs/33-current-risk-overlay.md`;
- `docs/development/multi-agent-orchestration.md`.

## Recently accepted Phase 0.5 Workers

- **W-004 / Issue #31 / PR #35** — agent pricing is consumed by immutable Quotes with replay-time authorization; merged.
- **W-007 / Issue #34 / PR #37** — bounded USDT BEP20 rate/quote foundation plus standard authorization seeding; merged. Issue #34 may remain open for later bounded USDT work such as the not-yet-accepted Tetherland path.
- **W-006 / Issue #33 / PR #38** — secure benefit-code lifecycle (`PRO-002`) with dedicated versioned lookup keys and canonical ledger lock ordering; merged and Issue #33 completed.

## Owner-cancelled work — do not redispatch

**W-005 / Issue #32 / PAY-001 is permanently cancelled in the current project plan.**

- Issue #32 is closed `not_planned` and marked `CANCELLED / OBSOLETE / DO NOT REDISPATCH`.
- PR #36 is closed/unmerged audit history.
- PR #39 is an accidental closed/unmerged audit PR.
- PR #40 is closed/unmerged and marked `CANCELLED / DO NOT MERGE`.
- branches `agent/32-payment-method-eligibility` and `agent/32-payment-method-eligibility-r2` are obsolete audit refs, not active task bases.
- no W-005 implementation was merged into the integration branch.
- do not recreate/recover W-005 or dispatch a replacement PAY-001 implementation unless the Owner explicitly reverses this cancellation.

Cancellation does **not** prove `PAY-001` implemented. The original Phase 0.5 specification still names `PAY-001`; treat this as an explicit owner-cancelled/unresolved requirement gap, never as completed evidence.

## Current open PRs

At this checkpoint the only open PRs are:

1. **PR #6** — long-running Draft integration PR, `develop/v1.0.0-completion` -> `main`; never merge/mark Ready/enable auto-merge without explicit final release acceptance.
2. **PR #24** — protected PasarGuard live-acceptance bootstrap, `ops/provider-live-dispatch-bootstrap` -> `main`; remains Draft/open and separate from normal Worker integration. Re-fetch before any decision.

No implementation Worker is intentionally active at this handoff.

## Phase boundaries

Phase `0.4.0` / Issue `#7` remains open around protected provider-live acceptance. Phase `0.5.0` / Issue `#8` remains open for remaining pricing/promotion/referral/payment/provider capability gaps. Phase `0.6.0` / Issue `#9` owns Orders, provisioning and Service lifecycle; do not pull those effects into Phase 0.5 just to increase parallelism.

The accepted Payment Intent remains bounded to `wallet_top_up`; it is not purchase authority. No paid provisioning may occur before authoritative capture.

## New MASTER entry point

Read, in order:

1. `AGENTS.md`;
2. this file;
3. `docs/project-status.json`;
4. `docs/development/multi-agent-orchestration.md`;
5. `docs/development/continuation-runbook.md`;
6. `docs/52-current-continuation-handoff.md`;
7. `docs/32-current-traceability-overlay.md` and `docs/33-current-risk-overlay.md`;
8. live GitHub PR `#6`, Issues `#7/#8`, PR `#24`, and all currently open Issues/PRs.

Then recompute the task/dependency/conflict graph from live state. Do **not** recover or recreate W-005/PAY-001. Preserve High/Critical human merge approvals and keep PR #6 Draft.
