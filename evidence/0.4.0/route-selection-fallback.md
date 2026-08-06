# Phase 0.4 Availability, Route Selection and Disclosed Fallback

Status: **Implementation verified — evidence-head CI pending**

Bounded sub-increment: `4b — Availability, Route Selection and Disclosed Compatible Fallback` under Issue #7.

## Accepted implementation

- SHA: `ca88704d34f7df68799d8673e30c3ff479529206`
- CI run ID: `31067121678`
- CI run number: #826
- result: success across Repository preflight, Secret scan, MariaDB and Redis tests, PHP static quality, and Dependency/license policy
- automated suite: 247 tests, 1228 assertions
- test artifact: `test-evidence-31067121678`
- test artifact digest: `sha256:90c32234ab6452cd0847513c820bc25094041d0e19653623b1189f6ea963af9d`

## Verified policy boundary

- exactly one primary route at priority zero;
- fallback routes have deterministic contiguous priorities;
- duplicate Target routes are rejected;
- primary Server/Target must match the Offering;
- fallback requires Persian disclosure and optional English fallback;
- customer-selectability matches customer/system/hybrid selection policy;
- every route Target supports all Offering Protocol Profiles;
- every route Target declares every required Offering and operation capability;
- Route Policy is mutable only while Offering is Draft;
- configuration hashes and actor/reason/correlation history are retained;
- active Offering dependencies cannot be silently archived.

## Verified selection boundary

- actor account, customer profile, tier and active tags are read authoritatively from MariaDB;
- agent route selection requires an active agent profile;
- audience, tier and any/all tag eligibility are enforced;
- requested/default Protocol Profile follows Offering policy;
- system-selected Offering rejects caller-selected route;
- customer/hybrid selections require membership in the configured selectable routes;
- candidates are attempted in deterministic policy order;
- unavailable or capacity-exhausted primary may fall back only to configured compatible routes;
- Persian fallback disclosure is snapshotted on the immutable selection;
- Target capacity hold and route-selection snapshot commit atomically;
- exact replay returns the prior selection and prior capacity availability/version snapshot;
- command-key payload conflict fails closed;
- selection rows cannot be updated or deleted.

## Operational verifier

Production dependency injection binds `DatabaseRouteOperationalVerifier`. It requires an active visible Offering, active listed Sales Server, active verified Service Target, active successful Panel Connection whose current version matches Target verification evidence, active assigned Protocol Profile and verified required capabilities.

Current Targets deliberately remain disabled with declared capability evidence until provider adapters and capability discovery are complete. The Production verifier therefore rejects operational selection now. The focused selection test binds a test-only verifier double to validate route ordering and real MariaDB capacity behavior without inventing Production health. The real `TargetCapacityAllocator`, reservation Triggers and transactional snapshot are used unchanged.

## Atomic fallback verification

The accepted Feature suite creates:

- a Hybrid Offering with one configured primary and one configured fallback route;
- compatible Protocol Profile and declared capability mappings on both Targets;
- an enabled one-unit primary capacity and two-unit fallback capacity;
- an authoritative eligible customer tier/tag profile.

The primary capacity is filled before selection. The selector attempts the requested primary, receives the fail-closed capacity rejection, then reserves one unit on the configured fallback. The resulting selection stores fallback route, Target, Protocol Profile, Persian disclosure and capacity snapshot. Repeating the exact command returns the same selection; changing units under the same command key is rejected.

## Failure history and remediation

- run #824: MariaDB rejected the automatically generated history actor FK name because it exceeded the 64-character identifier limit; Pint also reported five mechanical style changes. The FK was renamed explicitly to `route_history_actor_fk`, and the exact Pint diff was applied without behavior changes.
- run #825: all MariaDB/Redis tests passed, while PHPStan reported two unnecessary nullsafe expressions in safe-state construction and one unnecessary `array_values()` after `usort()`. The snapshot builder now normalizes the nullable route list once, and the already-list route array is assigned directly.
- run #826: all five mandatory jobs passed with 247 tests and 1228 assertions.

No operational gate, authorization check, eligibility condition, compatibility requirement, fallback ordering rule, capacity reservation, replay control, immutable snapshot or Production fail-closed binding was removed or weakened.

## Retained implementation artifacts

- preflight: `preflight-evidence-31067121678`, digest `sha256:3e9ef487d79d03a948e8287e476cdfde4bed31ac537d5c26a4e50d1030adc6a6`
- secret scan: `gitleaks-results.sarif`, digest `sha256:ff17a175fa193cee41f2b0ce4e8d0b5cd3f11d3d413bbd331b64984a8c237418`
- static: `static-evidence-31067121678`, digest `sha256:aed41b5f69b7200139721ff3d9712988c7e98afdec90185ade9ba8acc92eadd1`
- dependency: `dependency-evidence-31067121678`, digest `sha256:3de1fa0806bd84fbcc9bb900b68fbe9b88d9bf9564c0017f439e71cc4ad753c1`
- tests: `test-evidence-31067121678`, digest `sha256:90c32234ab6452cd0847513c820bc25094041d0e19653623b1189f6ea963af9d`

## Intentionally excluded

- Quote, Order, payment, ledger and resolved pricing;
- provisioning orchestration or remote Panel effects;
- adapter health/capability discovery and Target activation;
- custom-plan range/step calculation snapshots;
- trial eligibility, abuse limits and trial order source.

## Evidence-head gate

The commit containing this evidence must pass the same complete CI workflow before the combined Issue #7 capacity/availability/selection/fallback checkbox is marked complete.
