# Freedom Platform

Production-grade Telegram commerce and lifecycle-management platform for VPN/proxy subscriptions.

## Start here

Every engineer or AI agent must read these files before changing the repository:

1. [`AGENTS.md`](AGENTS.md) — repository operating contract and authority order;
2. [`PROJECT_STATUS.md`](PROJECT_STATUS.md) — current verified boundary, active increment, blockers, and next sequence;
3. [`CONTRIBUTING.md`](CONTRIBUTING.md) — development, testing, and commit workflow;
4. [`docs/development/continuation-runbook.md`](docs/development/continuation-runbook.md) — exact new-chat/recovery procedure;
5. [`docs/development/repository-map.md`](docs/development/repository-map.md) — navigation by task and module.

Do not begin from this README's prose alone. Fetch PR `#6` and treat its exact `head_sha` as live truth.

## Current status

- target release: `1.0.0`;
- active phase: `0.4.0 — Catalog, Panels and Offerings`;
- authoritative Issue: `#7`;
- authoritative PR: `#6`;
- allowed branch: `develop/v1.0.0-completion`;
- PR base/state: `main` / Draft;
- last evidence-complete boundary: Trial Policy and Panel Adapter Offline/Fake Foundation;
- accepted boundary: 298 tests, 1471 assertions on implementation and evidence-head CI;
- active work: source-pinned HTTP adapter contracts for Marzban `v0.8.4` and PasarGuard `v5.2.1`;
- live provider testing: intentionally deferred until the owner supplies dedicated test panels near final integration;
- stabilization: complete; ordinary feature development is not paused.

The normative scope remains [`docs/specification/master-execution-prompt.md`](docs/specification/master-execution-prompt.md). Machine-readable status is in [`docs/project-status.json`](docs/project-status.json). The active provider contract/handoff is [`docs/35-phase-0.4-panel-provider-source-contracts.md`](docs/35-phase-0.4-panel-provider-source-contracts.md).

## Product and architecture baseline

- Laravel 13.x on PHP 8.4;
- MariaDB with `utf8mb4` as durable correctness boundary;
- authenticated Redis for queues, cache, rate limiting, and coordination;
- Telegram-first presentation with limited installer/update/restore/health/provider-callback web routes;
- modular monolith with explicit Domain, Application, Infrastructure, and Presentation boundaries;
- transactional outbox and database-enforced idempotency;
- Marzban and PasarGuard behind a common capability contract;
- aaPanel/OpenLiteSpeed atomic-release target deployment;
- Persian visible default with English fallback and multilingual-ready content.

Some target modules and operational flows are not implemented yet. Architecture documents must not be read as a capability claim without code, tests, and accepted evidence.

## Panel-provider policy

Provider work uses exact source contracts rather than a mutable `latest` assumption:

- Marzban: `Gozargah/Marzban` tag `v0.8.4`;
- PasarGuard: `PasarGuard/panel` tag `v5.2.1`;
- Mirza Bot: secondary practical integration reference only.

Source-contract HTTP tests may be completed without live panels. Real targets remain disabled/fail-closed until final live acceptance. Absence of a temporary panel installation must not block unrelated project phases.

## Non-negotiable invariants

1. No paid service is provisioned before authoritative payment capture.
2. One paid order item creates at most one active remote service identity.
3. Duplicate updates, callbacks, webhooks, retries, and operator actions create no duplicate financial or remote effect.
4. An uncertain remote create enters discovery/reconciliation before any second create.
5. Database transactions, row locks, and uniqueness remain the final correctness barrier.
6. Wallet/financial records are append-only and provably balanced when those phases are implemented.
7. TLS verification is never disabled.
8. Secrets and sensitive identifiers are never committed, printed, or logged.

## Verification model

No phase or increment is complete because source files, migrations, tests, or documentation exist. Acceptance requires:

- mandatory CI success on the exact implementation SHA;
- executable test and assertion counts;
- retained artifact name/ID and independently calculated SHA-256;
- bounded evidence and traceability;
- mandatory CI success on the exact evidence-head SHA;
- Issue/PR updates only after both exact-SHA boundaries pass.

See [`docs/development/increment-lifecycle.md`](docs/development/increment-lifecycle.md).

## Documentation and evidence

- planning/architecture/security/operations: [`docs/`](docs/);
- accepted phase evidence: [`evidence/`](evidence/);
- current audit: [`docs/31-project-control-plane-audit.md`](docs/31-project-control-plane-audit.md);
- active provider handoff: [`docs/35-phase-0.4-panel-provider-source-contracts.md`](docs/35-phase-0.4-panel-provider-source-contracts.md);
- prior Trial/Panel handoff: [`docs/30-phase-0.4-trial-panel-handoff.md`](docs/30-phase-0.4-trial-panel-handoff.md);
- transient CI output: `build/evidence/` and retained GitHub Actions artifacts.

Historical reports are preserved for auditability but must be explicitly marked historical or superseded when they are no longer current.

## Safe local setup

For a disposable development checkout, follow [`CONTRIBUTING.md`](CONTRIBUTING.md). `composer setup:local` refuses to overwrite an existing `.env`.

Never run local setup, staging provisioning, installer, deployment, update, restore, or root-level workflows merely because a command or workflow file exists. Confirm its current approved status and exact environment first.

## License

Proprietary. All rights reserved. No permission is granted to use, copy, modify, or distribute this software except under a separate written agreement with the owner.
