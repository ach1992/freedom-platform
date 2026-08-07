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
- operational instruction status: `docs/development/operational-document-status.md`;
- pinned panel-provider source contracts: `docs/35-phase-0.4-panel-provider-source-contracts.md`.

## Live-state rule

Do not treat a SHA written in this document as current. Before work, fetch PR `#6` and use its exact `head_sha`. Then fetch workflow runs for that SHA. Follow `AGENTS.md` and `docs/development/continuation-runbook.md`.

## Last evidence-complete boundary

### Phase 0.4 increment 6 — Trial Policy and Panel Adapter Offline/Fake Foundation

Implementation boundary:

- SHA: `b146c2c6aa902b4ed252d200121d63e422cd87f2`;
- CI: `31135758918` / run `#931` — success;
- suite: 298 tests, 1471 assertions;
- artifact: `test-evidence-31135758918`;
- artifact ID: `8977870831`;
- independently verified digest: `sha256:97212a1a18ee3d444dd8a6a342981a6bae8bbdd4d013c3a204f6d26eaca3e95d`.

Evidence boundary:

- SHA: `31a1854a2804bb0b2cf466c96887de7c5813b343`;
- CI: `31136119421` / run `#934` — success;
- suite: 298 tests, 1471 assertions;
- artifact: `test-evidence-31136119421`;
- artifact ID: `8978007207`;
- independently verified digest: `sha256:65b46242099caeef40e8bfeb7717b915ec306b449078570bd680420811bbde09`;
- evidence: `evidence/0.4.0/trial-policy-panel-adapter-foundation.md`;
- traceability: `docs/34-phase-0.4-trial-panel-traceability.md`.

Accepted controls include:

- Trial policy, eligibility, daily/target capacity reservation, abuse controls, lifecycle, regrant/reset and disclosed compatible fallback;
- common Panel Adapter contract, capability/request/result/snapshot validation and redaction;
- deterministic `FakePanelAdapter` create/mutation behavior and operation-key journal;
- authoritative deterministic-username lookup, exact-match adoption, mismatch conflict and unavailable-lookup fail-closed behavior;
- uncertain-create discovery before any subsequent create;
- unavailable real-provider shells that fail closed;
- deterministic self-hosted CI, patched dependency lockfile, project-control gate and retained evidence lifecycle.

## Active bounded increment

### Pinned Marzban and PasarGuard Source-Contract Adapters

Status: active offline implementation/contract work. Live provider testing is intentionally deferred by owner until dedicated test panels are provided near final integration.

Pinned contracts:

- Marzban `v0.8.4` — `Gozargah/Marzban`;
- PasarGuard `v5.2.1` — `PasarGuard/panel`;
- Mirza Bot (`mahdiMGF2/mirzabot`) is a secondary practical integration reference only.

Authoritative implementation/handoff document:

- `docs/35-phase-0.4-panel-provider-source-contracts.md`.

The next implementation may proceed without a live panel. It must use deterministic HTTP contract tests against the exact pinned source behavior and keep real targets disabled/fail-closed.

Expected offline scope:

- token/API-key authentication mapping without exposing credentials;
- `/api/system` version compatibility checks;
- inbound/group target discovery;
- authoritative username lookup;
- create/update/reset/suspend/activate/delete/revoke-subscription mapping;
- legacy Marzban `proxies`/`inbounds` payload mapping;
- PasarGuard `proxy_settings`/`group_ids` payload mapping;
- HTTP/provider failure classification into definitive/retryable/uncertain outcomes;
- timeout-after-mutation discovery/reconciliation without blind retry;
- TLS, SSRF, redirect, redaction and secret-safety tests;
- exact-SHA implementation/evidence lifecycle.

## Deferred live-provider gate

Marzban and PasarGuard are **not installed on the current server**. This is not a blocker for normal project development.

The owner decision is:

- do not install temporary panel instances merely to unblock Phase `0.4.0` development;
- implement and verify adapters from the pinned upstream source/API contracts now;
- continue later project phases after offline contract evidence is accepted;
- near final integration/release acceptance, the owner will provide dedicated test panels and protected credentials;
- only then perform live authentication, version/capability discovery, create/adopt/conflict, mutation, uncertainty, cleanup and target-activation tests.

Until the final live gate:

- real panel targets remain disabled;
- no live-provider compatibility or production-activation claim is allowed;
- absence of a test panel must not cause a new chat/engineer to stop unrelated development.

## Stabilization status

Repository/CI/control-plane stabilization is complete and accepted through the increment-6 evidence boundary.

Completed controls include:

- repository operating contract, contributor guide, continuation runbook, repository map, increment lifecycle and machine-readable status/schema;
- reconciled execution ledger, architecture, test/CI, traceability/risk overlays and active handoff model;
- historical audits/candidates marked superseded;
- deterministic self-hosted PHP/Composer contract with explicit CLI INI, JIT disabled and PCOV only for coverage;
- patched `league/commonmark` `2.9.0` lockfile and focused Pint repair;
- temporary write-capable repair automation removed;
- project-control verification integrated into preflight;
- guarded read-only `Staging Readiness` workflow and inert historical staging stubs;
- direct PHPUnit execution inside the configured wrapper process so coverage preserves PCOV/JIT settings.

Feature development is no longer paused for stabilization.

## Immediate next sequence

1. Fetch PR `#6` and exact current head.
2. Read `docs/35-phase-0.4-panel-provider-source-contracts.md`.
3. Implement the smallest source-contract HTTP gateway increment against Marzban `v0.8.4` and PasarGuard `v5.2.1` without live credentials.
4. Add deterministic HTTP contract tests for authentication, version detection, target discovery, lookup, mutation, error classification, uncertainty and redaction.
5. Keep real gateway activation behind explicit fail-closed configuration until the final live gate.
6. Run mandatory CI on the exact implementation SHA and complete the standard evidence-head lifecycle.
7. Continue remaining Phase `0.4.0` closure/reconciliation work that does not require a live provider.
8. Defer live panel acceptance until the owner supplies dedicated test panels near final integration.

## Known current risks

- The global baseline traceability/risk catalogues still require a later full regeneration; current overlays govern current status meanwhile.
- `TrialReservationService` is a reviewability hotspot. Decompose it only in a bounded behavior-preserving increment, preserving transactions, lock order, replay and tests.
- Aggregate coverage exists, but Phase `0.4.0` closure still requires explicit critical-branch interpretation rather than relying on a raw percentage.
- Source-contract tests can prove request/response mapping but cannot prove deployment-specific provider configuration, reverse proxy, permissions, TLS chain, plugins/forks or runtime defects; that proof is intentionally deferred to the final live gate.
- Mirza Bot is useful as a practical integration reference but is not an authority for security, TLS, persistence, idempotency or provider-version semantics.
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

The source-contract adapter increment is complete only when:

- production adapter/gateway code exists for the pinned contracts without enabling real targets by default;
- all mandatory jobs are green on the exact implementation SHA;
- deterministic HTTP contract tests cover the declared provider operations and failure classes;
- executable test/assertion counts and retained artifact digest are recorded;
- bounded evidence/traceability is committed;
- all mandatory jobs are green on the exact evidence-head SHA;
- Issue `#7` and Draft PR `#6` are updated without changing Draft/base/branch state.
