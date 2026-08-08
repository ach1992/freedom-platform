# Repository Operating Contract

This file is the mandatory entry point for every AI agent and human engineer working in this repository.

## 1. Authority order

Use sources in this order. A lower source must not override a higher source.

1. `docs/specification/master-execution-prompt.md` — normative product, engineering, security, testing, deployment, and acceptance specification.
2. Current GitHub state — PR, branch head, Issues, workflow runs, job logs, and retained artifacts.
3. `PROJECT_STATUS.md` and `docs/project-status.json` — current verified boundary and active bounded increment.
4. `docs/development/multi-agent-orchestration.md` — stable MASTER/Worker branching, isolation, review, and recovery rules.
5. The active handoff document linked from `PROJECT_STATUS.md`.
6. Requirement, architecture, risk, testing, deployment, and phase traceability documents.
7. Historical evidence and superseded handoffs.

When sources disagree, stop treating the lower source as current, record the drift, and correct it with a focused commit. Never silently choose the more convenient source.

## 2. Mandatory start sequence

Before changing code or documentation:

1. Fetch PR `#6` from `ach1992/freedom-platform`.
2. Confirm it is open, Draft, based on `main`, and headed by `develop/v1.0.0-completion`.
3. Treat the PR's exact `head_sha` as the only current integration head. Never trust a SHA copied from a handoff without re-fetching the PR.
4. Read, in order:
   - this file;
   - `PROJECT_STATUS.md`;
   - `docs/project-status.json`;
   - `docs/development/multi-agent-orchestration.md`;
   - `docs/development/continuation-runbook.md`;
   - `docs/development/github-actions-runner-policy.md`;
   - `docs/specification/master-execution-prompt.md` for the affected scope;
   - the active handoff document;
   - the authoritative phase Issue linked from the status file;
   - relevant traceability, architecture, risk, test, and evidence files.
5. Fetch workflow runs for the exact integration head SHA. Inspect every mandatory job and executable log before deciding whether the next action is code, infrastructure, documentation, or a human-only blocker.
6. Search the repository and existing Issues before introducing a new concept, service, table, enum, workflow, document, or implementation Issue. Prefer extending the existing authoritative implementation over creating a parallel one.
7. Determine the current role:
   - MASTER: reconstruct graph/state, create/revise Task Contracts, create Worker branches, review/integrate Worker PRs, and maintain project-control state;
   - Worker: operate only from an assigned Task Contract, branch, `BASE_SHA`, and isolated worktree/environment.

A new chat or engineer must be able to begin from these steps without relying on prior conversation memory.

## 3. Branch and pull-request policy

- Version 1 integration branch: `develop/v1.0.0-completion`.
- Authoritative integration PR: Draft PR `#6`, integration branch -> `main`.
- PR `#6` must remain Draft.
- Do not merge PR `#6`, enable auto-merge, mark it Ready for review, rewrite history, force-push, or push directly to `main`.
- Contracted Worker branches are explicitly allowed using `agent/<issue-number>-<short-slug>` and must start from the live PR `#6` head recorded as the Task Contract `BASE_SHA`.
- Every Worker has one bounded Task Contract, one isolated writable worktree/environment, one Worker branch, and one PR targeting `develop/v1.0.0-completion`.
- Workers must not push directly to `develop/v1.0.0-completion` or `main`, must not merge their own PR, and must not alter another Worker branch.
- Do not create uncontracted temporary branches. Existing bootstrap/safety/recovery branches are retained only under their documented exception and cleanup conditions.
- The MASTER may make focused project-control/CI governance commits directly on the integration branch when necessary to keep Worker execution safe; substantial product implementation belongs on Worker branches.
- Worker PRs are integrated with history-preserving merge semantics so verified implementation/evidence commits are not squashed or rebased away.
- Re-fetch PR `#6` immediately before every MASTER write that depends on the current integration head. Workers must verify their assigned branch and `BASE_SHA` before changing code.
- Every GitHub Actions job must run on the owner-controlled self-hosted runner using `runs-on: [self-hosted, Linux, X64, freedom-staging, php84]`.
- Do not use GitHub-hosted `ubuntu-*`, `windows-*`, or `macos-*` runners as a fallback for CI, provider, staging, bootstrap, or historical workflows. Follow `docs/development/github-actions-runner-policy.md`.

## 4. Scope control

- Work only inside an authoritative phase and a bounded Task Contract/increment.
- Do not enter a later phase to make the current implementation easier.
- Do not dispatch a Task whose predecessors are not merged into its declared `BASE_SHA`.
- Keep target-state architecture distinct from implemented and independently verified behavior.
- A schema, interface, fake, shell, or document is not evidence that a real provider or production workflow is compatible.
- Do not claim real Marzban, PasarGuard, payment, SMS, Telegram, backup, restore, installer, updater, or production compatibility without dated contract or target-environment evidence.

## 5. Correctness and side effects

The following rules are release-blocking:

- no paid provisioning before authoritative payment capture;
- no duplicate financial, provisioning, Telegram, or remote-provider effect;
- database constraints and transactions remain the final correctness barrier;
- uncertain external results enter authoritative lookup, discovery, reconciliation, or manual review before retry;
- remote create requires authoritative absence immediately before creation;
- exact remote match is adopted; mismatch is a conflict; unavailable lookup means no create;
- conflicting idempotency-key reuse never overwrites the original result;
- TLS verification is never disabled;
- system CA is the default; custom CA or pinning is explicit and limited to configured private/self-signed endpoints.

## 6. Secrets and sensitive data

- Never request, retrieve, print, commit, log, attach, or quote secrets.
- GitHub Actions Secrets and protected runtime configuration are references, not evidence content.
- Do not include full tokens, credentials, OTPs, identity values, card data, gift codes, provider payloads, subscription URLs, or private files in tests, logs, Issues, PRs, screenshots, or artifacts.
- Verification must prove presence or behavior without disclosing the value.

## 7. Change protocol

For each bounded change:

1. State the requirement IDs, Task Contract Revision, dependencies, and exact boundary.
2. Inspect current implementation, tests, migrations, evidence, and known risks.
3. Choose the smallest reliable change that preserves verified behavior.
4. Add or update automated tests for normal, replay, conflict, authorization, validation, concurrency, redaction, and failure behavior as applicable.
5. Run the repository's mandatory checks using the documented self-hosted runner contract and repository-wide runner policy.
6. Fix real failures; do not weaken checks, suppress diagnostics, switch to GitHub-hosted capacity, or relabel failures as infrastructure without executable evidence.
7. Commit implementation separately from evidence/documentation when an independently verified implementation boundary is required.
8. Update Task Contract state, status, traceability, risk, handoff, Issue, and PR records as required before leaving the increment.

## 8. Exact-SHA verification lifecycle

An increment is complete only when all of the following exist:

1. implementation commit SHA;
2. mandatory CI success associated with that exact implementation head and its current PR merge candidate;
3. exact test and assertion counts from executable logs;
4. retained evidence artifact name and ID;
5. independently calculated artifact SHA-256 digest;
6. bounded evidence and traceability documents that make no unsupported claim;
7. evidence-head commit SHA;
8. mandatory CI success associated with that exact evidence head and its current PR merge candidate;
9. MASTER review of the actual current Worker PR HEAD, diff, dependencies, CI and evidence;
10. history-preserving Worker PR merge into `develop/v1.0.0-completion`;
11. successful integration CI on the resulting live integration head through Draft PR `#6`;
12. Issue and PR updates made only after the applicable exact-SHA boundaries are accepted.

A cancelled, queued, stale, superseded, skipped-fork, unreviewed, or non-executable workflow is not implementation evidence. A merge-ref run is useful only when its source head is explicitly verified and the repository lifecycle records the exact source SHA.

## 9. Human-only blockers

Stop and ask the owner only when the immediate next safe step requires one of:

- a real secret or account;
- root or target-server action;
- a legally or financially significant business decision not fixed by the specification;
- an irreversible or destructive production decision;
- real provider behavior that differs from the official contract and affects money, security, or remote effects.

Report one blocker at a time with: exact blocker, evidence, recommended action, safe command or input method, expected result, rollback, and the exact output required back.

## 10. Required handoff quality

Before ending work, leave the repository and GitHub so another MASTER can continue without chat history:

- `PROJECT_STATUS.md` reflects the current verified boundary, active unverified increment, blockers, and exact next sequence;
- `docs/project-status.json` remains schema-valid and consistent with the Markdown status;
- stable branch/Worker rules remain in `docs/development/multi-agent-orchestration.md`;
- active Worker IDs, Task Contract revisions, branches, `BASE_SHA`s, dependencies, blockers and PR state are recorded in GitHub Issues/PRs/comments/CI;
- the active handoff records changed files, invariants, unverified claims, CI state, and mandatory next actions;
- requirement traceability and risk entries are updated for affected scope;
- temporary workflows, repair scripts, generated files, and stale instructions are removed or explicitly marked historical;
- no statement relies on an unverified current SHA; readers are instructed to fetch PR `#6`.
