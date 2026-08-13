# Contributing

## Workflow

1. Read `AGENTS.md`, Program Issue `#3`, and the GitHub Issue for the task.
2. Confirm the task's parent phase/dependencies and inspect the current head of `develop/v1.0.0-completion` / Draft PR `#6` from GitHub.
3. Work on one temporary task branch unless the active Phase explicitly owns one cumulative implementation branch/PR.
4. Open the PR against `develop/v1.0.0-completion`.
5. Keep the PR Draft while it is changing; mark it Ready only when the intended validation should run.
6. Do not merge your own Worker PR. Delete a temporary branch only after integration/cancellation and after its preservation/safety state is verified.

`main` is the release/default branch, not a normal development target. Current project state and source live in GitHub; there is no Owner-maintained local/server project checkout to synchronize and no repository status snapshot to maintain.

Do not insert a human checkpoint between ordinary reversible steps. When the current objective is authorized and READY work exists, continue implementation, targeted validation, PR maintenance, self-review/correction, and dependency-safe follow-on task selection. Stop only for a real blocker/decision/capability boundary or for the specific action that is explicitly approval-gated.

## Execution boundaries and access routing

The project deliberately separates GitHub control, runtime execution, and deployment operations. GitHub remains the project source of truth in every case.

| Boundary | Use it for | Important behavior |
|---|---|---|
| ChatGPT Master + connected GitHub integration | Normal self-execution: Issues, PRs, refs, repository files, reviews, branch/PR maintenance, and Actions evidence exposed by the connected App | This is the default path. Verify every mutation from live GitHub. It is not an interactive server shell and does not reveal secret values. |
| GitHub Actions self-hosted runner | Authoritative shell/runtime execution, repository CI, MariaDB/Redis integration validation, and reviewed operational/readiness workflows | Workflows create transient checkouts for the exact GitHub revision. They are not a second project source and must not become a generic chat-to-shell interface. |
| External coding/review Worker | Optional delegated implementation/review when isolation, safe parallelism, specialist expertise, or a missing Master capability materially helps | Not a default prerequisite. Durable work must return to GitHub for Master verification. Codex Cloud is only one possible optional Worker, not the normal execution path. |
| Deployment/staging target | Target-like runtime/readiness and explicitly authorized deployment/provider operations | Runtime state is not source state. Never treat a deployed tree as a developer checkout or hidden project copy. |

The self-hosted runner contract is:

```yaml
runner name: freedom-staging-runner
runs-on: [self-hosted, Linux, X64, freedom-staging, php84]
```

Exact CI/runtime requirements are owned by `docs/06-test-strategy.md`. Staging/provider workflow and secret interfaces are owned by `docs/09-deployment-runbook.md`.

### Master self-execution and Worker delegation

The active ChatGPT Master should perform normal reversible READY work itself when the connected GitHub integration and repository-native automation expose the required capability. Do not route broad work to Codex Cloud merely because earlier documentation used it as the default working tree.

Delegate only when there is a concrete benefit: independent review, isolation of risky experiments, safe parallelism, specialist capability, or an execution capability that the Master cannot obtain through GitHub/Actions. A Worker never becomes the project source of truth and never merges its own high-risk work.

### GitHub-native branch cleanup

`.github/workflows/delete-branch.yml` lives on `main` because the default branch owns the repository control entrypoint. It is the accepted automated path for deleting merged temporary task branches.

Supported entrypoints:

1. manual `workflow_dispatch` with `branch_name` and explicit `confirm_delete=true`;
2. owner-only control command on Issue `#105`: `/delete-branch task/<name> CONFIRM`.

The second path exists so the connected ChatGPT Master can request the same guarded operation even when its GitHub connector does not expose workflow dispatch directly. The workflow itself remains authoritative for safety.

The workflow allows only `task/*`, and fails closed for protected branches, any branch participating in an open PR, and any branch whose exact current HEAD is not preserved by a merged PR. The long-lived/default/release branch model is outside the allowed prefix and additionally guarded. It uses repository-scoped `GITHUB_TOKEN` with only the permissions needed to inspect PR state and delete the ref. Do not broaden it to arbitrary branch prefixes, arbitrary commands, or a general-purpose write shell.

An unmerged/abandoned branch may contain unique work and therefore requires separate explicit inspection before any manual destructive cleanup; the automated workflow intentionally refuses it.

### GitHub Actions and repository permissions

Repository-level Actions settings may permit read/write automation, but the workflow YAML for the exact revision is authoritative for each job's effective `GITHUB_TOKEN` scope. Existing CI/readiness/provider workflows intentionally declare narrow permissions. A workflow that genuinely needs mutation must request the smallest explicit permission needed.

Never infer that a job can push merely because repository defaults are permissive, and never weaken a workflow's `permissions:` block just to bypass a missing execution path.

### Secrets

Secret values are write-only operational state. Do not ask the Owner to paste a PAT, SSH key, password, provider key, bot token, `.env`, or other secret into Chat. Existing secret **identifiers**, which workflows consume them, and which are currently reserved/unconsumed are documented in `docs/09-deployment-runbook.md`.

When a workflow says a required secret is missing/invalid, ask the Owner only to create or rotate that exact identifier in GitHub Settings. Do not request its value for debugging.

## Task and PR contracts

Use `.github/ISSUE_TEMPLATE/task.yml` for bounded engineering work. The Issue needs enough information to implement and review safely: parent/requirements, outcome, dependencies, bounded scope, objective acceptance criteria, validation strategy, risk, and current state.

Add security/data/financial/provider/schema/runtime/compatibility/protected-area detail only when it matters. Do not create fields or documents merely to say `N/A`, repeat GitHub state, or preserve test logs already available from CI.

Pull requests use `.github/pull_request_template.md`: link the Issue, summarize the change, state material risk/impact, and provide the focused verification or applicable CI result. Record a nonclaim only when adjacent scope could otherwise be misunderstood.

Sensitive paths are assigned in `.github/CODEOWNERS`. CODEOWNERS identifies intended ownership; actual merge enforcement depends on repository protection/rulesets. Verify live repository settings before relying on protection as an enforced fact.

High/Critical work involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations requires independent review and explicit Owner approval before merge unless that exact merge/action was already authorized. This gate does not by itself block reversible implementation, testing, review preparation, or continuation to another dependency-safe READY task.

## Merge method

Merge style never substitutes for review or the applicable green CI tier.

- **Squash** documentation, governance, generated/mechanical cleanup, and task branches whose intermediate commits have no durable audit value.
- Preserve multiple implementation commits with **merge** or **rebase** only when those boundaries are intentional, reviewable, and useful for later audit/debugging.
- Never rewrite shared long-lived history merely to make it look tidy.

## Reproducible execution prerequisites

These prerequisites describe any reviewed execution environment that needs to run the repository; they are **not** an assumption that the Owner maintains a local checkout.

- Git
- Docker + Compose
- PHP 8.4 with project extensions, or the repository CI PHP environment
- Composer 2.10.x

Never use production/staging secrets or real customer data in a development/test execution environment.

## Bootstrap commands

When a reviewed Actions/Worker environment needs a repository runtime:

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
cp .env.example .env
php artisan key:generate
```

Use development-only database, Redis, Telegram, SMS, panel, and payment values. Prefer deterministic fakes unless the task explicitly owns a controlled integration test.

## Verification commands

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

Fast application feedback (SQLite; not database-engine acceptance evidence):

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
