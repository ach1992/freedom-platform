# Phase 0.4 Panel Connection Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`  
Active issue: #7  
Accepted implementation SHA: `2174bc7844fde0282f75e6d258a7789dc588ecf8`  
Implementation CI: `31053240018` / run #806 — success  
Status: Implementation Green; evidence-head verification pending

| Requirement | Verified behavior | Code | Tests | Evidence |
|---|---|---|---|---|
| `PRV-001` partial | Encrypted, versioned Panel Connection configuration with provider/TLS/network policy and database test-evidence activation gate | Panels Domain/Application/Infrastructure and migration | domain, migration, service tests | `evidence/0.4.0/panel-connection-foundation.md` |
| `ACL-001` support | Exact `servers.view`, `panels.manage`, `panels.manage_secrets`, `panels.test` catalog and idempotent role grants | `PanelsAccessFoundationSeeder` | seeder test | same evidence |
| `ACL-002` | Permission checked before mutation and revalidated after active Administrator row lock | `PanelMutationExecutor` | authorized/unauthorized service tests | same evidence |
| `ACL-003` | Secret-bearing create/reconfigure/rotation consumes the existing action/target-bound Sensitive Action Approval | `SensitivePanelApprovalGate`, `PanelConnectionService` | production binding and approval interaction tests; AccessControl approval suite remains regression coverage | same evidence |
| `SEC-001` | HTTPS/TLS policy, explicit public/private network boundary, encrypted credentials, reset-on-change test evidence and secret-safe audit | endpoint/TLS/credential objects, migration, service | domain, migration, encryption/leakage tests | same evidence |
| `SEC-002` | Exact replay, keyed payload conflict rejection, transaction authorization, row locks, versions and uniqueness | mutation infrastructure and service | replay/conflict/lifecycle tests | same evidence |
| `DAT-003` | FK-backed append-only connection history and enforced MariaDB checks | migration and history writer | migration/history tests | same evidence |
| `QUA-001` | Requirement annotations, focused tests, complete regression, standard CI and retained evidence | all increment files | 217 tests / 1072 assertions | run `31053240018` artifacts |

## Remaining combined work package

This traceability record does not claim connection testing, capability discovery, Service Targets, Protocol Profiles, Sales Servers, capacity, offerings or adapter completion.

The next bounded sub-increment implements Protocol Profiles, encrypted Service Target configuration, declared unverified capabilities and localized Sales Servers. Issue #7's combined checkbox remains open until that sub-increment and its evidence-head pass standard CI.
