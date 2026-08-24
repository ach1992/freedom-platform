# Repository Map

This map is navigation only. It does not contain live task, branch, CI, runner, host, or implementation-status data.

## Entry points

| Path / source | Purpose |
|---|---|
| `README.md` | project orientation and zero-context recovery path |
| `AGENTS.md` | repository operating, architecture, safety, and authority rules |
| `CONTRIBUTING.md` | development/Issue/PR/review workflow |
| GitHub Program Issue `#3` | live phase/backlog/dependency state |
| GitHub Draft PR `#6` | live Version 1 integration line toward `main` |
| `docs/README.md` | canonical documentation index and ownership map |
| `composer.json` / `composer.lock` | PHP dependency/command contract |
| `phpstan.neon` | static-analysis configuration |
| `phpunit.xml` | test configuration defaults |

Live task/PR/CI/runner state is in GitHub. The repository intentionally has no mutable project-status or infrastructure-inventory snapshot.

## Canonical references

- `docs/specification/master-execution-prompt.md` — normative Version 1 product/security/correctness contract
- `docs/01-authoritative-requirements.md` — stable requirement IDs
- `docs/03-risk-register.md` — durable cross-project risks/owner decisions
- `docs/04-domain-glossary.md` — canonical domain terminology
- `docs/05-architecture-overview.md` — architecture/correctness boundaries
- `docs/06-test-strategy.md` — tests, CI semantics, evidence, and compatibility contract
- `docs/07-security-threat-model.md` — security controls
- `docs/08-data-classification.md` — sensitive-data handling
- `docs/09-deployment-runbook.md` — deployment/backup/update/rollback safety contract
- `docs/development/execution-infrastructure.md` — development execution tools, capability routing, toolchain ownership, self-hosted runner lifecycle/qualification
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
| `docs/development/execution-infrastructure.md` | where/how development work runs; self-hosted runner lifecycle and capability qualification |
| `.github/workflows/ci.yml` | risk-based repository CI on owner-controlled self-hosted runners |
| `.github/workflows/` | controlled workflow definitions; verify current default-branch registration and live GitHub state before treating one as callable |
| `scripts/ci/bootstrap-self-hosted-toolchain.sh` | executable PHP/Composer runner toolchain contract |
| `scripts/ci/` | CI classification, static/security/project-control checks |
| `docker-compose.ci.yml` | disposable MariaDB/Redis test dependencies |
| `deploy/bin/` | guarded runtime/deployment primitives |
| `deploy/supervisor/` | Supervisor templates |

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
