# Freedom Platform

Telegram-first commerce and lifecycle-management platform for VPN/proxy subscriptions.

## Start here

For development or project recovery, use this order:

1. [`AGENTS.md`](AGENTS.md) — repository authority, safety rules, branch/PR policy, and delivery model.
2. [`CONTRIBUTING.md`](CONTRIBUTING.md) — development workflow, Issue/PR/review conventions, and links to the owning execution/validation references.
3. [Program Issue #3](https://github.com/ach1992/freedom-platform/issues/3) — live Version 1 phase/backlog/dependency state.
4. [Draft integration PR #6](https://github.com/ach1992/freedom-platform/pull/6) — live integration head toward `main`.
5. [`docs/README.md`](docs/README.md) — index of durable product, architecture, security, testing, execution-infrastructure, and operations references.

GitHub is authoritative for current phase/task priority, dependencies, blockers, branches, PRs, reviews, CI, repository source, and live runner inventory. Do not maintain repository status or infrastructure-inventory snapshots that duplicate this state. A replacement manager or developer should be able to recover current work from the sources above without chat history.

## Execution model

The project is maintained from GitHub. No Owner-managed local checkout or server checkout is assumed to exist or to contain project truth.

- Durable changes and current delivery state live in GitHub.
- Real runtime/CI commands execute through supported execution capabilities rather than an invented local environment.
- Execution workspaces, runner checkouts, and deployed trees are never alternative project sources of truth.
- Every repository mutation is verified against live GitHub state after the write.
- Branch cleanup remains Owner-operated.

The canonical map for GitHub integration, `AI_Server_Agent`, standard GitHub-hosted CI, optional private/trusted self-hosted runners, external Workers, toolchain ownership, and runner lifecycle is [`docs/development/execution-infrastructure.md`](docs/development/execution-infrastructure.md). Required test/CI semantics are in [`docs/06-test-strategy.md`](docs/06-test-strategy.md); deployment/live operations are in [`docs/09-deployment-runbook.md`](docs/09-deployment-runbook.md).

## Product authority

The Version `1.0.0` product contract is [`docs/specification/master-execution-prompt.md`](docs/specification/master-execution-prompt.md). Stable requirement IDs are indexed in [`docs/01-authoritative-requirements.md`](docs/01-authoritative-requirements.md).

The master specification owns product scope, product/security/correctness invariants, and final acceptance requirements. Repository execution rules are defined by `AGENTS.md`, `CONTRIBUTING.md`, canonical engineering references, and live GitHub state. Historical process instructions must not recreate retired status, traceability, handoff, or per-task evidence documents.

A task, implementation shortcut, old design note, or governance cleanup cannot silently remove or redefine a Version 1 product/security/correctness requirement.

## Technical baseline

- PHP 8.4 / Laravel 13.x
- MariaDB 10.11 as the primary required compatibility/CI target; newer compatible lines are checked when materially useful
- authenticated Redis for queues, cache, throttling, and coordination
- modular monolith with Domain / Application / Infrastructure / Presentation boundaries
- Telegram-first product surface
- integer IRR for fiat; fixed-precision decimal for crypto
- transactional and idempotent financial/remote effects
- Persian default UI with English fallback
- aaPanel/OpenLiteSpeed atomic-release deployment target

## Engineering direction

- optimize for understandable module boundaries and low coupling rather than file-count or line-count targets;
- do not refactor solely because a class/file is large;
- add new capabilities behind the owning module/Application contract instead of spreading cross-module Domain dependencies;
- no new architecture-boundary exception is accepted merely to make a task easier;
- keep task contracts, validation, documentation, and tooling proportional to the value/risk they control; do not create ceremony or duplicated knowledge that slows future work.

## Non-negotiable invariants

- no paid provisioning before authoritative payment capture;
- no duplicate financial, provisioning, Telegram, or provider effect;
- uncertain external mutation is reconciled before retry;
- database transactions, locks, uniqueness, and immutable history are final correctness barriers;
- browser/customer assertions never prove payment;
- TLS verification is never disabled;
- secrets and sensitive data never enter Git, Issues/PRs, logs, CI artifacts, or repository evidence.

## Evidence policy

Git commits, PRs, Issues, reviews, workflow checks, tags, and releases are the history of implementation work. The repository `evidence/` directory is reserved for release-candidate/release records that have a real retention need; it is not a per-task archive.

## License

Proprietary. All rights reserved.
