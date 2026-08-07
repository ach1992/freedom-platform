# CI and Quality Gates

**Reviewed:** 2026-08-07  
**Workflow:** `.github/workflows/ci.yml`  
**Runner contract:** `docs/development/ci-runner-contract.md`

## Gate model

CI has two repository modes:

1. **Planning mode** — only when `composer.json` is absent. Application gates are not applicable, but planning and project-control checks still run. This is not application evidence.
2. **Application mode** — `composer.json`, `composer.lock`, `artisan`, mandatory scripts, PHP runtime, dependencies, static gates, MariaDB/Redis tests, and artifacts are required.

The repository is in Application mode. Removing the application manifest, lockfile, or mandatory scripts is a blocking scope regression.

## Exact-head acceptance

Before interpreting CI:

1. fetch PR `#6` and its exact `head_sha`;
2. fetch workflow runs for that SHA;
3. inspect every mandatory job and executable log;
4. verify the checkout corresponds to the intended PR head even when GitHub runs a synthetic merge ref;
5. reject stale, cancelled, queued, skipped, cleanup-only, or superseded runs as evidence.

A green run on a prior head does not validate the current head.

## Mandatory jobs

| Job | Blocking conditions | Primary evidence |
|---|---|---|
| Repository preflight | invalid repository/application state; missing planning/control files; inconsistent status; temporary repair automation; unsafe staging workflow | `preflight-evidence-<run id>` |
| Secret scan | committed secret-like material or scanner failure | job log and scanner artifact/result |
| Dependency and license policy | advisory, abandoned package, invalid lock, unknown/incompatible license | `dependency-evidence-<run id>` |
| PHP static quality | Pint, Composer validation, PHPStan, forbidden-pattern, or architecture failure | `static-evidence-<run id>` |
| MariaDB and Redis tests | service, migration, suite, warning, JUnit, Clover, cleanup, or artifact failure | `test-evidence-<run id>` |

All five jobs are mandatory for an application implementation/evidence head.

## Self-hosted runtime gate

Every PHP job first executes:

```bash
bash scripts/ci/bootstrap-self-hosted-toolchain.sh <coverage|no-coverage>
```

The gate verifies:

- runner PHP `/www/server/php/84/bin/php`;
- CLI configuration `/www/server/php/84/etc/php-cli.ini`;
- PHP `8.4.x` and required extensions;
- Composer `2.10.2`;
- JIT disabled for deterministic CI;
- PCOV enabled only for the coverage job.

Do not replace this with a privileged package/runtime installer. The prior `setup-php` action attempted interactive `sudo` and blocked the self-hosted runner.

## Project-control gate

`bash scripts/ci/verify-project-control.sh` checks:

- mandatory entry/status/contributor/runbook files;
- status JSON/schema parse and fixed repository/phase/boundary constants;
- active handoff existence;
- README entry links;
- superseded labels on historical audits;
- absence of temporary repair workflow/scripts;
- disabled state and inert content of legacy staging workflows;
- guarded, secret-free, read-only staging readiness workflow.

A stale or contradictory control plane is a blocking repository defect because it can cause duplicate, wrong-phase, or unsafe work.

## Dependency installation and policy

Install exact locked dependencies without implicit scripts:

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
```

Then run:

```bash
composer audit --locked --abandoned=fail --format=json
bash scripts/ci/licenses.sh
```

Critical/High reachable advisories block acceptance. Any temporary mitigation requires a risk entry with package/advisory, reachability, control, owner, expiry, and removal plan. A reachable Critical issue is not waivable.

A dependency update is focused to named packages and followed by lock review, static gates, full tests, and artifact inspection. Do not run an unconstrained update to make CI green.

## Static gates

```bash
php vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer validate --strict --no-check-publish
bash scripts/ci/forbidden-patterns.sh
bash scripts/ci/architecture.sh
```

Static scripts prove only the checks they implement. Known gaps are tracked in `docs/31-project-control-plane-audit.md`; CI output must not be described as a complete architecture or security proof.

## Integration and coverage gates

The integration job:

1. starts disposable MariaDB/authenticated Redis from `docker-compose.ci.yml`;
2. installs the exact lockfile;
3. clears Laravel configuration;
4. runs the complete PHPUnit/Laravel suite with warnings treated as failure;
5. requires non-empty JUnit and Clover files;
6. captures sanitized dependency-service logs;
7. always removes containers and volumes.

Mandatory CI environment variables select MariaDB/Redis. SQLite defaults in `phpunit.xml` are not accepted for constraint, trigger, lock, migration, or concurrency evidence.

Coverage mode must report:

```text
ini=/www/server/php/84/etc/php-cli.ini
pcov_loaded=yes
pcov_enabled=1
jit_buffer_size=0
```

## Time and queue bounds

One self-hosted runner executes jobs serially even though the workflow exposes multiple jobs.

- toolchain: 2 minutes;
- dependency install: 10 minutes;
- static policy step: 15 minutes;
- MariaDB/Redis startup: 5 minutes;
- full suite: 30 minutes;
- workflow job bounds are documented in the runner contract.

When a superseded job remains `in_progress` without steps/logs and blocks the queue, inspect the runner service once. Do not create repeated commits or dispatches to unstick it.

## Artifact and evidence contract

Failure artifacts are useful diagnostics but not acceptance evidence. An accepted artifact must contain the expected outputs from an executable successful job, not only cleanup logs produced under `if: always()`.

For every accepted implementation/evidence head record:

- exact SHA;
- workflow run ID/number;
- every mandatory job conclusion;
- test/assertion counts from logs;
- artifact name and ID;
- independent SHA-256;
- content inspection result;
- unsupported claims and remaining risks.

Artifacts are retained at least 30 days during development. Accepted release evidence is referenced from repository evidence and retained according to release policy.

## Local verification

Follow `CONTRIBUTING.md`. The closest host reproduction is:

```bash
docker compose -f docker-compose.ci.yml config --quiet
docker compose -f docker-compose.ci.yml up -d --wait
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
composer validate --strict --no-check-publish
php vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
bash scripts/ci/forbidden-patterns.sh
bash scripts/ci/architecture.sh
bash scripts/ci/verify-project-control.sh
composer audit --locked --abandoned=fail
bash scripts/ci/licenses.sh
php artisan test --display-warnings --fail-on-warning \
  --log-junit build/evidence/tests/junit.xml \
  --coverage-clover build/evidence/coverage/clover.xml
docker compose -f docker-compose.ci.yml down --volumes
```

The GitHub self-hosted run remains the acceptance gate.

## Branch protection and review

For `main`, require:

- PR review by someone other than the author;
- all mandatory CI jobs;
- current branch before merge;
- no force push/deletion;
- resolved conversations;
- signed commits/tags where repository policy supports them.

During current completion work, PR `#6` remains Draft and no merge/auto-merge/Ready action is permitted.

Financial, authorization, installer/updater, backup/restore, staging mutation, and provider changes require specialist review and their phase-specific evidence. CI green does not replace provider contract evidence, staging acceptance, restore rehearsal, update/rollback rehearsal, independent security review, or owner acceptance.
