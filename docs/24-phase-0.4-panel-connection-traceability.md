# Phase 0.4 Panel Connection Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`  
Active issue: #7  
Status: Candidate / Unverified

| Requirement | Candidate behavior | Code | Tests | Evidence |
|---|---|---|---|---|
| `PRV-001` partial | Encrypted, versioned Panel Connection configuration with provider/TLS/network policy and test-evidence activation gate | Panels Domain/Application/Infrastructure and migration | domain, migration, service tests | `evidence/0.4.0/panel-connection-foundation.md` |
| `ACL-001` support | Exact panel/server permission catalog and idempotent role grants | `PanelsAccessFoundationSeeder` | seeder test | same evidence |
| `ACL-002` | Permission checked before mutation and revalidated after active Administrator row lock | `PanelMutationExecutor` | authorized/unauthorized service tests | same evidence |
| `ACL-003` | Secret-bearing create/reconfigure/rotation consumes existing action/target-bound Sensitive Action Approval | `SensitivePanelApprovalGate`, `PanelConnectionService` | production binding and approval interaction tests; AccessControl approval suite remains regression coverage | same evidence |
| `SEC-001` | HTTPS/TLS policy, public/private network boundary, encrypted credentials, and secret-safe audit | endpoint/TLS/credential objects, migration, service | domain, migration, encryption/leakage tests | same evidence |
| `SEC-002` | Exact replay, payload conflict rejection, transaction authorization, locks, versions, and uniqueness | mutation infrastructure and service | replay/conflict/lifecycle tests | same evidence |
| `DAT-003` | FK-backed connection history and enforced database checks | migration and history writer | migration/history tests | same evidence |
| `QUA-001` | Requirement annotations, focused tests, complete regression, standard CI, and retained evidence | all candidate files | pending CI | same evidence |

This document does not claim connection testing, capability discovery, targets, servers, capacity, offerings, or adapter completion.
