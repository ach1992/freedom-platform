# Phase 0.4 Protocol Profile, Service Target and Sales Server Foundation

Status: **Candidate — Unverified**

This bounded sub-increment completes the local configuration foundation required before Plan Offerings can reference a Sales Server and Service Target. It is not complete until the exact implementation SHA and a separate evidence-head SHA pass the standard CI workflow.

## Candidate boundary

- typed Protocol Profile definition for protocol family, transport, security layer, host, SNI, path, port and flow;
- explicit Profile lifecycle with active-definition immutability and dependency-safe archival;
- encrypted, versioned Service Target configuration tied to one Panel Connection;
- typed target kinds: `inbound`, `group`, `template`, `host`;
- normalized declared capability rows and Target-to-Profile compatibility assignments;
- customer-selectable profiles only when assigned and active;
- localized Sales Server identity, ordering, lifecycle and hidden/listed visibility;
- existing `panels.manage` authorization for non-secret inventory and `panels.manage_secrets` plus Sensitive Action Approval for target configuration;
- exact replay receipts, payload-conflict rejection, row locks and optimistic versions;
- FK-backed append-only histories and safe structural audit data;
- MariaDB checks and triggers preventing unverified Target activation and unsafe dependency archival.

## Security boundary

Target configuration contains remote identifiers and typed routing material. It is encrypted at rest. Audit and history retain only structural metadata such as IDs, kind, counts, state, visibility and version. They do not retain target remote identifiers, host/SNI/path values, encrypted blobs, configuration hashes, localized names or mutation HMACs.

## Intentionally excluded

- adapter test connection, version discovery, health or capability verification;
- Service Target activation and operational HTTP/TLS/SSRF behavior;
- capacity, availability, selection and fallback;
- Plan Offering commercial configuration;
- custom-plan, trial, order, payment and provisioning behavior.

A Target remains `disabled` with `declared` capabilities. Database triggers reject `active` Target rows until the later adapter-contract increment replaces that provisional gate with verified evidence rules.

## Code and test map

- Domain value objects under `app/Modules/Panels/Domain/`;
- `PanelInventoryService` and bounded operation/support traits under `app/Modules/Panels/Application/`;
- `PanelsServiceProvider` binding;
- `database/migrations/2026_08_06_001600_create_panel_inventory_foundation.php`;
- `tests/Unit/Modules/Panels/PanelInventoryDomainTest.php`;
- `tests/Feature/PanelInventoryFoundationMigrationTest.php`;
- `tests/Feature/PanelInventoryServiceTest.php`.

## Verification gate

Pending:

- implementation SHA and standard CI result;
- actual test/assertion counts;
- mandatory job results and artifact digests;
- evidence-head CI;
- Issue #7 and PR #6 update after both Green runs.

Issue #7 remains open and the combined Panel/Target/Sales Server checkbox remains unchecked while this candidate is unverified.
