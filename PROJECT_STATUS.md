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
- staging workflow status: `docs/development/staging-workflow-inventory.md`.

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

## Immediate stabilization gate

Feature development is temporarily paused at this bounded increment until repository control is restored.

### Completed or implemented cleanup

- mandatory repository operating contract and continuation entry points added;
- current human/machine status and schema added;
- execution ledger reconciled through verified Phase `0.4.0` increment 5;
- current traceability and risk overlays added;
- historical audits/candidate documents marked superseded;
- self-hosted PHP/Composer runner contract made explicit;
- safe read-only `Staging Readiness` workflow added;
- all legacy staging workflows replaced with inert historical stubs;
- project-control verification script added.

### Still required in mandatory order

1. clear the occupied/stuck self-hosted runner queue;
2. apply the validated lockfile security update and five focused formatting repairs;
3. remove the temporary repair workflow and generator;
4. integrate project-control verification into mandatory preflight;
5. reconcile PR `#6`, Issue `#7`, architecture/test/deployment documents, and active handoff;
6. run mandatory CI on the exact stabilization head and fix every real failure;
7. only then resume Trial/Panel implementation verification.

## Known current blockers and risks

- An older self-hosted CI static job remained `in_progress` without executable steps and blocked newer queued jobs. Repeated commits/dispatches must not be used as a workaround; the runner service must be inspected once connector evidence confirms the queue is still blocked.
- The runner toolchain must select `/www/server/php/84/etc/php-cli.ini`, disable JIT for CI, enable PCOV only for coverage, and validate PHP/Composer/extensions before expensive work.
- The previously locked `league/commonmark` `2.8.3` is affected by `CVE-2026-3066`; a bounded repair to `2.9.0` has been generated and validated but is not accepted until committed and exact-head CI passes.
- Five active-increment files have a validated Pint-only repair pending application.
- Temporary write-capable repair automation must be removed immediately after the repair commit.
- The global baseline traceability/risk catalogues still require later full regeneration; current overlays govern status meanwhile.
- The long-running PR is intentionally retained, but its size requires strict status, handoff, and evidence discipline.

## Non-negotiable remote-effect rules

- authoritative remote lookup before every create;
- exact match means adopt, not create;
- mismatch means conflict/manual review;
- unavailable lookup means no create;
- uncertain create means discovery first, never immediate second create;
- conflicting idempotency-key reuse never overwrites the original effect;
- TLS verification is never disabled;
- system CA is default; custom CA or pinning is limited to explicitly configured private/self-signed endpoints.

## Next completion boundary

The next acceptable feature boundary is not “code exists.” It is:

- exact implementation SHA;
- all mandatory jobs green on that SHA;
- executable test/assertion counts;
- retained artifact name/ID and independently calculated SHA-256;
- bounded Trial/Panel evidence and traceability;
- all mandatory jobs green on the exact evidence-head SHA;
- only then updates to Issue `#7` and PR `#6`.
