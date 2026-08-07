# Phase 0.4 Pinned Panel Provider Read-Contract Traceability

**Status:** implementation boundary green; evidence-head CI pending.  
**Implementation SHA:** `954973e505901208b5cef9348551e0c71ac027b6`  
**Implementation CI:** `31178042191` / run `#959` — success  
**Suite:** 306 tests, 1522 assertions  
**Evidence:** `evidence/0.4.0/pinned-panel-provider-read-contracts.md`

This document maps the pinned **read-only/source-contract** provider increment to Phase `0.4.0` requirements. It deliberately excludes live compatibility, provider mutations, Target activation and complete Provisioning/Service orchestration.

## Requirement map

| Requirement | Bounded acceptance statement | Implementation | Automated proof | Remaining gap |
|---|---|---|---|---|
| `PRV-001` | Common Marzban/PasarGuard adapters have pinned source-version read gateways for connection/version check, authoritative lookup, status/sync and compatible-target discovery. Unsupported mutation/delivery capabilities are not advertised and fail closed. | `PanelHttpExchange`, `PanelHttpTransport`, `PanelGatewayRequestFailure`, `AbstractPinnedReadOnlyPanelGateway`, source-contract gateways/factories, provider bindings. | `PanelProviderSourceContractGatewayTest`, previous common adapter contract suite, full regression. | Live connection and every mutation contract remain unverified. |
| `PRV-002` | Real-provider authoritative username lookup is source-mapped and lookup failure cannot be interpreted as remote absence. | provider `findByDeterministicUsername`; `AuthoritativePanelLookupUnavailable`; `RemoteIdentityResolver`. | Marzban/PasarGuard read fixtures; lookup exception → Manual Review test; previous coordinator regression. | Provider snapshot hash is observable-state hash, not create-request equality; real create/adopt mapping remains disabled. |
| `PRV-003` | The existing uncertainty rule remains fail closed: when authoritative lookup is unavailable, no create may proceed. This increment introduces no mutation path that could bypass discovery. | read-only capability advertisement; mutation-disabled base gateway; resolver lookup-unavailable handling. | mutation attempts create no provider HTTP; lookup-unavailable test; previous Fake uncertainty/discovery tests. | Real uncertain-create semantics and discovery require mutation mapping plus later live verification. |
| `SEC-001` | Provider transport verifies TLS, disables redirects, bounds timeouts, does not parse non-success bodies into application evidence, and preserves credential redaction. | `PanelHttpTransport`, existing endpoint/TLS/credential value objects and policy. | deterministic HTTP tests, secret scan, Pint/PHPStan/forbidden/architecture gates, artifact pattern inspection. | Live certificate/custom-CA/pin behavior remains environment evidence. |
| `SEC-002` | Provider failure/version mismatch/auth errors fail closed; exact version is pinned before reads; API-key format is validated at the canonical input boundary; unsupported mutations remain disabled. | credential policy, gateway version assertions, safe failure classification, read-only capabilities. | version mismatch, invalid API key, sanitized failure, mutation-disabled and regression tests. | Real provider rate limits/error payloads and remote mutation idempotency remain unverified. |
| `QUA-001` | The implementation candidate has exact-SHA mandatory CI, executable suite counts and independently checked retained artifact digest. | CI run/evidence lifecycle. | implementation run `31178042191` / #959; 306/1522; test artifact ID `8993620714`; independent SHA-256 match. | Evidence-head CI still required before Issue/PR acceptance. |

## Pinned provider contract map

### Marzban `v0.8.4`

| Capability | Pinned route/source shape | Local behavior |
|---|---|---|
| Authentication | `POST /api/admin/token` | username/password form → Bearer token; token is never logged/evidenced |
| Version/connection | `GET /api/system` | exact `0.8.4` required |
| Authoritative username lookup | `GET /api/user/{username}` | 404 = absent; transport/provider failure = lookup unavailable/manual review |
| Fetch/synchronize | same authoritative user route | normalized read snapshot |
| Compatible targets | `GET /api/inbounds` | protocol/tag converted to opaque target reference |
| Mutations | source reviewed but not accepted in this increment | capability absent; operation fails closed before provider request |

### PasarGuard `v5.2.1`

| Capability | Pinned route/source shape | Local behavior |
|---|---|---|
| API-key authentication | `X-Api-Key` | preferred protected API-key path |
| Password authentication | `POST /api/admin/token` | username/password fallback → Bearer token |
| Version/connection | `GET /api/system` | exact `5.2.1` required |
| Authoritative username lookup | `GET /api/user/by-username/{username}` | 404 = absent; provider failure = lookup unavailable/manual review |
| Remote-ID lookup | `GET /api/user/by-id/{id}` | numeric ID is validated and normalized to string local remote ID |
| Fetch/synchronize | authoritative ID route | normalized read snapshot |
| Compatible targets | `GET /api/groups` | disabled groups excluded; group ID becomes opaque provider target reference |
| Base path | endpoint may include path such as `/hpanel` | API path is appended without dropping the configured base path |
| Mutations | source reviewed but not accepted in this increment | capability absent; operation fails closed before provider request |

## Failure classification

| Condition | Result |
|---|---|
| connect/transport failure | retryable read failure; authoritative lookup unavailable |
| HTTP 429 / 5xx | retryable read failure |
| ordinary non-404 4xx | definitive read/auth failure |
| lookup 404 | authoritative absence only for the exact lookup route |
| malformed successful JSON | uncertain read result |
| exact provider version mismatch | definitive fail closed |
| malformed canonical PasarGuard API key | rejected before HTTP |
| authoritative lookup exception | Manual Review; never `Absent` |
| provider mutation attempt | definitive `*_source_contract_mutation_disabled`; no mutation HTTP |

## Snapshot semantics

The read gateways produce `RemoteServiceSnapshot` values from provider-observable fields.

- Marzban remote ID is the pinned username identity in this read contract.
- PasarGuard remote ID is the provider numeric user ID serialized as a string.
- timestamps/traffic/limit/status are normalized into the common snapshot contract.
- the snapshot `canonicalHash` is a deterministic normalized **provider-observable-state hash**.

That hash must not be compared as proof that a pre-existing remote user is exactly equivalent to a local create request until a separate mutation/equivalence mapper is accepted. Therefore this increment does not enable real-provider exact-match adoption or create.

## Test traceability

Primary source-contract test:

- `tests/Unit/Modules/Panels/PanelProviderSourceContractGatewayTest.php`.

Scenarios:

- DI bindings use pinned source-contract gateway factories;
- Marzban authentication/version/user/inbound read mapping;
- Marzban version mismatch;
- PasarGuard API-key path and endpoint base-path preservation;
- PasarGuard username and remote-ID lookup;
- PasarGuard group discovery with disabled group exclusion;
- PasarGuard password-token fallback;
- canonical invalid PasarGuard API key rejected without HTTP;
- lookup exception → Manual Review;
- non-success body/credentials absent from safe message;
- provider mutation remains disabled without HTTP;
- previously verified unavailable provider shell compatibility remains green;
- complete regression suite remains green on MariaDB/authenticated Redis.

## Implementation evidence

- implementation SHA: `954973e505901208b5cef9348551e0c71ac027b6`;
- CI: `31178042191` / #959 — all mandatory jobs success;
- 306 tests, 1522 assertions;
- test artifact: `test-evidence-31178042191`;
- artifact ID: `8993620714`;
- independent digest: `sha256:ae51266d7730c22d7076c1603b36f073bc9d6f4f6007f680b942300451d110a2`;
- detailed evidence: `evidence/0.4.0/pinned-panel-provider-read-contracts.md`.

## Explicit exclusions

- no user-provided live panel or credential was used;
- no real Marzban/PasarGuard connection is claimed;
- no provider mutation or delivery operation is enabled;
- no provider snapshot is accepted as local create-request equality;
- no production Target activation;
- no complete Provisioning/Service/Order/payment/Telegram workflow;
- Phase `0.4.0` remains open.

## Next bounded increment

After this evidence head is accepted, continue offline with **pinned mutation contract mapping** while keeping all real mutations disabled:

1. define provider-specific create/update/reset/suspend/activate/delete/rotate/delivery request mappers from the common contract;
2. define exact provider response/outcome mapping and safe error taxonomy;
3. prove request fixtures against pinned `v0.8.4` / `v5.2.1` source shapes;
4. introduce a provider create-equivalence mapper so adoption can compare the intended local request against authoritative remote observable state;
5. preserve lookup-before-create, no-create on lookup failure, idempotency-key conflict protection and discovery-before-retry;
6. do not advertise mutation capabilities or contact a live provider until that bounded mapping has its own exact-SHA evidence and a test panel is available.

## Evidence-head gate

The exact head containing this traceability and its evidence report must pass every mandatory CI job. Only after that run succeeds may Issue `#7`, Draft PR `#6` and current project status record this increment as evidence-complete.
