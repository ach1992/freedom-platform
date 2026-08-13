# Contributing

## Workflow

1. Read `AGENTS.md`, Program Issue `#3`, and the GitHub Issue for the task.
2. Confirm the task's parent phase/dependencies and fetch the current head of `develop/v1.0.0-completion` / Draft PR `#6`.
3. Work on one temporary task branch.
4. Open the PR against `develop/v1.0.0-completion`.
5. Keep the PR Draft while it is changing; mark it Ready only when the intended validation should run.
6. Do not merge your own Worker PR. Delete the temporary branch after integration or cancellation once GitHub preserves the history.

`main` is the release/default branch, not a normal development target. Current project state lives in GitHub; there is no repository status snapshot to synchronize.

Do not insert a human checkpoint between ordinary reversible steps. When the current objective is authorized and READY work exists, continue implementation, targeted validation, PR maintenance, self-review/correction, and dependency-safe follow-on task selection. Stop only for a real blocker/decision/capability boundary or for the specific action that is explicitly approval-gated.

## Task and PR contracts

Use `.github/ISSUE_TEMPLATE/task.yml` for bounded engineering work. The Issue needs enough information to implement and review safely: parent/requirements, outcome, dependencies, bounded scope, objective acceptance criteria, validation strategy, risk, and current state.

Add security/data/financial/provider/schema/runtime/compatibility/protected-area detail only when it matters. Do not create fields or documents merely to say `N/A`, repeat GitHub state, or preserve test logs already available from CI.

Pull requests use `.github/pull_request_template.md`: link the Issue, summarize the change, state material risk/impact, and provide the focused verification or applicable CI result. Record a nonclaim only when adjacent scope could otherwise be misunderstood.

Sensitive paths are assigned in `.github/CODEOWNERS`. CODEOWNERS identifies intended ownership; actual merge enforcement depends on repository protection/rulesets.

High/Critical work involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations requires independent review and explicit Owner approval before merge unless that exact merge/action was already authorized. This gate does not by itself block reversible implementation, testing, review preparation, or continuation to another dependency-safe READY task.

## Merge method

Merge style never substitutes for review or the applicable green CI tier.

- **Squash** documentation, governance, generated/mechanical cleanup, and task branches whose intermediate commits have no durable audit value.
- Preserve multiple implementation commits with **merge** or **rebase** only when those boundaries are intentional, reviewable, and useful for later audit/debugging.
- Never rewrite shared long-lived history merely to make it look tidy.

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

## Local verification

Repository/project-control checks:

```bash
bash scripts/ci/verify-planning.sh
bash scripts/ci/verify-project-control.sh
```

Static/application policy:

```bash
php vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer validate --strict --no-check-publish
bash scripts/ci/forbidden-patterns.sh
bash scripts/ci/architecture.sh
```

Dependencies:

```bash
composer audit --locked --abandoned=fail
bash scripts/ci/licenses.sh
```

Fast local application feedback (SQLite; not database-engine acceptance evidence):

```bash
composer test:quick
```

Primary disposable integration suite (MariaDB 10.11 + authenticated Redis):

```bash
composer test:integration
```

When a task or release needs additional compatibility evidence, override the database version explicitly, for example:

```bash
MARIADB_VERSION=11.4 composer test:integration
```

`composer test:quick` is not acceptance evidence for migrations, constraints, triggers, locking, or concurrency. Those behaviors require MariaDB. MariaDB 10.11 is the mandatory normal CI target; additional versions are compatibility goals, not an automatic per-PR matrix.

## GitHub Actions tiers

The workflow selects the cheapest safe tier and uses only the owner-controlled self-hosted runner.

**CONTROL CI** is valid only when a PR targets `develop/v1.0.0-completion` and every changed path is in the explicit documentation/governance-only allowlist in `.github/workflows/ci.yml`. It runs repository/project-control validation and secret scanning. Any unknown path defaults to FULL.

**FULL CI** runs repository/project-control, secret scan, dependency/license policy, Pint/Composer/PHPStan/forbidden-pattern/architecture checks, and the complete application suite on MariaDB 10.11 with authenticated Redis. Additional MariaDB compatibility runs are intentional task/manual/release checks, not a mandatory matrix on every PR.

Routine successful PR runs rely on workflow/check results as evidence. Diagnostic artifacts are retained on failure, and intentional manual/release runs may retain artifacts when they have a real consumer; do not upload large success artifacts by default merely to create evidence.

Draft PRs do not consume self-hosted-runner jobs automatically. Mark a PR Ready when validation should run. Draft PR `#6` stays quiet while `develop` receives already-reviewed task merges; before final release review it must be intentionally validated.

Do not rerun an unchanged green revision merely because it was merged without content edits into the unchanged intended base. If base/content changes invalidate the result, validate again. For a clearly transient infrastructure failure, rerun only failed work when possible. A deterministic failure must be fixed, not repeatedly rerun.

## Coding and architecture rules

- `declare(strict_types=1);`
- typed PHP and dependency injection
- modular Domain / Application / Infrastructure / Presentation direction
- cross-module orchestration through explicit Application contracts
- no new cross-module Domain dependency/architecture exception merely for convenience
- refactor for cohesion, coupling, reuse, transaction boundaries, testability, or expected change — never because a file is simply large
- integer IRR and fixed-precision crypto; no monetary float
- authorization at execution time
- transactional/idempotent durable effects
- no swallowed `Throwable`
- no direct secrets or sensitive payloads in logs, jobs, events, or evidence
- no editing historical migrations to change already-accepted behavior

## Documentation

Do not create a new document for task status, handoff, risk notes, traceability, or test evidence. Put live state and review evidence in GitHub. Update a canonical document only when a durable product/engineering rule changes.

See `docs/README.md` for the intentionally small documentation set.
