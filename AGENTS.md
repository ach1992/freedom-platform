# Default Branch Operating Guard

This file exists on `main` so a human or AI agent entering through GitHub's default branch can recover the active project without Chat history or stale phase assumptions.

## Normal development does not start from main

`main` is the release/default branch. The active Version 1 integration branch is `develop/v1.0.0-completion`.

Before normal implementation, switch to the current integration branch and read its current:

- `README.md`;
- `AGENTS.md`;
- `CONTRIBUTING.md`;
- owning GitHub Issue;
- relevant canonical product/architecture/security/testing reference.

Live phase/priority/dependency state starts at Program Issue #3. Draft PR #6 is the cumulative integration path from develop to main.

Do not use historical source/docs on `main` as proof of current implementation status. Do not create a status file to compensate; current state belongs in GitHub.

## Authority

- Current Version 1 product/security/correctness authority: `docs/specification/master-execution-prompt.md` on `develop/v1.0.0-completion`.
- Current repository execution/architecture/CI rules: `AGENTS.md` and `CONTRIBUTING.md` on `develop/v1.0.0-completion`.
- Current delivery state: Program Issue #3 -> active phase/task Issue -> task PR/checks, plus Draft PR #6.
- Historical implementation context: Git/PR/Issue/workflow history.

Historical process instructions on an older release snapshot must not recreate retired execution ledgers, mutable traceability matrices, project-status files, per-task handoff documents, or per-task evidence archives.

## Main changes

Normal product work must not be pushed directly to `main`.

A main-target change is limited to an explicitly authorized release or bounded default-branch/control-plane maintenance task. Use a PR, review the exact diff, and preserve the release/integration model. Historical bootstrap exceptions do not authorize direct main changes.

Never merge Draft PR #6 without explicit Owner release acceptance.

## GitHub Actions

Default-branch CI uses ephemeral standard GitHub-hosted Linux runners. Do not connect repository-level self-hosted runners while this repository is public; any future private self-hosted route requires an explicit reviewed control-plane change.

Do not weaken security, financial integrity, idempotency, migration, provider, or release validation merely to reduce CI work. Remove only validation/artifacts that do not improve a real implementation, review, or release decision.

## Task hygiene

GitHub Issues and PRs hold live work state. Task contracts should contain only the outcome, dependencies, bounded scope, objective acceptance, validation strategy, risk, and conditional details that materially affect implementation/review.

Do not create process or documentation for its own sake. Refactor code for responsibility, coupling, reuse, transaction boundaries, or testability—not because a file is large.
