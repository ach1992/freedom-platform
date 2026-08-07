# Project Status

This is the single human-readable current-state entry point. Detailed history belongs in evidence, traceability, risk, audit, and handoff documents.

**Last status review:** 2026-08-07  
**Target release:** `1.0.0`  
**Active phase:** `0.4.0 — Catalog, Panels and Offerings`  
**Authoritative phase Issue:** `#7`  
**Authoritative integration PR:** `#6`  
**Allowed branch:** `develop/v1.0.0-completion`  
**PR base/state:** `main` / Draft

Current overlays:

- traceability: `docs/32-current-traceability-overlay.md`;
- risks: `docs/33-current-risk-overlay.md`;
- stabilization audit: `docs/31-project-control-plane-audit.md`;
- staging workflow status: `docs/development/staging-workflow-inventory.md`;
- operational instruction status: `docs/development/operational-document-status.md`.

## Live-state rule

Do not treat a SHA written in this document as current. Before work, fetch PR `#6` and use its exact `head_sha`. Then fetch workflow runs for that SHA. Follow `AGENTS.md` and `docs/development/continuation-runbook.md`.

## Last independently verified boundary

### Phase 0.4 increment 5 — Custom Plan Policy and Calculation Snapshot

Implementation boundary:

- SHA: `8e62867277acdd39cd1471ed3d454ef25520bef8`;
- CI: `31071843621` / run `#833` — success;
- suite: 255 tests, 1267 assertions.

Evidence boundary:

- SHA: `0d34af0aa4f9f227fdf3cae74b4fd4717f199ddf`;
- CI: `31102652203` / run `#834` — success;
- artifact: `test-evidence-31102652203`;
- artifact ID: `8968201643`;
- digest: `sha256:5ec6b6dd94e1305c17312650abdc94a5253521834911c26eb48f8decbd105cb9`;
- evidence: `evidence/0.4.0/custom-plan-policy-calculation.md`;
- traceability: `docs/29-phase-0.4-custom-plan-traceability.md`.

No later implementation is independently accepted yet.

## Active unverified bounded increment

### Trial Policy and Panel Adapter Foundation

Authoritative handoff: `docs/30-phase-0.4-trial-panel-handoff.md`.

Current bounded scope:

- trial policy, eligibility, capacity reservation, abuse controls, regrant/reset authority, and disclosed fallback;
- common Panel Adapter contract and capability/result/snapshot validation;
- `FakePanelAdapter` deterministic remote behavior and mutation idempotency journal;
- authoritative deterministic-username lookup;
- exact-match adoption, mismatch conflict/manual review, and unavailable-lookup fail-closed behavior;
- uncertain-create discovery before any subsequent create;
- credential and delivery-artifact redaction;
- unavailable Marzban/PasarGuard shells that fail closed.

Explicitly unverified:

- mandatory CI on the exact current implementation head;
- final Trial/Panel Adapter test and assertion counts;
- retained implementation artifact and digest;
- bounded evidence and traceability head;
- real Marzban or PasarGuard version/API compatibility;
- live provider or production activation.

## Stabilization status

Feature development remains paused until the current stabilization head passes mandatory exact-SHA CI.

### Completed cleanup

- repository operating contract, contributor guide, continuation runbook, repository map, increment lifecycle, and machine-readable status/schema added;
- execution ledger reconciled through verified Phase `0.4.0` increment 5;
- current traceability/risk overlays and project control-plane audit added;
- historical audits/candidate documents marked superseded;
- architecture and test/CI documents now distinguish target, implemented, foundation, candidate, and accepted evidence;
- self-hosted PHP/Composer runner contract made deterministic with explicit CLI INI, JIT disabled, and PCOV enabled only for coverage;
- `league/commonmark` updated from vulnerable `2.8.3` to patched `2.9.0` through a bounded lockfile repair;
- five active-increment files received the validated Pint-only repair;
- temporary write-capable repair workflow and generator removed;
- project-control verification integrated into mandatory preflight;
- safe read-only `Staging Readiness` workflow added;
- all legacy staging mutation/diagnostic workflows replaced with inert historical stubs;
- active Trial/Panel handoff reconciled with the new control plane.

### Remaining stabilization gate

1. Fetch PR `#6` and obtain the exact current head.
2. Find a `CI` run on that exact head.
3. When no exact-head run exists, manually run `Actions → CI → Run workflow → develop/v1.0.0-completion`.
4. Inspect all mandatory jobs and executable logs.
5. Fix every real failure with a focused commit and repeat until exact-head CI is green.
6. Record stabilization results in this status/audit only after the accepted run exists.
7. Resume Trial/Panel implementation verification from the active handoff.

PR `#6` and Issue `#7` are intentionally not rewritten merely for stabilization. Their accepted feature-boundary updates remain gated by the implementation/evidence exact-SHA lifecycle.

## Known current risks

- Connector-originated commits may not automatically start Actions; an exact-head manual dispatch may be the only remaining human action.
- The single self-hosted runner serializes jobs; avoid commit/dispatch storms while a run is active.
- The global baseline traceability/risk catalogues still require a later full regeneration; current overlays govern status meanwhile.
- `TrialReservationService` is a reviewability hotspot. Decompose it only after a green behavior baseline, preserving transactions, lock order, replay, and tests.
- Aggregate coverage exists, but Phase `0.4.0` closure still requires explicit critical-branch interpretation rather than relying on a raw percentage.
- Real panel versions/contracts and production activation remain deliberately untested and fail closed.
- The long-running PR is intentionally retained; its scale requires strict status, handoff, and evidence discipline.

## Non-negotiable remote-effect rules

- authoritative remote lookup before every create;
- exact match means adopt, not create;
- mismatch means conflict/manual review;
- unavailable lookup means no create;
- uncertain create means discovery first, never immediate second create;
- conflicting idempotency-key reuse never overwrites the original effect;
- TLS verification is never disabled;
- system CA is default; custom CA or pinning is limited to explicitly configured private/self-signed endpoints.

## Next feature completion boundary

The next acceptable feature boundary is not “code exists.” It is:

- exact implementation SHA;
- all mandatory jobs green on that SHA;
- executable test/assertion counts;
- retained artifact name/ID and independently calculated SHA-256;
- bounded Trial/Panel evidence and traceability;
- all mandatory jobs green on the exact evidence-head SHA;
- only then updates to Issue `#7` and PR `#6`.
