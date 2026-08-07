# Current Traceability Overlay

**Last reviewed:** 2026-08-07  
**Purpose:** correct current implementation/evidence status while the large baseline matrix is incrementally reconciled.  
**Authority:** this overlay does not replace requirement definitions in `docs/01-authoritative-requirements.md`; it replaces stale status cells in `docs/02-requirement-traceability-matrix.md` where the two disagree.

Read with `PROJECT_STATUS.md`, `docs/project-status.json`, and the active handoff. Live head still comes from PR `#6`.

## Status vocabulary

- `verified`: implementation and evidence-head exact-SHA gates passed;
- `active`: current bounded implementation/evidence work;
- `foundation-only`: partial prerequisite exists; owning workflow remains incomplete;
- `deferred-live`: offline/source-contract work may continue, but live provider/environment acceptance is intentionally postponed;
- `not-started`: no accepted implementation claim.

## Accepted phase status

| Phase | Status | Authoritative evidence |
|---|---|---|
| `0.1.0` | verified | planning quality gate and baseline documents |
| `0.2.0` | verified | `evidence/0.2.0/PHASE-CLOSURE.md` |
| `0.3.0` | verified | `evidence/0.3.0/phase-closure-verification.md` |
| `0.4.0` | active | verified increments through Trial/Panel offline/Fake foundation; pinned provider source-contract adapter work is active |
| `0.5.0`–`1.0.0` | not-started except explicit earlier foundations | Master Prompt phase plan |

## Phase 0.4 verified requirements

### Catalog lifecycle

| Requirement | Status | Code/tests/evidence |
|---|---|---|
| `CAT-001` | verified | Catalog domain/application/migration; `CatalogLifecycleServicesTest`; `evidence/0.4.0/catalog-category-product-variant-lifecycle.md`; `docs/23-phase-0.4-catalog-traceability.md` |
| `CAT-002` | verified foundation through offering/inventory increments | Product/Offering/Panel inventory services; migration/test/evidence in `docs/23-26-*` |
| `CAT-003` | verified | typed service mode in Plan Offering; `docs/26-phase-0.4-plan-offering-traceability.md` |
| `CAT-004` | verified foundation | typed protocol profiles/targets and assignments; `docs/25-26-*` |

### Capacity, selection, custom plans and Trial

| Requirement | Status | Code/tests/evidence |
|---|---|---|
| `CAT-005` | verified | Custom Plan services/migrations/tests; `evidence/0.4.0/custom-plan-policy-calculation.md`; `docs/29-phase-0.4-custom-plan-traceability.md` |
| `CAT-006` | verified offline/database foundation | Trial policy, eligibility, membership policy, capacity reservation, abuse controls, lifecycle/regrant/reset and disclosed fallback; `evidence/0.4.0/trial-policy-panel-adapter-foundation.md`; `docs/34-phase-0.4-trial-panel-traceability.md` |
| `CAT-008` | verified through capacity/availability/selection/fallback plus Trial integration | `TargetCapacity*`, route selection and Trial reservation services/tests; `docs/27-28-*`, `docs/34-*` |
| `DAT-001` | verified within accepted Phase 0.4 snapshots | UTC persistence and explicit time snapshots in bounded increments |
| `DAT-003` | verified within accepted Phase 0.4 schema boundaries | foreign keys, uniqueness, checks/triggers and MariaDB migration tests |
| `ACL-002` | verified within accepted Catalog/Panel/Trial administrative services | execution-time authorization tests/evidence in increment documents |
| `SEC-001`, `SEC-002` | verified only for controls explicitly listed in accepted increment evidence | redaction, credential handling, TLS/network policy, replay/conflict and fail-closed behavior; final independent release security gate remains open |
| `QUA-001` | verified per accepted increment, not for Phase 0.4 closure | exact-SHA implementation/evidence runs and retained artifacts |

## Verified Trial/Panel foundation boundary

Implementation:

- SHA `b146c2c6aa902b4ed252d200121d63e422cd87f2`;
- CI `31135758918` / run `#931` — success.

Evidence:

- SHA `31a1854a2804bb0b2cf466c96887de7c5813b343`;
- CI `31136119421` / run `#934` — success;
- 298 tests, 1471 assertions;
- artifact `test-evidence-31136119421`, ID `8978007207`;
- independent digest `sha256:65b46242099caeef40e8bfeb7717b915ec306b449078570bd680420811bbde09`.

| Requirement | Accepted boundary | Remaining owning-phase/live gap |
|---|---|---|
| `PRV-001` | common adapter capability/result/snapshot contract, Fake adapter, validation/redaction and fail-closed provider shells | concrete pinned HTTP gateways and later live acceptance |
| `PRV-002` | create coordinator, deterministic identity resolver, exact-match adoption and mismatch conflict | durable full Provisioning/Service ownership remains Phase `0.6.0`; live provider acceptance deferred |
| `PRV-003` | uncertain create triggers authoritative discovery before any retry; no immediate second create | real timeout/error semantics are source-contract work now and live fault-harness evidence later |

## Active provider source-contract increment

Authoritative handoff/contract note:

- `docs/35-phase-0.4-panel-provider-source-contracts.md`.

Pinned sources:

- Marzban `v0.8.4` / `Gozargah/Marzban`;
- PasarGuard `v5.2.1` / `PasarGuard/panel`;
- Mirza Bot `mahdiMGF2/mirzabot` is a secondary practical integration reference, not provider authority.

| Requirement | Current work | Offline acceptance | Deferred live acceptance |
|---|---|---|---|
| `PRV-001` | implement source-pinned HTTP authentication, version/capability/target discovery and operation mapping | deterministic HTTP contract tests against exact tagged router/model behavior | dedicated test panels near final integration |
| `PRV-002` | map create/update/delete/status/delivery behavior while preserving authoritative pre-create lookup | fake HTTP proves payloads, snapshots, adoption/conflict and no duplicate create | real create/adopt/cleanup on disposable provider users |
| `PRV-003` | classify timeout/5xx/malformed mutation responses and rediscover before retry | fault fixtures prove one mutation attempt plus authoritative discovery | controlled live fault harness at final acceptance |
| `SEC-001`, `SEC-002` | preserve HTTPS/TLS, SSRF/network policy, no redirects by default, credential/delivery redaction and least privilege | static/contract/redaction tests and CI | validate target TLS chain, permissions and deployment-specific reverse proxy later |
| `QUA-001` | exact-SHA source-contract implementation/evidence lifecycle | mandatory CI, test counts, artifact/digest and evidence head | separate live acceptance artifact when panels are provided |

Absence of live Marzban/PasarGuard installations is **not a current blocker**. Real targets remain disabled/fail-closed until the deferred live gate.

## Earlier foundation rows that remain incomplete

The presence of a foundation does not move the complete workflow to `verified`:

| Requirement group | Foundation present | Still required |
|---|---|---|
| `PAY-002`, `PAY-003` | typed payment/order states and shared idempotency/outbox primitives | Phase `0.5.0` payment orchestration, authoritative capture, provider evidence, concurrency/reconciliation |
| `C2C-003`, `GFT-003` | contracts may exist | Fake and Generic REST implementations, security mapping, capture/reconciliation evidence in Phase `0.5.0` |
| `OPS-001`, `OPS-003` | logging redaction, outbox, worker heartbeat/release operations foundations | complete Operations Center, alert delivery, queue/task/dead-letter workflows and release evidence |
| `INS-001`, `RUN-001`–`RUN-003` | installer/runtime/release foundations and Phase `0.2.0` target evidence | final production package/owner environment re-verification at release |
| `CNT-001` | installer/identity translations | complete product localization/content override system in Phase `0.7.0` |

## Current control-plane traceability

| Control | Requirement/risk | Implementation/document | Current state |
|---|---|---|---|
| deterministic host PHP/Composer/PCOV | `ARCH-001`, `QUA-002`, `QUA-012`, `RSK-026` | `scripts/ci/bootstrap-self-hosted-toolchain.sh`; `docs/development/ci-runner-contract.md` | verified at run `#934` |
| dependency advisory repair | `ARCH-001`, `QUA-012`, `RSK-024` | patched `league/commonmark` lockfile | verified at run `#934` |
| project continuation contract | `QUA-001`, `QUA-013` | `AGENTS.md`, `PROJECT_STATUS.md`, status JSON/schema, continuation runbook | verified at run `#934`; machine boundary advanced to increment 6 |
| staging workflow safety | `RUN-001`, `SEC-001`, `SEC-008`, `RSK-014` | staging workflow inventory/cleanup | legacy workflows inert; guarded read-only readiness remains |
| architecture enforcement gap | `ARCH-002`, `QUA-002` | audit finding F-010 | open bounded hardening task; no broad rewrite |
| provider source contract | `PRV-001`–`PRV-003`, `SEC-001`, `SEC-002` | `docs/35-phase-0.4-panel-provider-source-contracts.md` | active; live testing explicitly deferred |

## Matrix reconciliation rule

Until `docs/02-requirement-traceability-matrix.md` is fully regenerated:

1. requirement wording comes from `docs/01-authoritative-requirements.md`;
2. accepted implementation/evidence comes from phase evidence/traceability documents;
3. current status comes from this overlay and `docs/project-status.json`;
4. live working head/CI comes from GitHub PR `#6`;
5. a stale `not-started`/`in-progress` cell in the baseline matrix must not override exact-SHA accepted evidence;
6. provider source-contract acceptance must not be described as live-provider acceptance until the deferred test-panel gate is executed.
