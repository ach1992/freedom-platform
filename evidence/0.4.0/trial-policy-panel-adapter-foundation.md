# Phase 0.4 Trial Policy and Panel Adapter Foundation

Status: **Implementation Green; evidence-head verification pending**

## Accepted implementation boundary

This bounded increment implements the offline Trial Policy and common Panel Adapter foundation for `CAT-006`, the applicable capacity/fallback extension of `CAT-008`, and the fake/fail-closed foundations of `PRV-001`–`PRV-003`. It does not enter Phase `0.5.0` pricing/payment or Phase `0.6.0` Order/provisioning/service lifecycle.

Implementation SHA:

- `b146c2c6aa902b4ed252d200121d63e422cd87f2`

Mandatory standard CI:

- workflow: `CI`
- run ID: `31135758918`
- run number: `931`
- conclusion: `success`

Automated suite result extracted from the executable PHPUnit log and retained JUnit:

- **298 tests passed**
- **1471 assertions**
- failures: `0`
- errors: `0`
- skipped: `0`
- PHPUnit: `12.5.33`
- PHP: `8.4.23`
- PCOV: `1.0.12`
- test duration: approximately `9.086s`

## Trial Policy controls

### Policy and eligibility

- one versioned Trial Policy per trial-enabled draft Plan Offering;
- explicit data allowance, duration, daily capacity, phone-verification, membership, one-per-user, one-per-phone, administrator-regrant, fallback, tier/tag, delivery-template and configuration-hash policy;
- authoritative customer account, tier, tag, phone and membership evaluation inside the application transaction boundary;
- membership verification fails closed when required evidence is unavailable;
- phone policies distinguish Telegram contact, SMS OTP, either, both and no requirement;
- policy mutation uses existing Catalog administration authorization and transaction-time revalidation;
- policy history is append-only and records actor, reason, correlation, version and configuration hash without sensitive values.

### Capacity and route selection

- one UTC business-day counter per Trial Policy;
- hard-limit snapshot with reserved, committed, released and expired counts;
- reservation acquisition integrates with the previously verified real Target Capacity hold and compatible route-selection/fallback foundation;
- only configured compatible routes may be selected;
- fallback remains disabled unless explicitly enabled and is snapshotted with the Persian disclosure;
- no fallback or no compatible capacity fails closed without creating a Trial reservation;
- counter constraints prevent reserved plus committed usage from exceeding the daily hard limit.

### Reservation lifecycle and abuse controls

- guarded states: `reserved`, `committed`, `released`, `expired`;
- exact command replay returns the original receipt;
- conflicting command-key payload reuse fails closed;
- one-active-user and one-active-phone uniqueness is enforced by database keys when the corresponding policy is enabled;
- commit, release, expiration and eligibility reset enforce expected version and allowed transition;
- administrator reset/regrant requires current authorization, policy permission and append-only event evidence;
- reservation snapshots preserve policy version/hash, allowance, duration, capacity, eligibility, fallback, disclosure and delivery-template inputs;
- direct mutation of immutable reservation/evidence fields is rejected by MariaDB guards.

## Panel Adapter controls

### Common contract

- typed panel capabilities, create request, operation outcome/result, service status, remote snapshot and sensitive delivery artifacts;
- explicit operations for connection test, authoritative username lookup, create, status, expiry, allowance, usage reset, suspend/activate/delete, link rotation, delivery, synchronize and target discovery;
- provider codes/messages and identifiers are validated and bounded before entering normal application evidence;
- capabilities and target references are canonicalized and validated.

### Remote identity and uncertain-result handling

- authoritative deterministic-username lookup occurs immediately before every coordinated create;
- an exact remote username and canonical create-hash match is adopted without a create call;
- username or expected-attribute mismatch becomes a definitive conflict/manual-review result;
- unavailable authoritative lookup blocks creation and returns an uncertain/manual-review result;
- a create timeout/uncertain result triggers a second authoritative discovery pass;
- no uncertain path performs an immediate second create;
- a provider success without an authoritative snapshot is treated as uncertain;
- a success snapshot that does not match the requested canonical identity is treated as conflict.

### Fake remote idempotency

- `FakePanelAdapter` creates deterministic remote IDs and records one operation result per idempotency key;
- exact replay returns the original stored result;
- reuse of the same key for another operation or payload returns `fake_idempotency_conflict` and does not overwrite the original result/effect;
- mutation operations use operation-specific fingerprints;
- timeout-after-create simulation verifies discovery/adoption with exactly one remote service;
- the complete declared Fake operation surface is exercised deterministically.

### Credentials, TLS and fail-closed real-provider shells

- Panel credentials redact JSON, debug and string projections;
- sensitive delivery links/QR sources validate input and redact ordinary projections;
- system CA is the default TLS mode;
- TLS verification cannot be disabled;
- custom CA/pinning is accepted only through explicit compatible configuration;
- Marzban and PasarGuard factories/adapters are present behind the common contract, but unavailable gateway factories fail closed for connection, mutation and delivery behavior;
- no real credential, endpoint, OpenAPI contract or provider response is included in this evidence.

## Database controls

Migration:

- `database/migrations/2026_08_06_002200_create_trial_policy_foundation.php`

Tables:

- `trial_policies`;
- `trial_policy_tiers`;
- `trial_policy_tags`;
- `trial_policy_histories`;
- `trial_daily_capacity_counters`;
- `trial_reservations`;
- `trial_reservation_events`.

MariaDB enforcement includes:

- foreign keys to Offering, users, phones, administrators, tags, route selections and capacity counters;
- unique policy-per-Offering, daily-counter, command-key, active-user, active-phone and event-version constraints;
- checks for policy values, states, hashes, versions, fallback/disclosure consistency, timestamp/state consistency and eligibility reset evidence;
- triggers guarding draft/trial-enabled Offering policy mutation, child/history consistency, capacity transitions, reservation transitions, immutable fields and Offering activation dependencies.

## Authorization, privacy and audit

- Trial policy and administrative lifecycle operations re-check current server-side authorization;
- an unauthorized administrator cannot create or mutate policy/evidence;
- mutation/effect receipts contain structural IDs, versions, hashes, reason/correlation and safe result codes only;
- no credential, secret, OTP, full identity value, subscription URL, raw provider response or encrypted payload is written to normal logs/evidence;
- Git history secret scan passed on the implementation SHA.

## Code and test map

### Trial domain/application

- `app/Modules/Catalog/Domain/TrialPolicyDefinition.php`
- `app/Modules/Catalog/Domain/TrialReservationState.php`
- `app/Modules/Catalog/Application/TrialActorSnapshot.php`
- `app/Modules/Catalog/Application/TrialContext.php`
- `app/Modules/Catalog/Application/TrialEligibility.php`
- `app/Modules/Catalog/Application/TrialMembershipVerifier.php`
- `app/Modules/Catalog/Application/TrialPolicyService.php`
- `app/Modules/Catalog/Application/TrialReservationRequest.php`
- `app/Modules/Catalog/Application/TrialReservationReceipt.php`
- `app/Modules/Catalog/Application/TrialReservationService.php`
- `app/Modules/Catalog/Application/TrialRouteSelector.php`
- `app/Modules/Catalog/Infrastructure/UnavailableTrialMembershipVerifier.php`
- `app/Modules/Catalog/Infrastructure/CatalogServiceProvider.php`

### Panel contracts/application/infrastructure

- `app/Modules/Panels/Application/Contracts/`
- `app/Modules/Panels/Application/PanelAdapterRegistry.php`
- `app/Modules/Panels/Application/PanelAdapterSession.php`
- `app/Modules/Panels/Application/PanelCreateCoordinator.php`
- `app/Modules/Panels/Application/PanelCredentialPolicy.php`
- `app/Modules/Panels/Application/PanelServiceCanonicalizer.php`
- `app/Modules/Panels/Application/RemoteIdentityResolution.php`
- `app/Modules/Panels/Application/RemoteIdentityResolver.php`
- `app/Modules/Panels/Infrastructure/FakePanelAdapter.php`
- `app/Modules/Panels/Infrastructure/FakePanelAdapterFactory.php`
- `app/Modules/Panels/Infrastructure/DelegatingPanelAdapter.php`
- `app/Modules/Panels/Infrastructure/UnavailablePanelGateway.php`
- `app/Modules/Panels/Infrastructure/UnavailableMarzbanGatewayFactory.php`
- `app/Modules/Panels/Infrastructure/UnavailablePasarGuardGatewayFactory.php`
- `app/Modules/Panels/Infrastructure/MarzbanAdapter.php`
- `app/Modules/Panels/Infrastructure/MarzbanAdapterFactory.php`
- `app/Modules/Panels/Infrastructure/PasarGuardAdapter.php`
- `app/Modules/Panels/Infrastructure/PasarGuardAdapterFactory.php`
- `app/Modules/Panels/Infrastructure/PanelsServiceProvider.php`

### Focused tests

- `tests/Feature/TrialPolicyReservationTest.php`
- `tests/Unit/Modules/Catalog/TrialPolicyDomainTest.php`
- `tests/Unit/Modules/Panels/PanelAdapterContractTest.php`
- `tests/Unit/Modules/Panels/FakePanelMutationIdempotencyTest.php`
- `tests/Unit/Modules/Panels/PanelCapabilitiesTest.php`
- `tests/Unit/Modules/Panels/PanelCreateServiceRequestTest.php`
- `tests/Unit/Modules/Panels/PanelCredentialsRedactionTest.php`
- `tests/Unit/Modules/Panels/PanelOperationResultTest.php`
- `tests/Unit/Modules/Panels/RemoteServiceSnapshotTest.php`
- `tests/Unit/Modules/Panels/SensitiveDeliveryArtifactsTest.php`
- complete pre-existing regression suite.

## Mandatory job results

| Job | Result |
|---|---|
| Repository preflight and project-control verification | success |
| Secret scan | success |
| Pint formatting | success |
| PHPStan/Larastan | success |
| Forbidden-pattern policy | success |
| Architecture policy | success |
| Composer strict validation | success |
| Dependency advisory/abandoned-package audit | success |
| License policy | success |
| MariaDB/authenticated Redis integration suite | success |
| JUnit and Clover artifact checks | success |

## Retained artifacts

| Artifact | Artifact ID | GitHub digest |
|---|---:|---|
| `preflight-evidence-31135758918` | `8977857236` | `sha256:7d5b3c9562177549277bc33dd2d7ff9c8f1a8c053f4339324a7372d38f975378` |
| `gitleaks-results.sarif` | `8977853594` | `sha256:d063557f53f0749d97ba807a14a91c1fa92dc5157177f2233f39101dff1a38d5` |
| `static-evidence-31135758918` | `8977886208` | `sha256:d256ccc3ade8522f3e699b1d057df0472d89ec4a5195bafb98fd8ff5cc10c2dc` |
| `dependency-evidence-31135758918` | `8977875540` | `sha256:fdb88cb1e141d18a9a4cd7c8c6fba728cff8726046ed7cfeb62e5efaa943a32d` |
| `test-evidence-31135758918` | `8977870831` | `sha256:97212a1a18ee3d444dd8a6a342981a6bae8bbdd4d013c3a204f6d26eaca3e95d` |

The test artifact was independently downloaded and hashed. Its SHA-256 matched GitHub's digest:

- `sha256:97212a1a18ee3d444dd8a6a342981a6bae8bbdd4d013c3a204f6d26eaca3e95d`

Artifact inspection found:

- `tests/junit.xml` — `143113` bytes;
- `tests/test.log` — `763` bytes;
- `coverage/clover.xml` — `1010868` bytes;
- `services/compose-ps.txt` — `448` bytes;
- `services/compose.log` — `8084` bytes;
- no obvious secret/token/private-key pattern in the inspected test artifact;
- Clover project metrics: 343 files, 280 classes, 1038 methods with 524 covered, and 13889 statements with 11346 covered (approximately 81.69% statement coverage).

Coverage percentage is diagnostic only. Acceptance is based on mapped invariant scenarios and the complete mandatory suite, not a raw percentage.

## Scope intentionally excluded

This evidence does **not** claim:

- real Marzban or PasarGuard connectivity, exact supported version, authentication, target discovery, API schema, rate limit or error semantics;
- a production-capable Marzban/PasarGuard create/update/delete implementation;
- real remote side effects outside the deterministic Fake adapter;
- Panel target production activation or verified capabilities;
- complete Provisioning aggregate, queue orchestration, reconciliation case lifecycle, Service Subscription, Order or Order Item;
- Quote, customer/agent resolved pricing, discount, ledger, Payment Intent, payment capture or refund behavior;
- complete Telegram Trial/Admin UX or localized delivery execution;
- staging or production deployment acceptance.

Trial is modelled as a zero-cost/non-paid source foundation. A complete zero-cost Order and Service creation workflow remains in the owning later phases; this increment creates no fake successful payment.

## Maintainability follow-up

`TrialReservationService` is a large correctness-sensitive candidate. No broad behavior-changing refactor was included in this implementation boundary. After this evidence head is accepted, bounded extraction should separate replay journal, actor/policy snapshot, route/capacity transaction, lifecycle transition, history/event writing and receipt hydration while preserving schema, lock order, transaction boundaries and tests.

## Evidence-head gate

The commit containing this evidence and `docs/34-phase-0.4-trial-panel-traceability.md` must pass the same complete mandatory CI workflow. Only after that exact evidence-head run succeeds may Issue `#7` and Draft PR `#6` be updated with the final evidence-head SHA, run, test/assertion count and final artifact digest.
