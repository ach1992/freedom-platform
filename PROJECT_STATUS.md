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
- active Phase 0.4 continuation handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`;
- accepted parallel Phase 0.5 ledger evidence: `evidence/0.5.0/financial-ledger-foundation.md`;
- accepted parallel Phase 0.5 ledger traceability: `docs/45-phase-0.5-financial-ledger-traceability.md`;
- accepted parallel Phase 0.5 wallet-hold evidence: `evidence/0.5.0/wallet-holds-capture-release.md`;
- accepted parallel Phase 0.5 wallet-hold traceability: `docs/46-phase-0.5-wallet-holds-traceability.md`;
- active parallel Phase 0.5 handoff: `docs/47-phase-0.5-wallet-reconciliation-handoff.md`.

## Live-state rule

Do not treat a SHA written in this document as the current working head. Before work, fetch PR `#6` and use its exact `head_sha`, then inspect workflow runs for that exact SHA. Follow `AGENTS.md` and `docs/development/continuation-runbook.md`.

## Last evidence-complete active-phase boundary

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

- PasarGuard `v5.2.1` has a guarded manual live-acceptance harness behind protected Actions Secrets;
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

## Active Phase 0.4 increment

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

- PasarGuard `v5.2.1` is tested now when its protected workflow can be dispatched;
- Marzban `v0.8.4` live acceptance is deferred to final project/release acceptance;
- Marzban remains a mandatory `1.0.0` requirement and is not removed or considered live-accepted;
- Phase `0.4.0` / Issue `#7` therefore remains open.

This is a schedule deferral, not a scope deletion.

## Parallel Phase 0.5 accepted foundations

Phase `0.5.0` is **not** marked active or complete. The following independent financial foundations are accepted only under the parallel-continuation policy while Phase `0.4.0` remains open.

### Financial Ledger Foundation

Implementation:

- SHA `259e29e6f93c2b36cd36c2f40bf669789eaab62f`;
- CI `31232006308` / `#1037` — success;
- 326 tests / 1777 assertions;
- artifact `test-evidence-31232006308`, ID `9014138405`;
- independent digest `sha256:cfda302c54c7089dbb15eb0c83a546f63bd37a142c99e5919b8408dfa85b7610`.

Evidence:

- SHA `76c00a1c458c12ccc07dc658ee2f69e14389e65c`;
- CI `31232151035` / `#1039` — success;
- 326 tests / 1777 assertions;
- artifact `test-evidence-31232151035`, ID `9014190245`;
- independent digest `sha256:8354c7fc431a48390ea26211e30fafa69957e75690d91c1dc14e1df8de046510`;
- evidence `evidence/0.5.0/financial-ledger-foundation.md`;
- traceability `docs/45-phase-0.5-financial-ledger-traceability.md`.

Accepted bounded behavior: integer-IRR money, balanced append-only ledger posting, system/user wallet account constraints, exact replay/conflict protection, row-lock/transaction foundation and database-enforced ledger immutability. Holds/capture/release were not claimed by this first boundary.

### Wallet Holds, Available Balance, Capture and Release

Implementation:

- SHA `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf`;
- CI `31232610814` / `#1046` — success;
- 331 tests / 1834 assertions;
- artifact `test-evidence-31232610814`, ID `9014323613`;
- independent digest `sha256:9a5154dd6cb0baeb52880c8fbc86e06fcdccd98b9bd62cc41eed42ca745f06f1`.

Evidence:

- SHA `781a2dd63d999b2e01d99cd6888f3c9cbfda9f26`;
- CI `31232750290` / `#1048` — success;
- 331 tests / 1834 assertions;
- artifact `test-evidence-31232750290`, ID `9014371764`;
- independent digest `sha256:9f925e3e22c50384dcce39034b861cadc5368a58e9e76ef03de8a8e45af90367`;
- evidence `evidence/0.5.0/wallet-holds-capture-release.md`;
- traceability `docs/46-phase-0.5-wallet-holds-traceability.md`.

Accepted bounded behavior: active holds reduce ledger-derived available balance, no hold may create negative available balance, placement replay/conflict is exact, capture produces exactly one balanced ledger effect, release produces no ledger rewrite, captured/released states are terminal, expired active holds remain reserved until explicit cleanup, and database guards make hold identity/history non-deletable/immutable.

Remaining `WAL-002` work includes persisted non-authoritative balance snapshots, reconciliation, automated expired-hold cleanup and dedicated contention verification. `WAL-001`, `WAL-003`, `WAL-004`, `WAL-005`, pricing, payment providers and later Order/provisioning work remain unclaimed.

## Active parallel Phase 0.5 bounded increment

### Wallet Snapshot, Reconciliation and Expired-Hold Cleanup

Status: active parallel bounded work.  
Authoritative handoff: `docs/47-phase-0.5-wallet-reconciliation-handoff.md`.

The next work must preserve the immutable ledger as source of truth, treat persisted balance snapshots as derived/non-authoritative, surface mismatches instead of repairing financial history by mutation, and release only authoritatively expired active holds through the existing terminal release path.

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
- financial snapshots/reconciliation and dedicated contention stress are not yet accepted;
- Phase `0.4.0` remains open.

## Non-negotiable remote-effect and financial rules

- authoritative remote lookup before every create;
- unavailable lookup means no create;
- automatic adoption requires provider-specific accepted create-equivalence proof;
- missing equivalence proof means Manual Review/no create;
- mismatch means conflict/manual review;
- uncertain mutation means discovery first, never blind immediate retry;
- conflicting idempotency-key reuse never overwrites the original primary effect;
- TLS verification is never disabled;
- no credential, token, API key, password, subscription material or raw sensitive provider response enters repository evidence/logs;
- no real provider mutation capability or Target activation until the applicable live gate is explicitly accepted;
- monetary IRR values remain integers at financial boundaries;
- immutable balanced ledger history is authoritative; derived snapshots/caches never authorize a financial effect without a fresh transaction/lock-based authoritative read;
- no financial replay or conflict may create/overwrite a second accepted primary effect.

## Phase completion state

Phase `0.4.0` is **not closed**. PasarGuard's guarded harness is evidence-complete, actual PasarGuard live execution awaits protected secret configuration/manual dispatch, and Marzban live acceptance is intentionally carried to the final project/release gate by owner decision. Parallel Phase `0.5.0` foundations may continue under the policy above but cannot be used to claim Phase `0.4.0` or release completion.
