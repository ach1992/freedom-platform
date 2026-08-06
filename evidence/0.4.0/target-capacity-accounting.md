# Phase 0.4 Target Capacity Accounting

Status: **Implementation verified — evidence-head CI pending**

Bounded sub-increment: `4a — Target Capacity Accounting` under Issue #7.

## Accepted implementation

- SHA: `72b19d556430b42d1065cb9ca9cdb5cd3125dfdc`
- CI run ID: `31065079295`
- CI run number: #822
- result: success across Repository preflight, Secret scan, MariaDB and Redis tests, PHP static quality, and Dependency/license policy
- automated suite: 241 tests, 1204 assertions
- test artifact: `test-evidence-31065079295`
- test artifact digest: `sha256:f95eba7c95a7193a42293abbfb368ed773fa0810190ed575104d7345469c248d`

## Verified boundary

- one explicit capacity record per Service Target;
- positive unsigned hard limit and non-negative held/committed counters;
- availability derived from authoritative counters rather than cached caller input;
- enabled/disabled capacity lifecycle with optimistic version checks;
- idempotent reservation lifecycle: held, committed, released and expired;
- atomic MariaDB counter changes for every reservation transition;
- hard-limit enforcement under two simultaneous independent database connections;
- exact replay receipts backed by append-only command event snapshots;
- payload-conflict rejection for reused command keys;
- `panels.manage` authorization before administrative capacity mutation and revalidation inside the transaction;
- FK-backed capacity history and reservation events;
- immutable reservation identity and append-only histories/events.

## Schema and consistency controls

The migration creates:

- `panel_target_capacities`;
- `panel_capacity_reservations`;
- `panel_capacity_reservation_events`;
- `panel_target_capacity_histories`.

MariaDB checks and Triggers enforce:

- valid capacity and reservation states;
- positive hard limits and reservation units;
- `held_units + committed_units <= hard_limit`;
- initial reservation creation only as a version-1 hold;
- conditional atomic counter acquisition before insert succeeds;
- allowed transitions only;
- expired holds cannot be committed;
- premature expiry is rejected;
- counter underflow or inconsistent transitions fail closed;
- reservation identity fields are immutable;
- reservations, events and histories cannot be deleted through normal row operations;
- event/history rows cannot be updated.

Rollback uses literal Trigger names. No dynamic SQL is assembled from runtime or database values.

## Replay semantics

Every reserve/commit/release/expire command has a globally unique command key and keyed payload hash. The resulting append-only event stores:

- action and from/to state;
- reservation units and version;
- capacity hard, held and committed units;
- capacity state and version;
- correlation, source and reason codes.

An exact replay returns this original snapshot. A later reservation transition does not rewrite the earlier receipt. A command-key reuse with a different payload or action is rejected.

## Concurrency verification

The focused Feature test creates an enabled one-unit capacity, disconnects the framework connection, forks two processes, opens two independent PDO connections and releases them through a shared barrier. Both attempt an initial one-unit hold.

Verified outcome:

- one child succeeds;
- one child receives database rejection;
- one reservation row exists;
- `held_units` equals one;
- hard capacity is never exceeded.

The same suite also verifies direct sequential competing inserts, full reserve/commit/release behavior, original snapshot replay, append-only event enforcement and unauthorized administrative rejection.

## Failure history and remediation

- run #818: the concurrency fixture used `RefreshDatabase`; forked independent connections could not see the parent transaction's uncommitted Target/capacity rows. The production Trigger correctly rejected both inserts. The test isolation model was changed so concurrency fixtures are committed.
- run #820: `DatabaseMigrations` attempted rollback through an older migration whose historic Trigger assumptions no longer matched the current schema. Capacity behavior was not the failure. The test moved away from migration rollback isolation.
- run #821: `DatabaseTruncation` made the committed concurrency test pass, but the final seeded administrator user remained visible to later exact-count Telegram tests. Explicit post-test table truncation was added before application teardown.
- run #822: Capacity tests, following Telegram tests and the complete regression suite all passed.

No authorization check, hard-capacity condition, Trigger, version check, replay control, append-only rule or concurrency assertion was removed or weakened during remediation.

## Retained artifacts

- preflight: `preflight-evidence-31065079295`, digest `sha256:0bb89575cada3714fe4f17f4a43c0e43fb96891b99cc68bf0bdcf3681f917c5e`
- secret scan: `gitleaks-results.sarif`, digest `sha256:3c81220f35512abef0d1e1d69ea369e0f506fbfaad237deb0165b4723b61fb31`
- static: `static-evidence-31065079295`, digest `sha256:f8aabb6f3a5fbc44cc6ced9f1d4feff40dd148e3e141b52584e5d94d8aaf4825`
- dependency: `dependency-evidence-31065079295`, digest `sha256:f09cff7797afb1fee1478051a9fb8a8cbfbafbafa4cb656a07a963827c06eefe`
- tests: `test-evidence-31065079295`, digest `sha256:f95eba7c95a7193a42293abbfb368ed773fa0810190ed575104d7345469c248d`

## Intentionally excluded

- Offering route configuration and alternate Targets;
- fixed, customer-selected or system-selected route execution;
- compatible fallback ordering and Persian disclosure;
- health/capability-based recovery of sales;
- Order, Quote, payment, provisioning or remote Panel effects;
- custom-plan and trial policy.

The combined capacity/availability/selection/fallback checkbox in Issue #7 remains open until sub-increment `4b` is independently implemented and verified.

## Evidence-head gate

The commit containing this evidence must pass the same complete CI workflow before Issue #7 or PR #6 are updated to call sub-increment `4a` verified.
