# Phase 0.4 Custom Plan Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`
Active issue: #7
Implementation SHA: `8e62867277acdd39cd1471ed3d454ef25520bef8`
Implementation CI: `31071843621` / run #833 — success
Implementation suite: 255 tests / 1267 assertions

This supplement records the bounded Custom Plan Policy and Calculation Snapshot increment. It does not claim resolved discount/agent/customer override pricing, immutable Quote, Order, payment, provisioning or remote panel effects.

| Requirement | Implemented behavior | Code | Tests | Evidence |
|---|---|---|---|---|
| `CAT-005` | Offering-scoped data/day range and step rules, username policy, integer-IRR calculation components, minimum floor, eligibility and immutable calculation snapshots | Custom Plan domain/application classes and migrations | `CustomPlanDomainTest`, `CustomPlanCalculationTest` | `evidence/0.4.0/custom-plan-policy-calculation.md` |
| `ACL-002` | `catalog.manage` checked before policy mutation and revalidated inside the locked transaction | `CustomPlanPolicyService`, `CatalogMutationExecutor` | unauthorized policy mutation feature test and regression suite | same evidence |
| `SEC-002` | exact command replay, conflicting command-key rejection, row locks, expected versions, immutable snapshots and direct-SQL guards | `CustomPlanCalculator`, policy service and migrations | replay/conflict/direct-invalid-formula tests | same evidence |
| `DAT-002` | integer-IRR only, overflow-safe arithmetic and complete component snapshots | `CustomPlanPricing`, `CustomPlanArithmetic`, calculation persistence | formula/minimum-floor domain and feature tests | same evidence |
| `DAT-003` | versioned append-only policy histories, calculations and validation receipts | migrations and application services | immutability and history tests | same evidence |
| `QUA-001` | requirement annotations, unit/feature/MariaDB tests, full regression, static/security/dependency gates and retained artifacts | all increment files and CI | 255 tests / 1267 assertions | run `31071843621` artifacts |

## Verified calculation boundary

The calculation snapshot contains:

- Offering and policy identity/version/configuration hash;
- authoritative actor type, tier and eligibility snapshot hash;
- requested data and duration;
- normalized username and username mode;
- base, per-GB, per-day, subtotal, minimum adjustment and final integer-IRR components;
- discount-eligibility flag only as policy input for later pricing resolution;
- correlation, source and reason metadata.

The result is not an immutable Quote and cannot authorize payment or provisioning.

## Revalidation boundary

`pre_payment` and `pre_provisioning` validations re-check:

- operational Offering/route/capability state;
- current policy identity/version/hash;
- authoritative account/tier/tag/agent eligibility;
- username availability and registry conflict;
- exact command replay and payload consistency.

No remote Panel call or side effect occurs.

## Remaining Phase 0.4 work

- trial eligibility, identity/membership requirements, capacity, abuse limits and fallback;
- `FakePanelAdapter`, `MarzbanAdapter` and `PasarGuardAdapter` contract implementations;
- remote identity lookup, uncertain-result adoption and conflict/manual-review handling;
- complete migration/permission/contract/concurrency/security traceability review;
- Phase 0.4 closure audit and final mandatory CI evidence.

The commit containing this traceability document is the evidence-head candidate and must pass the complete standard CI workflow before Issue #7 is updated.
