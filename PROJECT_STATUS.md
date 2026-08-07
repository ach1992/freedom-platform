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

## Last evidence-complete boundary

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

## Active bounded increment

### Trial Policy and Panel Adapter Foundation

Implementation boundary is green; evidence-head verification is pending.

Implementation:

- SHA: `b146c2c6aa902b4ed252d200121d63e422cd87f2`;
- CI: `31135758918` / run `#931` — success;
- suite: 298 tests, 1471 assertions;
- test artifact: `test-evidence-31135758918`;
- artifact ID: `8977870831`;
- independently verified digest: `sha256:97212a1a18ee3d444dd8a6a342981a6bae8bbdd4d013c3a204f6d26eaca3e95d`;
- evidence: `evidence/0.4.0/trial-policy-panel-adapter-foundation.md`;
- traceability: `docs/34-phase-0.4-trial-panel-traceability.md`;
- handoff: `docs/30-phase-0.4-trial-panel-handoff.md`.

Verified at the implementation boundary:

- Trial policy, eligibility, daily/target capacity reservation, abuse controls, lifecycle, regrant/reset and disclosed compatible fallback;
- common Panel Adapter contract, capability/request/result/snapshot validation and redaction;
- deterministic `FakePanelAdapter` create/mutation behavior and operation-key journal;
- authoritative deterministic-username lookup, exact-match adoption, mismatch conflict and unavailable-lookup fail-closed behavior;
- uncertain-create discovery before any subsequent create;
- unavailable Marzban/PasarGuard shells that fail closed;
- deterministic self-hosted CI, dependency/license/security/static/database/test gates and retained artifact inspection.

Still unverified or intentionally excluded:

- mandatory CI on the exact evidence-head SHA containing the evidence/traceability documents;
- real Marzban or PasarGuard version, OpenAPI, authentication, rate-limit and error behavior;
- real remote mutations or Panel target production activation;
- complete Order, Provisioning Operation, Service Subscription and Telegram Trial/Admin workflow;
- Phase `0.4.0` closure.

## Stabilization status

Repository/CI/control-plane stabilization is implementation-green on `b146c2c6aa902b4ed252d200121d63e422cd87f2`. Its controls are included in the current evidence-head candidate and must pass the same exact-head CI before stabilization is considered accepted.

Completed cleanup:

- repository operating contract, contributor guide, continuation runbook, repository map, increment lifecycle and machine-readable status/schema;
- execution ledger, architecture, test/CI, current traceability/risk overlays and active handoff reconciliation;
- historical audits/candidates marked superseded;
- deterministic self-hosted PHP/Composer contract with explicit CLI INI, JIT disabled and PCOV only for coverage;
- patched `league/commonmark` `2.9.0` lockfile and focused Pint repair;
- temporary write-capable repair automation removed;
- project-control verification integrated into preflight;
- guarded read-only `Staging Readiness` workflow and inert historical staging stubs;
- direct PHPUnit execution in the configured wrapper process so coverage retains PCOV/JIT settings.

## Immediate next sequence

1. Fetch PR `#6` and exact current evidence-head candidate.
2. Find a `CI` run on that exact SHA.
3. If no run exists, manually run `Actions → CI → Run workflow → develop/v1.0.0-completion`.
4. Inspect all mandatory jobs and executable logs.
5. Fix every real failure without weakening a gate; any executable change creates a new evidence-head candidate.
6. On exact evidence-head success, inspect/download/hash its test artifact and confirm the 298/1471 regression boundary or document any legitimate count change.
7. Only then update Issue `#7` and Draft PR `#6` with the final evidence-head SHA/run/artifact.
8. Continue Phase `0.4.0` with exact installed Marzban/PasarGuard contract work only when protected provider inputs/environment are available; otherwise take the next offline closure/control task.

## Known current risks

- Connector-originated commits may not automatically start Actions; an exact-head manual dispatch may be the only remaining human action.
- The single self-hosted runner serializes jobs; avoid commit/dispatch storms while a run is active.
- The global baseline traceability/risk catalogues still require a later full regeneration; current overlays govern status meanwhile.
- `TrialReservationService` is a reviewability hotspot. Decompose it only after this evidence boundary is accepted, preserving transactions, lock order, replay and tests.
- Aggregate coverage exists, but Phase `0.4.0` closure still requires explicit critical-branch interpretation rather than relying on a raw percentage.
- Real panel versions/contracts and production activation remain deliberately untested and fail closed.
- The long-running PR is intentionally retained; its scale requires strict status, handoff and evidence discipline.

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

The current increment becomes evidence-complete only when:

- all mandatory jobs are green on the exact evidence-head SHA;
- executable test/assertion counts are confirmed;
- the evidence-head artifact name/ID and independently calculated SHA-256 are recorded;
- Issue `#7` and Draft PR `#6` are updated without changing Draft/base/branch state.
