# Deployment, Backup, Update, and Rollback

Target environment: Ubuntu/aaPanel/OpenLiteSpeed, PHP 8.4, MariaDB, authenticated Redis.

This document is a safety contract, not proof that every described capability currently exists. Execute operational actions only when the owning task/release authorizes them and the implementation exists on the exact GitHub revision.

## Source and execution topology

GitHub is the only project/source-of-truth location assumed by the repository. No Owner-managed local or server checkout is required for development continuity.

- **ChatGPT Master + connected GitHub integration** is the normal project-control and self-execution path for repository state and supported GitHub mutations.
- **GitHub Actions** is the authoritative reviewed shell/runtime path on `freedom-staging-runner` using `[self-hosted, Linux, X64, freedom-staging, php84]`. Workflow checkouts are transient and are not another source repository.
- **External Workers** are optional. Use one only when isolation, safe parallelism, specialist review, or a missing Master capability materially justifies delegation. Codex Cloud is not a required/default workspace. Durable results must return to GitHub.
- **Deployment/staging targets** are runtime infrastructure, not developer checkouts and not project recovery sources.

Use `CONTRIBUTING.md` for capability routing and `docs/06-test-strategy.md` for CI/runtime validation.

## GitHub repository Environments

GitHub repository Environments are operational approval/secret boundaries consumed by workflows using `environment:`. They are separate from the transient Actions checkout and from any optional external Worker environment.

A workflow referencing an Environment does not prove that reviewers, wait timers, or deployment-branch restrictions are configured. Immediately before any privileged/live use, verify both the exact workflow source and the live Environment protection settings. Missing/unreadable protection settings must be treated as unknown or absent for the decision that depends on them, not silently assumed safe.

The guarded provider mutation definition references GitHub Environment `provider-live-acceptance`. Its workflow source and live GitHub settings are jointly authoritative for current branch/approval restrictions.

## Secret and credential rules

Secret values are write-only operational state. Never paste them into Chat, Git, Issues, PRs, logs, screenshots, or repository evidence.

Known workflow/configuration identifiers include:

- `PASARGUARD_TEST_ORIGIN`
- `PASARGUARD_TEST_API_KEY`
- `PASARGUARD_TEST_USERNAME`
- `PASARGUARD_TEST_PASSWORD`
- `STAGING_DOMAIN`
- `STAGING_HOST`
- `STAGING_PORT`
- `STAGING_USER`
- `STAGING_PASSWORD`
- `STAGING_SSH_PRIVATE_KEY`
- `STAGING_KNOWN_HOSTS`
- `TELEGRAM_TEST_BOT_TOKEN`

The current workflow/source revision is authoritative for whether an identifier is actively consumed. A configured identifier that source does not use is reserved/unconsumed, not permission to invent a new execution path.

Prefer repository-scoped `GITHUB_TOKEN` for repository-local Actions operations. Introduce another credential type only when a concrete capability cannot be provided safely by `GITHUB_TOKEN`.

## Workflow definitions and activation state

The paths below are the repository's intended workflow definitions on the revision where they exist. **File existence on `develop` or a task branch is not proof of a currently registered/dispatchable standing workflow.** Before any manual or automated invocation, verify the current default-branch workflow tree, GitHub Actions registration/state, exact source revision, and owning Task/Release authorization.

Historical Actions registry entries whose workflow files are absent from the current default-branch tree are history/navigation, not execution authority.

### CI

`.github/workflows/ci.yml`

- self-hosted-only;
- Draft PRs stay quiet;
- CONTROL tier covers allowlisted documentation/governance-only diffs;
- FULL tier covers source/runtime/workflow/unknown changes and MariaDB 10.11 + authenticated Redis validation;
- the definition supports manual `workflow_dispatch`; use it only when current GitHub registration exposes it and verify the resulting exact `head_sha` before consuming the evidence.

### Staging Readiness

`.github/workflows/staging-readiness.yml` defines a manual read-only runtime readiness path where/when it is registered. It must not become a general remote shell or project checkout.

### Provider Readiness - Read Only

`.github/workflows/provider-readiness.yml` defines the manual read-only PasarGuard readiness path where/when it is registered and uses only the provider test secret identifiers required by that exact workflow revision.

### Provider Live Acceptance - PasarGuard

`.github/workflows/provider-live-acceptance.yml` defines a privileged disposable provider-mutation path where/when it is deliberately registered for a controlled acceptance task. It is not normal CI. The definition uses GitHub Environment `provider-live-acceptance`, explicit confirmation, branch guards, and only the current workflow-defined inputs/credentials.

Do not promote/enable a privileged provider workflow merely to discover whether credentials exist. Do not run a mutation workflow merely to discover whether credentials exist.

## Production layout

A release target should follow the immutable-release pattern:

```text
<root>/
├── releases/<version>/
├── shared/
│   ├── .env
│   ├── storage/
│   ├── backups/
│   └── update-packages/
└── current -> releases/<version>
```

Only `current/public` is web-exposed. Runtime secrets/private storage/backups must remain outside the public root.

## Deployment preflight

Before installation or release activation verify:

- compatible PHP 8.4 runtimes and required extensions;
- MariaDB and authenticated Redis reachability with least privilege;
- filesystem ownership/permissions without `0777`;
- valid HTTPS;
- exactly one Scheduler Cron entry;
- reviewed Supervisor worker configuration;
- no secret is exposed in command arguments, Chat, Git, or screenshots.

## Release gate

Do not deploy unless the exact release candidate has:

- mandatory CI success;
- reviewed migration/schema compatibility;
- verified package integrity metadata;
- applicable install/update/rollback rehearsal;
- current backup/restore confidence;
- no unresolved Critical/High release blocker;
- explicit Owner release approval.

## Package and activation

A release package must contain source, lockfile, migrations, release metadata, compatibility metadata, and integrity material without secrets.

Verify package integrity and compatibility before extraction/activation. Reject path traversal, symlink escape, incompatible runtime/schema, and unverified content.

Use the repository's guarded atomic release-switch implementation when available. Do not replace it with ad-hoc edits to an existing deployed release.

## Scheduler and workers

- exactly one Cron invokes Laravel Scheduler;
- Supervisor manages workers;
- critical queues remain isolated from bulk/report/broadcast work as configured;
- worker timeouts remain below retry-after bounds;
- worker/scheduler health is observable without exposing secrets.

## Backups

Production backups must be consistent, authenticated-encrypted, checksummed/manifested, stored outside the public root, retained according to policy, and periodically restored in an isolated target-like environment.

A backup that has never been restored is not sufficient release evidence.

## Restore

Restore is privileged and explicit. It must validate backup integrity/compatibility, preserve a safety copy before destructive replacement, restore through an isolated/staged boundary where possible, and reconcile financial/remote state before reopening.

Never edit ledger/payment history manually to make a restore appear consistent.

## Update and rollback

An updater must verify package and compatibility before mutation, quiesce unsafe work, create a verified pre-update backup, stage code separately, apply reviewed migrations, run health/smoke checks, and atomically activate.

Database migrations should use expand/contract compatibility. A code rollback is forbidden when the current schema is incompatible with the previous release.

If activation fails, preserve diagnostics, prevent unsafe new effects, and use the guarded rollback/restore path appropriate to the proven schema state. Never run `migrate:rollback` blindly on production.

## Operational evidence

Keep detailed operational evidence in protected target storage. Repository release records contain only sanitized metadata needed for release audit. Never commit credentials, private provider payloads, customer data, subscription URLs, or private backup contents.
