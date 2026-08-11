# Freedom Platform

Telegram-first commerce and lifecycle-management platform for VPN/proxy subscriptions.

## Start here

For development, read only:

1. [`PROJECT_STATUS.md`](PROJECT_STATUS.md) — current phase and where live work is tracked.
2. [`AGENTS.md`](AGENTS.md) — repository rules, safety invariants, and branch/PR policy.
3. [`CONTRIBUTING.md`](CONTRIBUTING.md) — local setup and verification commands.
4. [`docs/README.md`](docs/README.md) — index of durable product and engineering references.

Live task, branch, PR, review, and CI state is authoritative in GitHub. Do not infer current work from historical commits or old documents.

## Product authority

The Version `1.0.0` product contract is [`docs/specification/master-execution-prompt.md`](docs/specification/master-execution-prompt.md). Stable requirement IDs are in [`docs/01-authoritative-requirements.md`](docs/01-authoritative-requirements.md).

A task, implementation shortcut, or old design note cannot silently remove or redefine a Version 1 requirement.

## Technical baseline

- PHP 8.4 / Laravel 13.x
- MariaDB as the durable correctness boundary
- authenticated Redis for queues, cache, throttling, and coordination
- modular monolith with Domain / Application / Infrastructure / Presentation boundaries
- Telegram-first product surface
- integer IRR for fiat; fixed-precision decimal for crypto
- transactional and idempotent financial/remote effects
- Persian default UI with English fallback
- aaPanel/OpenLiteSpeed atomic-release deployment target

## Non-negotiable invariants

- no paid provisioning before authoritative payment capture;
- no duplicate financial, provisioning, Telegram, or provider effect;
- uncertain external mutation is reconciled before retry;
- database transactions, locks, uniqueness, and immutable history are final correctness barriers;
- browser/customer assertions never prove payment;
- TLS verification is never disabled;
- secrets and sensitive data never enter Git, Issues/PRs, logs, CI artifacts, or repository evidence.

## Evidence policy

Git commits, merged PRs, Issues, reviews, and GitHub Actions are the history of implementation work. The repository `evidence/` directory is reserved for release-candidate/release records only; it is not a per-task archive.

## License

Proprietary. All rights reserved.