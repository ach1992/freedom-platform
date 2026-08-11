# Contributing from the Default Branch

`main` is the release/default branch, not the active Version 1 development base.

## Normal development

1. Open Program Issue #3 and the owning task Issue to confirm current priority/dependencies.
2. Switch to `develop/v1.0.0-completion`.
3. Read the current `README.md`, `AGENTS.md`, and `CONTRIBUTING.md` on that branch.
4. Branch from the then-current integration head only when the task is READY/dispatched.
5. Open the task PR against `develop/v1.0.0-completion`.

Do not implement a normal product task from `main` and do not infer current feature completeness from this release snapshot.

## Main-target changes

A PR targeting `main` is reserved for explicit release work or bounded default-branch/control-plane maintenance. Draft PR #6 is the normal cumulative Version 1 release path and requires explicit Owner release acceptance before merge.

Every executing GitHub Actions job uses the owner-controlled self-hosted runner; GitHub-hosted runners are not a fallback.

For current setup, tests, static checks, MariaDB 10.11 integration validation, architecture rules, and merge/review requirements, use the current `CONTRIBUTING.md` on `develop/v1.0.0-completion` rather than copying those evolving commands into this release-branch guard.

## Task and evidence hygiene

Use the default-branch Task Issue and PR templates. Keep contracts proportional to risk. Live status, handoff, review reasoning, and task verification belong in GitHub; do not create per-task status/traceability/evidence documents.
