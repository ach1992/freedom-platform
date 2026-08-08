# Increment Lifecycle

This is the mandatory lifecycle for every bounded implementation increment. Read it with `AGENTS.md` and `docs/development/multi-agent-orchestration.md`.

## 1. Select a bounded increment

An increment must have:

- one owning phase and authoritative phase Issue;
- one bounded Task Contract Issue/comment when executed by a Worker;
- explicit requirement IDs;
- included behavior and excluded later-phase behavior;
- acceptance scenarios;
- affected security, financial, data, authorization and remote-effect invariants;
- expected migrations, services, tests, evidence and traceability;
- known owner/provider inputs and a fake/fail-closed path when unavailable;
- dependencies, expected modification surface and conflict classification.

If the scope cannot be verified independently or conflicts with another active Worker on a critical surface, reduce/serialize it before implementation.

## 2. Record the starting boundary

Before implementation:

- fetch Draft PR `#6` and its exact live `develop/v1.0.0-completion` head;
- confirm mandatory integration CI state on that head;
- identify the last independently verified boundary;
- inspect existing Issues/evidence/handoffs so completed work is not duplicated;
- record open risks and dependencies;
- confirm the increment stays within an authorized phase boundary;
- for a Worker Task Contract, record Worker ID, Contract Revision, `BASE_SHA`, `agent/*` branch, target `develop/v1.0.0-completion`, allowed/protected scope and merge prerequisites.

Do not build from an uninvestigated red integration head. A Worker does not silently rebase after dispatch; the MASTER revises the Task Contract if a new base is required.

## 3. Design before mutation

Document or confirm:

- aggregate/table ownership;
- state transitions and actors;
- command/idempotency key scope;
- transaction and lock order;
- uniqueness/check constraints;
- external result taxonomy: success, definitive failure, retryable failure, uncertain result, conflict/manual review;
- sensitive fields and redaction rules;
- application contracts and adapter boundaries;
- migration compatibility and rollback/restore impact;
- overlap with active/recent Worker changes.

Target-state design must be labelled separately from implemented behavior.

## 4. Implement the smallest complete behavior

A production increment normally includes:

- domain types and validation;
- application service/command boundary;
- infrastructure implementation behind contracts;
- migrations/indexes/constraints where required;
- authorization at display/execution boundaries where applicable;
- append-only history/audit where required;
- localization keys for user-facing behavior;
- deterministic fakes for unavailable external systems;
- fail-closed shells for unsupported real providers;
- automated tests.

Avoid broad refactors unrelated to the Task Contract. A Worker modifies protected/high-conflict scope only when its current Contract Revision explicitly permits it.

## 5. Required test dimensions

Select all applicable dimensions:

- valid lifecycle;
- input/domain validation;
- authorization and explicit deny;
- exact replay;
- conflicting replay/idempotency-key reuse;
- optimistic-version conflict;
- database concurrency and unique constraints;
- failure before side effect;
- uncertain result after possible side effect;
- authoritative lookup/adoption;
- mismatch/manual review;
- redaction and serialization;
- migration forward compatibility;
- MariaDB-specific constraints/triggers;
- Redis loss/retry where Redis participates;
- provider capability absence;
- localization/placeholder safety;
- regression against prior verified invariants.

Do not use an in-memory fake as the only proof for database or remote-effect concurrency.

## 6. Worker implementation boundary

Substantial implementation is committed on the assigned Worker branch, not directly on the integration branch.

Required sequence:

1. commit implementation on the Worker branch;
2. push only that branch and open/update one PR targeting `develop/v1.0.0-completion`;
3. require mandatory same-repository CI success for the exact implementation head/current merge candidate;
4. inspect executable logs and record exact test/assertion counts;
5. retain/inspect artifacts and calculate their digest independently;
6. any implementation fix creates a new implementation head and restarts this sequence.

Generic Worker CI is non-mutating and receives no protected provider/staging secrets. A skipped fork run is not evidence.

## 7. Evidence boundary

Evidence/traceability must state:

- Task Contract Issue/Revision and requirements;
- exact Worker implementation SHA and CI run;
- exact suite counts;
- artifact name, ID, digest and retention;
- verified scenarios;
- implementation/test references;
- database/transaction/concurrency controls;
- security/redaction controls;
- unsupported/untested behavior;
- remaining risks and next boundary.

Evidence must not claim real provider, staging, production, security-review, performance, restore or compatibility results that were not executed.

Commit evidence separately when required, then require mandatory CI for that exact evidence head/current merge candidate. If evidence changes executable behavior, treat it as a new implementation head.

## 8. MASTER review and integration

A Worker never self-merges. When the Worker reports `READY_FOR_REVIEW`, the MASTER independently fetches:

- current Task Contract Revision/state;
- Worker PR metadata and exact HEAD;
- actual diff/changed files;
- source/tests/migrations;
- CI jobs/logs/artifacts/evidence;
- dependencies and interaction with recently merged Worker PRs.

If corrections are required, return a complete correction prompt to the same Worker. If the merge gates pass, the MASTER uses history-preserving merge semantics into `develop/v1.0.0-completion` so verified implementation/evidence commits remain addressable.

After merge:

1. fetch the new integration head;
2. require mandatory integration CI through Draft PR `#6`;
3. update accepted boundary/status only after that integration gate succeeds;
4. recompute dependency/conflict graphs and newly READY tasks.

Never merge PR `#6` into `main` without explicit owner release acceptance.

## 9. Close the increment

Only after required Worker exact-SHA gates, MASTER review, Worker merge and post-merge integration CI:

- update the authoritative phase Issue and Task Contract state;
- update Worker PR and Draft PR `#6` status as appropriate;
- update `PROJECT_STATUS.md` / `docs/project-status.json` where the current boundary changes;
- update requirement traceability and risk state;
- mark/replace the active handoff;
- preserve PR `#6` Draft state and `main` unchanged.

## 10. Aborted or blocked increment

When blocked:

- leave partial implementation explicitly unverified;
- do not mark phase or requirements complete;
- record exact Worker head/PR/CI state as observational, not accepted evidence;
- record the immediate blocker and safe owner/MASTER action;
- preserve fail-closed behavior;
- do not hide a dependency/base mismatch with rebase/force-push;
- avoid speculative provider code when a contract/version is unknown.

## 11. Definition of done

An increment is done only when a new MASTER can answer from repository/GitHub alone:

- What requirement and Task Contract were implemented?
- What behavior is intentionally excluded?
- Which branch/PR and source/database objects own it?
- How are replay, concurrency, authorization, failure and secrets handled?
- Which exact implementation/evidence SHAs and CI/artifacts prove it?
- Was the Worker diff independently reviewed and history-preservingly merged?
- Did integration CI pass on the resulting `develop/v1.0.0-completion` head?
- What is still unverified and what is the exact next bounded action?
