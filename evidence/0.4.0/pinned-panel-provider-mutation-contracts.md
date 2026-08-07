# Phase 0.4 Pinned Panel Provider Mutation Contracts

## Scope

This bounded increment maps the pinned upstream mutation contracts for:

- Marzban `v0.8.4`;
- PasarGuard `v5.2.1`.

It is deliberately **offline-only**. The implementation adds deterministic request/result/equivalence/delivery mappers and fixtures; it does not enable any real-provider mutation capability, does not activate Targets, and does not contact a provider panel.

## Accepted implementation boundary

- SHA: `15b824e955d040a6bf43405f7015aa85110a73e4`;
- mandatory CI: `31189964977` / run `#978` — success;
- suite: **313 tests, 1650 assertions**;
- test artifact: `test-evidence-31189964977`;
- artifact ID: `8998394458`;
- GitHub upload digest: `sha256:80cbd67bff1373b5aa97f73f252a001858ac6d73b799f8a26805c874f9ee6737`;
- independently verified digest: `sha256:80cbd67bff1373b5aa97f73f252a001858ac6d73b799f8a26805c874f9ee6737`.

All mandatory jobs passed on the exact implementation head:

- repository preflight/project-control;
- secret scan;
- MariaDB/authenticated Redis full suite;
- Pint;
- PHPStan/Larastan and repository policy;
- dependency/license policy.

The retained test artifact was independently downloaded, SHA-256 checked, and inspected. It contains exactly five expected evidence files:

- `tests/junit.xml`;
- `tests/test.log`;
- `coverage/clover.xml`;
- `services/compose-ps.txt`;
- `services/compose.log`.

The inspected archive contained the expected `OK (313 tests, 1650 assertions)` summary and no match for the bounded private-key/common-token patterns used during the independent inspection.

## Source authority

Authoritative source pins:

- `Gozargah/Marzban` tag `v0.8.4`;
- `PasarGuard/panel` tag `v5.2.1`.

No live provider endpoint or credential was used. Secondary examples do not override the exact pinned provider source.

## Added implementation

Common offline value objects:

- `app/Modules/Panels/Infrastructure/PanelMappedMutationRequest.php`;
- `app/Modules/Panels/Infrastructure/PanelMappedMutationOutcome.php`.

Provider mappers:

- `app/Modules/Panels/Infrastructure/MarzbanMutationContractMapper.php`;
- `app/Modules/Panels/Infrastructure/PasarGuardMutationContractMapper.php`.

Deterministic fixtures:

- `tests/Fixtures/Panels/marzban-v0.8.4-mutations.json`;
- `tests/Fixtures/Panels/pasarguard-v5.2.1-mutations.json`.

Primary automated proof:

- `tests/Unit/Modules/Panels/PanelProviderMutationContractMapperTest.php`.

No existing source-contract gateway, capability advertisement, DI binding, Target activation path, or real-provider HTTP mutation path was changed by this increment.

## Mapped operations

Both provider mappers cover the common bounded operation set:

- create service;
- update expiry;
- update data allowance;
- reset usage;
- suspend;
- activate;
- delete;
- rotate subscription link where the pinned provider exposes the explicit operation;
- delivery-artifact mapping;
- mutation result classification;
- provider create-equivalence mapping.

### Marzban `v0.8.4`

Pinned request shapes include:

- create: `POST /api/user`;
- update: `PUT /api/user/{username}`;
- reset: `POST /api/user/{username}/reset`;
- delete: `DELETE /api/user/{username}`;
- subscription rotation: `POST /api/user/{username}/revoke_sub`.

Marzban target references preserve the pinned protocol plus inbound tag as an opaque local reference. The create mapper emits provider-shaped proxy/inbound state without deriving equality from provider-generated secrets or subscription material.

### PasarGuard `v5.2.1`

Pinned request shapes include:

- create: `POST /api/user`;
- update: `PUT /api/user/by-id/{id}`;
- reset: `POST /api/user/by-id/{id}/reset`;
- suspend/activate: `PUT /api/user/by-id/{id}/disabled` with the pinned boolean payload;
- delete: `DELETE /api/user/by-id/{id}`;
- subscription rotation: `POST /api/user/by-id/{id}/revoke_sub`.

PasarGuard numeric remote identity remains explicit and is not treated as interchangeable with Marzban username identity.

## Data allowance safety

Provider unlimited sentinels are not silently introduced by a data-mutation request:

- mapped mutation byte counts must be positive;
- `Set` maps to the explicit absolute byte value;
- `Add` requires an authoritative `RemoteServiceSnapshot` for the same remote identity;
- missing/mismatched authoritative state causes fail-closed rejection;
- an authoritative unlimited/null limit cannot be converted into an additive absolute mutation;
- integer overflow is rejected.

This preserves the rule that a local `0` or unavailable baseline must never accidentally become a provider unlimited mutation.

## Provider create-equivalence

Each mapper exposes deterministic canonical create-state hashing and equivalence checking.

Only provider-preserved intent fields are compared.

Marzban canonical create state includes:

- username;
- active status;
- data limit;
- expiry;
- reset strategy;
- selected protocol;
- selected inbound tag assignment.

PasarGuard canonical create state includes:

- username;
- active status;
- data limit;
- expiry;
- reset strategy;
- selected group IDs.

Excluded from create equality are volatile/provider-generated fields such as usage, timestamps, subscription URLs, generated proxy secrets and unrelated response-only metadata.

Malformed or mismatched remote state never becomes an accepted equivalence match.

## Mutation result classification

The offline result mappers preserve the common outcome taxonomy without changing the shared adapter contract:

| Provider response condition | Local mapped result |
|---|---|
| verified expected success response | `success` |
| ordinary definitive non-success | `definitive_failure` |
| HTTP `429` before an accepted effect | `retryable_failure` |
| transport/no-status failure | `uncertain_result`; discovery required before retry |
| HTTP `5xx` after possible effect | `uncertain_result`; discovery required before retry |
| malformed/unverified success response | `uncertain_result`; discovery required before retry |
| HTTP `409` conflict | definitive conflict/manual review; no blind retry |

Expected success statuses remain provider-specific:

- Marzban mapped mutation success: HTTP `200`;
- PasarGuard create: HTTP `201`;
- PasarGuard delete: HTTP `204`;
- other mapped PasarGuard mutations: HTTP `200`.

An uncertainty marker can only accompany `uncertain_result`. A conflict is marked for manual review and is not converted into a retry path.

## Remote-effect invariants preserved

This increment does not weaken the previously accepted common behavior:

1. authoritative lookup occurs before create;
2. lookup unavailable means no create;
3. an existing exact remote match may be adopted only through an accepted provider-specific create-equivalence comparison;
4. mismatch means conflict/manual review;
5. uncertain mutation means discovery/reconciliation before retry;
6. conflicting idempotency-key reuse must not overwrite the original primary effect;
7. credentials and sensitive delivery material remain outside normal logs/evidence;
8. TLS verification remains mandatory for any later live transport;
9. real mutation capabilities and Targets remain fail closed.

The current `AbstractPinnedReadOnlyPanelGateway` mutation/delivery boundary remains unchanged, so these offline mappers cannot cause a real remote effect.

## Delivery mapping

Delivery mapping is source-shaped but remains offline-only and unadvertised at runtime.

- subscription URLs must be HTTPS and cannot contain URL user/password credentials;
- Marzban additionally validates supported config-link schemes (`ss`, `trojan`, `vless`, `vmess`);
- mapped delivery data is wrapped in the existing `SensitiveDeliveryArtifacts` boundary;
- string conversion remains redacted.

## Deterministic tests

The mapper test proves, against repository fixtures:

- exact HTTP method/path/payload mapping for all declared operations;
- Marzban empty proxy settings serialize as a JSON object, not an array;
- additive data requires authoritative state;
- zero-byte/unlimited-sentinel ambiguity is rejected;
- create-equivalence accepts preserved fields and rejects material drift;
- volatile response fields do not affect create equality;
- transport/5xx/malformed-success uncertainty requires discovery before retry;
- `429` remains retryable-before-effect;
- `409` remains conflict/manual review and does not become a retry;
- exact provider success status/payload shape is required;
- delivery artifacts stay redacted and unsafe subscription URLs are rejected.

The full regression suite remains green.

## Explicit exclusions

This evidence does **not** claim:

- a live Marzban/PasarGuard connection;
- successful live authentication or provider-specific deployment compatibility;
- any real create/update/reset/suspend/activate/delete/rotate/delivery effect;
- provider-side idempotency guarantees;
- production Target activation;
- compatibility beyond Marzban `v0.8.4` and PasarGuard `v5.2.1` pinned source contracts;
- complete Provisioning/Service orchestration;
- Phase `0.4.0` closure.

Live panel verification remains intentionally deferred until the owner supplies controlled test panels through protected runtime configuration.

## Evidence-head acceptance rule

This report is evidence-complete only when the exact repository head containing this report and its traceability passes every mandatory CI job. The accepted evidence-head SHA/run must then be recorded in the authoritative project control plane. A later control-plane refresh must not rewrite the implementation evidence above.
