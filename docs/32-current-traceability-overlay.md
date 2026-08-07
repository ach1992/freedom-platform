# Current Traceability Overlay

**Last reviewed:** 2026-08-07  
**Purpose:** correct current implementation/evidence status while the large baseline matrix is incrementally reconciled.  
**Authority:** this overlay does not replace requirement definitions in `docs/01-authoritative-requirements.md`; it replaces stale status cells in `docs/02-requirement-traceability-matrix.md` where the two disagree.

Read with `PROJECT_STATUS.md`, `docs/project-status.json`, and the active handoff. Live head still comes from PR `#6`.

## Status vocabulary

- `verified`: implementation and evidence-head exact-SHA gates passed;
- `implemented-unverified`: code/tests exist but no accepted implementation/evidence boundary yet;
- `foundation-only`: partial prerequisite exists; acceptance workflow remains incomplete;
- `not-started`: no accepted implementation claim;
- `stabilization`: project-control/CI work that must pass before feature verification continues.

## Accepted phase status

| Phase | Status | Authoritative evidence |
|---|---|---|
| `0.1.0` | verified | planning quality gate and baseline documents |
| `0.2.0` | verified | `evidence/0.2.0/PHASE-CLOSURE.md` |
| `0.3.0` | verified | `evidence/0.3.0/phase-closure-verification.md` |
| `0.4.0` | active | verified increments below; Trial/Panel remains unverified |
| `0.5.0`–`1.0.0` | not-started except explicit earlier foundations | Master Prompt phase plan |

## Phase 0.4 verified requirements

### Catalog lifecycle

| Requirement | Status | Code/tests/evidence |
|---|---|---|
| `CAT-001` | verified | Catalog domain/application/migration; `CatalogLifecycleServicesTest`; `evidence/0.4.0/catalog-category-product-variant-lifecycle.md`; `docs/23-phase-0.4-catalog-traceability.md` |
| `CAT-002` | verified foundation through offering/inventory increments | Product/Offering/Panel inventory services; migration/test/evidence in `docs/23-26-*` |
| `CAT-003` | verified | typed service mode in Plan Offering; `docs/26-phase-0.4-plan-offering-traceability.md` |
| `CAT-004` | verified foundation | typed protocol profiles/targets and assignments; `docs/25-26-*` |

### Capacity, selection, and custom plans

| Requirement | Status | Code/tests/evidence |
|---|---|---|
| `CAT-005` | verified | Custom Plan services/migrations/tests; implementation `8e628672...`; evidence head `0d34af0a...`; `docs/29-phase-0.4-custom-plan-traceability.md` |
| `CAT-008` | verified for capacity/availability/selection/fallback foundation; adapter-dependent production activation remains fail-closed | `TargetCapacity*`, route selection services/tests; `docs/27-28-*` |
| `DAT-001` | verified within accepted Phase 0.4 snapshots | UTC persistence and explicit time snapshots in bounded increments |
| `DAT-003` | verified within accepted Phase 0.4 schema boundaries | foreign keys, uniqueness, checks/triggers, migration tests |
| `ACL-002` | verified within accepted Catalog/Panel administrative services | execution-time authorization tests/evidence in increment documents |
| `SEC-001`, `SEC-002` | verified only for controls explicitly listed in accepted increment evidence | redaction, credential handling, TLS/network policy, replay/conflict, approvals; full release security gate remains open |
| `QUA-001` | verified per accepted increment, not for Phase 0.4 closure | exact-SHA implementation/evidence runs and retained artifacts |

## Active Trial/Panel requirements

| Requirement | Current status | Implemented candidate | Remaining acceptance gap |
|---|---|---|---|
| `CAT-006` | implemented-unverified | trial policy, eligibility, membership policy, capacity reservation, abuse controls, lifecycle/regrant/reset, disclosed fallback | exact-head mandatory CI, accepted counts/artifact, evidence/traceability head |
| `CAT-008` | implemented-unverified extension | trial route selection integrated with compatible capacity/fallback | same; no production provider claim |
| `PRV-001` | implemented-unverified foundation | common adapter capability/result/snapshot contracts; Fake adapter; fail-closed Marzban/PasarGuard shells | exact-head CI and real installed-version contract tests before activation |
| `PRV-002` | implemented-unverified foundation | create coordinator, deterministic identity resolver, adoption/conflict behavior | exact-head CI; later Provisioning aggregate remains Phase `0.6.0` |
| `PRV-003` | implemented-unverified foundation | uncertain create triggers authoritative discovery before any retry | exact-head CI/evidence and real provider semantics later |
| `ACL-002` | implemented-unverified extension | trial administrative mutation authorization | exact-head CI/evidence |
| `SEC-001`, `SEC-002` | implemented-unverified extension | lookup fail-closed, redaction, TLS policy preservation, conflict/manual review | exact-head CI/evidence; independent full security review later |
| `DAT-001`, `DAT-003` | implemented-unverified extension | trial timestamps, tables, constraints, triggers, histories | exact-head CI/migration evidence |
| `QUA-001` | blocked by stabilization | tests exist, handoff exists | deterministic CI, dependency/formatting repair, artifact/evidence lifecycle |

Active handoff: `docs/30-phase-0.4-trial-panel-handoff.md`.

## Earlier foundation rows that remain incomplete

The presence of a foundation does not move the complete workflow to `verified`:

| Requirement group | Foundation present | Still required |
|---|---|---|
| `PAY-002`, `PAY-003` | typed payment/order states and shared idempotency/outbox primitives | Phase `0.5.0` payment orchestration, authoritative capture, provider evidence, concurrency/reconciliation |
| `C2C-003`, `GFT-003` | contracts may exist | Fake and Generic REST implementations, security mapping, capture/reconciliation evidence in Phase `0.5.0` |
| `OPS-001`, `OPS-003` | logging redaction, outbox, worker heartbeat/release operations foundations | complete Operations Center, alert delivery, queue/task/dead-letter workflows and release evidence |
| `INS-001`, `RUN-001`–`RUN-003` | installer/runtime/release foundations and Phase `0.2.0` target evidence | final production package/owner environment re-verification at release |
| `CNT-001` | installer/identity translations | complete product localization/content override system in Phase `0.7.0` |

## Current stabilization traceability

| Control | Requirement/risk | Implementation/document | Acceptance condition |
|---|---|---|---|
| deterministic host PHP/Composer | `ARCH-001`, `QUA-002`, `QUA-012`, `RSK-026` | `scripts/ci/bootstrap-self-hosted-toolchain.sh`; `docs/development/ci-runner-contract.md` | all mandatory exact-head jobs execute with explicit CLI INI, JIT off, PCOV coverage mode |
| dependency advisory repair | `ARCH-001`, `QUA-012`, `RSK-024` | bounded `league/commonmark` lockfile update | Composer audit/license/static/full suite green |
| project continuation contract | `QUA-001`, `QUA-013` | `AGENTS.md`, `PROJECT_STATUS.md`, status JSON/schema, continuation runbook | `verify-project-control.sh` passes and documents agree |
| staging workflow safety | `RUN-001`, `SEC-001`, `SEC-008`, `RSK-014` | staging workflow inventory/cleanup | obsolete mutation workflows removed; retained workflows guarded and documented |
| architecture enforcement gap | `ARCH-002`, `QUA-002` | audit finding F-010 | machine-readable module graph and incremental checks added without broad unverified rewrite |

## Matrix reconciliation rule

Until `docs/02-requirement-traceability-matrix.md` is fully regenerated:

1. requirement wording comes from `docs/01-authoritative-requirements.md`;
2. accepted implementation/evidence comes from phase evidence/traceability documents;
3. current status comes from this overlay and `docs/project-status.json`;
4. live working head/CI comes from GitHub PR `#6`;
5. a stale `not-started` cell in the baseline matrix must not reopen accepted evidence, and a stale `in-progress` cell must not be treated as verification.
