# Phase 0.4 Panel Connection Foundation

Status: **Candidate — Unverified**

This bounded sub-increment establishes the secure configuration and lifecycle boundary for Panel Connections. It is not complete until the exact implementation SHA and the evidence-head SHA pass the standard CI workflow.

## Implemented boundary

- typed provider vocabulary: `fake`, `marzban`, `pasarguard`;
- HTTPS endpoint validation with explicit `public_only` or `private_allowed` network policy;
- exact TLS policy: system CA, protected custom-CA reference, or SHA-256 certificate pin;
- encrypted credential storage with explicit credential key version;
- explicit `disabled`, `active`, `maintenance`, `archived` state machine;
- database constraints preventing activation without successful test/version/capability evidence;
- optimistic version checks, row locks, exact replay receipts, and payload-conflict rejection;
- current `panels.manage` and `panels.manage_secrets` authorization before and inside transactions;
- existing target-bound Sensitive Action Approval consumption for create, reconfigure, and credential rotation;
- FK-backed append-only history and safe audit data without credential, endpoint, CA path, pin, name, ciphertext, or payload-HMAC leakage;
- exact permission seed names from the authoritative Permission Catalog.

## Intentionally excluded

- connection testing, version discovery, health checks, and capability discovery;
- activation commands or live HTTP calls;
- runtime DNS resolution/rebinding protection, retry, timeout, and circuit breaker behavior;
- Service Targets, Protocol Profiles, Sales Servers, capacity, fallback, and Plan Offerings;
- Fake/Marzban/PasarGuard adapter implementations.

A configured connection remains `disabled`. Runtime HTTP/TLS/SSRF controls and operational activation belong to the adapter-contract increment.

## Code and test map

- domain/value objects under `app/Modules/Panels/Domain/`;
- mutation, approval, audit, and connection services under `app/Modules/Panels/Application/`;
- `app/Modules/Panels/Infrastructure/PanelsServiceProvider.php`;
- `database/migrations/2026_08_05_001500_create_panel_connection_foundation.php`;
- `database/seeders/PanelsAccessFoundationSeeder.php`;
- `tests/Unit/Modules/Panels/PanelConnectionDomainTest.php`;
- `tests/Feature/PanelConnectionFoundationMigrationTest.php`;
- `tests/Feature/PanelsAccessFoundationSeederTest.php`;
- `tests/Feature/PanelConnectionServiceTest.php`.

## Verification gate

Pending:

- implementation SHA and standard CI result;
- actual test/assertion counts from retained test evidence;
- static/security/dependency job results;
- artifact names and digests;
- evidence-head CI.

Issue #7 remains open and its Panel/Target/Sales Server checkbox remains unchecked after this sub-increment.
