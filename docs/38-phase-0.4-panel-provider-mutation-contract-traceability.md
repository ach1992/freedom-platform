# Phase 0.4 Pinned Panel Provider Mutation-Contract Traceability

**Implementation SHA:** `15b824e955d040a6bf43405f7015aa85110a73e4`  
**Implementation CI:** `31189964977` / run `#978` — success  
**Suite:** 313 tests, 1650 assertions  
**Implementation artifact:** `test-evidence-31189964977`, ID `8998394458`  
**Independent SHA-256:** `80cbd67bff1373b5aa97f73f252a001858ac6d73b799f8a26805c874f9ee6737`  
**Evidence:** `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`

This document maps the bounded **offline mutation-contract** increment to Phase `0.4.0` requirements. It does not activate real provider mutations or Targets and does not claim live compatibility.

## Requirement map

| Requirement | Bounded acceptance statement | Implementation | Automated proof | Remaining gap |
|---|---|---|---|---|
| `PRV-001` | Marzban `v0.8.4` and PasarGuard `v5.2.1` have provider-specific immutable offline mappings for create/update/reset/suspend/activate/delete/rotate/delivery and exact success/failure classification. | `MarzbanMutationContractMapper`, `PasarGuardMutationContractMapper`, mapped request/outcome value objects, deterministic fixtures. | `PanelProviderMutationContractMapperTest`, full regression, exact implementation CI #978. | Runtime mutation capabilities remain unadvertised; live provider acceptance remains deferred. |
| `PRV-002` | Provider create equality is no longer inferred from the read snapshot hash. A dedicated mapper compares only provider-preserved create intent fields and rejects malformed/drifted state. | provider `requestCreateCanonicalHash`, `providerCreateCanonicalHash`, `createEquivalent`. | equivalence-match, material-drift, volatile-field and malformed-state scenarios. | Runtime adoption for real providers stays disabled until later guarded integration/live acceptance. |
| `PRV-003` | Mutation uncertainty is explicitly classified as requiring discovery before retry; conflict is manual review and cannot become a blind retry. Existing lookup-before-create/no-create-on-unavailable behavior remains untouched. | `PanelMappedMutationOutcome`, provider `classifyMutation`, existing resolver/coordinator/Fake idempotency boundaries. | transport/5xx/malformed-success uncertainty tests; 409 conflict/manual-review test; 429 retryable-before-effect test; full regression. | Real provider post-effect discovery/reconciliation requires controlled live test panels. |
| `SEC-001` | Delivery mapping rejects unsafe subscription URLs, preserves redaction, and introduces no credential/logging path. Real transport/TLS behavior remains the accepted read-gateway policy. | provider `deliveryArtifacts`, existing `SensitiveDeliveryArtifacts`; no runtime HTTP mutation wiring. | unsafe URL/redaction tests, secret scan, artifact inspection, full mandatory CI. | Live certificate/custom-CA/pin evidence remains environment-specific and deferred. |
| `SEC-002` | Invalid identity/target/data/success shapes fail closed; provider unlimited sentinels cannot be introduced by zero-byte data mutations; uncertain responses are not treated as success. | mapper validation and exact expected-status/payload checks. | target/data/success/outcome fixture tests, Pint/PHPStan/repository policy, full regression. | Live provider authorization/rate-limit/error semantics remain deferred. |
| `QUA-001` | Exact implementation SHA passed mandatory CI and retained test evidence was independently hashed and inspected. | CI/evidence lifecycle. | #978; 313/1650; artifact ID `8998394458`; independent SHA-256 match. | Exact evidence-head CI must accept this report/traceability before control-plane promotion. |

## Provider route traceability

### Marzban `v0.8.4`

| Operation | Pinned source shape | Offline local mapping |
|---|---|---|
| Create | `POST /api/user` | username/status/proxy/inbound/expiry/data/reset-strategy payload |
| Update expiry/data | `PUT /api/user/{username}` | partial absolute provider update |
| Reset usage | `POST /api/user/{username}/reset` | no body |
| Suspend/activate | `PUT /api/user/{username}` | status `disabled` / `active` |
| Delete | `DELETE /api/user/{username}` | no body |
| Rotate subscription | `POST /api/user/{username}/revoke_sub` | no body |
| Delivery | successful user state | HTTPS subscription URL plus validated config links, wrapped as sensitive artifacts |

### PasarGuard `v5.2.1`

| Operation | Pinned source shape | Offline local mapping |
|---|---|---|
| Create | `POST /api/user` | username/expiry/data/reset-strategy/group/status payload |
| Update expiry/data | `PUT /api/user/by-id/{id}` | partial absolute provider update |
| Reset usage | `POST /api/user/by-id/{id}/reset` | no body |
| Suspend/activate | `PUT /api/user/by-id/{id}/disabled` | `{disabled:true}` / `{disabled:false}` |
| Delete | `DELETE /api/user/by-id/{id}` | no body |
| Rotate subscription | `POST /api/user/by-id/{id}/revoke_sub` | no body |
| Delivery | successful user state | HTTPS subscription URL wrapped as sensitive artifact |

## Create-equivalence semantics

The common read snapshot `canonicalHash` remains provider-observable state only. This increment introduces separate create-equivalence semantics.

Marzban preserved comparison fields:

- username;
- status;
- data limit;
- expiry;
- reset strategy;
- selected proxy protocol;
- selected inbound tags.

PasarGuard preserved comparison fields:

- username;
- status;
- data limit;
- expiry;
- reset strategy;
- group IDs.

Provider-generated proxy secrets, subscription material, usage, timestamps and unrelated response metadata are intentionally excluded. Malformed state fails equivalence.

## Mutation outcome traceability

| Condition | Outcome | Retry/discovery rule |
|---|---|---|
| exact verified success | `success` | effect accepted |
| ordinary definitive non-success | `definitive_failure` | no blind retry |
| `429` | `retryable_failure` | retryable before accepted effect |
| transport/no status | `uncertain_result` | discovery required before retry |
| `5xx` | `uncertain_result` | discovery required before retry |
| malformed/unverified expected-success response | `uncertain_result` | discovery required before retry |
| `409` | definitive conflict/manual review | no blind retry; original effect must remain authoritative |

The shared `PanelOperationOutcome` enum was not expanded merely to mirror provider-specific details. Conflict/manual-review and discovery requirements are carried in the mapped provider outcome without weakening common semantics.

## Data mutation traceability

- `Set` maps an explicit positive absolute byte count.
- `Add` is converted to an absolute provider value only after authoritative lookup supplies the current finite limit for the same remote identity.
- missing lookup, mismatched identity, unlimited/null baseline, zero bytes and overflow are rejected.
- provider zero/unlimited sentinels therefore cannot be introduced accidentally by a local data mutation.

## Runtime safety boundary

The existing `AbstractPinnedReadOnlyPanelGateway` remains the runtime provider boundary and still fails create/update/reset/suspend/activate/delete/rotate/delivery closed. No mutation capability advertisement or Target activation was added.

Consequently:

- lookup-before-create remains enforced by the existing coordinator/resolver path;
- lookup unavailable still means no create;
- offline create-equivalence does not activate real-provider adoption;
- uncertainty classification cannot produce a live retry because no real mutation path exists;
- the existing application-owned idempotency conflict rule remains authoritative and the original primary effect is not overwritten.

## Test traceability

Primary test:

- `tests/Unit/Modules/Panels/PanelProviderMutationContractMapperTest.php`.

Deterministic fixtures:

- `tests/Fixtures/Panels/marzban-v0.8.4-mutations.json`;
- `tests/Fixtures/Panels/pasarguard-v5.2.1-mutations.json`.

Verified scenarios include:

- exact method/path/payload mapping for every declared operation;
- provider-specific target identity decoding;
- correct Marzban JSON object shape for empty proxy settings;
- exact provider success status handling;
- authoritative lookup requirement for additive data;
- unlimited-sentinel/zero-byte protection;
- create-equivalence match/drift behavior and volatile-field exclusion;
- discovery-before-retry after uncertain mutation;
- conflict/manual review without retry conversion;
- sensitive delivery redaction and HTTPS validation;
- full regression on MariaDB/authenticated Redis.

## Evidence lifecycle

Implementation evidence is accepted:

- SHA `15b824e955d040a6bf43405f7015aa85110a73e4`;
- CI `31189964977` / #978 — success;
- 313 tests / 1650 assertions;
- `test-evidence-31189964977`, ID `8998394458`;
- GitHub and independent digest both `sha256:80cbd67bff1373b5aa97f73f252a001858ac6d73b799f8a26805c874f9ee6737`.

The exact head containing this traceability and its evidence report must pass the same mandatory CI before this increment is promoted in the authoritative status.

## Explicit exclusions

- no live panel endpoint or credential;
- no real provider mutation/delivery HTTP effect;
- no provider-side idempotency claim;
- no production Target activation;
- no compatibility claim outside the exact pinned versions;
- no complete Provisioning/Service/Order/payment/Telegram workflow;
- no Phase `0.4.0` closure.

## Next safe Phase 0.4 work after evidence acceptance

Because live panel acceptance is intentionally deferred, the next non-live work is Phase `0.4.0` reconciliation rather than activating mutations:

1. reconcile `PRV-001`–`PRV-003`, supporting security/data/quality requirements and current risk/traceability overlays against all accepted Phase `0.4.0` evidence;
2. verify no runtime path advertises unproved mutation capabilities or enables Targets;
3. identify the exact residual live-provider acceptance matrix (auth/version/read/target/create-adopt-conflict/mutations/uncertainty/cleanup);
4. leave live execution blocked until owner-supplied controlled test panels exist;
5. do not pull Phase `0.5.0`/`0.6.0` work into the Phase `0.4.0` closure audit.
