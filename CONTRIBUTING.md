# Contributing

## Workflow

1. Read `PROJECT_STATUS.md`, `AGENTS.md`, and the GitHub Issue for the task.
2. Fetch the current head of `develop/v1.0.0-completion` / Draft PR `#6`.
3. Work on a temporary `agent/<issue-number>-<slug>` branch.
4. Open the PR against `develop/v1.0.0-completion`.
5. Do not merge your own PR. Delete the task branch after integration or cancellation.

`main` is not a development target.

## Task and PR contracts

New bounded engineering work should use `.github/ISSUE_TEMPLATE/task.yml`. The Issue is the live task contract: parent/requirements, goal, dependencies/base rule, in/out scope, protected areas, risk, affected surfaces, verification, merge prerequisites, and blocker/handoff state.

Pull requests use `.github/pull_request_template.md` and must keep their summary, nonclaims, changed surfaces, risk, verification, and review gates explicit. Task state, review reasoning, exact SHAs, and CI runs stay in GitHub rather than new repository handoff/evidence documents.

Sensitive paths are assigned in `.github/CODEOWNERS`. CODEOWNERS identifies the intended reviewer/owner; whether GitHub blocks a merge until code-owner approval depends on repository protection/ruleset settings and must not be assumed from the file alone.

## Risk, state, and labels

Use this small vocabulary when the corresponding repository labels are available:

- risk: `risk:low`, `risk:medium`, `risk:high`, `risk:critical`;
- type: `type:feature`, `type:defect`, `type:security`, `type:governance`, `type:operations`;
- workflow: `state:blocked`, `state:review-ready`, `state:owner-approval`.

High/Critical work involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations requires independent review and explicit Owner approval before merge.

## Merge method

Merge style never substitutes for review or green exact-head CI.

- **Squash** documentation, governance, generated/mechanical cleanup, and task branches whose intermediate commits have no durable audit value.
- Preserve multiple implementation commits with **merge** or **rebase** only when those commit boundaries are intentional, individually reviewable, and useful to future audit/debugging.
- Never rewrite shared long-lived history to tidy it after integration.

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

Fast local application suite (SQLite, deterministic developer feedback only):

```bash
composer test:quick
```

Disposable MariaDB/Redis integration suite (MariaDB 11.4 by default):

```bash
composer test:integration
```

To reproduce the other CI database target locally:

```bash
MARIADB_VERSION=10.11 composer test:integration
```

`composer test:quick` is not acceptance evidence for migrations, constraints, triggers, locking, or concurrency. Those behaviors require MariaDB. Mandatory acceptance is the repository GitHub Actions workflow on the exact PR head, including both MariaDB targets.

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
