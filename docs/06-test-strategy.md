# Testing and CI Contract

This document defines durable verification requirements. Live run IDs, test counts, artifacts, current failures, runner availability, and repository setting state belong in GitHub.

## Execution model

GitHub is the project source of truth. No Owner-maintained local or server checkout is assumed.

The active ChatGPT Master normally self-executes repository work through the connected GitHub integration. Runtime commands execute through reviewed GitHub Actions on the owner-controlled self-hosted runner. External Workers are optional when isolation, parallelism, specialist review, or a missing capability materially justifies delegation; Codex Cloud is not a required/default execution path.

An Actions checkout is transient execution state for an exact GitHub revision, not a second source repository.

## Mandatory CI environment

Every executing GitHub Actions job uses:

```yaml
runs-on: [self-hosted, Linux, X64, freedom-staging, php84]
```

The runner must provide PHP 8.4, Composer 2.10.x, Docker/Compose, Git, Bash, `jq`, required PHP extensions, and a compatible coverage driver when coverage is intentionally requested. Repository workflows validate the effective toolchain; runner labels alone are not evidence.

GitHub-hosted runners are not a fallback.

## Using the runner

- A non-Draft PR targeting `develop/v1.0.0-completion` triggers the applicable CI tier.
- Draft PRs stay quiet until marked Ready for review.
- `.github/workflows/ci.yml` supports intentional manual validation through `workflow_dispatch`.
- Runtime/readiness/provider workflows remain narrow operational entrypoints and are not substitutes for normal CI.

If the Master cannot execute MariaDB/Docker/shell work directly, that is not itself a blocker. Persist reversible work on GitHub and use the self-hosted Actions path for authoritative runtime evidence. Delegate to an external Worker only when that materially improves execution or review.

## CI tiers

### CONTROL CI

CONTROL CI is allowed only for a PR targeting `develop/v1.0.0-completion` when every changed path is in the documentation/governance-only allowlist encoded in `.github/workflows/ci.yml`.

It requires:

1. repository/planning/project-control preflight;
2. secret scan.

Unknown, unclassifiable, or mixed diffs default to FULL CI.

### FULL CI

FULL CI is required for source, routes, bootstrap/config, schema/migrations, tests, dependencies, static/CI tooling, Docker/runtime/deployment/workflow changes, unknown paths, every PR targeting `main`, every push to `main`, and intentional release validation.

Normal FULL CI requires:

1. repository/project-control preflight;
2. secret scan;
3. dependency and license policy;
4. Pint / Composer validation / PHPStan / forbidden-pattern / architecture checks;
5. the complete application suite on disposable MariaDB 10.11 with authenticated Redis.

MariaDB 10.11 is the primary required compatibility target. Other compatible MariaDB lines are explicit task/release compatibility evidence, not an automatic matrix on every PR.

## Workflow permissions

Each workflow's explicit `permissions:` block is authoritative for its `GITHUB_TOKEN` access. Grant only the capability the workflow requires. Repository-local automation should prefer repository-scoped `GITHUB_TOKEN`; do not introduce a PAT merely to replace a capability that `GITHUB_TOKEN` already provides.

## Evidence reuse and reruns

A green applicable CI tier proves the tested PR revision while the resulting tree remains materially unchanged. Revalidate after a base advance, conflict resolution, post-test edit, dependency/runtime change, or other material difference.

For an unchanged revision:

- rerun a clearly transient failed job only when appropriate;
- do not rerun successful jobs without a concrete reason;
- fix deterministic failures instead of repeatedly rerunning them.

Routine successful checks are GitHub evidence. Do not manufacture per-task evidence files or large success artifacts.

## Reproducible verification commands

These commands may run in a reviewed Actions checkout or an explicitly delegated Worker environment. They do not imply an Owner local checkout.

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
composer test:quick
composer test:integration
```

Additional MariaDB compatibility can be requested explicitly, for example:

```bash
MARIADB_VERSION=11.4 composer test:integration
```

`composer test:quick` is fast feedback only. MariaDB is required for migrations, constraints, triggers, locking, and concurrency acceptance.

## Test design rules

- Redis coordination loss must not violate durable correctness.
- Time, randomness, external I/O, and providers should be deterministic/injectable in tests.
- Critical paths cover success, validation, authorization, exact replay, conflicting replay, concurrency/database conflict, and failure/uncertainty boundaries as applicable.
- Monetary tests use integer IRR or fixed-precision decimal, never floating-point money.
- Fakes prove local orchestration semantics only; they do not prove live provider compatibility.

## Required invariant coverage

When applicable, tests must prove:

- no paid provisioning before authoritative capture;
- duplicate callbacks/jobs/actions create no second durable effect;
- one provider transaction/redeemable value cannot settle twice;
- uncertain remote mutation is reconciled before retry;
- financial posting remains balanced and append-only;
- authorization is checked server-side at execution time;
- restricted data is not exposed through repository evidence.

## Failure policy

Do not suppress, weaken, quarantine, or repeatedly rerun a genuine deterministic failure until green. Financial, authorization, idempotency, schema, security, backup/restore, and release-integrity failures are blocking.
