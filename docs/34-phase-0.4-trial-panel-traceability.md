# Phase 0.4 Trial Policy and Panel Adapter Traceability

**Status:** implementation boundary green; evidence-head CI pending.  
**Implementation SHA:** `b146c2c6aa902b4ed252d200121d63e422cd87f2`  
**Implementation CI:** `31135758918` / run `#931` — success  
**Evidence:** `evidence/0.4.0/trial-policy-panel-adapter-foundation.md`

This document maps the bounded offline/fake Trial and Panel Adapter foundation to requirements, design, implementation, tests and evidence. It does not claim real Marzban/PasarGuard compatibility, complete Provisioning/Service orchestration, or Phase `0.4.0` closure.

## Boundary and terminology

- `verified` below means verified by the exact implementation run for the stated offline/database/Fake boundary.
- `foundation` means the requirement has partial reusable implementation but still requires a later real-provider, integration, presentation or owning-phase workflow.
- The exact evidence-head SHA and final CI are recorded externally in Issue `#7`/PR `#6` only after the evidence-head gate succeeds.

## Requirement map

| Requirement | Bounded acceptance statement | Design/implementation | Automated proof | Status and remaining gap |
|---|---|---|---|---|
| `CAT-006` | Trial policy is Offering-scoped and configures allowance, duration, daily capacity, tier/tag/account eligibility, optional phone and membership rules, one-per-user/phone abuse controls, administrator regrant/reset and disclosed compatible fallback. Reservation/commit/release/expire/reset is replay-safe and database guarded. | `TrialPolicyDefinition`, `TrialPolicyService`, `TrialEligibility`, `TrialRouteSelector`, `TrialReservationService`, Trial DTO/context/receipt types, `UnavailableTrialMembershipVerifier`, Trial migration. | `TrialPolicyDomainTest`; `TrialPolicyReservationTest` covers create/replay, commit, one-per-user, reset/regrant, fallback/disclosure, no-fallback failure, membership fail-closed, phone policy, release/daily capacity and authorization. | **verified offline/database foundation**. Complete zero-cost Order/Service creation and Telegram journey remain later owning phases. |
| `CAT-008` | Trial route selection reuses compatible Offering route/capacity rules; primary/fallback capacity is checked atomically, fallback is explicit and disclosed, and absence of compatible capacity fails closed. | `TrialRouteSelector`, existing route selection and Target Capacity services, Trial daily counters/reservations and snapshot fields. | Trial fallback, primary-capacity exhaustion, disabled fallback, no-compatible-route and daily-capacity scenarios in `TrialPolicyReservationTest`; previous accepted `docs/27-28-*` evidence. | **verified extension** for Trial reservation. Production route activation remains adapter-evidence dependent. |
| `PRV-001` | Common adapter contract exposes validated capabilities/operations/snapshots/results; Fake implementation is runnable; Marzban/PasarGuard are registered behind fail-closed unavailable shells; credentials/delivery artifacts are redacted; TLS verification cannot be disabled. | contracts under `Panels/Application/Contracts`; registry/session/credential policy; Fake/delegating/unavailable/Marzban/PasarGuard factories/adapters; Panel provider wiring. | `PanelAdapterContractTest`; capability/request/result/snapshot/credential/delivery tests; complete Fake operation-surface test; unavailable provider tests; system/custom CA validation tests. | **verified common/Fake/fail-closed foundation**. No real version, endpoint, health, target mapping or API compatibility claim. |
| `PRV-002` | Coordinated create performs authoritative deterministic-username lookup immediately before create, adopts exact match, rejects mismatch, and produces at most one Fake remote identity under replay/uncertainty. | `PanelCreateCoordinator`, `RemoteIdentityResolver`, `PanelServiceCanonicalizer`, `RemoteIdentityResolution`, Fake operation journal. | exact adoption/no-create, mismatch/no-create, unavailable lookup/no-create, successful create snapshot validation, idempotency conflict and Fake service-count assertions. | **verified orchestration foundation**. Complete durable Provisioning Operation/Attempt/Service identity ownership belongs to Phase `0.6.0`. |
| `PRV-003` | An uncertain create result is followed by authoritative discovery; exact discovered identity is adopted, mismatch is conflict, unresolved absence remains uncertain/manual review, and no immediate second create occurs. | `PanelCreateCoordinator::createOrAdopt`, resolver and typed operation outcomes. | mock expectations prove exactly one create and two lookups; Fake timeout-after-create proves one remote service; unresolved/mismatch tests prove no second create. | **verified offline/Fake uncertainty contract**. Real provider timeout/error semantics require exact installed-version contract tests. |
| `ACL-002` | Every Trial policy/admin lifecycle mutation checks server-side permission at execution time; hidden presentation is not authorization. | `TrialPolicyService`, `TrialReservationService`, existing Catalog/AccessControl foundations. | unauthorized policy creation; authorized reset/regrant; transaction-time administrator/permission checks exercised by feature suite and earlier Phase 0.3/0.4 regression. | **verified for bounded Trial administrative actions**. Telegram admin presentation remains unimplemented. |
| `SEC-001` | Sensitive values are redacted; provider messages/identifiers are bounded; Fake/unavailable adapters fail closed; no secrets enter evidence; dependency/secret/static gates pass. | `PanelCredentials`, `SensitiveDeliveryArtifacts`, `PanelOperationResult`, `PanelCredentialPolicy`, unavailable gateways, CI controls. | credential/delivery/result tests; Gitleaks; forbidden patterns; PHPStan/Pint; inspected retained test artifact. | **verified for stated controls**. Independent full security review, penetration testing and real-provider review remain release gates. |
| `SEC-002` | Remote create and Trial reservation are replay/conflict safe; unknown provider outcomes do not cause duplicate effects; TLS verification stays enabled; authoritative lookup absence blocks create. | coordinator/resolver/Fake journal; Trial command/event keys and hashes; DB uniqueness/checks/triggers; TLS domain policy. | replay/conflict tests, one-service counts, uncertain discovery, Trial command replay/conflict, MariaDB direct-mutation and lifecycle guards. | **verified bounded security/idempotency foundation**. No financial effect or complete durable provisioning orchestration is claimed. |
| `DAT-001` | Trial operational timestamps and capacity dates use explicit UTC/application time snapshots; stored evidence is immutable/versioned. | Trial contexts/services/migration timestamp columns and UTC capacity-date handling. | feature tests with explicit `DateTimeImmutable`/UTC expectations and complete regression suite. | **verified within Trial foundation**. Jalali/display behavior is outside this increment. |
| `DAT-003` | Trial schema uses relational foreign keys, uniqueness, checks, indexes and triggers for integrity, history, capacity, lifecycle and abuse rules. | `2026_08_06_002200_create_trial_policy_foundation.php`. | MariaDB migration/feature suite, direct immutable-field update rejection, active-user/phone/counter/state guards and full migration regression. | **verified on MariaDB** for the new schema. Future schema correction must use additive/corrective migrations after acceptance. |
| `QUA-001` | The bounded implementation has exact-SHA mandatory CI, executable counts, retained artifacts, independent test-artifact digest and reviewed evidence/traceability. | mandatory CI and project-control contract; this document and evidence file. | implementation run `31135758918` / `#931`; 298 tests, 1471 assertions; all mandatory jobs success; artifact `8977870831`; independent digest match. | **implementation verified; evidence-head pending**. This row becomes evidence-complete only after mandatory CI on the exact evidence head. |

## Trial schema traceability

| Table | Ownership and purpose | Principal integrity controls |
|---|---|---|
| `trial_policies` | current Offering-scoped policy | unique Offering, value/state/hash/version checks, draft/trial-enabled Offering trigger |
| `trial_policy_tiers` | allowed tier snapshot inputs | FK, unique policy/tier, enum check, policy-child guards |
| `trial_policy_tags` | allowed tag snapshot inputs | FK to policy/tag, unique assignment, policy-child guards |
| `trial_policy_histories` | append-only policy version evidence | FK, unique policy/version, immutable trigger, actor/reason/correlation |
| `trial_daily_capacity_counters` | UTC daily hard-limit accounting | unique policy/date, reserved+committed hard-limit check, version/transition guards |
| `trial_reservations` | immutable policy/eligibility/route/capacity snapshot and lifecycle | command unique/hash, FKs, active user/phone unique keys, state/time/reset/fallback checks, immutable/transition triggers |
| `trial_reservation_events` | append-only lifecycle/replay evidence | unique command and reservation/version, action/state/hash checks, immutable guard |

## Panel operation traceability

| Operation/control | Contract/implementation | Focused proof |
|---|---|---|
| capability discovery | `PanelCapabilities`, `PanelAdapter::capabilities` | valid/canonical operation set and Fake declared surface |
| connection test | common adapter plus unavailable gateways | Fake success; real shells fail closed |
| authoritative lookup | `findByDeterministicUsername`, resolver | exact match/adoption, absent, unavailable and mismatch scenarios |
| create | request, coordinator, canonicalizer, adapter | authoritative pre-lookup, validated success snapshot, one create under uncertainty |
| fetch/sync | adapter contract/Fake | complete operation-surface test |
| expiry/data/usage/status mutations | adapter contract/Fake operation journal | exact replay and conflicting-key mutation tests |
| delete/link rotation/delivery | adapter contract/Fake/sensitive artifact type | deterministic results and redacted delivery projection |
| TLS/credentials | `TlsConfiguration`, `TlsPolicy`, `PanelCredentialPolicy`, `PanelCredentials` | system CA/default, custom material validation, disabled verification prohibited, redaction |
| Marzban/PasarGuard | adapter/factory/gateway contracts | registry wiring and unavailable fail-closed behavior only |

## Replay and uncertainty matrix

| Scenario | Required result | Test evidence |
|---|---|---|
| Trial command exact replay | original receipt/result; no second state effect | policy/reserve/commit/reset replay scenarios |
| Trial same key, changed payload | conflict; original evidence retained | command fingerprint/conflict suite |
| existing remote exact match | adopt; zero create calls | mocked exact-match test |
| existing remote mismatch | conflict/manual review; zero create calls | mismatch tests |
| authoritative lookup unavailable | uncertain/manual review; zero create calls | unavailable lookup test |
| create succeeds with matching snapshot | success after canonical validation | successful create path |
| create succeeds without snapshot | uncertain | missing-snapshot test |
| create returns uncertain, exact identity later found | adopt after one create | mocked two-lookup/one-create and Fake timeout test |
| create returns uncertain, mismatch later found | conflict after one create | mismatch-after-uncertainty test |
| create returns uncertain, still absent | unresolved/manual review after one create | unresolved uncertainty test |
| operation key exact replay | stored original result | Fake operation journal tests |
| operation key conflicting reuse | conflict; original result/effect unchanged | create and mutation idempotency conflict tests |

## Evidence references

Implementation:

- SHA `b146c2c6aa902b4ed252d200121d63e422cd87f2`;
- CI `31135758918` / run `#931`;
- 298 tests, 1471 assertions;
- test artifact `test-evidence-31135758918`, ID `8977870831`;
- independent SHA-256 `97212a1a18ee3d444dd8a6a342981a6bae8bbdd4d013c3a204f6d26eaca3e95d`.

Detailed implementation evidence:

- `evidence/0.4.0/trial-policy-panel-adapter-foundation.md`.

Prior dependent accepted evidence:

- `evidence/0.4.0/panel-connection-foundation.md`;
- `evidence/0.4.0/panel-target-protocol-server-foundation.md`;
- `evidence/0.4.0/plan-offering-foundation.md`;
- `evidence/0.4.0/target-capacity-accounting.md`;
- `evidence/0.4.0/route-selection-fallback.md`;
- `evidence/0.4.0/custom-plan-policy-calculation.md`.

## Explicit exclusions and remaining work

- Real Marzban/PasarGuard provider/version/OpenAPI behavior is untested and unsupported by this evidence.
- Real adapters remain unavailable/fail-closed and targets must not be marked operational from this boundary.
- Complete Panel health polling/circuit breaker, provider rate-limit behavior and production activation remain.
- Trial does not create a Quote, paid Payment Intent, fake payment, complete Order, Provisioning Operation or Service Subscription.
- Durable provisioning attempts, reconciliation cases, service remote identity uniqueness and delivery attempts remain Phase `0.6.0`.
- Complete Persian customer/admin Trial UX remains Phase `0.7.0`.
- Phase `0.4.0` remains open for real adapter contract/activation evidence, complete traceability reconciliation and closure audit.
- `TrialReservationService` should be decomposed only after this behavior/evidence boundary is accepted, preserving schema, lock order, transactions and tests.

## Evidence-head gate

The evidence head containing this traceability and its evidence report must pass all mandatory jobs on its exact SHA. Until then, the implementation boundary is green but the increment is not evidence-complete and Issue `#7`/PR `#6` must not be updated as complete.
