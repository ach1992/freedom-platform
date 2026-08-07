# Phase 0.4 Controlled Live Provider Gate Handoff

**Status:** blocked on one human dependency: owner-supplied controlled provider test environment.  
**Authoritative Issue/PR:** Issue `#7`, Draft PR `#6`.  
**Live head rule:** always fetch PR `#6` and use its exact `head_sha`; never continue from a copied checkpoint SHA.

## Start with

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/continuation-runbook.md`;
5. `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
6. `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`;
7. `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`;
8. `docs/38-phase-0.4-panel-provider-mutation-contract-traceability.md`;
9. `evidence/0.4.0/provider-create-equivalence-reconciliation.md`;
10. `docs/40-phase-0.4-provider-create-equivalence-traceability.md`;
11. `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
12. this handoff.

## Last accepted implementation/evidence boundary

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

## Non-live Phase 0.4 provider state

The repository now has executable deterministic proof for:

- encrypted/validated Panel Connection and TLS policy foundations;
- Marzban `v0.8.4` and PasarGuard `v5.2.1` pinned read/auth/version/lookup/target mappings;
- offline mutation request/result/delivery mapping for create/update/reset/suspend/activate/delete/rotate;
- provider-specific create-equivalence mapping;
- explicit separation of provider-observable `canonicalHash` from create-intent equality;
- lookup-before-create;
- no create when lookup/equivalence proof is unavailable;
- exact-match adoption and mismatch conflict;
- uncertainty discovery before retry;
- conflicting idempotency-key reuse preservation;
- redaction and artifact safety;
- real provider mutation/delivery capabilities remaining unadvertised/fail closed;
- real Targets remaining disabled/unverified until live evidence exists.

No remaining non-live Phase 0.4 provider defect is currently identified by the reconciliation audit.

## Human blocker

The next action requires an owner-provided controlled environment:

- Marzban matching `v0.8.4`;
- PasarGuard matching `v5.2.1`;
- protected endpoint/credential configuration;
- permission to create, mutate, reconcile and delete uniquely prefixed disposable test users;
- preferably a dedicated least-privilege PasarGuard API key for its acceptance path.

If the supplied panel build differs from the pinned source version/build, do **not** silently proceed. Re-review that exact build first and create a new bounded compatibility increment if needed.

Do not ask the owner to paste credentials into chat, repository text, Issues, PR comments or evidence. Credentials belong only in the protected runtime/secret mechanism used by the eventual live acceptance harness.

## Exact live execution contract

Use `docs/41-phase-0.4-provider-live-acceptance-matrix.md` as the execution checklist.

Order is mandatory:

1. protected authentication;
2. exact version/health check;
3. target/capability discovery;
4. authoritative unique-username absence proof;
5. create and post-read equivalence;
6. exact-match adoption and mismatch/conflict checks;
7. expiry/data/reset/suspend/activate/rotate/delivery checks;
8. controlled timeout/5xx uncertainty with discovery before retry;
9. delete and final authoritative absence/cleanup;
10. separate explicit Target activation acceptance only after all required rows pass.

A failed/uncertain row stops later effectful rows until authoritative reconciliation is complete.

## Safety rules that remain active

- PR #6 stays Draft, base `main`, head `develop/v1.0.0-completion`;
- no merge, Ready, auto-merge, history rewrite, force-push, `main` write or temporary branch;
- no production Target activation from source/offline evidence;
- no mutation retry after uncertainty before discovery;
- no create when authoritative lookup is unavailable;
- no adoption without provider-specific create-equivalence proof;
- no idempotency conflict overwrite;
- TLS verification stays enabled;
- no raw sensitive provider body, credential, token, subscription URL or proxy/config secret enters evidence/logs;
- no Phase `0.4.0` closure claim until the controlled live matrix and final closure audit are accepted.

## What may proceed while blocked

Do not manufacture provider evidence by installing temporary panels. Work in later phases may proceed only if the repository phase policy explicitly allows independent foundations without weakening this blocked Phase 0.4 gate; do not mark Phase 0.4 complete or activate its real provider Targets.

For continuation specifically on Phase 0.4 provider work, stop at this human gate until the controlled test environment is supplied.