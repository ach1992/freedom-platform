# Contributing

Read `AGENTS.md` and `PROJECT_STATUS.md` before using this guide.

## Supported contribution model

This repository currently uses one long-running Draft integration PR:

- repository: `ach1992/freedom-platform`;
- branch: `develop/v1.0.0-completion`;
- PR: `#6`;
- base: `main`;
- phase tracker: Issue `#7` while Phase `0.4.0` is active.

Do not create temporary branches, push to `main`, rewrite history, merge, enable auto-merge, or mark PR `#6` Ready for review.

## Prerequisites

For local development, provide:

- Git;
- Docker with Compose;
- PHP 8.4 and required extensions, or the repository CI PHP image;
- Composer 2.10.2;
- MariaDB and Redis only when not using `docker-compose.ci.yml`.

Do not copy production or staging secrets into a local `.env`. Use safe development-only values and fake providers.

## Initial checkout

```bash
git clone <authorized-repository-url> freedom-platform
cd freedom-platform
git switch develop/v1.0.0-completion
git pull --ff-only
```

Verify the branch and status:

```bash
git branch --show-current
git status --short
```

Expected branch: `develop/v1.0.0-completion`. The worktree must be clean before dependency updates, migrations, evidence generation, or release tooling.

## Dependency installation

With a compatible host PHP:

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
```

Using the repository CI image after it has been built:

```bash
bash scripts/ci/run-php-container.sh composer install --no-interaction --prefer-dist --no-progress --no-scripts
bash scripts/ci/run-php-container.sh php artisan package:discover --ansi
```

Do not run an unconstrained `composer update`. A dependency change must be limited to named packages, reviewed in `composer.lock`, audited, license-checked, and verified by the complete suite.

## Environment

For local-only execution:

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with development-only database, Redis, and fake-provider values. Never commit `.env` or paste its contents into an Issue, PR, chat, log, or artifact.

## Required checks

### Static and repository policy

```bash
php vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer validate --strict --no-check-publish
bash scripts/ci/forbidden-patterns.sh
bash scripts/ci/architecture.sh
```

### Dependency policy

```bash
mkdir -p build/evidence/dependencies
composer audit --locked --abandoned=fail --format=json > build/evidence/dependencies/audit.json
bash scripts/ci/licenses.sh
```

### MariaDB and Redis suite

```bash
docker compose -f docker-compose.ci.yml up -d --wait
trap 'docker compose -f docker-compose.ci.yml down --volumes' EXIT

composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
php artisan config:clear --ansi
COLUMNS=240 php artisan test \
  --display-warnings \
  --fail-on-warning \
  --log-junit build/evidence/tests/junit.xml \
  --coverage-clover build/evidence/coverage/clover.xml
```

Coverage requires PCOV or another reviewed PHPUnit-compatible driver. Mandatory acceptance remains the self-hosted GitHub Actions run described in `docs/development/ci-runner-contract.md`.

## Coding rules

- add `declare(strict_types=1);` to PHP source;
- use typed properties, parameters, return values, enums, and value objects;
- store fiat as integer IRR; never use monetary `float`;
- validate input at transport and domain boundaries;
- authorize every action server-side; hidden UI is not authorization;
- use transactions, row locks, unique keys, and guarded transitions for correctness;
- make external effects idempotent or discoverable after uncertain results;
- never disable TLS verification;
- keep user-visible text in localization/content resources;
- never log or serialize secrets or sensitive delivery artifacts;
- avoid direct cross-module Infrastructure imports and undocumented table ownership;
- prefer a small complete change over a broad refactor.

## Database changes

Every migration must:

- be deterministic on MariaDB;
- preserve prior verified data or explicitly fail with a safe diagnostic;
- add foreign keys, unique constraints, checks, and indexes where relational correctness exists;
- avoid destructive rollback assumptions;
- include migration and temporal compatibility tests when changing existing schema;
- document any production backfill or expand/contract requirement.

Do not edit applied migration history to hide a correction. Add a corrective migration when an independently released boundary could already exist.

## Tests

Tests should identify requirement IDs through names, annotations, or surrounding evidence. Cover applicable normal, replay, conflict, concurrency, authorization, failure, redaction, and compatibility paths.

- Unit tests prove pure domain/value behavior.
- Feature tests prove Laravel wiring and database behavior.
- MariaDB tests prove locks, constraints, triggers, and concurrency.
- Fake adapters prove deterministic orchestration, not real-provider compatibility.
- Contract tests against real provider versions are dated and retained separately.

Do not weaken assertions or skip a gate to accommodate the current implementation.

## Commit discipline

Use focused imperative commit subjects, for example:

```text
fix(ci): pin the self-hosted PHP configuration
docs: reconcile Phase 0.4 project status
feat(catalog): reserve trial capacity atomically
test(panels): cover conflicting remote adoption
```

A commit should have one primary reason to change. Separate implementation from evidence when the exact-SHA lifecycle requires independent verification.

Before pushing:

```bash
git diff --check
git status --short
git diff --stat
```

Review every changed file for generated output, secrets, temporary scripts, accidental broad formatting, and unrelated changes.

## Pull request and evidence updates

PR `#6` remains Draft until the complete release gate, not merely the current increment, is accepted.

For each verified increment:

1. implementation commit and exact-SHA green CI;
2. exact test/assertion counts;
3. retained artifact ID and independent digest;
4. evidence and traceability commit;
5. exact evidence-head green CI;
6. Issue and PR status update.

See `docs/development/increment-lifecycle.md` for the complete sequence.

## Security reporting

Do not open a public Issue containing an exploitable detail or sensitive value. Record a redacted repository risk/evidence item and notify the owner through the approved private channel. Preserve enough information to reproduce safely without disclosing credentials, payloads, customer data, or subscription material.
