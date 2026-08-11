# Freedom Platform

Telegram-first commerce and lifecycle-management platform for VPN/proxy subscriptions.

## Default branch landing

`main` is the **release/default branch**. It is intentionally not the active Version 1 development branch, so source and historical documentation on `main` may lag accepted work on the integration line until release.

For current development or project recovery, do **not** infer project state from old files/commits on `main`. Use this order:

1. [Program Issue #3](https://github.com/ach1992/freedom-platform/issues/3) — live Version 1 phase, priority, dependencies, and next work.
2. [Draft integration PR #6](https://github.com/ach1992/freedom-platform/pull/6) — live `develop/v1.0.0-completion` integration line toward `main`.
3. [`README.md` on `develop/v1.0.0-completion`](https://github.com/ach1992/freedom-platform/blob/develop/v1.0.0-completion/README.md) — current project/development entry point.
4. [`AGENTS.md` on `develop/v1.0.0-completion`](https://github.com/ach1992/freedom-platform/blob/develop/v1.0.0-completion/AGENTS.md) — current authority, branch/PR, architecture, safety, and CI rules.
5. [`CONTRIBUTING.md` on `develop/v1.0.0-completion`](https://github.com/ach1992/freedom-platform/blob/develop/v1.0.0-completion/CONTRIBUTING.md) — current setup and verification commands.
6. [`master-execution-prompt.md` on `develop/v1.0.0-completion`](https://github.com/ach1992/freedom-platform/blob/develop/v1.0.0-completion/docs/specification/master-execution-prompt.md) — normative Version 1 product/security/correctness specification.

GitHub is authoritative for mutable task/phase priority, dependencies, blockers, PR/review state, and CI. There is no repository project-status snapshot to synchronize, and Chat history is not required to continue the project.

## Branch model

- `main` — release/default branch; normal feature development does not start here.
- `develop/v1.0.0-completion` — active Version 1 integration branch.
- temporary task branches — branch from the current integration head and target `develop/v1.0.0-completion`.

Draft PR #6 remains the cumulative release path and must not be merged to `main` without explicit Owner release acceptance.

## Default-branch controls

- GitHub Actions execution is owner-controlled self-hosted-only; GitHub-hosted runners are not a fallback.
- Dependabot version-update PRs target `develop/v1.0.0-completion` during Version 1 development.
- GitHub Issue/PR templates on this default branch define the lean risk-based task/review contract used by the repository.
- `AGENTS.md` and `CONTRIBUTING.md` on this branch are guards/routers for zero-context entry; current implementation rules live on the active integration branch linked above.

## Non-negotiable product invariants

- no paid provisioning before authoritative payment capture;
- no duplicate financial or remote effect;
- uncertain external mutation is reconciled before retry;
- database transactions, locks, uniqueness, and immutable history are final correctness barriers;
- browser/customer assertions never prove payment;
- TLS verification is never disabled;
- secrets and sensitive data never enter Git, Issues/PRs, logs, CI artifacts, or repository evidence.

## License

Proprietary. All rights reserved.
