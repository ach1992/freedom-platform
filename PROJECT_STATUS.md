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
- controlled live matrix: `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
- active continuation handoff: `docs/42-phase-0.4-controlled-live-provider-handoff.md`.

## Live-state rule

Do not treat a SHA written in this document as the current working head. Before work, fetch PR `#6` and use its exact `head_sha`, then inspect workflow runs for that exact SHA. Follow `AGENTS.md` and `docs/development/continuation-runbook.md`.

## Last evidence-complete boundary

### Phase 0.4 increment 9 — Provider Create-Equivalence Reconciliation

Implementation boundary:

- SHA: `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43`;
- CI: `31223470871` / run `#995` — success;
- suite: 315 tests, 1678 assertions;
- artifact: `test-evidence-31223470871`;
- artifact ID: `9011256284`;
- independently verified digest: `sha256:f3fc78ab55ba0adbb2814bb6d0de464736e897df36625b8f72f54b3f7b8fe95d`.

Evidence boundary:

- SHA: `a70b28cb984c23f1e219287e88d20014ee9f0310`;
- CI: `31223749257` / run `#997` — success;
- suite: 315 tests, 1678 assertions;
- artifact: `test-evidence-31223749257`;
- artifact ID: `9011359876`;
- independently verified digest: `sha256:140e6a45fe2ef9523dee0147f6131a1e2d1bcaf63548b57eea7b83d3f0d9b827`;
- evidence: `evidence/0.4.0/provider-create-equivalence-reconciliation.md`;
- traceability: `docs/40-phase-0.4-provider-create-equivalence-traceability.md`.

Accepted behavior:

- `RemoteServiceSnapshot::canonicalHash` remains provider-observable read-state evidence and is not create equality;
- automatic adoption requires separate provider-specific `createEquivalenceHash` derived only from preserved create fields;
- Marzban `v0.8.4` and PasarGuard `v5.2.1` source gateways derive create-equivalence through their accepted pinned mutation-contract mappers;
- missing/invalid equivalence proof => Manual Review and no create;
- authoritative lookup unavailable => no create;
- mismatch => conflict/no overwrite;
- successful create without an authoritative verifiable snapshot remains uncertain;
- uncertain mutation/create still requires authoritative discovery before retry;
- conflicting idempotency-key reuse cannot overwrite the original primary effect;
- real provider mutation/delivery capabilities remain unadvertised and fail closed;
- real Targets remain disabled/unverified.

This boundary does **not** prove live connectivity, deployment-specific compatibility or any real provider mutation effect.

## Prior accepted provider boundaries

Pinned read contracts:

- Marzban `v0.8.4`, PasarGuard `v5.2.1`;
- implementation `954973e505901208b5cef9348551e0c71ac027b6`, CI #959;
- evidence `23a8da1ce1327407ecd826daa87b334452883d77`, CI #961.

Pinned offline mutation contracts:

- implementation `15b824e955d040a6bf43405f7015aa85110a73e4`, CI #978;
- evidence `eec613c1cb5241d8fff621047086361f2753fd24`, CI #980;
- deterministic request/result/delivery mapping for create, expiry/data updates, reset, suspend/activate, delete and explicit subscription rotation;
- real mutations remained disabled throughout.

## Active increment

### Controlled Live Provider Acceptance

Status: **blocked on owner-supplied controlled provider test environments**.  
Authoritative handoff: `docs/42-phase-0.4-controlled-live-provider-handoff.md`.  
Execution matrix: `docs/41-phase-0.4-provider-live-acceptance-matrix.md`.

The non-live reconciliation found and fixed the create-equivalence defect, reconciled traceability/risk/execution status, verified runtime provider mutations/Targets remain fail closed, and identified no further non-live Phase 0.4 provider defect.

The next required evidence is deployment-specific and cannot be manufactured safely from source fixtures alone.

## Human dependency

Owner must provide controlled test environments for:

- Marzban matching `v0.8.4`;
- PasarGuard matching `v5.2.1`;
- or explicit authorization to re-review a different exact build before testing it.

The controlled environment must provide, through protected runtime/secret configuration only:

- endpoint and least-privilege credentials;
- permission to create, mutate, reconcile and delete uniquely prefixed disposable test users;
- permission to perform the bounded fault/reconciliation scenarios in `docs/41-phase-0.4-provider-live-acceptance-matrix.md`.

Credentials must **not** be pasted into repository files, Issues, PR comments, evidence, handoffs or chat.

## Deferred live-provider gate

Required live acceptance sequence:

1. protected authentication;
2. exact version/health verification;
3. compatible target discovery;
4. authoritative unique-username absence proof;
5. create and provider-specific post-read equivalence;
6. exact-match adoption and mismatch/conflict;
7. expiry/data/reset/suspend/activate/rotation/delivery;
8. timeout/5xx/rate-limit uncertainty with discovery before retry;
9. delete and final authoritative absence/cleanup;
10. separate explicit Target activation acceptance only after all applicable rows pass.

An uncertain or failed effectful row stops later effectful rows until authoritative reconciliation completes.

## Known risks

- source-contract fixtures cannot prove reverse-proxy/TLS-chain/plugins/forks/permissions/runtime behavior of the deployed panel;
- provider-side idempotency is not assumed; application-owned discovery/reconciliation remains mandatory;
- no offline test can prove post-effect discovery on a real panel;
- `TrialReservationService` remains a reviewability hotspot but no current Phase 0.4 correctness gap was identified there;
- Phase `0.4.0` remains open until the controlled live gate and final closure audit pass.

## Non-negotiable remote-effect rules

- authoritative remote lookup before every create;
- unavailable lookup means no create;
- automatic adoption requires provider-specific accepted create-equivalence proof;
- missing equivalence proof means Manual Review/no create;
- mismatch means conflict/manual review;
- uncertain mutation means discovery first, never blind immediate retry;
- conflicting idempotency-key reuse never overwrites the original primary effect;
- TLS verification is never disabled;
- no credential, token, API key, password, subscription material or raw sensitive provider response enters logs/evidence;
- no real provider mutation capability or Target activation until the controlled live gate is explicitly accepted.

## Phase completion state

Phase `0.4.0` is **not closed**. The repository has reached the genuine human live-provider gate. No additional provider source/offline work should be invented merely to avoid that gate, and future-phase work must not be pulled forward to claim Phase 0.4 completion.