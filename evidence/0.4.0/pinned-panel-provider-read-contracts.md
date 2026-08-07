# Phase 0.4 Pinned Panel Provider Read Contracts

Status: **Evidence Complete**

This document is the accepted historical evidence for the pinned provider read-contract increment. A later reconciliation corrected only stale pre-acceptance wording; the implementation/evidence facts below are unchanged.

## Accepted boundaries

Implementation:

- SHA: `954973e505901208b5cef9348551e0c71ac027b6`;
- CI: `31178042191` / run `#959` — success;
- suite: 306 tests, 1522 assertions;
- artifact: `test-evidence-31178042191`;
- artifact ID: `8993620714`;
- independently verified digest: `sha256:ae51266d7730c22d7076c1603b36f073bc9d6f4f6007f680b942300451d110a2`.

Evidence head:

- SHA: `23a8da1ce1327407ecd826daa87b334452883d77`;
- CI: `31178477080` / run `#961` — success;
- suite: 306 tests, 1522 assertions;
- artifact: `test-evidence-31178477080`;
- artifact ID: `8993821250`;
- independently verified digest: `sha256:9a45179652a3a88456c835c4d715aceef4efaf770c06b8246f0ebd68ca1b6fe6`;
- traceability: `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`.

## Source authority

Pinned upstream source is authoritative for this increment:

- `Gozargah/Marzban` tag `v0.8.4`;
- `PasarGuard/panel` tag `v5.2.1`.

`mahdiMGF2/mirzabot` was reviewed only as a secondary practical reference and does not override pinned upstream source.

No live panel was contacted for this evidence.

## Implemented read-only transport boundary

Accepted infrastructure includes:

- `PanelHttpExchange`;
- `PanelHttpTransport`;
- `PanelGatewayRequestFailure`;
- `AbstractPinnedReadOnlyPanelGateway`;
- `AuthoritativePanelLookupUnavailable`;
- source-contract gateways/factories for both providers.

Transport controls include bounded timeouts, redirects disabled, TLS verification always enabled, system CA by default, protected custom-CA/pin resolution only when explicitly configured, sanitized typed failure outcomes, and exclusion of raw non-success provider bodies/credentials/tokens from ordinary evidence/messages.

## Marzban `v0.8.4` accepted read contract

Pinned mapping:

- authentication: `POST /api/admin/token` with username/password form fields, then ephemeral Bearer token;
- version/connection: `GET /api/system`, exact `0.8.4` required;
- authoritative username lookup: `GET /api/user/{username}`;
- status/synchronization: authoritative user lookup;
- compatible-target discovery: `GET /api/inbounds`;
- snapshot inputs: username, status, `data_limit`, `used_traffic`, Unix `expire`, proxies and inbound assignments.

Advertised runtime capabilities remain read-only:

- `test_connection`;
- `authoritative_username_lookup`;
- `fetch_status`;
- `synchronize`;
- `list_compatible_targets`.

## PasarGuard `v5.2.1` accepted read contract

Pinned mapping:

- preferred protected API-key authentication through `X-Api-Key` with validated `pg_key_...` format;
- username/password fallback: `POST /api/admin/token`, then ephemeral Bearer token;
- version/connection: `GET /api/system`, exact `5.2.1` required;
- authoritative username lookup: `GET /api/user/by-username/{username}`;
- numeric remote-ID lookup: `GET /api/user/by-id/{id}`;
- compatible-target discovery: `GET /api/groups`, excluding disabled groups;
- snapshot inputs: numeric remote ID, username, status, traffic, datetime expiry, proxy settings, group IDs and HWID limit;
- configured endpoint base paths such as `/hpanel` are preserved.

Advertised runtime capabilities remain read-only:

- `test_connection`;
- `authoritative_username_lookup`;
- `fetch_status`;
- `synchronize`;
- `list_compatible_targets`.

## Credential and failure policy

- Marzban uses protected username/password credentials for this pinned contract.
- PasarGuard accepts canonical validated `api_key`; username/password fallback remains supported.
- historical `api_token` compatibility input remains accepted at the credential-policy boundary without weakening source-contract key validation.
- exact provider versions are checked before authoritative reads/target discovery.
- HTTP `429`/`5xx` read failures are retryable at the read boundary; malformed successful JSON is uncertain; ordinary non-404 `4xx` failures are definitive for that read/auth operation.
- unavailable authoritative lookup becomes Manual Review, never authoritative absence.
- credentials and sensitive response material remain redacted.

## Remote-effect safety

This read boundary does not implement real provider mutation.

Accepted invariants:

1. authoritative lookup must succeed before create can be considered;
2. lookup failure/unavailability means no create;
3. `RemoteServiceSnapshot::canonicalHash` represents provider-observable normalized read state and is not local create-request equality;
4. no real mutation/delivery capability is advertised by this read boundary;
5. no real Target becomes operational from source-contract read proof alone.

Later accepted mutation/equivalence work added a separate provider-specific create-equivalence semantic; it did not retroactively change the meaning of this read-state `canonicalHash`.

## Deterministic proof

Primary test at this boundary:

- `tests/Unit/Modules/Panels/PanelProviderSourceContractGatewayTest.php`.

Accepted scenarios include:

- DI bindings use pinned source-contract gateway factories;
- Marzban authentication/version/user/inbound mapping and safe version mismatch;
- PasarGuard API-key/base-path handling, username/remote-ID lookup, enabled-group discovery and password-token fallback;
- invalid canonical PasarGuard API key rejected before HTTP;
- provider mutation attempts remain disabled with no mutation HTTP;
- authoritative lookup failure becomes Manual Review;
- provider failure body and fixture credentials are not copied into safe messages;
- complete regression suite green on MariaDB/authenticated Redis.

All mandatory jobs passed on both the exact implementation and evidence heads, including repository preflight/project-control, secret scan, dependency/license policy, Pint, PHPStan/Larastan/repository policy, full MariaDB/authenticated Redis tests, and JUnit/Clover artifact checks.

## Explicit exclusions

This evidence does **not** claim:

- a successful live Marzban/PasarGuard connection;
- compatibility outside the pinned source shapes;
- use of owner credentials or a real provider endpoint;
- live create/update/reset/suspend/activate/delete/rotation/delivery compatibility;
- provider-side idempotency;
- real uncertain-create recovery;
- production Target activation;
- complete Provisioning/Service orchestration;
- Phase `0.4.0` closure.

Live provider acceptance remains a separate controlled owner-supplied test-panel gate.