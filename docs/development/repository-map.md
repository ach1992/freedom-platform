# Repository Map

This map helps a new engineer find the current source of truth without reading the full PR history.

## Root control files

| Path | Purpose |
|---|---|
| `AGENTS.md` | mandatory operating contract and source precedence |
| `PROJECT_STATUS.md` | single current human-readable status |
| `docs/project-status.json` | machine-readable current status |
| `CONTRIBUTING.md` | local development, testing, and commit workflow |
| `README.md` | project orientation and supported entry points |
| `composer.json` / `composer.lock` | PHP dependency and command contract |
| `phpstan.neon` | static-analysis scope and level |
| `phpunit.xml` | local test defaults; CI environment overrides database/cache/queue |

## Authoritative specification and planning

| Path | Purpose |
|---|---|
| `docs/specification/master-execution-prompt.md` | normative release specification |
| `docs/00-execution-ledger.md` | phase and verified-boundary ledger |
| `docs/01-authoritative-requirements.md` | stable requirement catalogue |
| `docs/02-requirement-traceability-matrix.md` | requirement → design → code → test → evidence |
| `docs/03-risk-register.md` | open/controlled/closed delivery risks and decisions |
| `docs/04-domain-glossary.md` | canonical domain terms |
| `docs/05-architecture-overview.md` | target architecture and correctness boundaries |
| `docs/06-test-strategy.md` | test layers and evidence policy |
| `docs/07-security-threat-model.md` | threats and required controls |
| `docs/08-data-classification.md` | data sensitivity and handling |
| `docs/09-deployment-runbook.md` | target aaPanel/OpenLiteSpeed deployment procedure |
| `docs/10-release-checklist.md` | release-gate checklist |
| `docs/adr/` | durable architecture decisions |

## Current continuation documents

| Path | Purpose |
|---|---|
| `docs/development/continuation-runbook.md` | exact startup/recovery/handoff sequence |
| `docs/development/ci-runner-contract.md` | self-hosted CI toolchain and evidence contract |
| `docs/development/increment-lifecycle.md` | implementation/evidence exact-SHA lifecycle |
| `docs/development/project-status.schema.json` | schema for machine-readable project status |
| `docs/30-phase-0.4-trial-panel-handoff.md` | active unverified Trial/Panel increment handoff |

## Application modules currently implemented

The project is a Laravel modular monolith. The currently materialized modules are:

| Module | Current responsibility |
|---|---|
| `AccessControl` | administrators, permissions, overrides, sensitive approvals, owner transfer |
| `Agents` | application and profile lifecycle |
| `Catalog` | categories, products, variants, offerings, routes, custom plans, trials |
| `Customers` | account status, tiers, tags, account summaries |
| `Identity` | Telegram identity, phone/contact/OTP, identity items, SMS providers |
| `Installer` | one-time environment/preflight/finalization foundation |
| `Operations` | release activation, health verification, worker heartbeats |
| `Panels` | connections, inventory, capacity, adapter contracts/fakes/shells |
| `Telegram` | webhook ingress, update processing, API adapter, operational commands |
| `Shared` | cross-cutting value objects, idempotency, outbox, redaction, clocks/errors |

Modules described in target architecture but not yet implemented must not be treated as present merely because they appear in the Master Prompt or architecture table.

## Layer layout

Each feature module uses some or all of:

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

Expected direction:

- Domain depends on its own domain and shared abstractions;
- Application orchestrates domain behavior and declared ports;
- Infrastructure implements persistence/provider/framework ports;
- Presentation validates transport input and calls Application services.

The current architecture script enforces only part of this contract. Stronger module graph/table ownership checks are tracked as control-plane work, not silently assumed.

## Database

Migrations live in `database/migrations/` and are chronological foundations/corrections for:

- operations and outbox/idempotency;
- identity, phone, OTP, administrators, agents, and approvals;
- product catalog;
- panel connections, inventory, capacity, and route fallback;
- plan offerings and custom-plan calculation;
- active unverified trial policy/reservation.

Seeders live in `database/seeders/` and currently seed identity/access, catalog access, and panel access foundations.

Database correctness is proven on MariaDB in mandatory CI. SQLite defaults in `phpunit.xml` are only for safe local tests that do not depend on MariaDB semantics.

## Tests

```text
tests/
├── Unit/
│   ├── Deployment/
│   └── Modules/
└── Feature/
```

- `Unit/Modules` covers domain values, adapter contracts, runtime configuration, installer/operations primitives, and fakes.
- `Feature` covers Laravel services, migrations, seeders, MariaDB behavior, Redis behavior, concurrency, and webhook/application flows.
- Active Trial/Panel tests are primarily `TrialPolicyReservationTest`, `TrialPolicyDomainTest`, `PanelAdapterContractTest`, and related Panel DTO/redaction/idempotency tests.

## CI and quality

| Path | Purpose |
|---|---|
| `.github/workflows/ci.yml` | mandatory exact-head CI |
| `scripts/ci/bootstrap-self-hosted-toolchain.sh` | deterministic host PHP/Composer wrapper |
| `scripts/ci/verify-planning.sh` | planning/traceability verification |
| `scripts/ci/forbidden-patterns.sh` | prohibited source patterns |
| `scripts/ci/architecture.sh` | current layer-boundary checks |
| `scripts/ci/licenses.sh` | dependency license inventory/policy |
| `docker-compose.ci.yml` | disposable MariaDB/Redis services |
| `docker/ci/php84.Dockerfile` | optional reproducible PHP CI image foundation |

Temporary repair workflows or generators are never permanent project entry points and must be removed immediately after their exact repair is committed.

## Evidence and phase traceability

- `evidence/<phase>/` contains accepted bounded evidence reports.
- `docs/23-29-*` contain accepted Phase 0.4 traceability increments.
- `docs/30-*` is a handoff, not accepted evidence.
- Issue comments and PR text summarize accepted boundaries but do not replace repository evidence.

## Deployment and staging

| Path | Purpose |
|---|---|
| `deploy/bin/release-switch.php` | guarded atomic release activation primitive |
| `deploy/bin/queue-worker-with-heartbeat.sh` | supervised queue wrapper |
| `deploy/supervisor/freedom-platform.conf` | target Supervisor worker definitions |
| `deploy/staging/` | historical/controlled staging scripts requiring explicit status review |
| `.github/workflows/staging-*.yml` | manually triggered staging workflows; not all are currently approved to run |

Do not run a `staging-*` workflow merely because it exists. Consult the current staging workflow inventory and project status first. Several workflows were created for one-time Phase 0.2 operations and can mutate a remote host as root.

## Where to begin by task

| Task | Start here |
|---|---|
| Continue current feature | `AGENTS.md` → `PROJECT_STATUS.md` → active handoff |
| Diagnose CI | `docs/development/ci-runner-contract.md` → exact-head workflow logs |
| Add a bounded feature | `docs/development/increment-lifecycle.md` → requirement row → module |
| Change database | requirement/ADR → migration tests → MariaDB CI |
| Change external integration | Master Prompt → dated contract note → adapter/fake/contract tests |
| Change deployment | target runbook → release/restore compatibility → staging evidence |
| Understand history | execution ledger → evidence file → phase traceability → Issue comment |
