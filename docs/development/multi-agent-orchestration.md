# Multi-Agent Orchestration Contract

This document is the stable repository contract for isolated concurrent Worker Agents. It extends `AGENTS.md`; it does not replace the authoritative product specification, phase Issues, exact-SHA evidence lifecycle, or release gates.

## 1. Integration topology

- Version 1 integration branch: `develop/v1.0.0-completion`.
- Long-running integration PR: Draft PR `#6`, `develop/v1.0.0-completion` -> `main`.
- PR `#6` remains Draft until all Version 1 release gates pass and the owner explicitly accepts the final release.
- Worker branches use `agent/<issue-number>-<short-slug>`.
- Every Worker PR targets `develop/v1.0.0-completion`, never `main`.
- No Worker pushes directly to `develop/v1.0.0-completion` or `main` and no Worker merges its own PR.
- Do not rewrite history or force-push.

Existing non-`agent/*` branches are not automatically obsolete. Classify each as active, required exception, safety/recovery, obsolete, or unknown and satisfy its documented cleanup condition before deletion. In particular, `ops/provider-live-dispatch-bootstrap` and `safety/main-2026-08-08-pre-provider-bootstrap` remain retained while Draft PR `#24` is unresolved.

## 2. MASTER responsibilities

The MASTER is the integration authority and must:

1. fetch PR `#6` before planning or repository writes and use its live head SHA;
2. reconstruct project state from repository/GitHub rather than Chat memory;
3. reconcile existing Issues/evidence before creating work;
4. maintain the dependency and conflict graph;
5. create or revise bounded Task Contracts under the existing authoritative phase Issues;
6. dispatch only READY tasks whose dependencies are already merged into the current integration base;
7. create Worker branches from the exact live integration head recorded as `BASE_SHA`;
8. review actual Worker PR diffs, exact HEAD SHA, tests, CI, evidence and interaction with recently merged work;
9. merge eligible Worker PRs into `develop/v1.0.0-completion` only after merge gates pass;
10. re-fetch the integration head and recompute dependencies/conflicts after every merge;
11. never merge PR `#6` into `main` without explicit owner release acceptance.

Project-control/CI governance fixes owned by the MASTER may be committed directly to the integration branch when needed to make Worker execution safe. Substantial product implementation belongs to Workers.

## 3. Task Contract

Each implementation Worker has exactly one bounded Task Contract with a monotonically increasing `Contract Revision`. The authoritative contract lives in a GitHub Issue or Issue comment and records at least:

- Worker ID;
- parent phase Issue and requirement IDs;
- Contract Revision and state;
- `BASE_SHA`;
- Worker branch;
- Worker PR target;
- dependencies;
- goal and expected behavior;
- in-scope and out-of-scope behavior;
- allowed modification areas;
- protected/high-conflict areas;
- acceptance criteria;
- required tests and regression checks;
- required evidence/traceability updates;
- security, financial, migration and compatibility constraints;
- risk class and merge prerequisites.

Task states are: `DRAFT`, `BLOCKED`, `READY`, `DISPATCHED`, `IN_PROGRESS`, `IN_REVIEW`, `CHANGES_REQUESTED`, `MERGE_READY`, `DONE`.

## 4. Worker isolation

Each Worker must use one isolated writable worktree or equivalent environment. Two Workers must never share a writable working tree.

Required startup checks in the Worker environment:

```bash
pwd
git status --short
git branch --show-current
git rev-parse HEAD
git worktree list
```

The assigned branch and `BASE_SHA` must match the Task Contract before implementation starts. If they do not match, the Worker stops and reports the environment mismatch instead of rebasing, force-pushing, or switching to another task.

Reference local setup, after the MASTER has created the GitHub branch:

```bash
git fetch origin
git worktree add ../freedom-platform-<worker-id> origin/<worker-branch>
cd ../freedom-platform-<worker-id>
git switch --track -c <worker-branch> origin/<worker-branch>
```

If the local Git version reports that the branch is already checked out or the remote-tracking setup differs, do not improvise destructive commands; inspect `git worktree list` and use an equivalent isolated checkout without sharing another Worker's directory.

## 5. Worker boundaries

A Worker must:

- stay within the current Task Contract Revision;
- preserve higher-authority requirements and existing verified invariants;
- inspect existing code/evidence before introducing a parallel abstraction;
- commit focused changes to its own branch only;
- push only its assigned branch;
- open one PR targeting `develop/v1.0.0-completion`;
- reference its Issue, Contract Revision, requirement IDs and tests in the PR;
- never self-merge, retarget to `main`, force-push, or alter another Worker branch;
- stop on scope expansion, dependency mismatch, protected-scope conflict, or a real credential/root/irreversible blocker and report it to the MASTER.

## 6. CI and runner security

Generic Worker PR CI is allowed only for same-repository PRs targeting `develop/v1.0.0-completion` and must remain secret-free and non-mutating.

- Every job uses `runs-on: [self-hosted, Linux, X64, freedom-staging, php84]`.
- Generic CI must not receive provider, staging, Telegram, deployment, or other protected runtime secrets.
- Fork PR code must not execute on the self-hosted runner; the same-repository guard is fail-closed.
- Generic PR CI performs tests/static checks only. It must not deploy, mutate staging/production, call live provider mutation paths, configure Telegram webhooks, or switch release symlinks.
- Secret-consuming provider/staging workflows remain separate, manually dispatched, branch/confirmation guarded and least-privileged.
- Checkout/workspace cleanup and disposable MariaDB/Redis resources must prevent one task from contaminating another. The self-hosted runner is not assumed disposable.
- A skipped fork run is not accepted evidence for merge.

The detailed runtime requirements remain in `docs/development/github-actions-runner-policy.md` and `docs/development/ci-runner-contract.md`.

## 7. Exact-SHA evidence with Worker PRs

The existing exact-SHA lifecycle remains mandatory and is not weakened by Worker branches.

1. Worker commits implementation to its branch.
2. Mandatory Worker PR CI must succeed for the exact Worker PR head/merge candidate under the repository CI contract.
3. Required implementation evidence/artifact metadata and independent digest are recorded without unsupported claims.
4. Evidence/traceability changes are committed separately when the increment lifecycle requires a separate evidence head.
5. Mandatory CI must also succeed for that exact evidence head.
6. The MASTER reviews the actual current Worker PR head and merge candidate.
7. Worker PRs are merged with history-preserving merge semantics; do not squash/rebase away verified implementation/evidence commits.
8. The resulting `develop/v1.0.0-completion` head must receive successful integration CI through Draft PR `#6` before it becomes the new accepted integration baseline.

## 8. Conflict and merge policy

Classify Worker overlap before dispatch:

- `LOW`: disjoint modules/files and no shared schema/contract/configuration;
- `MEDIUM`: shared nearby surfaces or indirect contract interaction; dispatch only with explicit protected scope and merge order;
- `HIGH`: same critical service/schema/migration sequence/lockfile/workflow/core contract; serialize or extract a prerequisite.

Financial, authorization, installer/updater/backup, live-provider, security-boundary, release and other high-risk changes require independent specialist review as required by the specification. A Worker never approves its own sensitive change.

The MASTER merge gate requires: current Contract Revision satisfied, exact reviewed HEAD, mandatory CI green, dependencies merged, no unresolved review, no scope expansion, current target compatibility, required evidence complete, and no release-blocking security/financial invariant failure.

## 9. Durable dispatch and recovery

Dynamic Worker state belongs in GitHub Issues/PRs/comments/CI, not in Chat memory. Every dispatch record must include:

- Worker ID;
- Issue;
- Contract Revision;
- state;
- branch;
- PR target;
- `BASE_SHA`;
- dependencies;
- risk/conflict class;
- allowed/protected scope.

A future MASTER must be able to reconstruct active Workers, branches, contracts, dependencies, blockers and PR review state from GitHub alone. Stable rules belong in repository documentation; transient assignment state belongs in GitHub.
