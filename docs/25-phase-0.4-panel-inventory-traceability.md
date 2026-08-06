# Phase 0.4 Panel Inventory Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`  
Active issue: #7  
Status: Candidate / Unverified

| Requirement | Candidate behavior | Code | Tests | Evidence |
|---|---|---|---|---|
| `CAT-002` partial | Local Sales Server identity and Service Target ownership are modeled separately from Panel Connection and future Plan Offering | Panels Domain/Application and inventory migration | domain, migration, service tests | `evidence/0.4.0/panel-target-protocol-server-foundation.md` |
| `CAT-004` | Protocol Profiles and Service Targets use typed, validated configurations; customer-selectable profiles must be active and assigned | profile/target value objects and services | domain and service tests | same evidence |
| `PRV-001` partial | Target configuration is encrypted and bound to a versioned Panel Connection; capabilities remain declared until adapter verification exists | target service, migration and provider binding | encryption, dependency and migration tests | same evidence |
| `ACL-002` | Existing `panels.manage` and `panels.manage_secrets` authorization is checked before and inside transactions | `PanelMutationExecutor`, `PanelInventoryService` | authorized/unauthorized service tests | same evidence |
| `ACL-003` | Secret-bearing target creation/update consumes the existing action/target-bound Sensitive Action Approval | `PanelApprovalGate`, target operations | approval interaction and replay tests | same evidence |
| `SEC-001` | Target remote identity and typed configuration are encrypted; safe audit excludes raw configuration and localized names | target configuration/service | encryption and leakage assertions | same evidence |
| `SEC-002` | Exact replay, payload conflict rejection, row locks, optimistic versions and database dependency guards | shared mutation infrastructure and inventory services | replay/conflict/lifecycle tests | same evidence |
| `DAT-003` | FK-backed histories, database checks and archive/immutability triggers preserve structural integrity | inventory migration | direct-SQL migration tests | same evidence |
| `QUA-001` | Requirement annotations, focused tests, complete regression, standard CI and retained evidence | all candidate files | pending CI | same evidence |

## Explicit boundary

This candidate does not claim:

- Panel connection testing, capability discovery or health checks;
- Service Target activation or operational readiness;
- live HTTP calls or Fake/Marzban/PasarGuard adapters;
- capacity, fallback, Plan Offerings, custom plans, trials or provisioning.

Service Target capabilities are deliberately stored as `declared`. Activation is database-blocked until a later adapter-contract increment can supply verified capability evidence tied to the tested Panel Connection version.
