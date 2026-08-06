# Phase 0.4 Plan Offering Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`  
Active issue: #7  
Status: Implementation verified; evidence-head CI pending

## Accepted implementation

- implementation SHA: `cf0971c068c46d8e71d4f7765fdda249d9a24471`
- standard CI: `31061752803` / run #816 — success
- automated suite: 236 tests, 1171 assertions
- test artifact: `test-evidence-31061752803`
- test artifact digest: `sha256:dbb2f16d84249eb3b6ddc539673c2e820293d9be1de353c7d1cc5825ef3f9a58`

| Requirement | Verified behavior | Code | Tests | Evidence |
|---|---|---|---|---|
| `CAT-002` | A Plan Offering links Product/optional Variant to one Sales Server and one Service Target; price and limits live on the Offering | Offering Domain/Application and migration | domain, migration, service tests | `evidence/0.4.0/plan-offering-foundation.md` |
| `CAT-003` | Service mode is a typed code with editable Persian/English labels; shared, dedicated and namespaced custom modes are supported | `PlanOfferingServiceMode`, definition and schema | domain/service tests | same evidence |
| `CAT-004` | Protocol selection is fixed, customer-selected or system-selected; profiles must be assigned to the Target and activation requires active profiles | protocol assignment policy and dependency gates | domain, migration and service tests | same evidence |
| `ACL-002` | Existing `catalog.manage` authorization is checked before and inside every mutation transaction | `CatalogMutationExecutor`, `PlanOfferingService` | unauthorized and lifecycle tests | same evidence |
| `SEC-002` | Exact replay, payload-conflict rejection, row locks, optimistic versions, immutable active configuration and database guards | Catalog mutation infrastructure, services and triggers | replay/conflict/direct-SQL tests | same evidence |
| `DAT-003` | Integer IRR, explicit nullable unlimited limits, FK-backed child policies and append-only histories are enforced | offering migration | migration and service tests | same evidence |
| `QUA-001` | Requirement annotations, focused tests, full regression, standard CI and retained artifacts | all implementation files | run #816 and retained artifacts | same evidence |

## Verification history

- run #813 / `31061309702`: Code Style identified two formatting-only Domain files; exact Pint output was applied without behavior changes;
- run #814 / `31061432033`: the suite identified a DTO-to-schema key mismatch (`service_target_id` versus `panel_service_target_id`) and PHPStan identified four unnecessary Collection count calls;
- the persistence mapping was aligned to the authoritative schema and dependency checks were changed to direct Query Builder counts without removing locks or validations;
- run #816 / `31061752803`: all mandatory jobs passed.

## Artifact set

- `preflight-evidence-31061752803` — `sha256:7ace5bb8bed2ed7650fa77cbe2ca9a47b6e43c75c56a8536194387b38e85e541`
- `gitleaks-results.sarif` — `sha256:29ba3a2ce28aba433f337ba868a1c4da4efe2a2fbc6a134f1246f3fb90794623`
- `static-evidence-31061752803` — `sha256:a1ff9ccba211f4a86fca32f1ce0fb3a13a5cdd4f340f8a4caeb243b8c0ebba35`
- `dependency-evidence-31061752803` — `sha256:7861b7f62405aa868813fc38dea7c96eb475ff66b035d66aad9174d3259fc712`
- `test-evidence-31061752803` — `sha256:dbb2f16d84249eb3b6ddc539673c2e820293d9be1de353c7d1cc5825ef3f9a58`

## Explicit boundary

This increment defines commercial catalog configuration only. It does not calculate resolved customer/agent prices, promotions, discounts, quotes or ledger entries. It creates no Order, Service or remote effect. Capacity/fallback, custom-plan calculations, trials and adapters remain separate Phase 0.4 increments.

Current Service Targets intentionally remain `disabled` with `declared` capability evidence. Offering activation and visibility are therefore verified as fail-closed. A successful operational activation is not claimed until the adapter-contract increment verifies the Target and its current Panel Connection version.
