# Phase 0.4 Target Capacity Accounting Traceability

Authoritative contract: `docs/specification/master-execution-prompt.md`  
Active issue: #7  
Bounded sub-increment: `4a — Target Capacity Accounting`  
Status: Implementation verified; evidence-head CI pending

| Requirement | Verified behavior | Code | Tests | Evidence |
|---|---|---|---|---|
| `CAT-008` | Every Service Target may have one explicit hard capacity; availability is derived from hard, held and committed units; holds cannot oversubscribe under competing MariaDB connections | `TargetCapacityService`, `TargetCapacityAllocator`, capacity migration and Triggers | Domain validation, lifecycle, direct-SQL and two-connection concurrency tests | `evidence/0.4.0/target-capacity-accounting.md` |
| `ACL-002` | Administrative capacity configuration requires current `panels.manage` authority before execution and revalidation inside the mutation transaction | `TargetCapacityService`, existing `PanelMutationExecutor` | unauthorized administrator test and full regression | same evidence |
| `SEC-002` | Command keys are payload-bound; exact replay returns the original event snapshot; conflicting reuse fails closed; rows are locked and versions are checked | allocator, event snapshots and mutation infrastructure | reserve/commit/release replay and conflict tests | same evidence |
| `DAT-003` | Capacity, reservation and event relations use explicit foreign keys, uniqueness, checks, lookup indexes and append-only guards | `2026_08_06_001800_create_target_capacity_foundation.php` | schema exercised by all capacity tests and full migration suite | same evidence |
| `QUA-001` | Requirement annotations, focused domain/integration/concurrency tests, full regression, retained artifacts and independent evidence-head CI gate | all candidate files | CI run #822 and pending evidence-head CI | same evidence |

## Accepted implementation

- implementation SHA: `72b19d556430b42d1065cb9ca9cdb5cd3125dfdc`
- mandatory CI run ID: `31065079295`
- CI run number: #822
- result: success across Repository preflight, Secret scan, MariaDB and Redis tests, PHP static quality, and Dependency/license policy
- automated suite: 241 tests, 1204 assertions
- test artifact: `test-evidence-31065079295`
- test artifact digest: `sha256:f95eba7c95a7193a42293abbfb368ed773fa0810190ed575104d7345469c248d`

## Data ownership

`panel_target_capacities` is the authoritative local capacity ledger for one `panel_service_targets` row. Its counters have the following meanings:

- `hard_limit`: configured maximum units;
- `held_units`: units reserved by live temporary holds;
- `committed_units`: units consumed by committed reservations;
- available units: `hard_limit - held_units - committed_units` when capacity is enabled, otherwise zero.

The allocator never accepts caller-supplied counters. MariaDB Triggers update counters atomically as reservation state changes.

## Reservation lifecycle

- initial creation is always `held` with version 1;
- `held → committed` moves units from held to committed and rejects expired holds;
- `held → released` and `held → expired` return held units;
- `committed → released` returns committed units;
- every other transition fails closed;
- reservation identity, target, purpose, units and expiry are immutable;
- reservations, events and capacity histories cannot be deleted as ordinary business operations.

## Concurrency and replay boundary

The accepted test suite starts two independent PDO connections against the same enabled one-unit Target capacity. Both attempt a hold after a shared barrier. Exactly one insert succeeds, exactly one fails, and the final held counter remains one. The database Trigger performs a conditional capacity update, so correctness does not depend on an application pre-read.

Each command stores a keyed payload hash and an append-only event snapshot. Exact replay returns the state, version and capacity availability recorded for the original command, even when the reservation has subsequently transitioned. Reuse of a command key with different payload or action fails closed.

## Explicit boundary

This sub-increment verifies capacity accounting and reservation concurrency only. It does **not** complete the combined Issue #7 checkbox for capacity, availability, selection and fallback. The following remain in sub-increment `4b`:

- fixed, customer-selected and system-selected route execution;
- Offering-compatible alternate Target configuration;
- deterministic fallback ordering;
- Persian fallback disclosure;
- atomic route selection plus capacity reservation;
- operational health/capability gating for route selection.

No Order, payment, Quote, provisioning, remote Panel request or Service lifecycle is created here.
