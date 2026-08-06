# Phase 0.4 Trial and Panel Adapter Continuation Handoff

Status: active, implementation present, mandatory CI evidence not yet complete.

This document is a continuation checkpoint for Issue #7 and Draft PR #6. It is not completion evidence and must not be cited as proof that the current increment is verified.

## Operating constraints

- Work only on `develop/v1.0.0-completion`.
- Keep PR #6 Draft with base `main`.
- Do not merge, enable auto-merge, mark Ready, rewrite history, or push directly to `main`.
- Do not print, copy, or persist secrets in logs, comments, evidence, fixtures, or prompts.
- Do not claim an increment complete until mandatory CI is green on the exact implementation SHA, retained evidence is recorded, and a second CI run is green on the evidence-head SHA.
- Do not begin real Marzban or PasarGuard compatibility testing until offline contract, static, architecture, security, MariaDB, and Redis gates are green.

## Last independently verified boundary

Custom Plan Policy and Calculation Snapshot is already independently verified:

- implementation: `8e62867277acdd39cd1471ed3d454ef25520bef8`
- implementation CI: `31071843621` / #833 — success
- evidence head: `0d34af0aa4f9f227fdf3cae74b4fd4717f199ddf`
- evidence CI: `31102652203` / #834 — success
- 255 tests, 1267 assertions
- artifact digest: `sha256:5ec6b6dd94e1305c17312650abdc94a5253521834911c26eb48f8decbd105cb9`
- evidence: `evidence/0.4.0/custom-plan-policy-calculation.md`
- traceability: `docs/29-phase-0.4-custom-plan-traceability.md`

## Current unverified bounded increment

The current implementation combines the remaining Trial policy/reservation work with the common Panel Adapter contract and remote idempotency foundation. The main implementation boundary before CI-routing changes was:

- `e5d9344448118f4a34c00d483e0ab384a9e2f32d`

Do not assume that SHA is the current branch head. Always fetch PR #6 metadata and use its exact `head_sha` before inspecting or running CI.

### Trial implementation present

- Trial policy and reservation foundation, including identity evidence, membership/eligibility checks, one-per-user and one-per-phone controls, target capacity use, deterministic fallback, expiration, commit, and release behavior.
- Transactional guards reject commit after expiry and reject early expiration.
- Test fixtures were corrected to respect the Owner singleton, target capacity semantics, and globally unique reservation keys.
- Primary file: `tests/Feature/TrialPolicyReservationTest.php`.
- Foundation migration: `database/migrations/2026_08_06_002200_create_trial_policy_foundation.php`.

### Panel Adapter contract present

- Common adapter registry and capability contract.
- Exact remote lookup before create.
- Exact-match adoption with no create.
- Mismatch conflict/manual review.
- Lookup unavailable means fail closed and no create.
- Uncertain create result requires discovery first and never permits an immediate second create.
- Lost-response discovery and adoption are covered by contract tests.
- Fake adapter journals create and mutation operation keys with payload fingerprints; exact replay returns the original result and conflicting reuse returns `fake_idempotency_conflict`.
- Unavailable real-provider shells fail closed for mutation and delivery operations.
- System CA mode rejects custom CA/pin material; TLS verification must never be disabled.
- Primary contract test: `tests/Unit/Modules/Panels/PanelAdapterContractTest.php`.
- Fake mutation idempotency test: `tests/Unit/Modules/Panels/FakePanelMutationIdempotencyTest.php`.

### Contract hardening present

- `PanelCreateServiceRequest` validates safe deterministic inputs.
- `RemoteServiceSnapshot` validates identifiers, counters, and canonical SHA-256 hashes.
- `PanelCapabilities` validates and canonicalizes capability declarations.
- `PanelOperationResult` bounds provider codes/messages to prevent raw-body leakage.
- `PanelCredentials` is redacted for JSON, debug, and string conversion.
- `SensitiveDeliveryArtifacts` validates subscription-link and QR-source lists and redacts debug/string output.

## Remote-effect invariants

The next implementation must preserve these fail-closed rules:

1. Perform authoritative remote lookup before every create.
2. Adopt only an exact match.
3. Treat mismatches as conflict/manual review.
4. If authoritative lookup is unavailable, do not create.
5. If a create result is uncertain, perform discovery; do not issue a second create immediately.
6. Reuse of an operation/idempotency key with a different fingerprint must never overwrite or mutate the original remote effect.
7. Never disable TLS verification. Use the system CA by default; custom CA/pinning is only for explicitly configured private/self-signed endpoints.

## CI runner checkpoint

The repository has a connected runner named `freedom-staging-runner`, observed Online/Idle with labels:

- `self-hosted`
- `Linux`
- `X64`
- `freedom-staging`
- `php84`

The CI workflow was changed from GitHub-hosted `ubuntu-24.04` to those exact labels:

- routing commit: `22261aa2e51b92fac5d9393c4d5632b1d508f068`
- hardening commit: `408c4d8a58cac09863c23f3276bdd416d948b5f5`

The hardening change also:

- uses branch-aware concurrency so a manual run and PR run for the same branch do not waste the single runner;
- permits self-hosted execution only for same-repository PRs, while retaining trusted `workflow_dispatch` and `main` push execution.

Previous runs #871 and #872 failed before any executable step/log was created during a GitHub Actions incident. They are infrastructure failures and are not implementation evidence.

Connector-based file commits may not trigger Actions automatically. If no run exists for the current exact PR head, manually use Actions -> CI -> Run workflow -> `develop/v1.0.0-completion`.

## Mandatory next sequence

1. Fetch PR #6 and record the exact current `head_sha`.
2. Find or manually start CI for that exact SHA on the self-hosted runner.
3. Inspect every job and log: Repository preflight, Secret scan, Dependency and license policy, PHP static quality, and MariaDB and Redis tests.
4. Fix only observed failures, using small focused commits on the same branch.
5. Repeat until CI is green on the exact implementation SHA.
6. Record the exact run ID, test count, assertion count, artifact ID/name, and SHA-256 digest.
7. Create bounded evidence and traceability for the Trial and Panel Adapter increment without claiming real-provider compatibility.
8. Run CI again on the evidence-head SHA and require it to be green.
9. Update Issue #7 and PR #6 only after both implementation and evidence-head runs are green.
10. Only then test exact supported Marzban/PasarGuard versions and OpenAPI behavior using staging/provider secrets already stored in GitHub Actions Secrets.

## Evidence that must remain unclaimed

Until the sequence above is complete, do not claim:

- current test/assertion totals;
- a green CI run for the Trial/Panel increment;
- artifact retention or digest for the increment;
- real Marzban/PasarGuard compatibility;
- Phase 0.4 closure;
- Production target enablement.
