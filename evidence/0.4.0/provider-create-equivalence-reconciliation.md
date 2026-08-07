# Phase 0.4 Provider Create-Equivalence Reconciliation

This bounded reconciliation closes a non-live correctness gap between the accepted provider mutation-contract mappers and the common remote-identity adoption path.

Acceptance of this report is controlled by the exact evidence-head CI recorded in current project status. This document does not self-declare a later head accepted.

## Scope

Providers remain pinned to:

- Marzban `v0.8.4`;
- PasarGuard `v5.2.1`.

No live panel, credential, provider mutation, delivery operation, or Target activation was used for this increment.

## Defect reconciled

The accepted mutation-contract mappers already defined provider-specific create-equivalence functions using only fields that the pinned provider preserves and returns through authoritative lookup.

However, `RemoteIdentityResolver` still compared the local create request to `RemoteServiceSnapshot::canonicalHash`. That hash intentionally represents normalized provider-observable read state and includes read-oriented or provider-shaped state that is not the same semantic object as a local create request.

Using it as create equality could therefore make adoption semantics depend on the wrong canonicalization boundary.

## Implemented correction

### Separate read-state and create-equivalence evidence

`RemoteServiceSnapshot` now carries two distinct hashes:

- `canonicalHash` — deterministic provider-observable read-state hash for synchronization/diagnostic identity;
- `createEquivalenceHash` — optional provider-specific proof derived only from create fields that the provider is known to preserve and expose authoritatively.

The create-equivalence hash is optional because an authoritative read can remain valid even when the response does not contain enough stable fields to prove local create equality.

### Common adapter semantic

`PanelAdapter` now exposes `createEquivalenceHash(PanelCreateServiceRequest)`.

This does not copy a provider API into the common contract. It expresses the common semantic required by `PRV-002`/`PRV-003`: before adoption, the adapter must state the provider-specific canonical create intent against which authoritative remote state is compared.

### Pinned provider integration

`MarzbanSourceContractGateway` uses `MarzbanMutationContractMapper` for:

- local request create-equivalence hash;
- authoritative remote-state create-equivalence hash.

`PasarGuardSourceContractGateway` does the same through `PasarGuardMutationContractMapper`.

If the pinned read response is valid but incomplete for create-equivalence proof, the gateway preserves the read snapshot and leaves `createEquivalenceHash` null. It does not invent or infer missing provider fields.

### Fail-closed resolver behavior

`RemoteIdentityResolver` now:

1. requires authoritative username lookup capability;
2. requires a valid provider-specific local create-equivalence hash before create can be considered;
3. performs authoritative lookup;
4. returns `Absent` only when lookup authoritatively returns no service;
5. requires an authoritative remote `createEquivalenceHash` before adoption;
6. adopts only when username and provider-specific create-equivalence hash match;
7. returns conflict on a proved mismatch;
8. returns Manual Review when equivalence cannot be proved.

Therefore:

- lookup unavailable => no create;
- create-equivalence mapping unavailable => no create;
- pre-existing remote state without create-equivalence proof => no adopt and no create;
- uncertainty still requires discovery before any retry.

### Successful-create verification

`PanelCreateCoordinator` also validates a successful provider snapshot through the same adapter-specific equivalence path. A provider success without a usable authoritative snapshot or create-equivalence proof becomes uncertain/manual-review state rather than an accepted effect.

### Fake regression adapter

`FakePanelAdapter` preserves its deterministic test behavior, but now records and compares create-equivalence evidence explicitly. Mutation idempotency/replay behavior is unchanged, including the rule that conflicting idempotency-key reuse cannot overwrite the original effect.

## Runtime safety unchanged

Real Marzban and PasarGuard gateways still advertise only:

- `test_connection`;
- `authoritative_username_lookup`;
- `fetch_status`;
- `synchronize`;
- `list_compatible_targets`.

Real create/update/reset/suspend/activate/delete/rotate/delivery operations remain unadvertised and fail closed in `AbstractPinnedReadOnlyPanelGateway` before provider mutation HTTP.

Service Targets also remain non-operational: inventory creation produces disabled/declared Targets, while route verification requires active state, verified capability evidence, active/verified panel connection, matching version, and active protocol assignment.

## Deterministic proof

Tests prove at least the following reconciliation cases:

- an exact existing Fake remote service is adopted without create;
- a remote service with valid observable read state but no create-equivalence proof goes to Manual Review and blocks create;
- a create-equivalence mismatch is conflict and blocks recreate;
- an uncertain create is followed by authoritative discovery and never by an immediate second create;
- successful create without authoritative snapshot is uncertain;
- successful create with mismatched create-equivalence evidence is conflict;
- unavailable authoritative lookup blocks create;
- Marzban `v0.8.4` authoritative fixture state derives provider create-equivalence evidence and is adoptable through the common resolver;
- PasarGuard `v5.2.1` fixture state does the same;
- provider mutation remains disabled and produces no mutation HTTP;
- snapshot read hash and create-equivalence hash remain distinct semantics;
- the complete pre-existing regression suite remains green.

## Exact implementation evidence

Implementation SHA:

- `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43`

Mandatory CI:

- workflow: `CI`;
- run ID: `31223470871`;
- run number: `995`;
- conclusion: `success`.

Executable suite:

- **315 tests passed**;
- **1678 assertions**;
- failures: `0`;
- errors: `0`;
- PHPUnit: `12.5.33`;
- PHP: `8.4.23`;
- PCOV: `1.0.12`.

Retained test artifact:

- artifact: `test-evidence-31223470871`;
- artifact ID: `9011256284`;
- GitHub uploader digest: `sha256:f3fc78ab55ba0adbb2814bb6d0de464736e897df36625b8f72f54b3f7b8fe95d`;
- independently downloaded SHA-256: `sha256:f3fc78ab55ba0adbb2814bb6d0de464736e897df36625b8f72f54b3f7b8fe95d`;
- artifact contains 5 expected test/coverage/service evidence files;
- bounded inspection found no known Bearer-header, API-key-header, private-key, fixture-password, or canonical PasarGuard-key pattern.

## Requirements supported

- `PRV-001` — provider contracts remain explicit, version-pinned and fail closed; target mapping does not imply activation;
- `PRV-002` — adoption is now based on provider-specific preserved create fields rather than a generic read-state hash;
- `PRV-003` — uncertainty and insufficient reconciliation evidence block retry/create until discovery can prove state;
- `SEC-001` / `SEC-002` — transport/redaction/fail-closed controls remain unchanged and mandatory gates pass;
- `QUA-001` — exact implementation-SHA CI and retained artifact evidence are recorded.

## Explicit exclusions

This increment does **not** claim:

- successful live Marzban/PasarGuard authentication;
- live provider compatibility beyond deterministic source-shaped fixtures;
- any live create/update/reset/suspend/activate/delete/rotate/delivery effect;
- provider-side idempotency guarantees;
- production Target activation;
- Phase `0.4.0` closure.

Live acceptance remains a separate owner-supplied controlled-test-panel gate.