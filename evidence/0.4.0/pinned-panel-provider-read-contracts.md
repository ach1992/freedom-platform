# Phase 0.4 Pinned Panel Provider Read Contracts

Status: **Implementation Green; evidence-head verification pending**

## Accepted implementation candidate

This bounded increment maps the pinned upstream source contracts for:

- Marzban `v0.8.4`;
- PasarGuard `v5.2.1`.

It implements **read-only/source-contract gateways** behind the previously accepted common Panel Adapter boundary. Real-provider mutation, live compatibility and target activation remain disabled and unverified.

Implementation SHA:

- `954973e505901208b5cef9348551e0c71ac027b6`

Mandatory CI:

- workflow: `CI`;
- run ID: `31178042191`;
- run number: `959`;
- conclusion: `success`.

Executable suite:

- **306 tests passed**;
- **1522 assertions**;
- failures: `0`;
- errors: `0`;
- PHPUnit: `12.5.33`;
- PHP: `8.4.23`;
- PCOV: `1.0.12`.

## Source authority

Pinned upstream source is authoritative for this increment:

- `Gozargah/Marzban` tag `v0.8.4`;
- `PasarGuard/panel` tag `v5.2.1`.

`mahdiMGF2/mirzabot` was reviewed only as a secondary practical usage reference. It does not override the pinned upstream source.

No live panel was contacted for this evidence.

## Implemented common transport boundary

New infrastructure:

- `PanelHttpExchange` — sanitized transport result containing status, parsed successful JSON state and transport/malformed classifications only;
- `PanelHttpTransport` — bounded Laravel HTTP transport using the validated Panel Adapter session;
- `PanelGatewayRequestFailure` — typed safe provider failure classification;
- `AbstractPinnedReadOnlyPanelGateway` — common fail-closed mutation boundary;
- `AuthoritativePanelLookupUnavailable` — explicit lookup-unavailable signal so connectivity/provider failures cannot be interpreted as authoritative absence.

Transport controls:

- endpoint is supplied through the existing validated `PanelEndpoint`;
- request timeout and connect timeout are bounded;
- redirects are disabled;
- TLS verification is always enabled;
- system CA is the default;
- configured custom CA must resolve through protected filesystem storage and be readable;
- configured certificate pinning uses the reviewed cURL pin option when available;
- non-2xx provider response bodies are not parsed or copied into normal operation messages;
- connection/local transport failures are reduced to safe typed outcomes;
- no response body, credential or token is copied into ordinary evidence messages.

## Marzban `v0.8.4` read contract

Implemented by:

- `MarzbanSourceContractGateway`;
- `MarzbanSourceContractGatewayFactory`;
- existing `MarzbanAdapter` and common contracts.

Pinned source mapping:

- authentication: `POST /api/admin/token` with username/password form fields, then Bearer token;
- version/connection check: `GET /api/system` and exact `0.8.4` comparison;
- authoritative username lookup: `GET /api/user/{username}`;
- status/synchronization: read through the authoritative user lookup;
- compatible-target discovery: `GET /api/inbounds`;
- snapshot inputs: username, status, `data_limit`, `used_traffic`, Unix `expire`, proxies and inbound assignments.

Read-only advertised capabilities:

- `test_connection`;
- `authoritative_username_lookup`;
- `fetch_status`;
- `synchronize`;
- `list_compatible_targets`.

Mutations and delivery are deliberately not advertised and fail closed before a provider request.

## PasarGuard `v5.2.1` read contract

Implemented by:

- `PasarGuardSourceContractGateway`;
- `PasarGuardSourceContractGatewayFactory`;
- existing `PasarGuardAdapter` and common contracts.

Pinned source mapping:

- preferred protected API-key authentication: `X-Api-Key` using the `pg_key_...` key family;
- username/password fallback: `POST /api/admin/token`, then Bearer token;
- version/connection check: `GET /api/system` and exact `5.2.1` comparison;
- authoritative username lookup: `GET /api/user/by-username/{username}`;
- remote-ID lookup: `GET /api/user/by-id/{id}`;
- compatible-target discovery: `GET /api/groups`, with disabled groups excluded;
- snapshot inputs: numeric remote ID, username, status, traffic, datetime expiry, proxy settings, group IDs and HWID limit.

An endpoint containing a base path such as `/hpanel` is preserved when API paths are appended.

Read-only advertised capabilities:

- `test_connection`;
- `authoritative_username_lookup`;
- `fetch_status`;
- `synchronize`;
- `list_compatible_targets`.

Mutations and delivery are deliberately not advertised and fail closed before a provider request.

## Credential and failure policy

- Marzban requires username/password for this pinned read contract.
- PasarGuard accepts a canonical validated `api_key`; username/password fallback remains supported.
- Historical `api_token` input remains accepted at the credential-policy compatibility boundary so previously verified unavailable-shell behavior is not broken; the source-contract gateway still validates a key before sending it as `X-Api-Key`.
- Credential projections remain redacted through the previously verified `PanelCredentials` boundary.
- Exact provider versions are checked before authoritative reads/target discovery; mismatch fails closed.
- HTTP 429 and 5xx responses become retryable read failures; malformed successful JSON becomes an uncertain result; ordinary 4xx responses are definitive for the relevant read/auth operation.
- an unavailable authoritative lookup raises a dedicated signal and `RemoteIdentityResolver` converts it to Manual Review, never to `Absent`.

## Remote-effect safety

This increment intentionally does **not** implement provider mutations.

The following existing invariant remains enforced:

1. authoritative lookup must succeed before any create can be considered;
2. lookup failure/unavailability means no create;
3. exact local-request equality must not be inferred from a provider-observable snapshot hash;
4. mutations remain disabled until request-to-provider mappings, idempotency, uncertainty discovery and provider result semantics are verified;
5. no real Target may become operational because this read-only contract exists.

The provider snapshot `canonicalHash` is a deterministic hash of provider-observable normalized fields. It is **not** evidence that a remote user equals a local `PanelCreateServiceRequest`, and this increment makes no adoption-equivalence claim for real providers.

## Deterministic contract tests

Primary test:

- `tests/Unit/Modules/Panels/PanelProviderSourceContractGatewayTest.php`.

Covered scenarios include:

- application container binds the pinned source-contract factories;
- Marzban token authentication, exact version check, username lookup, status snapshot and inbound discovery;
- Marzban version mismatch fails definitively and safely;
- PasarGuard API-key authentication and base-path preservation;
- PasarGuard exact version check, username lookup, ID lookup and group discovery;
- disabled PasarGuard groups are not returned as compatible targets;
- PasarGuard username/password token fallback;
- malformed canonical PasarGuard API key is rejected before HTTP;
- provider mutation attempts remain disabled and produce no mutation HTTP request;
- authoritative lookup exception resolves to Manual Review rather than remote absence;
- provider failure body and fixture credentials are not copied into safe messages;
- previous unavailable-shell compatibility remains green.

The complete pre-existing regression suite also passes.

## Mandatory job results

| Job | Result |
|---|---|
| Repository preflight/project-control verification | success |
| Secret scan | success |
| Dependency and license policy | success |
| Pint | success |
| PHPStan/Larastan | success |
| Forbidden-pattern policy | success |
| Architecture policy | success |
| MariaDB/authenticated Redis full suite | success |
| JUnit and Clover artifact checks | success |

## Retained implementation artifacts

| Artifact | ID | GitHub digest |
|---|---:|---|
| `preflight-evidence-31178042191` | `8993597010` | `sha256:6f9e498e10704fde1570780a60c96f969cb585784a2bb661cf413d0f109c222e` |
| `gitleaks-results.sarif` | `8993603940` | `sha256:1ee213a1ca878452e0eead59d437c037006e7b0a27b7b15af1952fb8b4d2fbf1` |
| `dependency-evidence-31178042191` | `8993627218` | `sha256:280fc7b86dff878ff1d767729d1e200c3c7c20fca5c3214eac7c84869569a1ed` |
| `static-evidence-31178042191` | `8993643181` | `sha256:77454dcb4b2a78160984ac12356730fba14305f2c952f810b9c98f019798b73c` |
| `test-evidence-31178042191` | `8993620714` | `sha256:ae51266d7730c22d7076c1603b36f073bc9d6f4f6007f680b942300451d110a2` |

The test artifact was independently downloaded and SHA-256 hashed. The independent digest matches the GitHub artifact digest:

- `sha256:ae51266d7730c22d7076c1603b36f073bc9d6f4f6007f680b942300451d110a2`.

Artifact inspection found:

- `tests/junit.xml` — 146977 bytes;
- `tests/test.log` — 763 bytes;
- `coverage/clover.xml` — 1052619 bytes;
- `services/compose-ps.txt` — 448 bytes;
- `services/compose.log` — 8084 bytes;
- no obvious private-key, Bearer-header, API-key-header or fixture-password pattern in the inspected test artifact;
- Clover metrics: 352 files, 288 classes, 1102 methods with 550 covered, and 14471 statements with 11690 covered.

Coverage percentage is diagnostic only; requirement/invariant scenarios remain the acceptance criterion.

## Explicit exclusions

This evidence does **not** claim:

- a successful live connection to any Marzban or PasarGuard installation;
- compatibility with any installation other than the pinned source shape reviewed for `v0.8.4` and `v5.2.1`;
- that a user-supplied panel endpoint, credentials or API key was used;
- create, update-expiry, update-data, usage reset, suspend, activate, delete, subscription rotation or delivery compatibility;
- real remote idempotency or real uncertain-create recovery;
- real provider rate-limit/error semantics beyond source-shaped deterministic HTTP fixtures;
- production Target activation;
- complete Provisioning/Service/Order/payment/Telegram UX;
- Phase `0.4.0` closure.

## Deferred live verification

Live provider verification is deliberately deferred until a controlled test Marzban/PasarGuard environment is available. Credentials must be supplied through protected secret/runtime configuration, not repository text, Issue/PR comments, chat handoffs or evidence files.

Before enabling mutations, a separate bounded increment must map and test each provider mutation request/response against pinned source fixtures while preserving:

- lookup-before-create;
- no-create on lookup unavailability;
- exact replay and conflicting-key protection;
- discovery after uncertain result before retry;
- safe error classification/redaction;
- TLS verification;
- fail-closed capability advertisement.

## Evidence-head gate

The commit containing this report and `docs/36-phase-0.4-panel-provider-read-contract-traceability.md` must pass the same mandatory CI. Only after that exact evidence-head succeeds may this read-only source-contract increment be recorded as evidence-complete in Issue `#7`, Draft PR `#6`, and current project status.
