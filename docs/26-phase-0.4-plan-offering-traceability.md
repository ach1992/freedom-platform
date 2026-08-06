# Phase 0.4 Plan Offering Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`  
Active issue: #7  
Status: Candidate / Unverified

| Requirement | Candidate behavior | Code | Tests | Evidence |
|---|---|---|---|---|
| `CAT-002` | A Plan Offering links Product/optional Variant to one Sales Server and one Service Target; price and limits live on the Offering | Offering Domain/Application and migration | domain, migration, service tests | `evidence/0.4.0/plan-offering-foundation.md` |
| `CAT-003` | Service mode is a typed code with editable Persian/English labels; shared, dedicated and namespaced custom modes are supported | `PlanOfferingServiceMode`, definition and schema | domain/service tests | same evidence |
| `CAT-004` | Protocol selection is fixed, customer-selected or system-selected; profiles must be assigned to the Target and activation requires active profiles | protocol assignment policy and dependency gates | domain, migration and service tests | same evidence |
| `ACL-002` | Existing `catalog.manage` authorization is checked before and inside every mutation transaction | `CatalogMutationExecutor`, `PlanOfferingService` | unauthorized and lifecycle tests | same evidence |
| `SEC-002` | Exact replay, payload-conflict rejection, row locks, optimistic versions, immutable active configuration and database guards | Catalog mutation infrastructure, services and triggers | replay/conflict/direct-SQL tests | same evidence |
| `DAT-003` | Integer IRR, explicit nullable unlimited limits, FK-backed child policies and append-only histories are enforced | offering migration | migration and service tests | same evidence |
| `QUA-001` | Requirement annotations, focused tests, full regression, standard CI and retained evidence | all candidate files | pending CI | same evidence |

## Explicit boundary

This candidate defines commercial catalog configuration only. It does not calculate resolved customer/agent prices, promotions, discounts, quotes or ledger entries. It creates no Order, Service or remote effect. Capacity/fallback, custom-plan calculations, trials and adapters remain separate Phase 0.4 increments.

Because current Service Targets intentionally remain `disabled` with `declared` capability evidence, Offering activation and visibility are fail-closed. The activation code and MariaDB dependency checks are present, but a successful operational activation is not claimed until the adapter-contract increment verifies the Target and its Panel Connection version.
