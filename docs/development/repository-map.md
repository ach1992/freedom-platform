# Repository Map

This map is navigation only. It does not contain live task, branch, CI, or implementation-status data.

## Entry points

| Path | Purpose |
|---|---|
| `README.md` | project orientation |
| `PROJECT_STATUS.md` | current phase-level boundary |
| `AGENTS.md` | repository operating and safety rules |
| `CONTRIBUTING.md` | local setup and verification workflow |
| `docs/README.md` | canonical documentation index |
| `composer.json` / `composer.lock` | PHP dependency/command contract |
| `phpstan.neon` | static-analysis configuration |
| `phpunit.xml` | test configuration defaults |

Live task/PR/CI state is in GitHub.

## Canonical references

- `docs/specification/master-execution-prompt.md` — normative Version 1 product contract
- `docs/01-authoritative-requirements.md` — stable requirement IDs
- `docs/03-risk-register.md` — durable cross-project risks/owner decisions
- `docs/04-domain-glossary.md` — canonical domain terminology
- `docs/05-architecture-overview.md` — architecture/correctness boundaries
- `docs/06-test-strategy.md` — tests, CI, and runner contract
- `docs/07-security-threat-model.md` — security controls
- `docs/08-data-classification.md` — sensitive-data handling
- `docs/09-deployment-runbook.md` — deployment/backup/update/rollback safety contract
- `docs/adr/` — durable architecture decisions
- `evidence/README.md` — release-evidence policy

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
│   ├── Telegram/
│   └── Wallet/
└── Shared/
```

Feature modules use Domain / Application / Infrastructure / Presentation layers where applicable. `App\Shared` contains reviewed cross-cutting primitives.

A module directory does not imply every Version 1 requirement for that domain is complete.

## Database

- migrations: `database/migrations/`
- seeders: `database/seeders/`
- historical accepted migrations are forward history; add new migrations instead of rewriting them
- MariaDB is required for schema, trigger, lock, and concurrency correctness evidence

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
| `.github/workflows/ci.yml` | mandatory repository CI |
| `.github/workflows/` | controlled workflow definitions; inspect before executing |
| `scripts/ci/` | static/security/project-control checks |
| `docker-compose.ci.yml` | disposable MariaDB/Redis test dependencies |
| `deploy/bin/` | guarded runtime/deployment primitives |
| `deploy/supervisor/` | Supervisor templates |

Retired staging bootstrap/mutation automation is Git history, not an active repository path or implementation template.

Never execute an operational file solely because it exists.

## Task navigation

- Product behavior: requirement ID -> GitHub task -> owning module -> relevant tests.
- Database change: owning module -> current schema -> new forward migration -> MariaDB tests.
- External integration: normative requirement -> current official/installed contract -> adapter/fake/contract tests.
- CI failure: exact PR head -> workflow/job/log -> relevant local gate.
- Deployment/release: exact release task -> `docs/09-deployment-runbook.md` -> actual source/tool at that commit.
- Historical task context: Git commits, merged PRs, Issues, reviews, and workflow history.