# Freedom Platform

Production-grade Telegram commerce and lifecycle-management platform for VPN/proxy subscriptions.

## Status

Phase `0.1.0` planning is closed and the `0.2.0` Laravel foundation has passed its automated quality gates. A target-like aaPanel/OpenLiteSpeed installation rehearsal is still required before closing `0.2.0`. The authoritative scope is maintained in
[`docs/specification/master-execution-prompt.md`](docs/specification/master-execution-prompt.md).

The repository follows gated semantic phases. No phase is complete until its documented quality gate passes, and no financial or provisioning behavior may be inferred from an incomplete phase.

## Target baseline

- Laravel 13.x on PHP 8.4
- MariaDB with `utf8mb4`
- authenticated Redis for queues, cache, rate limiting, and coordination locks
- Telegram webhook presentation layer
- modular monolith with transactional outbox and database-enforced idempotency
- aaPanel and OpenLiteSpeed atomic-release deployment
- Persian default UI with English fallback

## Non-negotiable invariants

1. No paid service is provisioned before authoritative payment capture.
2. One paid order item creates at most one active remote service identity.
3. Duplicate updates, callbacks, webhooks, retries, and operator actions create no duplicate financial or remote effect.
4. The wallet ledger is append-only and provably balanced.
5. Secrets and sensitive identifiers are never committed or logged.

## Documentation map

Planning, architecture, security, testing, operations, and acceptance artifacts live in [`docs/`](docs/). CI writes transient artifacts to `build/evidence/`; reviewed phase manifests live in `evidence/`; protected staging/production evidence lives under private shared storage. An unsupported claim never replaces evidence.

## Safe local setup

`composer setup:local` is only for a new disposable development checkout. It refuses to run when `.env` already exists. Production installation and upgrades must use the reviewed installer/updater workflows; never run the local setup command on a server.

## License

Proprietary. All rights reserved. No permission is granted to use, copy, modify, or distribute this software except under a separate written agreement with the owner.
