# Repository Map

Use this map after establishing live state from `AGENTS.md`, `PROJECT_STATUS.md`, and Draft PR `#6`. It describes where code and stable references live; it intentionally does not duplicate current SHAs, Worker assignments, or task status.

## Root entry points

| Path | Purpose |
|---|---|
| `README.md` | stable project orientation |
| `AGENTS.md` | mandatory operating contract and authority order |
| `PROJECT_STATUS.md` | current human-readable project status |
| `docs/project-status.json` | machine-readable project status |
| `docs/README.md` | documentation authority/freshness guide |
| `CONTRIBUTING.md` | local development and contribution workflow |
| `composer.json` / `composer.lock` | PHP dependency and command contract |
| `phpstan.neon` | static-analysis configuration |
| `phpunit.xml` | local test defaults; CI supplies MariaDB/Redis runtime values |

## Product and engineering references

| Path | Purpose |
|---|---|
| `docs/specification/master-execution-prompt.md` | normative Version 1 specification |
| `docs/01-authoritative-requirements.md` | stable requirement catalogue |
| `docs/04-domain-glossary.md` | canonical domain terms |
| `docs/05-architecture-overview.md` | target architecture and correctness boundaries |
| `docs/06-test-strategy.md` | verification strategy |
| `docs/07-security-threat-model.md` | security model |
| `docs/08-data-classification.md` | sensitive-data handling |
| `docs/adr/` | durable architecture decisions |
| `docs/development/increment-lifecycle.md` | exact implementation/evidence lifecycle |
| `docs/development/operational-document-status.md` | executable vs target/historical operations status |

Detailed phase traceability and `evidence/` contain accepted bounded history. They are not current task-state sources.

## Application modules

The project is a Laravel modular monolith. Materialized source modules currently include:

| Module | Responsibility currently represented in source |
|---|---|
| `AccessControl` | administrators, permissions, overrides, sensitive approvals, owner transfer |
| `Agents` | agent application/profile lifecycle and agent-pricing resolution |
| `Catalog` | categories, products, variants, offerings, routes, custom plans, trials |
| `Customers` | account status, tiers, tags, account summaries |
| `Identity` | Telegram identity, phone/contact/OTP, identity items, SMS integration |
| `Installer` | installer preflight/bootstrap/finalization foundations |
| `Operations` | release activation, health verification, worker heartbeat/runtime foundations |
| `Orders` | deterministic immutable Quote/pricing-boundary services; full Order lifecycle is not implied |
| `Panels` | panel connections, inventory, capacity, adapter/provider contracts and implementations/fakes |
| `Payments` | payment foundations including wallet top-up and USDT rate/amount-quote surfaces; full payment-method suite is not implied |
| `Promotions` | promotion rule/reservation and BenefitCodes foundations |
| `Telegram` | webhook ingress, update processing, API adapter, operational commands |
| `Wallet` | immutable ledger, holds, reconciliation, transfer, refund/correction and maintenance foundations |
| `Shared` | cross-cutting values, idempotency, outbox, redaction, clocks/errors |

A module being present does not mean every Version 1 requirement assigned to that domain is complete. Verify accepted behavior through current status, GitHub, tests, and evidence.

## Layer layout

Modules use some or all of:

```text
app/Modules/<Module>/
├── Domain/
├── Application/
│   ├── Contracts/
│   ├── Exceptions/
│   └── Jobs/
├── Infrastructure/
└── Presentation/
    ├── Console/
    └── Http/
```

Intended dependency direction:

- Domain depends on its own domain and approved shared abstractions;
- Application orchestrates domain behavior through declared contracts;
- Infrastructure implements persistence/provider/framework ports;
- Presentation validates transport input and calls Application services.

Repository automation enforces only the currently implemented subset of these architecture rules. Do not assume a target rule is already machine-enforced.

## Database

- migrations: `database/migrations/`;
- seeders: `database/seeders/`;
- MariaDB is the mandatory database-correctness environment in CI;
- accepted historical migrations are forward-only history and should not be edited merely to tidy the tree;
- financial/audit correctness depends on transactions, locking, uniqueness, checks, and immutable/compensating history as defined by the owning module.

## Tests

```text
tests/
├── Unit/
├── Feature/
├── Fixtures/
└── Support/
```

- `Unit` covers value objects, contracts, adapters and isolated runtime behavior;
- `Feature` covers Laravel services, migrations/seeders, MariaDB/Redis semantics, authorization, replay, concurrency and integration flows;
- `Fixtures` stores deterministic external-contract fixtures;
- `Support` contains shared test builders/helpers.

The exact current suite size belongs in CI/status records, not in this map.

## CI and operations

| Path | Purpose |
|---|---|
| `.github/workflows/ci.yml` | mandatory repository CI |
| `scripts/ci/` | repository/static/security/control verification helpers |
| `docker-compose.ci.yml` | disposable MariaDB/Redis CI dependencies |
| `docker/ci/php84.Dockerfile` | optional reproducible PHP CI image foundation |
| `deploy/bin/` | guarded deployment/runtime primitives |
| `deploy/supervisor/` | Supervisor templates |
| `deploy/staging/` | staging-specific scripts; verify current status before use |
| `.github/workflows/staging-*.yml` | staging workflows with mixed historical/current status; consult operational inventory first |

Never execute deployment/provider/staging automation solely because the file exists.

## Where to begin by task

| Task | Start here |
|---|---|
| Understand current project state | `AGENTS.md` -> `PROJECT_STATUS.md` -> live PR `#6` / relevant Issues |
| Understand documentation | `docs/README.md` |
| Add/change product behavior | requirement ID -> owning module -> tests/evidence -> `increment-lifecycle.md` |
| Change database | owning requirement/ADR -> current schema -> new forward migration -> MariaDB tests |
| Change external integration | normative requirement -> dated official/installed contract -> adapter/fake/contract tests |
| Diagnose CI | exact-head workflow/jobs/logs -> `development/ci-runner-contract.md` |
| Change operations/deployment | `development/operational-document-status.md` -> exact script/workflow -> relevant runbook/evidence |
| Understand historical proof | `evidence/<phase>/` and the bounded traceability document referenced by that evidence |
| Use parallel Workers | `development/multi-agent-orchestration.md` only after live task/dependency reconstruction |
