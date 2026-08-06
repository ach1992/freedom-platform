# Phase 0.4 Panel Inventory Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`  
Active issue: #7  
Status: Implementation verified; evidence-head CI pending

## Accepted implementation

- implementation SHA: `7c48399a2be8a79ddb078f79684d73dd9869b322`
- standard CI: `31059517586` / run #811 — success
- automated suite: 227 tests, 1121 assertions
- test artifact: `test-evidence-31059517586`
- test artifact digest: `sha256:e86440c197119403c49aa7137346dff3b2b37af08276bb56cadd06cfc4459d13`

| Requirement | Verified behavior | Code | Tests |
|---|---|---|---|
| `CAT-002` partial | Local Sales Server identity and Service Target ownership are modeled separately from Panel Connection and future Plan Offering | Panels Domain/Application and inventory migration | domain, migration, service tests |
| `CAT-004` | Protocol Profiles and Service Targets use typed, validated configurations; customer-selectable profiles must be active and assigned | profile/target value objects and services | domain and service tests |
| `PRV-001` partial | Target configuration is encrypted and bound to a versioned Panel Connection; capabilities remain declared until adapter verification exists | target service, migration and provider binding | encryption, dependency and migration tests |
| `ACL-002` | Existing `panels.manage` and `panels.manage_secrets` authorization is checked before and inside transactions | `PanelMutationExecutor`, `PanelInventoryService` | authorized/unauthorized service tests |
| `ACL-003` | Secret-bearing target creation/update consumes the existing action/target-bound Sensitive Action Approval | `PanelApprovalGate`, target operations | approval interaction and replay tests |
| `SEC-001` | Target remote identity and typed configuration are encrypted; safe audit excludes raw configuration and localized names | target configuration/service | encryption and leakage assertions |
| `SEC-002` | Exact replay, payload conflict rejection, row locks, optimistic versions and database dependency guards | shared mutation infrastructure and inventory services | replay/conflict/lifecycle tests |
| `DAT-003` | FK-backed histories, database checks and archive/immutability triggers preserve structural integrity | inventory migration | direct-SQL migration tests |
| `QUA-001` | Requirement annotations, focused tests, complete regression, standard CI and retained artifacts | all implementation files | run #811 and retained artifacts |

## Verification history

- run #808 / `31058738550`: Code Style failed; exact Pint diff applied without behavior changes;
- run #809 / `31058913596`: PHPStan exposed a missing closure capture and the full suite exposed the same migration path;
- run #810 / `31059117469`: Static, Secret and Dependency passed; MariaDB exposed an overlong generated FK constraint name;
- run #811 / `31059517586`: all mandatory jobs passed after explicit short FK naming.

## Artifact set

- `preflight-evidence-31059517586` — `sha256:65c5ab6c2493161ca08bec7c6a665b3509354b769f1d6332f73690c451842325`
- `gitleaks-results.sarif` — `sha256:deda186218e5205e4b2b64ce833e003bc58c642f0e713fceeca81ecad45cb8e1`
- `static-evidence-31059517586` — `sha256:cc2bc8f4931bad75a1e231a7ecf8bac00dfda6317c23f9c5debccd963aa3ffe1`
- `dependency-evidence-31059517586` — `sha256:2173db6ac3ff3c11710233c012c32c0a52d0161d585ee483acee86f1c8f77627`
- `test-evidence-31059517586` — `sha256:e86440c197119403c49aa7137346dff3b2b37af08276bb56cadd06cfc4459d13`

## Explicit boundary

This increment does not claim Panel connection testing, capability discovery, Service Target activation, live HTTP calls, provider adapters, capacity, fallback, Plan Offerings, custom plans, trials or provisioning. Target capabilities remain `declared`, and MariaDB rejects activation until a later adapter-contract increment supplies verified evidence tied to the tested connection version.
