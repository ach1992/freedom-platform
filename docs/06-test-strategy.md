# Testing and CI Contract

This document defines durable verification requirements. Live run IDs, test counts, artifacts, current failures, runner online/offline state, and repository setting state belong in GitHub.

## Mandatory CI environment

All executing GitHub Actions jobs run on the owner-controlled self-hosted runner:

```yaml
runner name: freedom-staging-runner
runs-on: [self-hosted, Linux, X64, freedom-staging, php84]
```

GitHub-hosted runners are not a fallback. The selected host must provide PHP 8.4, Composer 2.10.x, Docker/Compose, Git, Bash, `jq`, and the required PHP extensions. Coverage runs require PCOV or another explicitly reviewed PHPUnit-compatible driver.

Repository workflows use `scripts/ci/bootstrap-self-hosted-toolchain.sh` to validate the effective runtime. Runner labels alone are not evidence.

### How a developer/agent uses the runner

The runner is reached through GitHub Actions, not by assuming direct SSH or an arbitrary remote shell is available to the current chat/coding sandbox.

- A non-Draft PR targeting `develop/v1.0.0-completion` triggers the applicable CI tier on the PR revision.
- Draft PRs intentionally stay quiet. Marking a Draft PR **Ready for review** is the normal way to request CI for its current head.
- `.github/workflows/ci.yml` also supports `workflow_dispatch` for intentional manual full validation/coverage.
- `.github/workflows/staging-readiness.yml` is the read-only way to verify sanitized host/runtime facts when runner/staging readiness itself is in question.
- Provider readiness/live workflows are operational paths, not substitutes for normal application CI; their secret/environment interfaces are documented in `docs/09-deployment-runbook.md`.

If a future coding environment cannot run MariaDB/Docker locally, that is not by itself a project blocker. Publish the reversible task branch/PR through the supported GitHub path and obtain authoritative MariaDB/Redis/static evidence on this self-hosted runner before acceptance/merge.

If the runner is offline or a job cannot acquire the expected labels, inspect live GitHub `Settings -> Actions -> Runners` and the workflow run. Do not invent a replacement GitHub-hosted runner or weaken database-sensitive validation.

### Runner role and trust boundary

The self-hosted runner is the repository's authoritative CI execution boundary, not a general remote shell. `actions/checkout` creates a workflow checkout in the runner workspace for the exact GitHub revision being tested. That workspace is separate from any deployed staging release tree; development and CI must not edit a deployed `current` release in place.

Only reviewed repository workflows may run commands on the runner. Do not add an arbitrary command input, unrestricted SSH bridge, or chat-to-shell workflow merely to make remote execution convenient. A workflow that needs write access, staging/provider mutation, deployment credentials, or another privileged capability must be owned by a bounded Task Contract, scope its `GITHUB_TOKEN` permissions explicitly, and preserve the approval/validation gates appropriate to the risk.

Because a self-hosted runner executes repository-controlled code on an owner-controlled machine, workflow changes and contributors able to influence executed code are part of the runner security boundary. Never execute untrusted fork/PR code with privileged secrets or a write-capable token.

The execution/capability routing for GitHub integration vs Codex workspace vs Actions runner is in `CONTRIBUTING.md`. Staging/secret handling is in `docs/09-deployment-runbook.md`.

## CI tiers

Validation is selected by changed risk surface rather than running every expensive gate for every diff.

### CONTROL CI

CONTROL CI is allowed only for a PR targeting `develop/v1.0.0-completion` when every changed path is in the narrow documentation/governance-only allowlist encoded in `.github/workflows/ci.yml`.

It requires:

1. repository/planning/project-control preflight;
2. secret scan.

Unknown, unclassifiable, or mixed diffs default to FULL CI.

### FULL CI

FULL CI is required when source, routes, bootstrap/config, schema/migrations, tests, Composer/dependency state, PHP/static configuration, CI scripts, Docker/runtime/deployment files, GitHub workflow definitions, or any unknown path changes. It is also mandatory for every PR targeting `main`, every push to `main`, and intentional manual release validation.

Normal FULL CI requires:

1. repository/project-control preflight;
2. secret scan;
3. dependency and license policy;
4. Pint / Composer validation / PHPStan / forbidden-pattern / architecture checks;
5. complete application suite on disposable **MariaDB 10.11** with authenticated Redis.

MariaDB 10.11 is the primary required compatibility target. Other MariaDB lines should remain compatible where practical, but they are not an automatic gate on every PR. Run an additional version when a task changes database-sensitive behavior, when compatibility is specifically being evaluated, or as part of deliberate release/hardening validation.

## Draft and integration behavior

Draft PRs do not automatically consume the self-hosted runner. `ready_for_review` triggers the applicable tier on the current revision.

The long-running integration PR #6 remains Draft during normal Version 1 development. Synchronizing it because an already-reviewed task PR was merged into `develop/v1.0.0-completion` is not, by itself, a reason to run the full suite again. Before final release review, PR #6 is moved to Ready and must pass the applicable release validation.

## Workflow permissions and secrets

Repository-level Actions settings may allow read/write automation and PR creation, but that is only the outer capability ceiling. Each workflow's explicit `permissions:` block is authoritative for its effective `GITHUB_TOKEN` access on that revision.

Current CI/readiness/provider workflows deliberately request read-scoped repository permissions. When a future workflow genuinely needs to mutate GitHub state, grant the narrow job/workflow permission explicitly, for example `contents: write` only for a bounded task that must update a branch, rather than relying on broad repository defaults.

`GITHUB_TOKEN` is repository-scoped; it is not a replacement for arbitrary cross-repository/server credentials.

Secrets are referenced by identifier from GitHub repository/environment storage and are never echoed for discovery. A workflow must validate only whether a required secret is present, then use it through the narrow owning adapter/script. Secret values must not be uploaded in diagnostic artifacts or copied into repository evidence.

Operational secret identifiers, current workflow consumers, reserved interfaces and GitHub Environment boundaries are documented in `docs/09-deployment-runbook.md`.

## Evidence reuse, artifacts, and reruns

A green applicable CI tier is valid evidence for the final tested PR revision while the tested resulting tree remains materially unchanged.

Do not rerun CI merely because that same reviewed content is merged without conflict-resolution/content edits into the unchanged intended base. Revalidate when a base advance, conflict resolution, post-test edit, dependency/runtime change, or other material difference means the previous run no longer proves the resulting tree.

Routine successful PRs use GitHub checks/results as their evidence. Large diagnostic artifacts are uploaded on failure, and intentional `workflow_dispatch`/release runs may retain artifacts when a real reviewer/release consumer needs them. Do not upload artifacts on every successful job merely to manufacture an evidence trail.

Coverage is useful for deliberate quality/release analysis, but generating and storing a coverage report is not required on every normal FULL PR. Manual/release validation may enable it when the report will be used.

For an unchanged revision:

- a clearly transient runner/service/infrastructure failure may rerun only the failed job, or only failed jobs when several failed for the same transient reason;
- already-successful jobs should not be rerun without a concrete reason;
- a deterministic test/static/security failure must be diagnosed and fixed, producing a new revision before validation is attempted again.

A stale, skipped Draft run, queued run, superseded run, cleanup-only run for a non-cleanup change, or failed applicable run is not acceptance evidence.

## Local commands

Install/bootstrap and repository/static policy checks:

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
bash scripts/ci/verify-planning.sh
bash scripts/ci/verify-project-control.sh
php vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer validate --strict --no-check-publish
bash scripts/ci/forbidden-patterns.sh
bash scripts/ci/architecture.sh
composer audit --locked --abandoned=fail
bash scripts/ci/licenses.sh
```

Fast developer feedback uses the PHPUnit configuration's in-memory SQLite defaults:

```bash
composer test:quick
```

This quick path is useful for deterministic local feedback but is not database-engine acceptance evidence.

The default disposable integration environment is MariaDB 10.11 plus authenticated Redis:

```bash
composer test:integration
```

Run another MariaDB line explicitly when compatibility evidence is useful, for example:

```bash
MARIADB_VERSION=11.4 composer test:integration
```

The integration entrypoint exports the same database/Redis connection values used by `docker-compose.ci.yml`, starts disposable dependencies, runs PHPUnit, and tears them down even when the test command fails.

## Test design rules

- MariaDB is required for migrations, constraints, triggers, locking, and concurrency proof; SQLite is not equivalent evidence.
- Redis may be restarted/lost without violating durable correctness.
- Time, randomness, external HTTP, Telegram, SMS, panels, payment providers, and storage destinations should be injectable/deterministic in tests.
- Critical-path tests cover success, validation, authorization, exact replay, conflicting replay, concurrency/database conflict, failure before side effect, uncertainty after possible side effect, and redaction as applicable.
- Add tests because they prove behavior or prevent a material regression, not to satisfy a generic test-count target.
- Monetary tests use integer IRR or fixed-precision decimal, never floating-point money.
- A fake proves local orchestration semantics only; it does not prove a live provider contract.

## Non-negotiable invariant coverage

Tests for the owning feature must prove, when applicable:

- no paid provisioning before authoritative capture;
- duplicate callbacks/jobs/updates/actions create no second durable effect;
- one provider transaction/redeemable value cannot settle twice;
- one paid item cannot create duplicate active remote identity;
- uncertain remote mutation is discovered/reconciled before retry;
- financial posting remains balanced and append-only;
- authorization is checked server-side at execution time;
- secrets/restricted data do not leak through logs, jobs, serialization, or evidence;
- restore/update paths fail closed on tamper or incompatibility when those capabilities are implemented.

## Evidence model

Task-level verification is preserved by the PR, review, applicable workflow result, and retained diagnostic artifact when one is actually needed. Do not commit a new evidence Markdown file for every task.

Repository `evidence/` records are reserved for RC/release summaries that need to outlive workflow retention. A release record may reference exact commit, workflow run, artifact digest, environment, and known limitations without copying raw logs or sensitive payloads.

## Failure policy

Do not suppress, weaken, quarantine, or repeatedly rerun a genuine deterministic failure until green. Diagnose the cause. Financial, authorization, idempotency, schema, security, backup/restore, and release-integrity failures are blocking.
