# Increment Lifecycle

This is the mandatory lifecycle for every bounded implementation increment.

## 1. Select a bounded increment

An increment must have:

- one phase and authoritative Issue;
- explicit requirement IDs;
- included behavior and excluded later-phase behavior;
- acceptance scenarios;
- affected security, financial, data, authorization, and remote-effect invariants;
- expected migrations, services, tests, evidence, and traceability;
- known owner/provider inputs and a fake or fail-closed path when they are not yet available.

If the scope cannot be verified independently, reduce it before implementation.

## 2. Record the starting boundary

Before code:

- fetch PR `#6` and exact head;
- confirm mandatory CI state on that head;
- identify the last independently verified boundary;
- create or update the active handoff;
- record open risks and dependencies;
- confirm the change stays inside the active phase.

Do not build on an uninvestigated red CI head.

## 3. Design before mutation

Document or confirm:

- aggregate/table ownership;
- state transitions and actors;
- command/idempotency key scope;
- transaction and lock order;
- uniqueness and check constraints;
- external result taxonomy: success, definitive failure, retryable failure, uncertain result, conflict/manual review;
- sensitive fields and redaction rules;
- application contracts and adapter boundaries;
- migration compatibility and rollback/restore impact.

Target-state design must be labelled separately from implemented behavior.

## 4. Implement the smallest complete behavior

A production increment normally includes:

- domain types and validation;
- application service or command boundary;
- infrastructure implementation behind contracts;
- migrations and indexes/constraints;
- authorization at display and execution boundaries where applicable;
- append-only history/audit where required;
- localization keys for user-facing behavior;
- deterministic fakes for unavailable external systems;
- fail-closed shells for unsupported real providers;
- automated tests.

Avoid broad refactors unrelated to the increment. Decompose a hotspot only when it reduces immediate correctness or verification risk and can be tested independently.

## 5. Required test dimensions

Select all applicable dimensions:

- valid lifecycle;
- input/domain boundary validation;
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

## 6. Implementation boundary

The implementation commit contains code, migrations, tests, and necessary implementation documentation, but not acceptance claims derived from a run that has not happened.

Required sequence:

1. commit implementation;
2. fetch exact PR head;
3. obtain mandatory CI success on that exact SHA;
4. inspect all executable logs;
5. record exact test/assertion counts;
6. retain and inspect artifacts;
7. calculate artifact digest independently.

A fix after CI creates a new implementation head and restarts this sequence.

## 7. Evidence boundary

Evidence and traceability must state:

- exact implementation SHA and CI run;
- exact suite counts;
- artifact name, ID, digest, and retention;
- requirements and verified scenarios;
- implementation files and test references;
- database/transaction/concurrency controls;
- security and redaction controls;
- unsupported or untested behavior;
- remaining risks and next phase boundary.

Evidence must not claim real provider, staging, production, security-review, performance, restore, or compatibility results that were not executed.

Commit evidence separately, then run mandatory CI on the exact evidence head. If evidence changes executable behavior, treat it as a new implementation head.

## 8. Close the increment

Only after both exact-SHA CI runs pass:

- update the authoritative Issue checklist/comment;
- update the Draft PR body/current verified boundary;
- update `PROJECT_STATUS.md` and `docs/project-status.json`;
- update requirement traceability and risk register;
- mark the handoff complete or replace it with the next active handoff;
- preserve PR Draft state and `main` unchanged.

## 9. Aborted or blocked increment

When blocked:

- leave partial implementation explicitly unverified;
- do not update phase checkboxes as complete;
- record the exact head and CI/log state as observational, not accepted evidence;
- record one immediate blocker and safe owner action;
- preserve fail-closed behavior;
- avoid speculative provider code when a contract or version is unknown.

## 10. Definition of done

An increment is done only when a new engineer can answer, from repository state alone:

- What requirement was implemented?
- What behavior is intentionally excluded?
- Which code and database objects own it?
- How are replay, concurrency, authorization, failure, and secrets handled?
- Which exact SHA was tested?
- Which run and artifact prove it?
- What is still unverified?
- What is the exact next bounded action?
