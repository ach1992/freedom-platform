# Repository Map

This file is source/module/runtime navigation only. Project recovery order is owned by [`../../README.md`](../../README.md); durable documentation ownership/indexing is owned by [`../README.md`](../README.md). Do not duplicate those lists here.

Live task, branch, CI, runner, host, review, and implementation-status data belongs in GitHub or the system that owns it.

## Application source

```text
app/
├── Modules/
│   ├── AccessControl/
│   ├── Agents/
│   ├── Catalog/
│   ├── Customers/
│   ├── Identity/
│   ├── Installer/
│   ├── Operations/
│   ├── Orders/
│   ├── Panels/
│   ├── Payments/
│   ├── Promotions/
│   ├── Provisioning/
│   ├── Telegram/
│   └── Wallet/
└── Shared/
```

Feature modules use Domain / Application / Infrastructure / Presentation layers where applicable. `App\Shared` contains reviewed cross-cutting primitives.

A module directory does not imply every Version 1 requirement for that domain is complete. Do not add cross-module Domain coupling merely to avoid defining a stable Application contract or module-local type.

## Database

- migrations: `database/migrations/`
- seeders: `database/seeders/`
- historical accepted migrations are forward history; add new migrations instead of rewriting them
- MariaDB is required for schema, trigger, lock, and concurrency correctness evidence
- MariaDB 10.11 is the primary required CI/runtime-compatibility baseline; other supported lines are compatibility targets when materially useful

## Tests

```text
tests/
├── Unit/
├── Feature/
├── Fixtures/
└── Support/
```

Use deterministic fixtures/fakes for unavailable external systems. Real provider compatibility requires a separately controlled contract/acceptance boundary.

## CI and runtime operations

| Path | Purpose |
|---|---|
| `docs/development/execution-infrastructure.md` | where/how development work runs; hosted-CI boundary plus optional self-hosted runner lifecycle and capability qualification |
| `docs/06-test-strategy.md` | validation semantics, evidence freshness and CI failure policy |
| `.github/workflows/ci.yml` | risk-based repository CI on standard GitHub-hosted Linux runners |
| `.github/workflows/` | controlled workflow definitions; verify current default-branch registration and live GitHub state before treating one as callable |
| `scripts/ci/bootstrap-ci-toolchain.sh` | runner-neutral executable PHP/Composer toolchain contract |
| `scripts/ci/` | CI classification, static/security/project-control checks |
| `docker-compose.ci.yml` | disposable MariaDB/Redis test dependencies |
| `deploy/bin/` | guarded runtime/deployment primitives |
| `deploy/supervisor/` | Supervisor templates |
| `docs/09-deployment-runbook.md` | privileged deployment/backup/update/rollback safety contract |

A workflow definition staged on an integration/task branch is code under review, not proof of a standing execution entrypoint. Historical Actions registry entries whose files are absent from the current default-branch tree are navigation/history only. Retired staging bootstrap/mutation automation is Git history, not an active repository path or implementation template. Never execute an operational file solely because it exists.

## Task navigation

- Product behavior: requirement ID -> GitHub task -> owning module -> relevant tests.
- Project state: Program `#3` -> active phase Issue -> specific task/PR -> exact-head checks.
- Execution/tool/runner question: `docs/development/execution-infrastructure.md` -> exact current workflow/source/live GitHub state as needed.
- Validation question: `docs/06-test-strategy.md` -> current classifier/workflow/check evidence.
- Database change: owning module -> current schema -> new forward migration -> MariaDB tests.
- External integration: normative requirement -> current official/installed contract -> adapter/fake/contract tests.
- CI failure: exact PR head -> workflow/job/log -> relevant validation or runner-capability owner.
- Deployment/release: exact release task -> `docs/09-deployment-runbook.md` -> actual source/tool at that commit.
- Historical task context: Git commits, merged PRs, Issues, reviews, and workflow history.
