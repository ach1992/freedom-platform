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

Workflow source on the exact revision plus current GitHub registration/state are jointly authoritative. **A workflow file existing only on a non-default task branch is code under review, not proof of a standing dispatchable capability.** Historical registry entries whose source is absent from the current default-branch tree are history/navigation only.

Normal repository CI is owned by `.github/workflows/ci.yml` and `docs/06-test-strategy.md`; this runbook does not duplicate its validation tiers or runner selector.

### Staging Readiness

`.github/workflows/staging-readiness-runtime.yml` defines the read-only runtime readiness path where/when a trusted staging runner is registered. It is triggered only through the `staging_readiness` repository-dispatch event, so GitHub sources the workflow and ref from the default branch instead of a caller-selected branch/tag. The dispatch payload must include `confirmation=READ_ONLY_STAGING_CHECK`; do not add `workflow_dispatch` or another branch-selectable trigger. The workflow must remain bounded, non-mutating, and must not become a general remote shell or project checkout.

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
- `config:clear` and `config:cache` finalization children explicitly remove `TELEGRAM_LIFECYCLE_DB_PASSWORD` even if the installer parent environment contains it. Installer environment persistence also fails before snapshot/write/migration when the existing application `.env` contains any non-empty configured lifecycle password (including a later duplicate after an empty placeholder); remove that stale deployment-only value first. For manual migration/decommission, remove the variable immediately afterward and rebuild any production configuration cache without that secret before starting web/worker/scheduler runtime; never run `config:cache` while the lifecycle password is injected;
- the deterministic Telegram installation `GET_LOCK` is a serialization primitive only, not an identity proof; database lifecycle triggers independently require the authenticated `telegram_lifecycle` username, capability secret and exact lock ownership for activation/deactivation;
- the Telegram authority migration preflight succeeds before any Telegram authority DDL; missing/under-privileged/over-privileged/wrong-server metadata authority is a deployment failure, not a reason to weaken grants or fall back to the runtime principal;
- the ordinary application/migration database principal (`DB_USERNAME`) must retain the schema-local `LOCK TABLES` privilege in addition to the DDL authority already required to install/remove this migration. The exact decommission session must also report `@@SESSION.innodb_table_locks = 1`; the migration re-attests that dynamic prerequisite immediately before relying on `LOCK TABLES`. Galera-configured MariaDB is unsupported for this reference-fence primitive and must fail closed before lifecycle deactivation/reference-fence DDL when the exact session has `wsrep_on` enabled or a non-`none` Galera `wsrep_provider` is loaded. Decommission uses the lock only on the two Telegram authority tables to keep competing parent DDL excluded while an atomic no-reference-index barrier is attested and dropped; no global `RELOAD`/backup-lock privilege is required. Missing `LOCK TABLES`, disabled InnoDB table locking, or enabled Galera/wsrep fails closed before the reference-fenced `DROP`; do not bypass these checks;
- filesystem ownership/permissions without `0777`;
- valid HTTPS;
- exactly one Scheduler Cron entry;
- reviewed Supervisor worker configuration;
- `php artisan health:check --critical --json --redact` passes on the candidate runtime before protected work is reopened. This shared readiness path fails closed on incompatible MariaDB family/version/`@@server_uid`, connection charset/collation/strict-mode drift, missing authentication on required Redis connections, Redis queue `after_commit` drift, or `retry_after` that does not exceed every reviewed Supervisor worker timeout; the atomic release-switch consumes this same check after activation;
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

The Phase 0.8 backup authority is disabled by default. When a reviewed deployment deliberately enables it:

- set `BACKUP_ROOT` to protected persistent storage outside `current/` and the web root (normally `<root>/shared/backups`), with the runtime account able to create `0700` directories and `0600` artifacts;
- provision `BACKUP_ENCRYPTION_KEY` as a dedicated base64-encoded 32-byte random secret through the protected deployment secret path. It is independent of `APP_KEY`; retain the exact key for every backup that may still need restore and never log or commit it;
- `BACKUP_DATABASE_INTERVAL_MINUTES` must divide 60; `BACKUP_DAILY_TIME` is UTC `HH:MM`; retention is controlled by `BACKUP_RETENTION_DAYS`; `BACKUP_PRIORITY_LOCK_WAIT_SECONDS` bounds how long daily-full/pre-update backups wait for an already-running frequent backup while preventing new frequent work from entering ahead of them; the existing single Laravel Scheduler Cron owns both schedules when `BACKUP_ENABLED=true`;
- `php artisan operations:backup --kind=frequent_database --json` creates the database-only artifact; `--kind=daily_full` also captures configured environment/private storage; `--kind=pre_update` is the explicit hook consumed by the later updater authority;
- a completed backup is the encrypted `.fbk` plus its final `.manifest.json`. The manifest is published last; an artifact without its manifest is incomplete and is recovered on the next backup run. Never treat work-directory/intermediate files as backup evidence;
- MariaDB credentials are supplied to `mariadb-dump` through a mode-`0600` temporary client option file passed as the first `--defaults-file` option, isolating the dump from ambient system/user MariaDB option files; credentials never enter argv, and successful/failed runs remove authority-owned plaintext work state.

Optional Telegram export is a secondary copy, not the local backup authority. Enable it separately with `BACKUP_TELEGRAM_EXPORT_ENABLED=true`. `BACKUP_TELEGRAM_PART_BYTES` is capped at 20,000,000 bytes, which remains within BAK-001's `<=45 MB` requirement while reusing the existing protected Telegram document boundary. `php artisan operations:backup:telegram-export <backup-id> --json` only queues an integrity-bound manifest plus encrypted artifact-part references through the existing durable Telegram Outbox; it does not bypass rate/retry/uncertain-delivery handling. At provider time, each part is re-read from the completed artifact and SHA-256 verified. Delivery is restricted to the single current active Owner Telegram account. Do not use Telegram export to send plaintext database/config/private-media material.

## Restore

Restore is privileged and explicit. The Phase 0.8 authority consumes only completed full backups (`daily_full` or `pre_update`) from the local backup authority; database-only frequent backups are rejected for controlled full restore.

The non-destructive preflight remains available without enabling destructive restore:

```bash
php artisan operations:restore <backup-id> --json
```

Preflight verifies backup authority/version/identity, artifact size and SHA-256, encryption algorithm/key identity, authenticated decryption, application/PHP/database/composer/migration compatibility, bundle entry count, and path-safe config/private payload ownership. For the backed-up deployment `.env`, Restore parses and compares the effective non-secret critical authority semantics that select database, Redis/queue/cache and maintenance targets; a candidate that redirects those authorities is rejected before maintenance or destructive mutation. Critical values may reference only already-defined approved critical variables; unresolved, indirect, or unsupported interpolation fails closed before mutation rather than being compared as a raw right-hand-side string. Credentials may rotate only when they do not redirect that authority, and no raw private configuration is written to restore reports. Preflight writes a protected sanitized report under `BACKUP_ROOT/restore/reports/` and removes decrypted work state.

A real replacement is separately disabled by default. `RESTORE_ENABLED=true` only makes the reviewed execution path technically available; it is not authorization for a staging/production restore. A live invocation still requires the current destructive/production Owner gate. When that gate is satisfied, the command additionally requires exact backup-ID confirmation:

```bash
php artisan operations:restore <backup-id> --apply --confirm=<backup-id> --json
```

Apply enters Laravel maintenance mode with no bypass path, acquires the production Scheduler mutation flock, requests `queue:restart`, and waits the configured quiescence period before the safety backup. Production Cron must invoke `artisan schedule:run` through `/usr/bin/flock -n storage/framework/operations-scheduler-mutation.lock`; mutation-capable scheduled commands must stay in that foreground process so a restore cannot acquire the exclusive fence until an already-running mutator has drained. `RESTORE_QUIESCE_SECONDS` must remain at least 60 seconds above the largest reviewed Supervisor worker `--timeout`; the restore authority reads the canonical Supervisor template and fails before maintenance activation when this relationship is unsafe. Unknown/unbounded Scheduler mutation or a lock that cannot be acquired within the configured bound fails closed before safety-backup capture.

While maintenance is retained, Restore creates a new `daily_full` current-state safety backup and independently re-verifies its completed manifest/hash, compatibility, encryption identity, authenticated decryption, bundle entry count, and configured payload ownership. Destructive replacement cannot begin until that safety backup passes.

Database replacement uses the MariaDB client with credentials confined to a mode-`0600` temporary `--defaults-file`; password material is not placed in argv or surfaced provider errors. Config/private payloads are staged beside their configured targets and swapped only from path-safe regular-file/directory inputs. Any staging failure removes authority-owned plaintext swap state before returning. Original targets move only into a mode-`0700` restore-owned `.restore-recovery-<run-id>/` boundary while swaps are in flight. Rollback attempts every tracked operation even if one recovery step fails; an incomplete rollback remains contained and may leave recovery evidence only inside that protected boundary, never as an untracked `.restore-*.previous` sidecar. The destructive database/config/private/post-check sequence holds the shared backup authority lock so another backup cannot race replacement.

After config/private replacement and while maintenance plus the Scheduler mutation fence are still retained, Restore clears the Laravel configuration cache, requests a second `queue:restart`, and waits the same reviewed quiescence boundary. This prevents workers that booted before the restored environment was activated from reopening with stale in-memory configuration. The long-running restore process fingerprints the exact critical authority it restored/reconciled, including live MariaDB `@@server_uid` plus database identity and the non-secret Redis/queue/cache/maintenance authority configuration. A fresh Artisan child is launched with inherited application configuration explicitly masked and only a minimal operating-system environment allowlist retained, so database/Redis credentials and authority variables from the pre-restore parent cannot override the restored `.env`. That child reruns critical health, independently fingerprints its effective authority, and must match the exact expected fingerprint before reopen. Health against a different healthy database or Redis target, or health obtained through inherited pre-restore credentials, is therefore insufficient.

Before reopening, Restore requires exact migration identity, balanced finalized-ledger checks, state-aware purchase authority reconciliation, Service/Order consistency for both provisioned and imported Services, and the existing critical runtime health contract from that fresh runtime. A valid purchase `awaiting_payment` / version-0 Order must match its immutable Quote and must have null settlement/payment/paid fields; later purchase states require the matching settlement, payment-intent and provider authority. Restore then removes decrypted work state and persists a protected `resume_pending` report with `report_finalized=false`. The resume transition releases only the maintenance mode owned by that restore run while the Scheduler mutation fence is still proven held, verifies that maintenance is actually inactive, and only then releases and verifies the Scheduler fence. A successful protected final-report write changes that report to `completed` with `report_finalized=true`; if that post-reopen write fails, the command reports `completed_reporting_failed` instead of reclassifying the already-reopened restore as a contained failure.

Failures before the destructive boundary release owned maintenance when that can be proven safe. Any failure after destructive replacement starts never relies on stale caller bookkeeping: Restore re-establishes and re-proves owned maintenance, Scheduler-fence ownership, and queue-worker quiescence before recording full containment. Recontainment requests `queue:restart`, waits the reviewed worker-quiescence bound, and rechecks maintenance/fence ownership before `worker_quiescence_retained=true` or combined `containment_retained=true` may be recorded. Reports therefore distinguish `maintenance_retained`, `scheduler_mutation_fence_retained`, `worker_quiescence_retained`, and combined `containment_retained` from proven postconditions only. If an intermediate resume step becomes uncertain, the still-held Scheduler fence is not released first; maintenance is re-established and any worker that could have entered during the maintenance-off gap must be stopped/drained before full containment is claimed. A reporting failure after a fully successful reopen is reported separately and is not represented as a contained restore failure. Do not manually deactivate containment, edit ledger/payment/order history, or delete restore/safety-backup evidence to make the system appear healthy; establish the actual state and use the reviewed safety-backup/forward-recovery decision.

The final target-like generated-backup restore rehearsal remains the Phase 0.8 acceptance gate; implementation/unit/integration tests do not substitute for that later Outcome-F evidence.

## Update and rollback

The controlled updater is a CLI/release authority layered on the existing backup, Restore, maintenance/Scheduler/worker and atomic `current` symlink authorities. It does not introduce a second deployment path. `UPDATE_ENABLED=false` is the safe default; setting it to `true` only makes the reviewed apply path technically available and is not production authorization.

Production release configuration must point `UPDATE_DEPLOYMENT_ROOT` at the directory that owns `current`, `releases/` and `shared/`, and `UPDATE_PACKAGE_ROOT` at protected persistent storage outside `current/` and the web root (normally `<root>/shared/update-packages`). The updater must run as `UPDATE_RUN_USER` (normally `www`) and uses only the configured absolute PHP/Composer binaries plus fixed allowlisted arguments. `UPDATE_RELEASE_RETENTION` is bounded to 2–20 and defaults to 3; pruning touches only releases with the controlled `freedom_platform_release_v1` manifest and never the protected current/previous release.

### Release-package contract and preflight

A candidate is one regular `.tar` file directly inside `UPDATE_PACKAGE_ROOT`. Obtain the SHA-256 of the complete package through a trusted out-of-band release channel and pass that exact value with `--trusted-sha256`; that complete-package trusted checksum is the accepted signature/trusted-checksum mechanism. The updater verifies it **before** archive parsing, then rejects traversal, duplicate paths, symlink/hardlink/special/PAX-style entries, oversized/unsupported archive shapes and reserved `.env`, `storage/` or `vendor/` payloads.

The archive must contain `release-manifest.json`, `release-checksums.json`, `RELEASE_NOTES.md`, `artisan`, `composer.json`, `composer.lock`, `public/index.php` and the exact migration set. The manifest authority/version is `freedom_platform_release_v1` / `1` and records the release/application version, exact predecessor release/application version, predecessor/target migration identities, PHP range, Laravel major, Composer-lock SHA-256, checksum-list SHA-256, required free bytes and the explicit list of schema identities for which the previous code is rollback-compatible. `release-checksums.json` must exactly cover every payload file. The target schema identity is recomputed from the packaged migration filenames/content; manifest metadata cannot override the package contents.

Run a non-mutating preflight first:

```bash
php artisan operations:update /absolute/protected/path/release.tar \
  --trusted-sha256=<trusted-complete-package-sha256> \
  --json
```

Dry-run verifies package trust/shape, runtime/dependency prerequisites, disk capacity, exact active release/schema predecessor and absence of unresolved financial/provider work. It does not create the pre-update backup, stage code, run Composer/migrations or switch `current`.

### Controlled apply

A reviewed apply additionally requires `UPDATE_ENABLED=true` and exact release confirmation:

```bash
php artisan operations:update /absolute/protected/path/release.tar \
  --trusted-sha256=<trusted-complete-package-sha256> \
  --apply \
  --confirm=<release-id> \
  --json
```

The apply path creates `BackupKind::PreUpdate` through the existing backup authority and independently decrypts/preflights it through the existing Restore dry-run before staging may proceed. The candidate is extracted to a unique updater-owned staging directory, rechecksummed, and receives locked production Composer dependencies as the reviewed non-root runtime user with both Composer plugins and package scripts disabled. It is then atomically published as a new release identifier; an existing release directory is never overwritten. Only the existing release activator may prepare approved shared `.env`/storage links or switch `current`. After those shared links exist, the updater explicitly runs only the fixed allowlisted `artisan package:discover --ansi --no-interaction` runtime-bootstrap step; package-provided Composer scripts are never allowed to boot Laravel before shared runtime state is ready. Generated `bootstrap/cache/*` runtime artifacts are not accepted from the release package (except the inert `.gitignore` placeholder). After dependency/runtime preparation, every reviewed payload file plus the manifest/checksum metadata is hashed again against the authenticated package contract immediately before immutable sealing; any missing, symlinked or changed reviewed file aborts before mutation. After those links and the packaged schema identity are verified, the updater seals the published release read-only before migration/activation. Code/vendor/config files and directories lose owner write permission while reviewed executable entrypoints retain execute permission; only `bootstrap/cache` remains updater/runtime-writable, while `.env` and `storage` remain the approved shared symlinks. Exact-release pre/postflight also refuses activation if the Supervisor queue-worker wrapper is missing or no longer executable. Updater-owned retention/cleanup temporarily reopens directory permissions only while deleting a sealed inactive release.

Before migration, the updater holds the existing purchase-provider mutation barrier, enters the existing maintenance/Scheduler mutation fence, requests queue-worker drain/restart, rechecks unsafe financial/provider/provisioning/synchronization work and proves the active release/schema have not drifted. Migrations run only from the exact staged release with `artisan migrate --force --isolated=1`; there is no updater path that calls `migrate:rollback`.

Immediately after migration and before `current` switches, installed migration rows are proved against the candidate release's exact migration directory rather than inferred from the still-active predecessor symlink. The same release-bound proof is used when a compatibility-approved code-only rollback intentionally keeps the newer schema while switching back to previous code. A partial/unknown migration set cannot satisfy that exact release identity and remains contained.

Before activation and again after activation, the exact release must pass the existing critical health contract plus route/webhook, Scheduler and Composer platform/dependency checks. After activation the existing runtime-refresh path restarts workers. Each reviewed Supervisor wrapper resolves its physical release directory, exports that exact release ID to both the queue process and heartbeat sidecar, and generates one boot ID for that wrapper generation. Immediately before the intended refresh the updater snapshots a complete baseline covering every reviewed worker ID. A predecessor created before boot-generation support may contribute a `null` baseline value, but a missing worker or malformed non-null boot ID is never accepted. After refresh, every reviewed worker must publish a fresh heartbeat for the exact intended release with a valid boot ID; when the baseline already had a boot ID, the post-refresh ID must differ. Wrong-release rows, stale same-generation rows, stale timestamps, or an incomplete baseline fail closed before maintenance can be released. Compatible rollback and controlled Restore recovery use the same post-refresh proof; therefore a legacy target release that cannot emit the required post-refresh release/generation evidence remains contained rather than reopening processing. The updater then re-proves release/schema identity before reopening normal processing.

Protected sanitized reports are written to `<root>/shared/update-reports/update-<run-id>.json` with mode `0600`; installed release/rollback identity is stored in `<root>/shared/installed-release.json`. Reports contain release/schema/package/backup identifiers and phase/containment decisions, never exception details, credentials, provider payloads or private backup contents. An interrupted **pre-mutation** run may clean only its updater-owned staging/inactive release and finalize as recovered. Any unfinished report that crossed migration/activation remains fail-closed for explicit operator recovery rather than being guessed from stale local booleans.

### Failure and rollback decisions

If mutation/postflight fails, maintenance, the Scheduler fence and worker quiescence are re-proven before full containment is claimed. A code-only recovery is allowed only when the observed schema exactly matches signed/trusted rollback-compatibility metadata for the previous release; the previous release must then pass health and worker-boot verification. That recovery also reconciles `installed-release.json` to the actually active previous release before processing is reopened.

When old code is not schema-compatible, migration completion is uncertain, containment cannot be proven, or code rollback cannot restore a coherent verified state, the update report returns `restore_required` and preserves the verified pre-update backup ID plus the potential data-loss window. Recovery is keyed only by the exact protected update-run identity: preflight with `php artisan operations:update:recover <update-run-id> --json`, and an authorized apply requires the same update-run ID in both the argument and `--confirm`. The handoff reloads that protected report and therefore cannot substitute an arbitrary backup or release. It proves the recorded pre-update backup against the exact predecessor release. If update containment is already retained, the recovery process adopts it only when maintenance is owned by that exact update run; foreign or unproven ownership fails closed without a maintenance-off gap. Existing Restore machinery restores the protected state, the existing release activator returns `current` to the exact predecessor, and predecessor schema, exact-release runtime, fresh worker generation, installed identity, and the protected report are reconciled before maintenance is released. Any failed handoff proof remains contained. Never manually edit update reports, maintenance ownership, financial/provider evidence, or migration guards to force recovery.

Evaluate explicit rollback without mutation first:

```bash
php artisan operations:update:rollback --json
```

A compatibility-proven code-only rollback, when separately authorized, requires exact active-release confirmation:

```bash
php artisan operations:update:rollback \
  --apply \
  --confirm=<active-release-id> \
  --json
```

If rollback metadata does not prove the current schema safe for the previous code, the command refuses mutation and reports `rollback_incompatible_restore_required` with the verified pre-update backup context. Database rollback is never inferred from code rollback and the updater never runs production `migrate:rollback`.

If activation fails, preserve diagnostics, prevent unsafe new effects, and use the guarded code-rollback/Restore decision appropriate to the proven schema state. Do not manually edit update reports, `installed-release.json`, `current`, migration rows, financial/provider evidence or update package metadata to manufacture a healthy state.

The final target-like update/rollback rehearsal remains Phase 0.8 Outcome F. Repository tests and Outcome-E integration do not substitute for that later rehearsal.

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

### NOWPayments terminal-conflict authority migration

The NOWPayments terminal-conflict hardening release unit is the ordered pair [`2026_09_21_000050_create_purchase_provider_mutation_attempt_authority.php`](../database/migrations/2026_09_21_000050_create_purchase_provider_mutation_attempt_authority.php) followed by [`2026_09_21_000100_harden_nowpayments_terminal_provider_conflict.php`](../database/migrations/2026_09_21_000100_harden_nowpayments_terminal_provider_conflict.php). It is a financial-authority migration and must be applied only from the exact reviewed release revision after the normal release gate and backup requirements above have passed. Quiescing purchase/payment workers remains required operational hygiene and is mandatory for any older application revision that predates the provider-attempt protocol, but current-revision safety does not rely on quiescence as the mechanical race barrier.

| Release stage | Required operator evidence and action | Unsafe shortcut prohibited by the contract |
|---|---|---|
| **Preflight** | Confirm exact-revision CI is green, including MariaDB integration coverage; take the reviewed backup; quiesce purchase/payment workers and entrypoints; prove no older application revision can still issue provider mutations; confirm the current schema is the expected predecessor rather than an unknown partial/manual state. The `000050` migration must precede `000100` so durable provider-mutation attempt authority exists before any cutover begins; its schema constraints and guards are re-entry-safe after MariaDB partial DDL, and an unexpected table shape fails closed rather than being reused. | Do not rely on quiescence alone, manually normalize terminal payment rows, reorder the two migrations, or infer safety from a provider/dashboard view. |
| **Apply** | Run the ordinary timestamped migrations from the exact reviewed application revision. `000050` creates `purchase_provider_mutation_attempts`, the durable authority established before every external value mutation and retained unresolved until its exact local convergence is durable. `000100` first composes every temporary local-financial trigger while the marker is inactive; only after the entire staged trigger set exists does one DML operation activate `nowpayments_terminal_conflict_upgrade_fence`. From that activation instant, fresh PaymentIntent, Wallet, Card-to-Card, GiftCard, promotion, settlement, and paid-Order effects fail closed while the migration drains all 128 provider slots. A provider mutation that entered before the cut may finish only with the exact attempt/session capability attached to that mutation. After all slots are held, prepared attempts that never crossed the external-effect boundary are retired; any `external_started` or `reconciliation_required` current attempt blocks the first legacy-conflict preflight. Canonical guards are then composed with `CREATE OR REPLACE TRIGGER`, durable conflict evidence is revalidated and normalized, and the temporary cut is removed only while the entire provider pool remains exclusively held. | Do not delete or disable the fence table or staged triggers, edit or delete unresolved `purchase_provider_mutation_attempts`, set the provider-attempt session capability manually, bypass `PurchaseProviderMutationBarrier`, run an older application revision against the fenced schema, or edit reconciliation findings/observations to force migration progress. |
| **Postflight** | Verify both migrations are recorded; `purchase_provider_mutation_attempts` exists with its guards; no current unresolved provider-mutation attempt remains without an explicit reconciliation owner; `nowpayments_terminal_conflict_upgrade_fence` and every `*_np_conflict_upgrade_*_fence` trigger are absent; canonical `payment_intents_insert_guard`, `payment_intents_update_guard`, and `nowpayments_authority_update_guard` exist; run the reviewed health/smoke path before reopening workers. | Do not reopen purchase/payment traffic merely because the migration command returned; the durable-attempt state, absence of temporary fences, and presence of canonical guards are acceptance conditions. |
| **Interrupted or failed apply** | Treat continued purchase rejection as intentional fail-closed containment. If the process dies during staged-trigger composition before marker activation, no financial cut is active and no legacy decision read has occurred; prove that exact state and re-run the same reviewed revision. If the marker activated, preserve the database and diagnostics and keep workers quiesced: all fresh current-revision financial effects remain fenced. A provider SQL-session loss after the external-effect boundary deliberately leaves `external_started` or `reconciliation_required` durable authority even though MariaDB releases its named lock; reconcile the provider outcome and required local evidence under an approved recovery path before the migration may cross preflight. | Never manually drop the persistent fence/table/triggers, delete or complete an unresolved provider attempt merely to unblock migration, bypass canonical guards, or switch to an older application revision merely to make payment traffic appear healthy. |

Rollback is exceptional. The migration stages the same local-financial boundary and activates the persistent exclusion marker before draining provider mutations and restoring predecessor guards; it also refuses semantic rollback when terminal-conflict reconciliation evidence or a current unresolved provider-mutation attempt exists. A refusal means retain the current schema and use an approved forward fix or backup/restore decision; do not delete evidence or weaken either boundary to make `migrate:rollback` succeed.

## Operational evidence

Keep detailed operational evidence in protected target storage. Repository release records contain only sanitized metadata needed for release audit. Never commit credentials, private provider payloads, customer data, subscription URLs, or private backup contents.

[1]: ../database/migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php "Paid Service mutation authority migration"
[2]: ../database/migrations/2026_08_20_000100_enable_service_package_quotes.php "Service package Quote authority migration"

## Telegram shared rate control and Update payload retention

Phase 0.7 applies a shared generic Telegram capacity boundary without replacing stricter feature-specific controls:

- generic recognized-user interactions consume the configured per-user Redis budget before business/navigation dispatch;
- outbound delivery consumes one atomic global + per-chat Redis budget before the existing Telegram provider boundary;
- Support ticket/content quotas and OTP abuse controls remain separate stricter layers and are not replaced by the generic budget;
- Redis corruption/unavailability fails closed instead of silently bypassing the capacity boundary.

The generic runtime defaults are exposed through `TELEGRAM_RATE_LIMIT_PREFIX`, `TELEGRAM_INTERACTION_RATE_LIMIT_*`, `TELEGRAM_OUTBOUND_GLOBAL_RATE_LIMIT_*`, and `TELEGRAM_OUTBOUND_CHAT_RATE_LIMIT_*`. Tune them only from observed operational capacity; do not weaken Support/OTP controls to compensate for these budgets.

A Telegram API rate rejection with a valid `retry_after` is a provider-confirmed no-effect result. The canonical delivery operation remains retryable, an append-only directive records the exact provider attempt and not-before time, and the linked Outbox command cannot be delivered before that time. Generic Outbox backoff may extend the delay but may not shorten it. Transport/server ambiguity and crash-at-boundary states remain `uncertain`/manual-review and are never automatically converted into retryable delivery.

Raw inbound Update retention is intentionally split by recoverability:

- after successful processing, `processed_telegram_updates` keeps durable bot/update identity, payload hash, state and processing evidence while `payload_ciphertext` and `payload_size` are cleared immediately;
- a failed Update remains encrypted and explicitly requeueable while it is in `failed` state;
- after an externally approved retention age expires, operators may run `php artisan telegram:updates:retention --failed-older-than=<seconds> --limit=<n>` to move those rows to `failed_terminal` and clear the remaining raw payload bytes while retaining identity/hash/error evidence;
- `failed_terminal` rows are not eligible for `telegram:updates:requeue --include-failed`.

Phase 0.7 deliberately defines **no default retention age and no scheduler entry** for failed Update payloads. The exact legal/business retention duration and recurring operational schedule belong to the later Operations policy. Until that policy is approved, do not invent a duration or schedule this command implicitly.

