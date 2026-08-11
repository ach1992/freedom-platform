# Testing and CI Contract

This document defines durable verification requirements. Live run IDs, test counts, artifacts, and current failures belong in GitHub.

## Mandatory CI environment

All executing GitHub Actions jobs run on the owner-controlled self-hosted runner:

```yaml
runs-on: [self-hosted, Linux, X64, freedom-staging, php84]
```

GitHub-hosted runners are not a fallback. The selected host must provide PHP 8.4, Composer 2.10.x, Docker/Compose, Git, Bash, `jq`, and the required PHP extensions. Coverage jobs require PCOV or another explicitly reviewed PHPUnit-compatible driver.

Repository workflows use `scripts/ci/bootstrap-self-hosted-toolchain.sh` to validate the effective runtime. Runner labels alone are not evidence.

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

FULL CI requires:

1. repository/project-control preflight;
2. secret scan;
3. dependency and license policy;
4. Pint / Composer validation / PHPStan / forbidden-pattern / architecture checks;
5. complete application suite on disposable MariaDB 10.11 and authenticated Redis;
6. complete application suite on disposable MariaDB 11.4 and authenticated Redis.

## Draft and integration behavior

Draft PRs do not automatically consume the self-hosted runner. `ready_for_review` triggers the applicable tier on the current revision.

The long-running integration PR #6 remains Draft during normal Version 1 development. Synchronizing it because an already-reviewed Worker PR was merged into `develop/v1.0.0-completion` is not, by itself, a reason to run the full matrix again. Before final release review, PR #6 is moved to Ready and must pass FULL CI.

## Evidence reuse and reruns

A green applicable CI tier is valid evidence for the final tested PR revision while the tested resulting tree remains unchanged.

Do not rerun CI merely because that same reviewed content is merged without conflict-resolution/content edits into the unchanged intended base. Revalidate when a base advance, conflict resolution, post-test edit, dependency/runtime change, or other material difference means the previous run no longer proves the resulting tree.

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

For disposable MariaDB 11.4 plus authenticated Redis:

```bash
composer test:integration
```

For the other CI-supported MariaDB target:

```bash
MARIADB_VERSION=10.11 composer test:integration
```

The integration entrypoint exports the same database/Redis connection values used by `docker-compose.ci.yml`, starts disposable dependencies, runs PHPUnit, and tears them down even when the test command fails.

## Test design rules

- MariaDB is required for migrations, constraints, triggers, locking, and concurrency proof; SQLite is not equivalent evidence.
- Redis may be restarted/lost without violating durable correctness.
- Time, randomness, external HTTP, Telegram, SMS, panels, payment providers, and storage destinations should be injectable/deterministic in tests.
- Critical-path tests cover success, validation, authorization, exact replay, conflicting replay, concurrency/database conflict, failure before side effect, uncertainty after possible side effect, and redaction as applicable.
- Monetary tests use integer IRR or fixed-precision decimal, never floating-point money.
- A fake proves local orchestration semantics only; it does not prove a live provider contract.

## Non-negotiable invariant coverage

Tests for the owning feature must prove:

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

Task-level verification is preserved by the PR, review, applicable workflow run, and GitHub artifacts. Do not commit a new evidence Markdown file for every task.

Repository `evidence/` records are reserved for RC/release summaries that need to outlive workflow artifact retention. A release record may reference exact commit, workflow run, artifact digest, environment, and known limitations without copying raw logs or sensitive payloads.

## Failure policy

Do not suppress, weaken, quarantine, or repeatedly rerun a genuine deterministic failure until green. Diagnose the cause. Financial, authorization, idempotency, schema, security, backup/restore, and release-integrity failures are blocking.
