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
- pinned provider source review: `docs/35-phase-0.4-panel-provider-source-contracts.md`;
- accepted provider-read evidence: `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
- accepted provider-read traceability: `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`;
- accepted provider-mutation evidence: `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`;
- accepted provider-mutation traceability: `docs/38-phase-0.4-panel-provider-mutation-contract-traceability.md`;
- active continuation handoff: `docs/39-phase-0.4-provider-reconciliation-handoff.md`.

## Live-state rule

Do not treat a SHA written in this document as the current working head. Before work, fetch PR `#6` and use its exact `head_sha`, then inspect workflow runs for that exact SHA. Follow `AGENTS.md` and `docs/development/continuation-runbook.md`.

## Last evidence-complete boundary

### Phase 0.4 increment 8 — Pinned Panel Provider Mutation Contract Mapping

Implementation boundary:

- SHA: `15b824e955d040a6bf43405f7015aa85110a73e4`;
- CI: `31189964977` / run `#978` — success;
- suite: 313 tests, 1650 assertions;
- artifact: `test-evidence-31189964977`;
- artifact ID: `8998394458`;
- independently verified digest: `sha256:80cbd67bff1373b5aa97f73f252a001858ac6d73b799f8a26805c874f9ee6737`.

Evidence boundary:

- SHA: `eec613c1cb5241d8fff621047086361f2753fd24`;
- CI: `31190453594` / run `#980` — success;
- suite: 313 tests, 1650 assertions;
- artifact: `test-evidence-31190453594`;
- artifact ID: `8998628512`;
- independently verified digest: `sha256:ac5caaaad4a83af04efd27f3c88cfeb857755e497bd07a46fa1037eadea4361a`;
- evidence: `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`;
- traceability: `docs/38-phase-0.4-panel-provider-mutation-contract-traceability.md`.

Accepted behavior:

- Marzban source contract remains pinned to `v0.8.4` and PasarGuard to `v5.2.1`;
- deterministic offline request mapping covers create, expiry/data updates, usage reset, suspend/activate, delete and explicit subscription rotation;
- provider-specific delivery and mutation-result mapping are source-shaped and deterministic;
- provider create-equivalence compares only provider-preserved intent fields, not provider-generated secrets or volatile state;
- additive data mapping requires authoritative current finite state and rejects zero/unlimited ambiguity;
- transport/5xx/malformed-success mutation outcomes are uncertain and require discovery before retry;
- conflict is manual review/no blind retry;
- real provider mutation/delivery capabilities remain unadvertised and fail closed;
- real Targets remain disabled/unproved.

This boundary does **not** prove live connectivity, deployment-specific compatibility or any real remote mutation effect.

## Prior accepted provider-read boundary

Pinned Marzban/PasarGuard read contracts remain accepted:

- implementation SHA `954973e505901208b5cef9348551e0c71ac027b6`, CI `31178042191` / #959 — success;
- evidence SHA `23a8da1ce1327407ecd826daa87b334452883d77`, CI `31178477080` / #961 — success;
- evidence artifact `test-evidence-31178477080`, ID `8993821250`, independently verified digest `sha256:9a45179652a3a88456c835c4d715aceef4efaf770c06b8246f0ebd68ca1b6fe6`.

The older read evidence/traceability files may contain historical pre-acceptance gate wording; this authoritative status supersedes that wording. A bounded documentation-drift cleanup is permitted during the active reconciliation increment without changing historical implementation facts.

## Active bounded increment

### Phase 0.4 Provider Reconciliation and Deferred Live-Acceptance Matrix

Status: active non-live reconciliation.  
Authoritative handoff: `docs/39-phase-0.4-provider-reconciliation-handoff.md`.

Continue without a live panel:

- reconcile `PRV-001`–`PRV-003` and supporting security/data/quality requirements against accepted Phase 0.4 evidence;
- verify runtime provider capability advertisement remains read-only and fail closed for every real mutation/delivery path;
- verify Targets cannot become operational from source-contract proof alone;
- reconcile current traceability/risk overlays and identify any remaining non-live defect;
- build the exact deferred live-provider acceptance matrix;
- if a non-live defect exists, fix it as its own bounded exact-SHA increment;
- when only controlled provider panels/protected credentials remain, record that as the genuine human blocker.

## Deferred live-provider gate

Marzban and PasarGuard are not installed on the current server. This remains intentionally **not a blocker** while non-live reconciliation work exists.

Owner decision:

- do not install temporary provider panels only to unblock development;
- defer live authentication, version/capability, read/target, create/adopt/conflict, mutation, uncertainty, delivery, cleanup and Target activation checks until dedicated test panels are supplied;
- protected credentials must be supplied through runtime/secret configuration, never repository files, Issues, PR comments, evidence or handoff text.

Once all non-live Phase 0.4 reconciliation is complete, absence of those controlled test panels becomes the real human blocker for live provider acceptance and Phase 0.4 closure.

## Known risks

- source-contract tests cannot prove deployment-specific reverse proxy, TLS chain, plugins/forks, permissions or runtime defects;
- provider snapshot `canonicalHash` remains provider-observable state; only the dedicated create-equivalence mapper represents create-intent equality;
- mutation idempotency remains application-owned when providers lack native idempotency guarantees;
- offline mutation mapping cannot prove post-effect discovery against a real provider;
- `TrialReservationService` remains a reviewability hotspot and should only be decomposed in a later behavior-preserving bounded increment if current reconciliation identifies a Phase 0.4 requirement gap;
- the global baseline traceability/risk catalogues still require later full regeneration; current overlays govern current status;
- Phase `0.4.0` remains open.

## Non-negotiable remote-effect rules

- authoritative remote lookup before every create;
- exact match means adopt only when a provider-specific accepted equivalence mapper proves intended attributes;
- mismatch means conflict/manual review;
- unavailable lookup means no create;
- uncertain mutation means discovery first, never blind immediate retry;
- conflicting idempotency-key reuse never overwrites the original effect;
- TLS verification is never disabled;
- system CA is default; custom CA or pinning is limited to explicitly configured private/self-signed endpoints;
- no credential, token, API key, password, subscription material or raw sensitive provider response enters logs/evidence;
- no real provider mutation or Target activation until the deferred live gate is explicitly accepted.

## Next completion boundary

The current reconciliation increment is complete when repository/requirement/risk review identifies no remaining non-live Phase 0.4 provider defect, the deferred live-provider acceptance matrix is explicit and source-backed, real mutation/Target paths remain fail closed, documentation drift is reconciled, and its exact implementation/evidence lifecycle is green where code changes are required. Phase `0.4.0` itself remains open until the controlled live-provider gate is executed successfully.
