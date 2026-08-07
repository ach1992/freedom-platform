# Phase 0.4 Provider Reconciliation and Deferred Live-Acceptance Handoff

**Status:** active non-live Phase `0.4.0` reconciliation.  
**Authoritative Issue/PR:** Issue `#7`, Draft PR `#6`.  
**Live head rule:** fetch PR `#6` before every write and use its exact `head_sha`.

## Start with

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/continuation-runbook.md`;
5. `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
6. `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`;
7. `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`;
8. `docs/38-phase-0.4-panel-provider-mutation-contract-traceability.md`;
9. this handoff.

## Last accepted boundary

### Pinned Panel Provider Mutation Contract Mapping

Implementation:

- SHA: `15b824e955d040a6bf43405f7015aa85110a73e4`;
- CI: `31189964977` / run `#978` — success;
- suite: 313 tests, 1650 assertions;
- artifact: `test-evidence-31189964977`;
- artifact ID: `8998394458`;
- independently verified digest: `sha256:80cbd67bff1373b5aa97f73f252a001858ac6d73b799f8a26805c874f9ee6737`.

Evidence:

- SHA: `eec613c1cb5241d8fff621047086361f2753fd24`;
- CI: `31190453594` / run `#980` — success;
- suite: 313 tests, 1650 assertions;
- artifact: `test-evidence-31190453594`;
- artifact ID: `8998628512`;
- independently verified digest: `sha256:ac5caaaad4a83af04efd27f3c88cfeb857755e497bd07a46fa1037eadea4361a`;
- evidence: `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`;
- traceability: `docs/38-phase-0.4-panel-provider-mutation-contract-traceability.md`.

## Accepted provider state

Pinned source contracts:

- Marzban `v0.8.4`;
- PasarGuard `v5.2.1`.

Accepted runtime behavior remains read-only:

- exact-version connection/read checks;
- authoritative username lookup;
- status/synchronization;
- compatible-target discovery.

Accepted offline-only behavior now additionally includes deterministic mutation request/result/delivery/create-equivalence mapping for create/update/reset/suspend/activate/delete/rotate.

The offline mutation mappers do **not** activate runtime mutations.

## Non-negotiable safety state

Continue to preserve:

- real provider mutations fail closed;
- real Targets remain disabled/unproved;
- authoritative lookup before create;
- lookup unavailable means no create;
- create adoption requires provider-specific accepted equivalence;
- mismatch means conflict/manual review;
- uncertain mutation requires discovery/reconciliation before retry;
- idempotency-key conflict must not overwrite the original primary effect;
- TLS verification is never disabled;
- credentials/subscription/proxy secrets never enter repository evidence or normal logs.

Do not change `AbstractPinnedReadOnlyPanelGateway` to perform a real mutation during this reconciliation increment.

## Next bounded work: Phase 0.4 reconciliation

Perform a source-backed, repository-backed closure-preparation audit that can be completed without a live provider:

1. Reconcile `PRV-001`–`PRV-003` against the accepted read and mutation-contract boundaries.
2. Reconcile supporting `SEC-001`, `SEC-002`, `DAT-001`, `DAT-003`, `QUA-001` claims where Phase 0.4 provider behavior depends on them.
3. Verify every runtime provider factory/capability path still advertises only accepted read capabilities.
4. Verify no current Service Target becomes operational solely because source-contract mapping exists.
5. Verify lookup-before-create/no-create-on-unavailable/discovery-before-retry/conflicting-idempotency preservation remain covered by executable tests.
6. Build the exact deferred live-provider acceptance matrix, including:
   - protected authentication;
   - exact version/capability check;
   - authoritative lookup and target discovery;
   - create absence path;
   - exact-match adoption;
   - mismatch/conflict path;
   - expiry/data/reset/suspend/activate/delete/rotate mutations;
   - delivery;
   - transport/5xx uncertainty and discovery before retry;
   - disposable test-user cleanup/reconciliation.
7. Separate source-contract proof from deployment-specific proof. Do not mark any live matrix row complete without a controlled test panel.
8. Reconcile current traceability/risk overlays and identify any remaining non-live Phase 0.4 defect that can be fixed before the human gate.
9. If a real non-live defect exists, implement it as a separate bounded increment with exact implementation CI/artifact/evidence-head CI.
10. When only controlled live panels/credentials remain, record that as the genuine human blocker. Do not install temporary provider panels merely to manufacture live evidence.

## Phase boundaries

Do not pull future work forward:

- Phase `0.5.0`: ledger/pricing/discount/referral/agent pricing/Quotes/payment providers;
- Phase `0.6.0`: Orders, provisioning orchestration and Service lifecycle;
- later Telegram/support/reporting/release work.

Phase `0.4.0` closure may only assert the Catalog/Panels/Offerings scope actually proven here.

## Live provider gate

The owner intentionally deferred live provider testing until controlled test panels are supplied.

A controlled live gate must use protected runtime/secret configuration. Never place credentials in repository files, Issues, PR comments, evidence or handoff text.

Until that human dependency is supplied, prohibited claims remain:

- real Marzban/PasarGuard connectivity;
- deployment-specific compatibility;
- real remote mutation success/idempotency;
- production Target activation;
- complete Provisioning/Service orchestration;
- Phase `0.4.0` closure.
