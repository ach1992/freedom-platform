# Project Status

This is the single human-readable current-state entry point. Detailed history belongs in evidence, traceability, risk, audit, and handoff documents.

**Last status review:** 2026-08-08  
**Target release:** `1.0.0`  
**Active phase:** `0.4.0 — Catalog, Panels and Offerings`  
**Authoritative phase Issue:** `#7`  
**Authoritative integration PR:** `#6`  
**Allowed branch:** `develop/v1.0.0-completion`  
**PR base/state:** `main` / Draft

Current control documents:

- traceability: `docs/32-current-traceability-overlay.md`;
- risks: `docs/33-current-risk-overlay.md`;
- execution ledger: `docs/00-execution-ledger.md`;
- accepted provider-read evidence: `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
- accepted provider-read traceability: `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`;
- accepted provider-mutation evidence: `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`;
- accepted provider-mutation traceability: `docs/38-phase-0.4-panel-provider-mutation-contract-traceability.md`;
- accepted provider-equivalence evidence: `evidence/0.4.0/provider-create-equivalence-reconciliation.md`;
- accepted provider-equivalence traceability: `docs/40-phase-0.4-provider-create-equivalence-traceability.md`;
- provider live matrix: `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
- accepted PasarGuard harness evidence: `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- accepted PasarGuard harness traceability: `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`;
- active continuation handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`.

## Live-state rule

Do not treat a SHA written in this document as the current working head. Before work, fetch PR `#6` and use its exact `head_sha`, then inspect workflow runs for that exact SHA. Follow `AGENTS.md` and `docs/development/continuation-runbook.md`.

## Last evidence-complete boundary

### Phase 0.4 increment 10 — PasarGuard Guarded Live-Acceptance Harness

Implementation boundary:

- SHA: `e18460357d306789cbbf85721f61a4e3a3bbb0e2`;
- CI: `31226863010` / run `#1013` — success;
- suite: 317 tests, 1730 assertions;
- artifact: `test-evidence-31226863010`;
- artifact ID: `9012421937`;
- independently verified digest: `sha256:72d10f54f0347ac743471c78ea4401a4b9268a763e6653cb2a3c17bf4e2608e6`.

Evidence boundary:

- SHA: `71ca4b39df41bc9fcf725c30e9caba3285ee5412`;
- CI: `31227084007` / run `#1015` — success;
- suite: 317 tests, 1730 assertions;
- artifact: `test-evidence-31227084007`;
- artifact ID: `9012490991`;
- independently verified digest: `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`;
- evidence: `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- traceability: `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

Accepted behavior:

- PasarGuard `v5.2.1` now has a guarded manual live-acceptance harness behind protected Actions Secrets;
- exact version mismatch stops before mutation;
- the prepared sequence proves lookup-before-create, one-create intent, provider-specific create equivalence, mismatch/no-overwrite, expiry/data/reset/suspend/activate, protected rotation/delivery, delete and cleanup when actually dispatched;
- deterministic tests prove one create/one delete and sanitized output;
- HTTPS-only transport, peer/host TLS verification and no redirects are enforced;
- the live workflow does not accept credentials through ordinary workflow inputs;
- real runtime PasarGuard mutation/delivery capabilities remain unadvertised/fail-closed;
- real Service Targets remain disabled/unverified.

This boundary proves the **harness**, not successful connectivity or remote mutation against the owner deployment.

## Prior accepted provider boundaries

- Provider Create-Equivalence Reconciliation: implementation `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43` / CI #995; evidence `a70b28cb984c23f1e219287e88d20014ee9f0310` / CI #997.
- Pinned read contracts: Marzban `v0.8.4`, PasarGuard `v5.2.1`; implementation CI #959, evidence CI #961.
- Pinned offline mutation contracts: implementation CI #978, evidence CI #980; real mutations remained disabled.

## Active increment

### PasarGuard Controlled Live Execution

Status: **blocked on protected Actions secret configuration and manual workflow dispatch**.  
Authoritative handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`.  
Execution matrix: `docs/41-phase-0.4-provider-live-acceptance-matrix.md`.

The available GitHub integration can inspect Actions runs/artifacts and retry existing failed jobs, but cannot create/update repository Actions Secrets or initiate a new `workflow_dispatch`.

Required protected configuration outside repository text:

- `PASARGUARD_TEST_ORIGIN`;
- `PASARGUARD_TEST_API_KEY`.

After those Secrets are configured, manually dispatch `Provider Live Acceptance - PasarGuard` on `develop/v1.0.0-completion` using its exact confirmation value. Do not put secret values in workflow inputs, commits, Issues, PR comments, evidence or logs.

## Provider scheduling decision

Owner decision on 2026-08-08:

- PasarGuard `v5.2.1` is tested now;
- Marzban `v0.8.4` live acceptance is deferred to final project/release acceptance;
- Marzban remains a mandatory `1.0.0` requirement and is not removed or considered live-accepted;
- Phase `0.4.0` / Issue `#7` therefore remains open.

This is a schedule deferral, not a scope deletion.

## Parallel continuation policy

The repository may continue later-phase implementation while the protected PasarGuard dispatch and deferred Marzban final gate remain carried blockers, provided that:

- Phase `0.4.0` is not marked closed;
- Issue `#7` remains open;
- no later phase weakens lookup/create/idempotency/TLS/redaction/Target activation controls;
- no release or production-readiness claim is made before the carried provider gates are resolved;
- real provider Targets remain disabled until their explicit live acceptance passes.

## Remaining PasarGuard live work

Prepared harness rows require live execution and artifact review. Separate rows still require coordinator/fault/activation work:

- exact-match adoption through the application integration path;
- idempotency conflict/replay against the real provider integration path;
- controlled timeout/5xx/429 uncertainty and discovery-before-retry;
- explicit Service Target activation acceptance.

A failed or uncertain effectful row stops later effectful rows until authoritative reconciliation completes.

## Known risks

- source-contract/harness tests cannot prove reverse-proxy, TLS-chain, plugin/fork, permission or runtime behavior of the deployed panel;
- provider-side idempotency is not assumed;
- no offline test can prove post-effect discovery on a real panel;
- live credentials supplied outside the protected Actions secret store are not evidence and are intentionally not retained in repository artifacts;
- Marzban live compatibility remains a final-release blocker;
- Phase `0.4.0` remains open.

## Non-negotiable remote-effect rules

- authoritative remote lookup before every create;
- unavailable lookup means no create;
- automatic adoption requires provider-specific accepted create-equivalence proof;
- missing equivalence proof means Manual Review/no create;
- mismatch means conflict/manual review;
- uncertain mutation means discovery first, never blind immediate retry;
- conflicting idempotency-key reuse never overwrites the original primary effect;
- TLS verification is never disabled;
- no credential, token, API key, password, subscription material or raw sensitive provider response enters repository evidence/logs;
- no real provider mutation capability or Target activation until the applicable live gate is explicitly accepted.

## Phase completion state

Phase `0.4.0` is **not closed**. PasarGuard's guarded harness is evidence-complete, actual PasarGuard live execution awaits protected secret configuration/manual dispatch, and Marzban live acceptance is intentionally carried to the final project/release gate by owner decision.