# Contributing

## Workflow

1. Read `PROJECT_STATUS.md`, `AGENTS.md`, and the GitHub Issue for the task.
2. Fetch the current head of `develop/v1.0.0-completion` / Draft PR `#6`.
3. Work on a temporary `agent/<issue-number>-<slug>` branch.
4. Open the PR against `develop/v1.0.0-completion`.
5. Do not merge your own PR. Delete the task branch after integration or cancellation.

`main` is not a development target.

## Local prerequisites

- Git
- Docker + Compose
- PHP 8.4 with project extensions, or the repository CI PHP environment
- Composer 2.10.x

Never use production/staging secrets or real customer data locally.

## Setup

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
cp .env.example .env
php artisan key:generate
```

Use development-only database, Redis, Telegram, SMS, panel, and payment values. Prefer deterministic fakes unless the task explicitly owns a controlled integration test.

## Required checks

Static/policy:

```bash
php vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer validate --strict --no-check-publish
bash scripts/ci/forbidden-patterns.sh
bash scripts/ci/architecture.sh
bash scripts/ci/verify-project-control.sh
```

Dependencies:

```bash
composer audit --locked --abandoned=fail
bash scripts/ci/licenses.sh
```

Application suite:

```bash
docker compose -f docker-compose.ci.yml up -d --wait
php artisan config:clear --ansi
php artisan test --display-warnings --fail-on-warning
docker compose -f docker-compose.ci.yml down --volumes --remove-orphans
```

Isolation-sensitive schema, trigger, locking, and concurrency behavior must be verified on MariaDB, not SQLite. Mandatory acceptance is the repository GitHub Actions workflow on the exact PR head.

## Coding rules

- `declare(strict_types=1);`
- typed PHP and dependency injection
- modular Domain / Application / Infrastructure / Presentation direction
- integer IRR and fixed-precision crypto; no monetary float
- authorization at execution time
- transactional/idempotent durable effects
- no swallowed `Throwable`
- no direct secrets or sensitive payloads in logs, jobs, events, or evidence
- no editing historical migrations to change already-accepted behavior

## Documentation

Do not create a new document for task status, handoff, risk notes, traceability, or test evidence. Put task state and review evidence in the Issue/PR/CI. Update a canonical document only when a durable product/engineering rule changes.

See `docs/README.md` for the intentionally small documentation set.