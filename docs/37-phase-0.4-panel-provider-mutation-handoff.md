# Phase 0.4 Panel Provider Mutation-Contract Handoff

**Status:** active next offline increment.  
**Authoritative Issue/PR:** Issue `#7`, Draft PR `#6`.  
**Live head rule:** fetch PR `#6` before every write and use its exact `head_sha`; never continue from a copied SHA.

Start with:

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/continuation-runbook.md`;
5. `docs/35-phase-0.4-panel-provider-source-contracts.md`;
6. `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
7. `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`;
8. this handoff.

## Last accepted provider boundary

### Pinned Marzban/PasarGuard read contracts

Implementation:

- SHA: `954973e505901208b5cef9348551e0c71ac027b6`;
- CI: `31178042191` / run `#959` — success;
- suite: 306 tests, 1522 assertions;
- artifact: `test-evidence-31178042191`;
- artifact ID: `8993620714`;
- digest: `sha256:ae51266d7730c22d7076c1603b36f073bc9d6f4f6007f680b942300451d110a2`.

Evidence head:

- SHA: `23a8da1ce1327407ecd826daa87b334452883d77`;
- CI: `31178477080` / run `#961` — success;
- suite: 306 tests, 1522 assertions;
- artifact: `test-evidence-31178477080`;
- artifact ID: `8993821250`;
- independently verified digest: `sha256:9a45179652a3a88456c835c4d715aceef4efaf770c06b8246f0ebd68ca1b6fe6`;
- evidence: `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
- traceability: `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`.

## Provider pins

- Marzban: upstream `Gozargah/Marzban`, tag `v0.8.4`;
- PasarGuard: upstream `PasarGuard/panel`, tag `v5.2.1`;
- `mahdiMGF2/mirzabot` is secondary practical reference only.

The user intentionally deferred live provider tests until controlled test panels are available. Do not request or use live credentials during this offline increment.

## Accepted read-only behavior

The normal provider factories are now bound to source-contract gateways that advertise only:

- connection/version check;
- authoritative username lookup;
- fetch status;
- synchronize/read snapshot;
- compatible-target discovery.

Real provider create/update/reset/suspend/activate/delete/rotate/delivery remain **unadvertised and fail closed**.

Important safety behavior:

- exact pinned provider version is required before authoritative reads;
- provider lookup failure is Manual Review/unavailable, never authoritative absence;
- HTTP redirects are disabled;
- TLS verification cannot be disabled;
- non-success provider bodies are not copied into normal evidence/messages;
- provider snapshot `canonicalHash` is an observable-state hash, not local create-request equality;
- Targets remain disabled/unproven for live operation.

## Next bounded offline increment

Implement **mutation contract mapping and deterministic fixtures**, but do not enable live mutation capabilities yet.

Required sequence:

1. Re-read pinned upstream source for each exact mutation route/model before coding.
2. Define provider-specific immutable request mappers from common operations:
   - create service;
   - update expiry;
   - update data allowance;
   - reset usage;
   - suspend;
   - activate;
   - delete;
   - rotate subscription link, only when the pinned provider contract supports an explicit safe operation;
   - delivery-artifact retrieval.
3. Define provider result mapper:
   - success;
   - definitive failure;
   - retryable failure before effect;
   - uncertain result after possible effect;
   - conflict/manual review.
4. Define a provider **create-equivalence mapper** so authoritative remote state can be compared to a local `PanelCreateServiceRequest` using only fields the provider actually preserves.
5. Preserve lookup-before-create and discovery-before-retry. A source fixture must prove that an uncertain create never causes an immediate second create.
6. Map provider targets/capabilities only from stable pinned source fields; keep live Targets disabled.
7. Use deterministic `Http::fake`/fixture tests. No real endpoint, credential or provider mutation in CI.
8. Do not advertise a mutation capability until its request, response, replay/conflict and uncertainty tests are complete.
9. Obtain exact implementation-head mandatory CI and artifact evidence.
10. Create bounded mutation-contract evidence/traceability and run exact evidence-head CI.

## Design constraints

- Do not change the common adapter contract merely to mirror one provider unless both common semantics and requirement traceability justify it.
- Do not treat Marzban username identity and PasarGuard numeric user ID as interchangeable; keep provider identity mapping explicit.
- Do not infer a provider field that is absent from the pinned source.
- Do not derive remote create equality from subscription URL, provider-generated proxy secrets or volatile usage/status fields.
- Do not log raw remote response bodies, access tokens, API keys, passwords, subscription links or proxy secrets.
- Keep auth/token handling ephemeral; no token persistence is required for the offline mapping increment.
- Mutation idempotency remains application-owned even when a provider lacks native idempotency keys.
- For providers without an atomic mutation/idempotency guarantee, discovery/reconciliation is mandatory after uncertainty.
- TLS system CA remains default; custom CA/pinning follows the accepted transport policy.

## Live verification deferred

Do not claim live compatibility until the owner supplies a controlled test panel through protected configuration.

When live verification becomes available, use a separate guarded gate that starts with read-only version/auth/lookup/target checks. Mutation tests must use disposable uniquely prefixed test users and explicit cleanup/reconciliation. Never paste credentials into repository files, Issues, PR comments, evidence or chat handoffs.

## Claims that remain prohibited

Until later live evidence exists, do not claim:

- real Marzban/PasarGuard connectivity;
- live create/update/delete behavior;
- provider-side idempotency;
- production Target activation;
- provider compatibility outside the exact pinned source versions;
- complete Provisioning/Service orchestration;
- Phase `0.4.0` closure.
