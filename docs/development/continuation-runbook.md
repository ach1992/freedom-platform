# Continuation Runbook

Use this when a new engineer or new ChatGPT conversation takes over the project.

## 1. Establish live truth

Do not begin from Chat memory, an old handoff, or a copied SHA.

1. Read `AGENTS.md`.
2. Read `PROJECT_STATUS.md` and `docs/project-status.json`.
3. Fetch Draft PR `#6`; verify base `main`, head `develop/v1.0.0-completion`, and record its current `head_sha`.
4. Inspect exact-head mandatory CI.
5. Read the authoritative active-phase Issue from `PROJECT_STATUS.md`.
6. Use `docs/README.md` to open only the architecture/requirement/evidence files relevant to the next task.
7. Inspect current open PRs and branches before creating work.

If current GitHub state disagrees with a document, treat the document as stale and reconcile it before feature work.

## 2. Branch state

The expected long-lived branches are only `main` and `develop/v1.0.0-completion`.

A temporary `agent/<issue-number>-<short-slug>` branch exists only while its Worker task is active. Delete it after merge/cancellation/abandonment once the GitHub PR/Issue preserves task history. Do not accumulate standing bootstrap, safety, repair or recovery branches.

## 3. Choose the next task

Prioritize:

1. release-blocking security, authorization, financial or data-integrity defect;
2. exact-head CI failure;
3. project-control drift that can misdirect development;
4. an unblocked requirement in the current phase;
5. evidence/traceability reconciliation required to accept completed implementation.

Before creating a new Issue or implementation surface, inspect existing code, accepted evidence and current Issues for equivalent work.

## 4. Implement or dispatch

For one-person/sequential work, follow `AGENTS.md` and `docs/development/increment-lifecycle.md` directly.

When parallel Workers are genuinely useful, follow `docs/development/multi-agent-orchestration.md`: one bounded task, one branch, one isolated writable environment and one PR per Worker. Dynamic assignment state stays in GitHub, not in handoff documents.

## 5. Verification

Use the current CI contract in `docs/development/ci-runner-contract.md` and `.github/workflows/ci.yml`.

Mandatory repository jobs are:

- Repository preflight;
- Secret scan;
- PHP static quality;
- MariaDB and Redis tests;
- Dependency and license policy.

A queued, cancelled, stale, skipped, superseded or partially executable run is not acceptance evidence.

## 6. External/provider/production work

Generic CI is non-mutating and must not consume provider or production secrets.

Real provider, staging, deployment, backup/restore, installer or production work requires the exact current release/task boundary, protected credentials outside repository/Chat, explicit safety controls and retained sanitized evidence. Do not keep a temporary execution bootstrap around between phases merely because it might be useful later; create the smallest current path when the protected action is actually due.

## 7. Leave the repository recoverable

Before stopping a meaningful cycle:

- GitHub Issue/PR state must reflect active/completed/cancelled tasks;
- `PROJECT_STATUS.md` and `docs/project-status.json` must reflect the current phase and verified boundary;
- accepted evidence remains under `evidence/`;
- obsolete task branches and temporary coordination files are removed;
- no new handoff/overlay/status duplicate is created.

A replacement engineer should need only `README.md` -> `AGENTS.md` -> `PROJECT_STATUS.md` -> live GitHub state to continue.
