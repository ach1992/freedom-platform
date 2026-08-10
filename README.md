# Freedom Platform

Production-grade, Telegram-first commerce and lifecycle-management platform for VPN/proxy subscriptions.

## Start here

Before changing the repository, read these sources in order:

1. [`AGENTS.md`](AGENTS.md) — mandatory repository operating contract and authority order.
2. [`PROJECT_STATUS.md`](PROJECT_STATUS.md) — current verified product boundary, active work, blockers, and next sequence.
3. [`docs/project-status.json`](docs/project-status.json) — machine-readable current status.
4. [`docs/README.md`](docs/README.md) — documentation map and freshness rules.
5. [`CONTRIBUTING.md`](CONTRIBUTING.md) — local development, testing, and contribution workflow.
6. [`docs/development/repository-map.md`](docs/development/repository-map.md) — source-code and module navigation.

For live development state, always fetch Draft PR `#6` and use its current `head_sha`. Dynamic state such as active tasks, exact SHAs, CI runs, worker branches, and provider gates belongs in GitHub and `PROJECT_STATUS.md`; it is intentionally not duplicated in this README.

## Authoritative product scope

The normative Version `1.0.0` specification is [`docs/specification/master-execution-prompt.md`](docs/specification/master-execution-prompt.md). Stable requirement IDs are maintained in [`docs/01-authoritative-requirements.md`](docs/01-authoritative-requirements.md).

A lower-authority document, Issue, handoff, or implementation convenience must not silently remove or redefine a Version 1 requirement. Any deliberate scope change must be reconciled explicitly against the normative specification and requirement catalogue.

## Technical baseline

- PHP 8.4 and Laravel 13.x;
- MariaDB with `utf8mb4` as the durable correctness boundary;
- authenticated Redis for queue, cache, rate limiting, and coordination;
- modular-monolith structure with Domain, Application, Infrastructure, and Presentation boundaries;
- Telegram-first product surface with restricted browser endpoints for operational flows;
- integer IRR for fiat financial boundaries and fixed-precision decimal handling for crypto;
- transactional/idempotent financial and remote effects;
- Marzban and PasarGuard behind common panel-adapter contracts;
- Persian-visible default with English fallback and multilingual-ready content;
- aaPanel/OpenLiteSpeed atomic-release target deployment.

Implemented capability is intentionally narrower than the final architecture. Never infer a completed feature from a target-state document, class name, migration, fake adapter, or test fixture alone; accepted capability requires the repository's verification and evidence lifecycle.

## Non-negotiable invariants

- no paid provisioning before authoritative payment capture;
- no duplicate financial, provisioning, Telegram, or remote-provider effect;
- uncertain external mutation results enter lookup/discovery/reconciliation before retry;
- database transactions, locking, uniqueness, and immutable history remain final correctness barriers;
- browser redirects or customer claims never prove capture;
- TLS verification is never disabled;
- secrets and sensitive values are never committed, printed, logged, attached to Issues/PRs, or stored in evidence.

## Verification

The current mandatory CI contract is documented in [`docs/19-ci-quality-gates.md`](docs/19-ci-quality-gates.md) and [`docs/development/ci-runner-contract.md`](docs/development/ci-runner-contract.md). Exact implementation/evidence acceptance rules are in [`docs/development/increment-lifecycle.md`](docs/development/increment-lifecycle.md).

A phase or increment is not complete merely because code or documentation exists. Accepted boundaries require the applicable exact-head CI, tests, retained evidence, review, and integration verification defined by repository governance.

## Documentation and evidence

Use [`docs/README.md`](docs/README.md) to distinguish current sources, stable reference material, target-state documents, traceability, and historical records. Accepted historical proof lives under [`evidence/`](evidence/); it should be preserved for auditability but must not be treated as current runtime state.

## Local development

Follow [`CONTRIBUTING.md`](CONTRIBUTING.md). Do not run staging, deployment, installer, update, restore, provider-live, or root-level automation merely because a script or workflow exists; first verify its current approved status and exact execution boundary.

## License

Proprietary. All rights reserved. No permission is granted to use, copy, modify, or distribute this software except under a separate written agreement with the owner.
