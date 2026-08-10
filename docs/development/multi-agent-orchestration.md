# Multi-Agent Orchestration

Use this document only when the current dependency graph benefits from parallel implementation. It extends `AGENTS.md`; it does not replace product requirements or release gates.

## 1. Topology

- Integration branch: `develop/v1.0.0-completion`.
- Integration PR: Draft PR `#6` -> `main`.
- Temporary Worker branch: `agent/<issue-number>-<short-slug>`.
- Every Worker PR targets `develop/v1.0.0-completion`, never `main`.
- No Worker pushes directly to `develop/v1.0.0-completion` or `main` and no Worker merges its own PR.
- Worker branches are deleted after merge, cancellation or abandonment once GitHub preserves the task record.
- The only long-lived branches are `main` and `develop/v1.0.0-completion`.

## 2. MASTER responsibilities

The MASTER:

1. fetches PR `#6` and uses its live head before planning/writes;
2. reconstructs task state from GitHub and repository evidence;
3. avoids duplicate Issues/services/schema concepts;
4. maintains dependency/conflict ordering;
5. dispatches only READY tasks whose required predecessors are already in the declared base;
6. independently reviews Worker diff, exact HEAD, CI and evidence;
7. integrates eligible work with history-preserving semantics;
8. verifies integration CI after merge;
9. deletes/requests deletion of obsolete Worker branches;
10. never merges PR `#6` without final owner acceptance.

## 3. Task size and parallelism

Prefer a small number of coherent, independent capability slices over many micro-tasks. Parallelize only when modification surfaces and shared contracts make conflict LOW/MEDIUM and dependencies are already present.

Serialize work that competes for the same critical financial service, schema/migration sequence, authorization contract, workflow, lockfile, provider integration or release surface.

Do not create filler tasks or temporary branches merely to keep Workers busy.

## 4. Task Contract

Each Worker gets one current Task Contract in a GitHub Issue/comment containing:

- Worker ID and Contract Revision;
- parent phase/requirement IDs;
- exact `BASE_SHA`;
- branch and PR target;
- dependencies;
- goal/in-scope/out-of-scope behavior;
- allowed and protected modification areas;
- acceptance tests/evidence;
- risk and merge prerequisites.

Useful states are `DRAFT`, `BLOCKED`, `READY`, `IN_PROGRESS`, `IN_REVIEW`, `CHANGES_REQUESTED`, `MERGE_READY`, `DONE`, `CANCELLED`.

## 5. Worker isolation

One Worker uses one isolated writable checkout/worktree and one branch. Before coding, verify branch and `BASE_SHA`. A Worker does not silently rebase, switch tasks, alter another branch or expand protected scope.

## 6. CI and evidence

Generic Worker CI is same-repository, secret-free and non-mutating. All jobs use `[self-hosted, Linux, X64, freedom-staging, php84]`.

For High/Critical financial, authorization, concurrency, provider, migration or security work, accepted evidence requires independent MASTER review and explicit owner approval unless that exact risk was pre-authorized.

Use `docs/development/increment-lifecycle.md` for exact implementation/evidence acceptance. Worker summaries are not proof; inspect the actual diff, HEAD, CI and artifacts.

## 7. Merge and cleanup

After an eligible Worker merge:

1. fetch the new integration head;
2. require integration CI;
3. update/close the task Issue and PR state;
4. recompute dependencies/conflicts;
5. delete the merged Worker branch;
6. remove temporary task-only coordination files if they have no durable value.

Cancelled/abandoned Worker code is not merged or reused wholesale. Its GitHub PR/Issue remains audit history; the branch is deleted.

## 8. Durable state

Dynamic Worker state belongs in GitHub Issues/PRs/comments/CI, not in Chat memory.

Do not create numbered handoff documents, current overlays or repository status copies for Worker state. Stable conventions belong in `AGENTS.md`/this document; accepted proof belongs in `evidence/`; current project state belongs in `PROJECT_STATUS.md` and GitHub.

## 9. Human relay

When a separate Worker Chat is required, give it a complete standalone prompt containing the Task Contract, branch, `BASE_SHA`, scope and expected handoff. The human should normally need to return only a concise signal such as `READY_FOR_REVIEW` or `BLOCKED`; the MASTER then inspects GitHub directly.
