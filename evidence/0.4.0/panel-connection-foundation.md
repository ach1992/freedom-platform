# Phase 0.4 Panel Connection Foundation

Status: **Implementation Green; evidence-head verification pending**

This bounded sub-increment establishes the secure configuration and lifecycle boundary for Panel Connections. It does not complete the combined Panel/Target/Sales Server work package in Issue #7.

## Accepted implementation boundary

Accepted implementation SHA:

- `2174bc7844fde0282f75e6d258a7789dc588ecf8`

Standard CI:

- workflow: `CI`
- run ID: `31053240018`
- run number: `806`
- conclusion: `success`

Automated suite result extracted from retained `tests/test.log` and `tests/junit.xml`:

- **217 tests passed**
- **1072 assertions**
- failures: `0`
- errors: `0`
- skipped: `0`
- duration reported by the PHPUnit summary: `7.05s`

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

## Security and integrity controls

- URL credentials, query strings, fragments, unsafe paths and non-HTTPS endpoints are rejected;
- `public_only` rejects literal private/reserved addresses and local-only hostname suffixes;
- custom CA and certificate-pin material are mutually exclusive in both application validation and MariaDB checks;
- database checks require successful test status, timestamp, panel version and capability hash before an `active` state can exist;
- secret-bearing mutations require `panels.manage_secrets` and an existing action/target-bound one-time approval;
- request payloads are compared through a keyed HMAC retained only in the internal replay receipt table;
- audit and history contain structural metadata and booleans only, not endpoints, names, credential values, encrypted blobs, CA paths, pins or request HMACs;
- credential rotation and connection reconfiguration reset test evidence and return the connection to `disabled`;
- the closed Phase 0.3 Identity/ACL seed remains unchanged; Panel permissions are owned by a separate idempotent seeder.

## Code and test map

Domain:

- `app/Modules/Panels/Domain/PanelProviderType.php`
- `app/Modules/Panels/Domain/PanelConnectionState.php`
- `app/Modules/Panels/Domain/PanelEndpoint.php`
- `app/Modules/Panels/Domain/PanelNetworkPolicy.php`
- `app/Modules/Panels/Domain/TlsPolicy.php`
- `app/Modules/Panels/Domain/TlsConfiguration.php`
- `app/Modules/Panels/Domain/PanelCredentials.php`

Application and infrastructure:

- mutation, approval, audit and connection services under `app/Modules/Panels/Application/`;
- `app/Modules/Panels/Infrastructure/PanelsServiceProvider.php`;
- `bootstrap/providers.php`.

Persistence and access:

- `database/migrations/2026_08_05_001500_create_panel_connection_foundation.php`;
- `database/seeders/PanelsAccessFoundationSeeder.php`;
- `database/seeders/DatabaseSeeder.php`.

Tests:

- `tests/Unit/Modules/Panels/PanelConnectionDomainTest.php`;
- `tests/Feature/PanelConnectionFoundationMigrationTest.php`;
- `tests/Feature/PanelsAccessFoundationSeederTest.php`;
- `tests/Feature/PanelConnectionServiceTest.php`;
- complete pre-existing regression suite.

## Mandatory job results

| Job | Result |
|---|---|
| Repository preflight | success |
| Secret scan | success |
| PHP code style | success |
| PHPStan/Larastan and repository policy | success |
| Dependency audit and license policy | success |
| MariaDB and authenticated Redis complete suite | success |

## Retained artifacts

| Artifact | Artifact ID | Digest |
|---|---:|---|
| `preflight-evidence-31053240018` | `8949238820` | `sha256:0b20c4d67571a1cbe440a376cdbbb031d62963639bd9c3ef9b1f887ed6ac3b11` |
| `gitleaks-results.sarif` | `8949245471` | `sha256:8340b6b857fc3e7a01b41c361cfc1c572062b9f54ba4a041a196aa026eb99b50` |
| `dependency-evidence-31053240018` | `8949251823` | `sha256:8000f27a7900b3f19a690b3b2c4231a90fa3e581ef8692e30f9dc39343f059e2` |
| `static-evidence-31053240018` | `8949271539` | `sha256:1b369b3fbb442114f49982a591a88748e69b8ed2e06cbb6eb9b49bf15fa94f80` |
| `test-evidence-31053240018` | `8949284144` | `sha256:b9793151067144557216056134eb413936bf1eb1c5975d0b249cf2326b78ee5c` |

## Failure history and root-cause corrections

The sub-increment was not treated as verified during failed candidates.

### Run `31052471666` / #804

- complete MariaDB/Redis tests, Secret scan and Dependency policy were Green;
- Pint identified formatting differences in eight new Panel files.

Correction:

- repository-standard formatting was applied without changing behavior or weakening an invariant.

### Run `31053073605` / #805

- a malformed assignment in the transferred `PanelMutationAudit.php` blob caused a parse error before static analysis;
- the error was limited to the transfer/fix commit and did not change the intended design.

Correction:

- the syntax-valid local source was restored;
- Run #806 subsequently verified Pint, PHPStan, repository policy, the complete MariaDB/Redis suite, Secret scan and Dependency policy.

## Evidence-head gate

This document records the Green implementation boundary. The commit containing this evidence must pass the same standard CI before this sub-increment is accepted as evidence-complete.

Issue #7 remains open. Its combined Panel/Target/Sales Server checkbox remains unchecked until Service Targets, Protocol Profiles, Sales Servers and capability declarations are also implemented and independently verified.
