# Phase 0.4 Provider Reconciliation and Deferred Live-Acceptance Handoff

**Status:** historical / superseded by `docs/42-phase-0.4-controlled-live-provider-handoff.md`.  
**Authoritative Issue/PR:** Issue `#7`, Draft PR `#6`.  
**Live head rule:** fetch PR `#6` before every write and use its exact `head_sha`.

This handoff initiated the non-live Phase `0.4.0` provider reconciliation after the pinned mutation-contract mapping boundary. That reconciliation is now complete.

## Reconciliation outcome

The audit completed the following without contacting a live provider:

- reconciled `PRV-001`–`PRV-003` and supporting security/data/quality requirements;
- verified real provider factories/capabilities remain read-only and mutation/delivery paths fail closed;
- verified source-contract Target discovery cannot make a Service Target operational;
- verified lookup-before-create, no-create-on-unavailable, discovery-before-retry and idempotency-conflict preservation;
- identified and fixed one real non-live defect: the common resolver was using provider-observable `canonicalHash` as create equality instead of the already accepted provider-specific create-equivalence mapping;
- separated read-state `canonicalHash` from provider-specific `createEquivalenceHash`;
- completed exact implementation/evidence CI for that correction;
- built the exact controlled live-provider acceptance matrix;
- reconciled current traceability/risk/execution-status documentation.

## Accepted reconciliation boundary

### Provider Create-Equivalence Reconciliation

Implementation:

- SHA: `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43`;
- CI: `31223470871` / run `#995` — success;
- suite: 315 tests, 1678 assertions;
- artifact: `test-evidence-31223470871`;
- artifact ID: `9011256284`;
- independently verified digest: `sha256:f3fc78ab55ba0adbb2814bb6d0de464736e897df36625b8f72f54b3f7b8fe95d`.

Evidence:

- SHA: `a70b28cb984c23f1e219287e88d20014ee9f0310`;
- CI: `31223749257` / run `#997` — success;
- suite: 315 tests, 1678 assertions;
- artifact: `test-evidence-31223749257`;
- artifact ID: `9011359876`;
- independently verified digest: `sha256:140e6a45fe2ef9523dee0147f6131a1e2d1bcaf63548b57eea7b83d3f0d9b827`;
- evidence: `evidence/0.4.0/provider-create-equivalence-reconciliation.md`;
- traceability: `docs/40-phase-0.4-provider-create-equivalence-traceability.md`.

## Safety state preserved

- Marzban remains pinned to `v0.8.4` and PasarGuard to `v5.2.1`;
- real provider mutation/delivery capabilities remain unadvertised and fail closed;
- real Targets remain disabled/unverified;
- authoritative lookup precedes create;
- unavailable lookup or create-equivalence proof means no create;
- adoption requires provider-specific preserved-field equality;
- mismatch is conflict/manual review;
- uncertain mutation requires authoritative discovery before retry;
- conflicting idempotency-key reuse cannot overwrite the original primary effect;
- TLS verification remains enabled;
- credentials, tokens, subscription URLs, proxy/config secrets and raw sensitive provider bodies remain outside repository evidence and normal logs.

## Superseding continuation

The remaining Phase `0.4.0` provider work is deployment-specific and requires an owner-supplied controlled test environment.

Use:

- live acceptance matrix: `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
- active handoff: `docs/42-phase-0.4-controlled-live-provider-handoff.md`.

Do not install temporary provider panels merely to manufacture live evidence. Do not claim live compatibility, real mutation acceptance, production Target activation, or Phase `0.4.0` closure until the controlled live matrix and final closure audit pass.