# Phase 0.4 Trial and Panel Adapter Continuation Handoff

**Status:** active candidate implementation; mandatory exact-SHA implementation/evidence gates are not complete.  
**Authoritative Issue/PR:** Issue `#7`, Draft PR `#6`.  
**Live head rule:** never continue from a SHA copied into this file; fetch PR `#6` and use its exact `head_sha`.

This file is the feature-specific handoff for the active unverified increment. It is not the project entry point and is not completion evidence. Start with:

1. `AGENTS.md`;
2. `PROJECT_STATUS.md`;
3. `docs/project-status.json`;
4. `docs/development/continuation-runbook.md`;
5. this handoff.

## Operating constraints

- Work only on `develop/v1.0.0-completion`.
- Keep PR `#6` Draft with base `main`.
- Do not merge, enable auto-merge, mark Ready, rewrite history, force-push, create a temporary branch, or push to `main`.
- Do not retrieve, print, copy, or persist secrets in logs, comments, evidence, fixtures, screenshots, prompts, or artifacts.
- Do not claim an increment complete until mandatory CI is green on the exact implementation SHA, retained evidence is inspected/digested, and mandatory CI is green on the exact evidence-head SHA.
- Do not begin real Marzban/PasarGuard compatibility testing until offline contract/static/security/MariaDB/Redis gates are accepted and the exact installed/provider versions are known.
- Preserve the Phase `0.5.0` pricing/payment boundary and Phase `0.6.0` Order/provisioning/service boundary.

## Last independently verified boundary

Custom Plan Policy and Calculation Snapshot:

- implementation SHA: `8e62867277acdd39cd1471ed3d454ef25520bef8`;
- implementation CI: `31071843621` / run `#833` — success;
- evidence SHA: `0d34af0aa4f9f227fdf3cae74b4fd4717f199ddf`;
- evidence CI: `31102652203` / run `#834` — success;
- suite: 255 tests, 1267 assertions;
- artifact: `test-evidence-31102652203`;
- artifact ID: `8968201643`;
- digest: `sha256:5ec6b6dd94e1305c17312650abdc94a5253521834911c26eb48f8decbd105cb9`;
- evidence: `evidence/0.4.0/custom-plan-policy-calculation.md`;
- traceability: `docs/29-phase-0.4-custom-plan-traceability.md`.

No later SHA is independently accepted yet.

## Current unverified bounded increment

The candidate combines:

- Trial policy, eligibility, capacity reservation, abuse controls, lifecycle, reset/regrant authority, and disclosed fallback;
- common Panel Adapter capability/operation/result/snapshot contracts;
- deterministic Fake adapter behavior and operation-key journaling;
- authoritative deterministic-username lookup and remote identity resolution;
- exact-match adoption, mismatch conflict/manual review, and unavailable-lookup fail-closed behavior;
- uncertain-create discovery before any subsequent create;
- validation/redaction for requests, credentials, capabilities, snapshots, results, and delivery artifacts;
- unavailable Marzban/PasarGuard adapter/gateway shells that fail closed.

An early candidate boundary was `e5d9344448118f4a34c00d483e0ab384a9e2f32d`. It is historical context only. CI/runtime/control-plane commits and bounded repairs followed it; fetch the PR for the current exact head.

## Trial implementation present

Primary implementation:

- `app/Modules/Catalog/Application/TrialPolicyService.php`;
- `app/Modules/Catalog/Application/TrialReservationService.php`;
- `app/Modules/Catalog/Application/TrialRouteSelector.php`;
- Trial request/context/eligibility/snapshot/receipt contracts;
- `database/migrations/2026_08_06_002200_create_trial_policy_foundation.php`.

Candidate behavior:

- Offering-scoped enabled/disabled policy;
- tier/tag/account and identity/membership requirements;
- one-per-user and one-per-phone controls where phone evidence exists;
- deterministic compatible route/fallback selection;
- real target-capacity hold integration;
- reservation expiration, commit, release, administrative reset/regrant;
- exact command replay and conflicting replay rejection;
- append-only trial history/event evidence;
- transactional guards against commit after expiry and invalid early expiration.

Primary tests:

- `tests/Feature/TrialPolicyReservationTest.php`;
- `tests/Unit/Modules/Catalog/TrialPolicyDomainTest.php`.

### Trial review hotspot

`TrialReservationService` is large and currently owns multiple responsibilities. Do not perform a broad refactor before the exact candidate is green. After a green baseline, extract bounded collaborators without changing schema/behavior:

- command/replay journal;
- actor/policy snapshot loader;
- route/capacity reservation transaction;
- lifecycle transition service;
- history/event writer;
- query/receipt hydrator.

Every extraction must preserve lock order, transaction boundaries, replay semantics, and existing tests.

## Panel Adapter contract present

Primary implementation:

- `app/Modules/Panels/Application/Contracts/`;
- `PanelAdapterRegistry`, `PanelAdapterSession`, `PanelCreateCoordinator`;
- `RemoteIdentityResolver` and resolution/disposition types;
- `FakePanelAdapter` and factory;
- delegating and unavailable gateway/adapter shells;
- Marzban/PasarGuard adapter/factory shells.

Candidate behavior:

- common capabilities and typed operations;
- authoritative remote lookup before create;
- exact-match adoption with no create;
- mismatch conflict/manual review;
- unavailable authoritative lookup means no create;
- uncertain create result triggers discovery and never immediate second create;
- Fake create/mutation operation keys store payload fingerprints and original results;
- exact replay returns the stored result;
- conflicting operation-key reuse returns a conflict and does not overwrite the original effect;
- unavailable real-provider shells fail closed for mutation and delivery;
- system CA mode rejects custom CA/pin material; TLS verification is never disabled.

Primary tests:

- `tests/Unit/Modules/Panels/PanelAdapterContractTest.php`;
- `tests/Unit/Modules/Panels/FakePanelMutationIdempotencyTest.php`;
- DTO/capability/result/snapshot/credential/delivery-artifact tests under `tests/Unit/Modules/Panels/`.

## Contract hardening present

- `PanelCreateServiceRequest` validates deterministic/safe creation input.
- `RemoteServiceSnapshot` validates remote identity, usage/limit values, status, timestamps, and canonical SHA-256.
- `PanelCapabilities` canonicalizes and validates declared provider operations/targets.
- `PanelOperationResult` bounds provider-facing codes/messages so raw bodies do not leak.
- `PanelCredentials` redacts JSON, debug, and string projections.
- `SensitiveDeliveryArtifacts` validates link/QR sources and redacts ordinary debug/string projections.

## Remote-effect invariants

Every change and test must preserve:

1. authoritative lookup immediately before create;
2. exact remote match means adopt;
3. mismatch means conflict/manual review;
4. unavailable authoritative lookup means no create;
5. uncertain create means discovery before any further create;
6. conflicting idempotency/operation-key reuse never overwrites or mutates the original result/effect;
7. TLS verification is never disabled;
8. system CA is default; custom CA/pinning is explicit and limited to configured private/self-signed endpoints;
9. credentials and delivery artifacts never enter ordinary logs, audit safe-data, exceptions, screenshots, or evidence.

## Stabilization work applied around this increment

The repository control plane was audited before accepting the candidate. Current changes include:

- deterministic self-hosted PHP/Composer bootstrap with explicit `php-cli.ini`, JIT disabled, and PCOV only in coverage mode;
- focused dependency repair for `league/commonmark` to patched `2.9.0`;
- Pint-only repair for five candidate files;
- temporary write-capable repair automation removed after validation;
- `AGENTS.md`, `PROJECT_STATUS.md`, machine-readable status/schema, contributor/continuation/CI/increment/repository-map documentation;
- current traceability/risk overlays and project audit;
- project-control verification integrated into CI preflight;
- legacy staging mutation workflows replaced by inert historical stubs;
- one guarded read-only `Staging Readiness` workflow.

These changes improve control/verification but do not verify the Trial/Panel behavior by themselves.

## Self-hosted runner contract

Expected runner:

- name: `freedom-staging-runner`;
- labels: `self-hosted`, `Linux`, `X64`, `freedom-staging`, `php84`;
- PHP: `/www/server/php/84/bin/php`;
- CLI INI: `/www/server/php/84/etc/php-cli.ini`;
- Composer: `/usr/local/bin/composer`;
- single-runner capacity, so jobs may serialize.

Mandatory bootstrap:

```bash
bash scripts/ci/bootstrap-self-hosted-toolchain.sh <coverage|no-coverage>
```

Do not reintroduce privileged `setup-php`; it blocked on interactive `sudo`. See `docs/development/ci-runner-contract.md`.

Historical Actions incidents/runs that failed before executable steps/logs, including old runs `#871` and `#872`, are not implementation evidence.

## Mandatory next sequence

1. Fetch PR `#6`; verify Draft/base/branch and record exact current head for the inspection.
2. Fetch workflow runs on that exact head.
3. If no exact-head `CI` run exists, request only: `Actions → CI → Run workflow → develop/v1.0.0-completion`.
4. Inspect every mandatory job and executable log:
   - Repository preflight;
   - Secret scan;
   - Dependency and license policy;
   - PHP static quality;
   - MariaDB and Redis tests.
5. Fix every real failure with the smallest focused commit on the same branch; do not weaken gates.
6. Repeat until mandatory CI is green on the exact implementation head.
7. Extract exact test/assertion counts from executable logs.
8. Retain and inspect the test artifact; record artifact name/ID and independently calculate SHA-256.
9. Create bounded Trial/Panel evidence and traceability without claiming real-provider compatibility, complete Provisioning, Order, payment, or production activation.
10. Run mandatory CI on the exact evidence-head SHA.
11. Only after both exact-SHA boundaries pass, update Issue `#7` and PR `#6`.
12. Only after offline evidence is accepted may exact installed Marzban/PasarGuard versions and OpenAPI behavior be tested through protected credentials/environment.

## Claims that must remain unmade

Until the sequence above is complete, do not claim:

- current accepted test/assertion totals;
- green Trial/Panel implementation CI;
- accepted Trial/Panel artifact/digest;
- real Marzban/PasarGuard compatibility;
- Panel target production activation;
- complete Provisioning orchestration;
- Phase `0.4.0` closure;
- staging or production deployment acceptance.
