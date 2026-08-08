# GitHub Actions Self-Hosted Runner Policy

This repository uses the owner's self-hosted GitHub Actions runner for all workflow execution. This is a repository-wide execution policy, not only a CI optimization.

## Required runner

Expected runner:

- name: `freedom-staging-runner`;
- labels: `self-hosted`, `Linux`, `X64`, `freedom-staging`, `php84`;
- canonical selector: `runs-on: [self-hosted, Linux, X64, freedom-staging, php84]`.

The runner shown in repository Settings must be Online before an execution can start. `Idle` is the expected ready state when it is not processing another job.

## Repository-wide rule

Every GitHub Actions job in `.github/workflows/*.yml` or `.github/workflows/*.yaml` must use the canonical self-hosted selector above, including:

- mandatory CI;
- provider readiness and live acceptance;
- staging/read-only operational diagnostics;
- bootstrap workflows targeting `main`;
- historical/disabled workflows, so a future re-enable cannot silently select a GitHub-hosted runner.

GitHub-hosted labels such as `ubuntu-*`, `windows-*`, and `macos-*` are forbidden unless the owner explicitly changes this policy in a reviewed repository change.

Do not switch a queued or failing job to a GitHub-hosted runner as a workaround. Diagnose the self-hosted runner/service/labels or workflow contract instead.

## Why this is mandatory

The project intentionally keeps Actions execution on owner-controlled capacity to avoid dependency on GitHub-hosted plan/billing limits and to preserve the tested PHP 8.4, Composer, PCOV, Docker, MariaDB, and Redis runtime contract.

Using actions such as `actions/checkout`, `actions/upload-artifact`, or `gitleaks/gitleaks-action` does not imply use of a GitHub-hosted runner; those action steps execute inside the job selected by `runs-on`.

## Before dispatch or accepting CI evidence

1. Confirm the workflow job uses the canonical self-hosted selector.
2. Confirm `freedom-staging-runner` is Online and has all required labels.
3. Confirm the executable log reports the expected runner name/OS/architecture where the workflow records runner facts.
4. If no matching runner is available, treat that as an infrastructure blocker; do not fall back to GitHub-hosted capacity.
5. Do not treat a queued, skipped, billing-blocked, or non-executed job as evidence.

## Runtime details

The exact PHP/Composer/PCOV/Docker host contract is maintained in `docs/development/ci-runner-contract.md`. This policy controls *where* workflows execute; the runner contract controls *how* the selected host is validated and used.

## Continuation rule

Every new chat, AI agent, or human engineer must read this policy through the mandatory entry points in `AGENTS.md` and `docs/development/continuation-runbook.md` before changing or dispatching workflows.
