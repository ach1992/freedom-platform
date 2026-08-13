# Deployment, Backup, Update, and Rollback

Target environment: Ubuntu/aaPanel/OpenLiteSpeed, PHP 8.4, MariaDB, authenticated Redis.

This document is a **safety contract**, not proof that every described command/capability currently exists. Execute an operational step only when the release/task explicitly authorizes it and the referenced implementation is present on the exact release commit.

## Execution and access topology

The project separates coding, CI, and staging operations:

- GitHub is the source of truth for repository state, Issues, PRs, workflow definitions, and workflow evidence.
- A repository-linked Codex cloud environment may provide an authenticated development checkout for shell-based implementation, commits, and task-branch pushes. It is not a GitHub repository Environment and does not inherit GitHub Actions secrets merely because it is linked to the same repository.
- GitHub Actions runs only on the owner-controlled self-hosted runner selected by `[self-hosted, Linux, X64, freedom-staging, php84]`. CI checkouts live in the runner workspace and are not the deployed staging tree.
- The staging target root known to repository operational checks is `/www/acdomains/hell.hellpservice.ir`; `current` is a release symlink. Never use `current` as a mutable developer checkout.

Use `CONTRIBUTING.md` for capability routing and `docs/06-test-strategy.md` for the runner/CI trust boundary.

### GitHub repository Environment vs Codex environment

These names refer to different systems:

- **GitHub repository Environment** is configured under repository `Settings -> Environments`; a workflow job may reference one with `environment:` to add an operational boundary for branch/tag policy, environment-specific credentials, or approvals supported by the active GitHub plan.
- **Codex cloud environment** is configured in OpenAI Codex and provides an agent coding workspace linked to the repository.

The guarded provider mutation workflow references the GitHub Environment name `provider-live-acceptance`. Before executing that workflow, verify in GitHub Settings that the environment exists and that its deployment branch/tag policy matches the intended integration branch. Do not infer GitHub Environment configuration from the existence of a Codex environment.

## Secret and credential interfaces

Secret **values** are never documentation. GitHub repository/environment settings or protected target storage own the values; workflow/source code owns which secret identifiers are actually consumed.

The repository's operational setup contains capability groups for:

- disposable PasarGuard test/provider access used by controlled provider checks;
- staging host/domain and remote-access credentials reserved for explicitly reviewed staging operations;
- SSH host-verification material for any future reviewed SSH path;
- a controlled Telegram test-bot credential reserved for task-owned integration/acceptance work.

A configured credential is not proof that a capability is active or accepted. Before using or deleting one, inspect the exact current workflow/source revision and the owning Task Contract. If current source does not reference a configured credential, treat it as reserved only; do not create a new execution path merely because the credential exists.

Do not create a generic workflow that dumps secrets, environment variables, `.env`, SSH material, or repository settings for discovery. Validate only the presence of the exact secret identifiers required by the owning workflow, keep values write-only, and redact operational evidence.

### GitHub Actions authentication

Prefer the repository-scoped `GITHUB_TOKEN` for GitHub operations performed by a workflow. Keep default workflow permissions restrictive and grant write permissions explicitly only to a bounded workflow/job that genuinely needs them.

Do not create a broad PAT merely to make ordinary CI work. A PAT, GitHub App installation token, SSH deploy key, or another credential is justified only when `GITHUB_TOKEN` cannot satisfy a specific bounded requirement such as a cross-repository operation or another platform constraint documented by the owning task.

The existence of staging remote-access credentials does not authorize arbitrary shell execution. A workflow using them must define the exact command path, input validation, host verification, evidence/redaction behavior, rollback/safety conditions, and approval gate required by its Task Contract.

## Production layout

```text
<root>/
├── releases/<version>/       # immutable code
├── shared/
│   ├── .env                 # mode 0600
│   ├── storage/
│   ├── backups/
│   └── update-packages/
└── current -> releases/<version>
```

Only `current/public` is web-exposed. Do not expose repository root, `.env`, shared storage, backups, or provider certificates.

## Preflight

Before first install or release activation verify:

- CLI PHP and OpenLiteSpeed PHP are independently compatible PHP 8.4 runtimes;
- required extensions and Composer are available;
- MariaDB and authenticated Redis are reachable with least-privilege application credentials;
- filesystem owner/group/permissions are correct; never use `0777`;
- HTTPS is valid;
- only one Scheduler Cron entry exists;
- Supervisor workers use the reviewed queue/runtime configuration;
- no secret is passed through command arguments, Chat, Git, or screenshots.

## Release acceptance before deployment

Do not deploy unless the exact release candidate has:

- mandatory CI success;
- reviewed migration/schema compatibility;
- verified package manifest/checksum/signature policy;
- target-like install/update/rollback testing applicable to the release;
- current encrypted backup and restore confidence;
- no unresolved Critical/High release blocker;
- explicit owner release approval.

## Package and activation

A release package must contain source, lockfile, migrations, release metadata, compatibility metadata, and integrity material without secrets.

Verify package integrity against authenticated release metadata before extraction/activation. Reject path traversal, symlink escape, incompatible runtime/schema, and unverified content.

Activation must use the repository's guarded atomic release-switch implementation when available. Do not replace a guarded release primitive with ad-hoc `ln -sfn`, manual file copying over the live tree, or direct edits to an existing release.

After activation run the implemented health/readiness, queue/worker, Scheduler, webhook, and reconciliation checks required by that release. Do not invent commands that are not present in source.

## Scheduler and workers

- exactly one Cron invokes Laravel Scheduler;
- Supervisor manages workers;
- payment/provisioning-critical queues remain isolated from bulk/report/broadcast work as configured;
- worker timeouts remain below Redis queue retry-after bounds;
- worker/scheduler health must be observable without exposing secrets.

## Backups

Production backups must be:

- consistent for the database/private files required for recovery;
- authenticated-encrypted;
- checksummed/manifested;
- stored outside the public root;
- copied to an independent destination according to retention policy;
- periodically restored in an isolated target-like environment.

Never expose a database password in process arguments. Temporary plaintext, if unavoidable, must have restrictive permissions and be removed only after verified encrypted output exists.

A backup that has never been restored is not sufficient release evidence.

## Restore

Restore is a privileged, explicit operation. It must:

1. authenticate/authorize the operator;
2. validate backup manifest, checksum, encryption key, and compatibility;
3. preserve the current state/safety backup before destructive replacement;
4. restore into an isolated/staged boundary where possible;
5. run migrations/compatibility steps defined by the restore implementation;
6. smoke-test and reconcile financial/remote state before reopening.

Never edit ledger/payment history manually to make a restore appear consistent.

## Update

An updater must verify the package and compatibility before mutation, quiesce unsafe work, make a verified pre-update backup, stage code separately, apply reviewed migrations, run health/smoke checks, then atomically activate.

Database migrations should use expand/contract compatibility. A code rollback is forbidden when the current schema is not compatible with the previous release.

## Rollback

If activation fails:

- preserve logs/journal/evidence;
- stop new unsafe financial/provisioning effects if needed;
- use the guarded release rollback path only when schema compatibility is proven;
- otherwise restore the verified pre-update backup through the controlled restore process;
- re-run health, worker/Scheduler, webhook, and reconciliation checks before reopening.

Never run `migrate:rollback` blindly on production.

## Secrets and operational evidence

Operational evidence is stored in protected target storage. A repository release record contains only sanitized metadata such as release commit, command/result summary, artifact/checksum identifiers, and known limitations.

Never commit `.env`, credentials, raw provider responses, unrestricted screenshots, customer data, subscription URLs, or private backup contents.
