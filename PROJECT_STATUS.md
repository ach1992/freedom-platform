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
- active continuation handoff: `docs/37-phase-0.4-panel-provider-mutation-handoff.md`.

## Live-state rule

Do not treat a SHA written in this document as the current working head. Before work, fetch PR `#6` and use its exact `head_sha`, then inspect workflow runs for that exact SHA. Follow `AGENTS.md` and `docs/development/continuation-runbook.md`.

## Last evidence-complete boundary

### Phase 0.4 increment 7 — Pinned Marzban and PasarGuard Read Contracts

Implementation boundary:

- SHA: `954973e505901208b5cef9348551e0c71ac027b6`;
- CI: `31178042191` / run `#959` — success;
- suite: 306 tests, 1522 assertions;
- artifact: `test-evidence-31178042191`;
- artifact ID: `8993620714`;
- independently verified digest: `sha256:ae51266d7730c22d7076c1603b36f073bc9d6f4f6007f680b942300451d110a2`.

Evidence boundary:

- SHA: `23a8da1ce1327407ecd826daa87b334452883d77`;
- CI: `31178477080` / run `#961` — success;
- suite: 306 tests, 1522 assertions;
- artifact: `test-evidence-31178477080`;
- artifact ID: `8993821250`;
- independently verified digest: `sha256:9a45179652a3a88456c835c4d715aceef4efaf770c06b8246f0ebd68ca1b6fe6`;
- evidence: `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
- traceability: `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`.

Accepted behavior:

- Marzban source contract pinned to `v0.8.4`;
- PasarGuard source contract pinned to `v5.2.1`;
- source-shaped authentication, exact-version check, authoritative username lookup, status/synchronization and target discovery;
- PasarGuard numeric remote-ID lookup and endpoint base-path preservation;
- bounded HTTP transport with redirects disabled and TLS verification always enabled;
- provider lookup failure becomes unavailable/manual review, never authoritative absence;
- provider response/error bodies and credentials are excluded from normal safe messages/evidence;
- real-provider mutation and delivery capabilities remain unadvertised and fail closed.

This boundary does **not** prove live connectivity or mutation compatibility.

## Active bounded increment

### Pinned Panel Provider Mutation Contract Mapping

Status: active offline source-contract work.  
Authoritative handoff: `docs/37-phase-0.4-panel-provider-mutation-handoff.md`.

Continue without a live panel. The next bounded increment maps deterministic pinned-source fixtures for:

- create service;
- update expiry and data allowance;
- reset usage;
- suspend and activate;
- delete;
- subscription rotation only where the pinned provider exposes a safe explicit operation;
- delivery artifacts;
- provider outcome classification;
- provider create-equivalence mapping.

All real mutation capabilities and real Targets must remain disabled until the relevant operation has deterministic source-contract evidence and later live-panel acceptance.

## Deferred live-provider gate

Marzban and PasarGuard are not installed on the current server. This is intentionally **not a blocker**.

Owner decision:

- do not install temporary provider panels only to unblock development;
- complete offline mappings/tests against pinned upstream sources now;
- defer live authentication, version/capability, create/adopt/conflict, mutation, uncertainty, cleanup and Target activation checks until dedicated test panels are supplied near final integration;
- protected credentials must be supplied through runtime/secret configuration, never repository files, Issues, PR comments, evidence, or handoff text.

## Immediate next sequence

1. Fetch PR `#6` and exact current head.
2. Read `docs/37-phase-0.4-panel-provider-mutation-handoff.md` and the accepted read-contract evidence/traceability.
3. Re-read each exact mutation route/model from Marzban `v0.8.4` and PasarGuard `v5.2.1` before mapping it.
4. Implement the smallest offline mutation request/result/equivalence mapping with deterministic HTTP fixtures.
5. Keep mutation capabilities unadvertised until their replay/conflict/uncertainty tests are complete.
6. Preserve lookup-before-create, no-create on lookup failure, discovery-before-retry, idempotency conflict preservation, TLS verification and redaction.
7. Complete the exact implementation-SHA CI/artifact and evidence-head CI lifecycle.
8. Continue Phase `0.4.0` closure work that does not require a live provider; leave live acceptance for the final provider gate.

## Known risks

- source-contract tests cannot prove deployment-specific reverse proxy, TLS chain, plugins/forks, permissions or runtime defects;
- provider snapshot `canonicalHash` is provider-observable state, not proof of local create-request equality;
- mutation idempotency remains application-owned when providers lack native idempotency guarantees;
- `TrialReservationService` remains a reviewability hotspot and should only be decomposed in a later behavior-preserving bounded increment;
- the global baseline traceability/risk catalogues still require later full regeneration; the current overlays govern current status;
- Phase `0.4.0` remains open.

## Non-negotiable remote-effect rules

- authoritative remote lookup before every create;
- exact match means adopt only when a provider-specific accepted equivalence mapper proves the intended attributes;
- mismatch means conflict/manual review;
- unavailable lookup means no create;
- uncertain mutation means discovery first, never blind immediate retry;
- conflicting idempotency-key reuse never overwrites the original effect;
- TLS verification is never disabled;
- system CA is default; custom CA or pinning is limited to explicitly configured private/self-signed endpoints;
- no credential, token, API key, password, subscription material or raw sensitive provider response enters logs/evidence.

## Next completion boundary

`Pinned Panel Provider Mutation Contract Mapping` is complete only when its exact implementation SHA passes every mandatory CI job, deterministic source fixtures cover declared mappings and uncertainty/replay behavior, retained artifact evidence is inspected/digested, bounded evidence/traceability is committed, and the exact evidence-head SHA passes the same mandatory CI. Live provider compatibility remains a separate deferred gate.
