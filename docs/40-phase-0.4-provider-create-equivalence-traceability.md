# Phase 0.4 Provider Create-Equivalence Reconciliation Traceability

This document maps the bounded provider create-equivalence reconciliation to Phase `0.4.0` requirements. Acceptance is determined by the exact evidence-head CI recorded in current status; this document does not make a live-provider compatibility claim.

## Implementation boundary

- SHA: `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43`;
- CI: `31223470871` / run `#995` — success;
- suite: 315 tests, 1678 assertions;
- test artifact: `test-evidence-31223470871`;
- artifact ID: `9011256284`;
- independently verified digest: `sha256:f3fc78ab55ba0adbb2814bb6d0de464736e897df36625b8f72f54b3f7b8fe95d`;
- evidence: `evidence/0.4.0/provider-create-equivalence-reconciliation.md`.

## Requirement map

| Requirement | Reconciled acceptance statement | Implementation | Automated proof | Remaining live gap |
|---|---|---|---|---|
| `PRV-001` | Provider adapters keep version-pinned read contracts and explicit provider-specific create-equivalence semantics while real mutation capabilities remain unadvertised. | `PanelAdapter::createEquivalenceHash`, Marzban/PasarGuard source-contract gateways and existing read-only mutation boundary. | source-contract provider tests; full regression; static/architecture/project-control gates. | Live auth/version/health/capability evidence and operational Target acceptance require controlled panels. |
| `PRV-002` | Existing remote service is adopted only when authoritative username identity and a provider-specific hash of fields preserved by that provider match the local create intent. Generic provider-observable `canonicalHash` is no longer create equality. | `RemoteServiceSnapshot::createEquivalenceHash`, `RemoteIdentityResolver`, provider mutation-contract mappers. | matching adoption; missing-proof Manual Review; mismatch conflict; Marzban/PasarGuard fixture adoption. | Live create/adopt/conflict behavior still requires disposable test users on controlled panels. |
| `PRV-003` | Missing equivalence proof or unavailable lookup blocks create. Uncertain create remains discovery-first and cannot immediately produce a second create. | `RemoteIdentityResolver`, `PanelCreateCoordinator`. | missing lookup/missing equivalence/no-create tests; uncertain-create discovery test; Fake single-service assertion. | Live timeout/transport-fault reconciliation requires controlled panel fault injection. |
| `SEC-001` | No new credential or sensitive delivery path was introduced; provider transport/TLS/redaction behavior is unchanged. | existing `PanelHttpTransport`, endpoint/TLS/credential boundaries. | secret scan, source-contract failure redaction tests, retained artifact inspection. | Live private CA/pinning behavior is environment evidence only if configured. |
| `SEC-002` | Ambiguous or incomplete provider state fails closed to Manual Review/uncertain rather than being inferred as match/absence. | resolver/coordinator and nullable remote create-equivalence proof. | missing-proof, lookup-failure, mismatched-success and malformed-boundary regressions. | Live provider-specific error/rate-limit semantics require controlled panels. |
| `QUA-001` | The fix has exact-SHA mandatory CI, complete regression counts and an independently checked retained artifact digest. | CI/evidence lifecycle. | run `31223470871` / #995; 315/1678; artifact ID `9011256284`; independent digest match. | Exact evidence-head CI is required before this reconciliation is recorded as accepted current state. |

## Semantic boundary

### Provider-observable canonical state

`RemoteServiceSnapshot::canonicalHash` remains a deterministic normalized hash of state observed through provider reads. It is useful for stable read/synchronization evidence but is **not** proof of local create-request equality.

### Provider create-equivalence

`RemoteServiceSnapshot::createEquivalenceHash` is an optional proof over only the fields the provider preserves and returns authoritatively:

- Marzban `v0.8.4`: derived by `MarzbanMutationContractMapper` from its accepted pinned source mapping;
- PasarGuard `v5.2.1`: derived by `PasarGuardMutationContractMapper` from its accepted pinned source mapping;
- Fake: deterministic local canonical create intent for offline regression behavior.

An authoritative read can exist without create-equivalence proof. In that state the service may be synchronized/read, but it is not eligible for automatic adoption.

## Create/reconciliation state table

| Precondition / observation | Result | Remote effect allowed |
|---|---|---|
| authoritative username lookup capability absent | Manual Review | no create |
| local provider create-equivalence mapping unavailable/invalid | Manual Review | no create |
| authoritative lookup unavailable/fails | Manual Review | no create |
| authoritative lookup proves absence | create may be considered by the adapter boundary | only if the adapter itself advertises/implements create; real pinned providers currently do not |
| remote username differs | conflict | no create |
| remote create-equivalence proof missing | Manual Review | no create/adopt |
| remote create-equivalence mismatches | conflict | no create/adopt |
| remote create-equivalence matches | adopt | no create |
| create returns uncertain | authoritative discovery before any retry | no immediate second create |
| create reports success without authoritative snapshot/equivalence proof | uncertain/manual review | no assumed success |
| conflicting idempotency-key reuse | conflict; preserve prior result/effect | no overwrite |

## Runtime capability and Target verification

This reconciliation does not activate provider writes.

Marzban and PasarGuard source gateways still advertise only read operations. `AbstractPinnedReadOnlyPanelGateway` still rejects create/update/reset/suspend/activate/delete/rotate and delivery before mutation HTTP.

Service Targets remain fail closed:

- inventory creation records disabled state and declared capability status;
- operational route verification requires active Target state plus verified capability evidence, active/tested compatible Panel Connection, and active compatible protocol assignment;
- deterministic source-contract target discovery alone is insufficient for production routing.

## Test traceability

Primary tests:

- `tests/Unit/Modules/Panels/PanelAdapterContractTest.php`;
- `tests/Unit/Modules/Panels/PanelProviderSourceContractGatewayTest.php`;
- `tests/Unit/Modules/Panels/RemoteServiceSnapshotTest.php`;
- existing mutation-contract mapper tests and complete regression suite.

New/updated proof includes:

- matching create-equivalence adoption;
- observable-only snapshot => Manual Review/no create;
- mismatch => conflict/no recreate;
- discovery after uncertainty and one create effect only;
- successful-create snapshot verification through provider equivalence;
- Marzban and PasarGuard pinned fixtures flow through the common resolver;
- read `canonicalHash` and create-equivalence evidence are separate;
- real provider mutation remains disabled with no mutation HTTP.

## Deferred live acceptance

This boundary leaves the following intentionally unverified until owner-supplied controlled test panels exist:

- protected live authentication;
- exact live provider version and health/capability checks;
- live authoritative lookup and target discovery;
- disposable create/adopt/mismatch paths;
- update/reset/suspend/activate/delete/rotate/delivery effects;
- uncertain-result fault injection and reconciliation;
- cleanup and Target activation acceptance.

Credentials must be supplied only through protected runtime/secret configuration. No credential belongs in repository files, evidence, Issues, PR comments, or chat handoffs.