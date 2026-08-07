# Phase 0.4 Pinned Panel Provider Read-Contract Traceability

**Status:** evidence complete.  
**Implementation SHA:** `954973e505901208b5cef9348551e0c71ac027b6`  
**Implementation CI:** `31178042191` / run `#959` — success  
**Evidence SHA:** `23a8da1ce1327407ecd826daa87b334452883d77`  
**Evidence CI:** `31178477080` / run `#961` — success  
**Suite:** 306 tests, 1522 assertions  
**Evidence artifact:** `test-evidence-31178477080`, ID `8993821250`  
**Independent digest:** `sha256:9a45179652a3a88456c835c4d715aceef4efaf770c06b8246f0ebd68ca1b6fe6`  
**Evidence:** `evidence/0.4.0/pinned-panel-provider-read-contracts.md`

This document maps the accepted pinned read-only/source-contract provider increment to Phase `0.4.0` requirements. The acceptance-state wording was reconciled after CI #961; historical implementation facts are unchanged.

## Requirement map

| Requirement | Accepted boundary | Implementation/proof | Remaining live gap |
|---|---|---|---|
| `PRV-001` | Common Marzban/PasarGuard adapters have exact pinned read gateways for authentication/version check, authoritative lookup, status/sync and compatible-target discovery. Unsupported mutation/delivery capabilities are not advertised and fail closed. | `PanelHttpExchange`, `PanelHttpTransport`, `PanelGatewayRequestFailure`, `AbstractPinnedReadOnlyPanelGateway`, source-contract gateways/factories; `PanelProviderSourceContractGatewayTest`; exact-SHA CI. | Deployment-specific live auth/version/health/target acceptance. |
| `PRV-002` | Real-provider authoritative username lookup is source-mapped and lookup failure cannot be interpreted as remote absence. | provider lookup methods, `AuthoritativePanelLookupUnavailable`, resolver regression. | At this boundary provider-observable snapshot hash was explicitly not create equality; later accepted mutation/equivalence reconciliation supplies that separate semantic. |
| `PRV-003` | Lookup unavailable means no create and this read boundary introduces no mutation path that can bypass discovery. | read-only capabilities, mutation-disabled base gateway, lookup-unavailable tests. | Live uncertain-effect reconciliation remains controlled-panel evidence. |
| `SEC-001` | Transport verifies TLS, disables redirects, bounds timeouts, preserves endpoint/base-path policy, and excludes non-success provider bodies/credentials from ordinary evidence. | `PanelHttpTransport`, endpoint/TLS/credential value objects; deterministic HTTP/redaction tests; mandatory static/secret gates. | Environment-specific TLS/private-CA/pin behavior only when configured live. |
| `SEC-002` | Exact provider version/auth/response validation fails closed and unsupported mutations remain disabled. | credential policy, version assertions, typed safe failures, invalid-key/version tests. | Live provider permission/rate-limit/error behavior. |
| `QUA-001` | Exact implementation and evidence-head mandatory CI, executable suite counts and retained artifact digest are accepted. | #959 and #961, 306/1522, independent artifact digest verification. | No remaining offline evidence gap for this historical increment. |

## Pinned provider map

### Marzban `v0.8.4`

| Capability | Pinned route/source shape | Accepted behavior |
|---|---|---|
| authentication | `POST /api/admin/token` | username/password form -> ephemeral Bearer token; secret excluded from evidence |
| version/connection | `GET /api/system` | exact `0.8.4` required |
| authoritative username lookup | `GET /api/user/{username}` | 404 = absent; transport/provider failure = unavailable/manual review |
| fetch/synchronize | authoritative user route | normalized read snapshot |
| compatible targets | `GET /api/inbounds` | protocol/tag -> opaque local target reference |
| mutations/delivery | not accepted by read boundary | capability absent; fail closed before provider mutation HTTP |

### PasarGuard `v5.2.1`

| Capability | Pinned route/source shape | Accepted behavior |
|---|---|---|
| preferred API-key authentication | `X-Api-Key` | canonical validated protected key |
| password fallback | `POST /api/admin/token` | username/password -> ephemeral Bearer token |
| version/connection | `GET /api/system` | exact `5.2.1` required |
| authoritative username lookup | `GET /api/user/by-username/{username}` | 404 = absent; provider failure = unavailable/manual review |
| remote-ID lookup | `GET /api/user/by-id/{id}` | numeric ID validated and normalized as explicit remote identity |
| compatible targets | `GET /api/groups` | disabled groups excluded; enabled group ID -> opaque local reference |
| endpoint base path | configured path such as `/hpanel` | preserved when API path is appended |
| mutations/delivery | not accepted by read boundary | capability absent; fail closed before provider mutation HTTP |

## Failure classification at this boundary

| Condition | Accepted result |
|---|---|
| connect/transport failure | retryable read failure; authoritative lookup unavailable |
| HTTP `429` / `5xx` | retryable read failure |
| ordinary non-404 `4xx` | definitive read/auth failure |
| exact lookup 404 | authoritative absence for that lookup only |
| malformed successful JSON | uncertain read result |
| provider version mismatch | definitive fail closed |
| malformed canonical PasarGuard API key | rejected before HTTP |
| authoritative lookup exception | Manual Review; never `Absent` |
| provider mutation attempt | definitive `*_source_contract_mutation_disabled`; no mutation HTTP |

## Snapshot semantics

The accepted read gateways normalize provider-observable state into `RemoteServiceSnapshot`.

At this historical boundary, `canonicalHash` is explicitly a deterministic provider-observable-state hash. It is not proof that a remote service equals a local create request.

Later accepted work introduced `createEquivalenceHash` as a separate provider-specific semantic derived only from preserved create fields. That later correction preserves rather than changes this read-boundary definition.

## Automated proof

Primary historical test:

- `tests/Unit/Modules/Panels/PanelProviderSourceContractGatewayTest.php`.

Accepted scenarios include provider DI bindings, Marzban auth/version/user/inbound reads, safe version mismatch, PasarGuard API-key/base-path/username/remote-ID/group mapping, disabled-group exclusion, password-token fallback, invalid key rejection before HTTP, lookup failure -> Manual Review, safe failure messages, provider mutation disabled without HTTP, and complete MariaDB/authenticated Redis regression.

## Explicit exclusions

- no live provider credential/endpoint was used;
- no deployment-specific Marzban/PasarGuard compatibility claim;
- no live mutation/delivery effect;
- no production Target activation;
- no Phase `0.4.0` closure claim.

The live provider gate is defined separately in `docs/41-phase-0.4-provider-live-acceptance-matrix.md`.