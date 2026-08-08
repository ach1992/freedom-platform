# Repository Operating Contract

This file is the mandatory entry point for every AI agent and human engineer working in this repository.

## 1. Authority order

Use sources in this order. A lower source must not override a higher source.

1. `docs/specification/master-execution-prompt.md` — normative product, engineering, security, testing, deployment, and acceptance specification.
2. Current GitHub state — PR, branch head, Issues, workflow runs, job logs, and retained artifacts.
3. `PROJECT_STATUS.md` and `docs/project-status.json` — current verified boundary and active bounded increment.
4. The active handoff document linked from `PROJECT_STATUS.md`.
5. Requirement, architecture, risk, testing, deployment, and phase traceability documents.
6. Historical evidence and superseded handoffs.

When sources disagree, stop treating the lower source as current, record the drift, and correct it with a focused commit. Never silently choose the more convenient source.

## 2. Mandatory start sequence

Before changing code or documentation:

1. Fetch PR `#6` from `ach1992/freedom-platform`.
2. Confirm it is open, Draft, based on `main`, and headed by `develop/v1.0.0-completion`.
3. Treat the PR's exact `head_sha` as the only current implementation head. Never trust a SHA copied from a handoff without re-fetching the PR.
4. Read, in order:
   - this file;
   - `PROJECT_STATUS.md`;
   - `docs/project-status.json`;
   - `docs/development/continuation-runbook.md`;
   - `docs/development/github-actions-runner-policy.md`;
   - `docs/specification/master-execution-prompt.md` for the affected scope;
   - the active handoff document;
   - the authoritative phase Issue linked from the status file;
   - relevant traceability, architecture, risk, test, and evidence files.
5. Fetch workflow runs for the exact head SHA. Inspect every mandatory job and executable log before deciding whether the next action is code, infrastructure, documentation, or a human-only blocker.
6. Search the repository before introducing a new concept, service, table, enum, workflow, or document. Prefer extending the existing authoritative implementation over creating a parallel one.

A new chat or engineer must be able to begin from these steps without relying on prior conversation memory.

## 3. Branch and pull-request policy

- Allowed working branch: `develop/v1.0.0-completion`.
- Authoritative PR: `#6`.
- PR base: `main`.
- PR must remain Draft.
- Do not merge, enable auto-merge, mark Ready for review, rewrite history, force-push, push to `main`, or create temporary branches.
- Use small, focused commits on the allowed branch.
- Re-fetch the PR immediately before every write that depends on the current head.
- Every GitHub Actions job must run on the owner-controlled self-hosted runner using `runs-on: [self-hosted, Linux, X64, freedom-staging, php84]`.
- Do not use GitHub-hosted `ubuntu-*`, `windows-*`, or `macos-*` runners as a fallback for CI, provider, staging, bootstrap, or historical workflows. Follow `docs/development/github-actions-runner-policy.md`.

## 4. Scope control

- Work only inside the active phase and bounded increment declared in `PROJECT_STATUS.md`.
- Do not enter a later phase to make the current implementation easier.
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

1. State the requirement IDs and exact boundary.
2. Inspect current implementation, tests, migrations, evidence, and known risks.
3. Choose the smallest reliable change that preserves verified behavior.
4. Add or update automated tests for normal, replay, conflict, authorization, validation, concurrency, redaction, and failure behavior as applicable.
5. Run the repository's mandatory checks using the documented self-hosted runner contract and repository-wide runner policy.
6. Fix real failures; do not weaken checks, suppress diagnostics, switch to GitHub-hosted capacity, or relabel failures as infrastructure without executable evidence.
7. Commit implementation separately from evidence/documentation when an independently verified implementation boundary is required.
8. Update status, traceability, risk, and handoff documents before leaving the increment.

## 8. Exact-SHA verification lifecycle

An increment is complete only when all of the following exist:

1. implementation commit SHA;
2. mandatory CI success on that exact implementation SHA;
3. exact test and assertion counts from executable logs;
4. retained evidence artifact name and ID;
5. independently calculated artifact SHA-256 digest;
6. bounded evidence and traceability documents that make no unsupported claim;
7. evidence-head commit SHA;
8. mandatory CI success on that exact evidence-head SHA;
9. Issue and PR updates made only after both exact-SHA boundaries are accepted.

A cancelled, queued, stale, superseded, merge-ref-only, or non-executable workflow is not implementation evidence.

## 9. Human-only blockers

Stop and ask the owner only when the immediate next safe step requires one of:

- a real secret or account;
- root or target-server action;
- a legally or financially significant business decision not fixed by the specification;
- an irreversible or destructive production decision;
- real provider behavior that differs from the official contract and affects money, security, or remote effects.

Report one blocker at a time with: exact blocker, evidence, recommended action, safe command or input method, expected result, rollback, and the exact output required back.

## 10. Required handoff quality

Before ending work, leave the repository so another engineer can continue without chat history:

- `PROJECT_STATUS.md` reflects the current verified boundary, active unverified increment, blockers, and exact next sequence;
- `docs/project-status.json` remains schema-valid and consistent with the Markdown status;
- the active handoff records changed files, invariants, unverified claims, CI state, and mandatory next actions;
- requirement traceability and risk entries are updated for affected scope;
- temporary workflows, repair scripts, generated files, and stale instructions are removed or explicitly marked historical;
- no statement relies on an unverified current SHA; readers are instructed to fetch PR `#6`.
