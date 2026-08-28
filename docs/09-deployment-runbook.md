# Deployment, Backup, Update, and Rollback

Target environment: Ubuntu/aaPanel/OpenLiteSpeed, PHP 8.4, MariaDB, authenticated Redis.

This document is the canonical safety contract for **deployment and privileged/live runtime operations**. It is not the owner of development tooling, self-hosted runner lifecycle, or CI semantics.

- Development execution tools, `AI_Server_Agent`, self-hosted runners and their lifecycle: [`development/execution-infrastructure.md`](development/execution-infrastructure.md).
- Required CI/testing semantics: [`06-test-strategy.md`](06-test-strategy.md).
- Current source/task/PR/run state: live GitHub.

A deployed tree, staging host, persistent execution workspace, or Actions checkout is never a second project source of truth. Execute operational actions only when the owning task/release authorizes them and the implementation exists on the exact GitHub revision.

## GitHub repository Environments

GitHub repository Environments are operational approval/secret boundaries consumed by workflows using `environment:`. They are separate from Actions runner infrastructure and from transient checkouts.

A workflow referencing an Environment does not prove that reviewers, wait timers, or deployment-branch restrictions are configured. Immediately before privileged/live use, verify both the exact workflow source and the live Environment protection settings. Missing/unreadable protection settings must be treated as unknown or absent for the decision that depends on them, not silently assumed safe.

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

## Operational workflow definitions and activation state

Workflow source on the exact revision plus current GitHub registration/state are jointly authoritative. **A workflow file existing only on `develop` or a task branch is code under review, not proof of a standing dispatchable capability.** Historical registry entries whose source is absent from the current default-branch tree are history/navigation only.

Normal repository CI is owned by `.github/workflows/ci.yml` and `docs/06-test-strategy.md`; this runbook does not duplicate its validation tiers or runner selector.

### Staging Readiness

`.github/workflows/staging-readiness.yml` defines a manual read-only runtime readiness path where/when it is registered. It must remain bounded, non-mutating, and must not become a general remote shell or project checkout.

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
- when the generic outbound Telegram delivery authority is present or will be installed, MariaDB is `>=10.11.9` and exposes a non-empty `@@server_uid`; older or identity-ambiguous servers are incompatible with that authority surface;
- the Telegram metadata-attestation account is provisioned through the protected database-administration path as a principal distinct from `DB_USERNAME`, with only global `PROCESS`/`USAGE` and no grant option, role/PUBLIC grant, routine `EXECUTE`, schema/table/column privilege, or application DML/DDL authority;
- `TELEGRAM_METADATA_DB_USERNAME` / `TELEGRAM_METADATA_DB_PASSWORD` and, when needed, `TELEGRAM_METADATA_DB_URL` are supplied through protected deployment secrets and resolve to the exact same MariaDB server as the runtime connection; never reuse the ordinary runtime database credentials for this path;
- before installing/decommissioning the Telegram delivery authority, provision the fixed `telegram_lifecycle` account externally on that same MariaDB server with exactly global `USAGE` plus `SELECT, UPDATE` on the application schema and no `INSERT`, `DELETE`, DDL, `PROCESS`, role/PUBLIC/proxy/routine/grant-option or other privilege; this account must be distinct from both `DB_USERNAME` and the metadata principal;
- the lifecycle grant is intentionally schema-scoped only for `SELECT, UPDATE`: the account must exist before the migration creates `telegram_delivery_authority_capability`, and MariaDB 10.11 rejects table-specific grants for a table that does not yet exist. Do not compensate by granting any additional privilege; the lifecycle secret is short-lived deployment input and the database trigger still limits lifecycle-state transitions to the fixed authenticated principal plus capability secret plus installation-lock ownership;
- inject `TELEGRAM_LIFECYCLE_DB_PASSWORD` only into the protected migration/decommission process. When HTTP installer finalization is used, provide the value only through the dedicated top-level `lifecycle_database_password` input: the finalizer maps it to `TELEGRAM_LIFECYCLE_DB_PASSWORD` for the `migrate` child only and never writes it into the installer `environment` map, `.env`, result contract, command arguments, or later child processes. Missing/wrong-server/same-principal/under-privileged/over-privileged lifecycle authority must fail before Telegram authority DDL or lifecycle state changes;
- `config:clear` and `config:cache` finalization children explicitly remove `TELEGRAM_LIFECYCLE_DB_PASSWORD` even if the installer parent environment contains it. For manual migration/decommission, remove the variable immediately afterward and rebuild any production configuration cache without that secret before starting web/worker/scheduler runtime; never run `config:cache` while the lifecycle password is injected;
- the deterministic Telegram installation `GET_LOCK` is a serialization primitive only, not an identity proof; database lifecycle triggers independently require the authenticated `telegram_lifecycle` username, capability secret and exact lock ownership for activation/deactivation;
- the Telegram authority migration preflight succeeds before any Telegram authority DDL; missing/under-privileged/over-privileged/wrong-server metadata authority is a deployment failure, not a reason to weaken grants or fall back to the runtime principal;
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

For the generic outbound Telegram delivery authority, quiesce queue workers and effect consumers before rollback, but do not rely on quiescence as the only race barrier. The migration-owned rollback path must acquire the capability-row exclusive lifecycle fence, wait out runtime transactions that already hold the shared fence, refuse without deactivation if any operation or `telegram.delivery.requested` Outbox authority then exists, and persist the capability as inactive before destructive DDL. New queue/effect transactions must fail closed after that point; a rollback blocked by an external FK must leave the surviving authority tables guarded and inactive. Do not manually edit the capability row, lifecycle session variables, installation lock, or Telegram Outbox guards to force rollback progress. If durable authority exists, retain the schema and use the reviewed forward-fix/restore decision instead of bypassing the refusal.

### Paid Service package authority migrations

The Service package quote and paid-mutation authority changes are a single financial-schema release unit. Their ordered migrations are [`2026_08_20_000100_enable_service_package_quotes.php`](../database/migrations/2026_08_20_000100_enable_service_package_quotes.php) followed by [`2026_08_20_000110_enable_paid_service_mutation_authority.php`](../database/migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php). Apply them only through the reviewed, exact-release migration mechanism after the release gate in this document has passed. Do not apply either migration ad hoc, out of timestamp order, or against an unknown partial schema.

| Release stage | Required operator evidence and action | Unsafe shortcut prohibited by the contract |
|---|---|---|
| **Preflight** | Confirm exact-revision FULL CI, including MariaDB and Redis, is green; verify that the prerequisite non-paid and operational Service authority migrations are already applied; quiesce workers and entrypoints that can create paid Service operations; take a verified database backup and identify the release owner who can authorize restoration. | Do not infer migration compatibility from a green quick test, a fake provider, or source presence alone. |
| **Apply** | Apply the ordinary timestamped migration sequence while paid mutation creation remains unavailable. The second migration deliberately installs `provisioning_operations_paid_mutation_upgrade_fence` before composing its table, constraints, and guards, and removes that fence only after its final paid-operation insert guard is installed. | Do not manually create, alter, or drop the paid upgrade fence, trigger guards, check constraints, or authority table to accelerate the release. |
| **Postflight** | Verify that the two migrations are recorded, the paid authority table and expected guards exist, `provisioning_operations_paid_mutation_upgrade_fence` is absent, and guarded smoke checks prove that unauthorized paid operations remain rejected. Reopen workers only after those checks succeed and the release owner accepts the result. | Do not reopen paid mutation processing merely because DDL completed; the completed authoritative guard surface is the acceptance condition. |
| **Interrupted or failed apply** | Keep paid creation fail-closed, preserve the database and release diagnostics, and resume only the approved migration path on the same reviewed release revision after establishing the actual schema state. Escalate to restore when the state cannot be proven. | Never delete the fence or run an unreviewed partial rollback to make traffic appear healthy. |

A rollback is exceptional, not a routine release operation. The paid-mutation migration itself refuses rollback while a paid authority row or paid provisioning-operation evidence exists; it also refuses when Service-operation Quote or combined-action pricing/eligibility evidence would be stranded. The preceding quote migration similarly refuses rollback while non-purchase Service-operation Quotes or combined-action evidence exist. When any of those guards reject rollback, retain the current schema, stop unsafe new effects, and use the approved forward-fix or backup/restore decision rather than bypassing the guard. [1] [2]

## Operational evidence

Keep detailed operational evidence in protected target storage. Repository release records contain only sanitized metadata needed for release audit. Never commit credentials, private provider payloads, customer data, subscription URLs, or private backup contents.

[1]: ../database/migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php "Paid Service mutation authority migration"
[2]: ../database/migrations/2026_08_20_000100_enable_service_package_quotes.php "Service package Quote authority migration"
