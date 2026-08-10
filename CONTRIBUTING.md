# Contributing

Read `AGENTS.md`, `PROJECT_STATUS.md`, `docs/development/multi-agent-orchestration.md`, and `docs/development/github-actions-runner-policy.md` before using this guide.

## Supported contribution model

This repository uses one long-running Draft integration PR plus isolated contracted Worker PRs:

- Version 1 integration branch: `develop/v1.0.0-completion`;
- cumulative integration PR: Draft `#6`, `develop/v1.0.0-completion` -> `main`;
- implementation Worker branches: `agent/<issue-number>-<short-slug>`;
- Worker PR base: `develop/v1.0.0-completion`;
- current active phase tracker: Issue `#8` for Phase `0.5.0`;
- bounded Phase `0.5.0` work is explicitly dispatched under Issue `#8`.

Do not push directly to `main` or `develop/v1.0.0-completion`, rewrite history, force-push, merge your own Worker PR, enable auto-merge, or mark PR `#6` Ready for review. Do not create an uncontracted temporary branch. Existing bootstrap/safety/recovery branches are governed by their documented exception and cleanup conditions.

Every implementation Worker needs a GitHub Task Contract containing its Worker ID, Contract Revision, `BASE_SHA`, branch, dependencies, allowed/protected scope, acceptance criteria, tests, evidence and merge prerequisites. Dynamic assignment state belongs in GitHub, not Chat.

## Prerequisites

For local development, provide:

- Git;
- Docker with Compose;
- PHP 8.4 and required extensions, or the repository CI PHP image;
- Composer 2.10.2;
- MariaDB and Redis only when not using `docker-compose.ci.yml`.

Do not copy production or staging secrets into a local `.env`. Use safe development-only values and fake providers.

## GitHub Actions execution

All repository workflows must run on the owner-controlled `freedom-staging-runner` using:

```yaml
runs-on: [self-hosted, Linux, X64, freedom-staging, php84]
```

Generic CI validates same-repository Worker PRs targeting `develop/v1.0.0-completion` and must remain secret-free/non-mutating. Secret-consuming provider/staging workflows are separate and explicitly guarded. Do not use GitHub-hosted `ubuntu-*`, `windows-*`, or `macos-*` runners as a fallback. If the self-hosted runner is offline, busy, or label-mismatched, diagnose that runner instead. The detailed policy is `docs/development/github-actions-runner-policy.md`; the host/runtime contract is `docs/development/ci-runner-contract.md`.

## Isolated Worker checkout

The MASTER creates the GitHub Worker branch from the Task Contract `BASE_SHA`. Use an isolated worktree or equivalent writable checkout; never share another Worker's working directory.

Example after the remote branch exists:

```bash
git fetch origin --prune
git worktree add ../freedom-platform-worker origin/agent/<issue-number>-<short-slug>
cd ../freedom-platform-worker
git switch --track -c agent/<issue-number>-<short-slug> origin/agent/<issue-number>-<short-slug>
git status --short
git branch --show-current
git rev-parse HEAD
git worktree list
```

The resulting branch and `HEAD` must equal the Task Contract branch and `BASE_SHA` before implementation. If they do not, stop and resolve the environment mismatch; do not rebase or force-push to hide it.

For MASTER-only integration inspection, use a separate clean checkout of `develop/v1.0.0-completion` and always re-fetch PR `#6` before relying on its head.

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

Coverage requires PCOV or another reviewed PHPUnit-compatible driver. Mandatory acceptance remains the self-hosted GitHub Actions run described in `docs/development/ci-runner-contract.md` and must comply with `docs/development/github-actions-runner-policy.md`.

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

Do not edit applied migration history to hide a correction. Add a corrective migration when an independently released boundary could already exist. Two concurrent Workers must not independently claim the same migration sequence or shared critical schema surface; the MASTER must serialize or extract a prerequisite.

## Tests

Tests should identify requirement IDs through names, annotations, or surrounding evidence. Cover applicable normal, replay, conflict, concurrency, authorization, failure, redaction, and compatibility paths.

- Unit tests prove pure domain/value behavior.
- Feature tests prove Laravel wiring and database behavior.
- MariaDB tests prove locks, constraints, triggers, and concurrency.
- Fake adapters prove deterministic orchestration, not real-provider compatibility.
- Contract tests against real provider versions are dated and retained separately.

Do not weaken assertions or skip a gate to accommodate the current implementation.

## Commit and PR discipline

Use focused imperative commit subjects. A commit should have one primary reason to change. Separate implementation from evidence when the exact-SHA lifecycle requires independent verification.

Before pushing:

```bash
git diff --check
git status --short
git diff --stat
```

Review every changed file for generated output, secrets, temporary scripts, accidental broad formatting, and unrelated changes.

A Worker pushes only its assigned branch and opens exactly one PR targeting `develop/v1.0.0-completion`. The PR must reference the Task Contract Issue, Contract Revision, requirements and tests. The Worker never self-merges or retargets the PR to `main`.

## Worker PR and evidence lifecycle

For each verified Worker increment:

1. implementation commit on the Worker branch;
2. same-repository Worker PR to `develop/v1.0.0-completion`;
3. mandatory CI success for the exact implementation head/current merge candidate;
4. exact test/assertion counts;
5. retained artifact ID and independent digest;
6. evidence and traceability commit when required by the increment lifecycle;
7. mandatory CI success for the exact evidence head/current merge candidate;
8. MASTER review of current Contract Revision, exact HEAD, diff, tests, CI, evidence and dependency/conflict state;
9. history-preserving MASTER merge into `develop/v1.0.0-completion` when all gates pass;
10. mandatory integration CI through Draft PR `#6` on the resulting integration head;
11. durable Issue/PR/status updates.

PR `#6` remains Draft until the complete Version 1 release gate, not merely a Worker increment, is accepted. See `docs/development/increment-lifecycle.md` and `docs/development/multi-agent-orchestration.md`.

## Security reporting

Do not open a public Issue containing an exploitable detail or sensitive value. Record a redacted repository risk/evidence item and notify the owner through the approved private channel. Preserve enough information to reproduce safely without disclosing credentials, payloads, customer data, or subscription material.
