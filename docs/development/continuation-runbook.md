# Continuation Runbook

Use this runbook when starting in a new chat, joining the project, recovering after an interruption, or handing work to another MASTER/Worker.

## 1. Establish live truth

Do not begin from conversation memory or a copied SHA.

1. Fetch Draft PR `#6` in `ach1992/freedom-platform` and verify it is open, Draft, base `main`, head `develop/v1.0.0-completion`.
2. Record its exact live `head_sha` as the current integration head for this inspection only.
3. Read, in order:
   - `AGENTS.md`;
   - `PROJECT_STATUS.md`;
   - `docs/project-status.json`;
   - `docs/development/multi-agent-orchestration.md`;
   - `docs/development/github-actions-runner-policy.md`;
   - `docs/development/ci-runner-contract.md`;
   - the current handoff linked from `PROJECT_STATUS.md`;
   - the active phase Issue and any Task Contract Issue/PR relevant to the role;
   - relevant specification, traceability, risk, test, deployment and evidence files.
4. Inspect open PRs, active Worker branches and current GitHub Issues before creating a new task or abstraction.
5. Reconcile disagreement before feature work. A stale project-control document is a defect.

For a Worker, the Task Contract's branch and `BASE_SHA` must match the isolated environment before any implementation. A Worker does not silently rebase onto a newer integration head; the MASTER revises the contract when rebasing/re-dispatch is actually safe.

## 2. Inspect exact-head CI

For the live integration head, inspect workflow runs associated with Draft PR `#6`. For a Worker review, inspect the Worker PR's current exact head and current merge candidate.

Repository-wide runner rule:

- every job uses `runs-on: [self-hosted, Linux, X64, freedom-staging, php84]`;
- expected runner is `freedom-staging-runner`;
- GitHub-hosted runners are not a fallback;
- generic Worker PR CI runs only for same-repository PRs and contains no protected provider/staging secrets;
- a fork PR skipped by the same-repository guard is not accepted merge evidence.

Mandatory `CI` jobs:

- Repository preflight;
- Secret scan;
- Dependency and license policy;
- PHP static quality;
- MariaDB and Redis tests.

Inspect status, conclusion and executable logs. A queued, cancelled, stale, skipped, GitHub-hosted or partially executable run is not evidence.

## 3. Diagnose without wasting runner capacity

The repository currently has one self-hosted runner. Jobs that look parallel may serialize.

- Toolchain bootstrap: normally under 2 minutes.
- Dependency installation with warm cache: normally under 10 minutes.
- Static analysis: bounded to 15 minutes.
- Disposable MariaDB/Redis startup: bounded to 5 minutes.
- Full suite with coverage: bounded to 30 minutes.

When a run stalls, inspect current job/runner state and branch-aware concurrency. Do not create repeated commits/dispatches, and never switch to GitHub-hosted capacity.

## 4. MASTER: choose and dispatch bounded work

Apply this priority:

1. release-blocking security, authorization, financial, remote-idempotency or data-integrity defect;
2. exact-head CI failure;
3. project-control drift that can misdirect Workers;
4. active-increment blocker that is safely actionable;
5. READY task whose dependencies are already merged into the integration base;
6. evidence/traceability completion;
7. next planned increment.

Before creating a new implementation Issue, inspect existing phase Issues, completed evidence, current handoffs and recent Issue comments for equivalent work.

For every candidate Task Contract record requirement IDs, Contract Revision, dependencies, expected modification surface, conflict class, security/financial risk, acceptance criteria, tests, evidence and merge prerequisites. Do not parallelize two tasks that share a critical service, schema/migration sequence, central contract, lockfile or workflow unless a safe prerequisite/merge order makes the overlap explicit.

When READY:

1. re-fetch PR `#6` and record its exact head as `BASE_SHA`;
2. create/update the bounded Task Contract in GitHub;
3. create `agent/<issue-number>-<short-slug>` from exactly `BASE_SHA`;
4. record Worker ID/branch/base/dependencies/scope in the Issue;
5. provide the human relay a complete standalone Worker prompt and isolated worktree command;
6. mark the task `DISPATCHED` only after the durable GitHub state exists.

## 5. Worker: implement safely

A Worker operates only inside one isolated writable worktree/equivalent environment and one Task Contract Revision.

- verify branch and `BASE_SHA` before work;
- inspect existing implementation/evidence first;
- preserve strict types, localization, authorization, redaction, transactions, replay safety and fail-closed behavior;
- use the smallest complete change;
- do not touch protected/high-conflict scope unless the contract explicitly allows it;
- do not push `develop/v1.0.0-completion` or `main`;
- do not self-merge, retarget to `main`, force-push or alter another Worker branch;
- stop on dependency/scope/protected-surface mismatch and record a blocker for the MASTER.

## 6. Verify a Worker implementation boundary

1. Worker commits implementation on its branch.
2. Worker opens/updates its PR targeting `develop/v1.0.0-completion`.
3. Mandatory same-repository CI succeeds for the exact implementation head/current merge candidate.
4. Executable logs provide exact test/assertion counts and retained artifacts.
5. Required evidence/traceability is committed separately when the increment lifecycle requires it.
6. Mandatory CI succeeds for that exact evidence head/current merge candidate.
7. Worker records durable status in Issue/PR and tells the human relay only that the Worker is `READY_FOR_REVIEW`.
8. MASTER independently fetches Task Contract, PR metadata, exact HEAD, diff, changed files, tests, CI, evidence and dependency/conflict state.
9. Corrections go back to the same Worker Chat with a complete correction prompt.
10. If all gates pass, MASTER merges the Worker PR with history-preserving semantics into `develop/v1.0.0-completion`.
11. MASTER fetches the new integration head and requires integration CI through Draft PR `#6` before accepting it as the new baseline.
12. MASTER recomputes task dependencies/conflicts and records newly READY work.

PR `#6` is never merged to `main` without explicit final release acceptance from the owner.

## 7. Provider/staging and secret workflows

Generic CI is tests/static only. It must never deploy, mutate staging/production, invoke live provider mutations, configure Telegram webhooks or receive unnecessary protected secrets.

Secret-consuming provider/staging workflows remain separately `workflow_dispatch`/confirmation/branch guarded. Never request or expose a secret value; reference existing GitHub Actions Secrets by name through the approved workflow only.

The existing `ops/provider-live-dispatch-bootstrap` and `safety/main-2026-08-08-pre-provider-bootstrap` branches remain retained until the documented PR `#24` cleanup condition is satisfied.

## 8. Leave a durable handoff

Before stopping a MASTER cycle, GitHub/repository alone must reveal:

- integration branch and Draft PR;
- live task graph and phase state;
- active Worker IDs, Task Contract revisions, branches and `BASE_SHA`s;
- dependencies/blockers/conflict classifications;
- Worker PR review/CI/evidence state;
- latest accepted implementation/evidence boundaries;
- current project-control rules and next READY tasks.

Stable rules live in repository documentation. Dynamic Worker state lives in GitHub Issues/PRs/comments/CI. Chat memory is never the project database.
