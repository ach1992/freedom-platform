# Deployment, Backup, Update, and Rollback

Target environment: Ubuntu/aaPanel/OpenLiteSpeed, PHP 8.4, MariaDB, authenticated Redis.

This document is a **safety contract**, not proof that every described command/capability currently exists. Execute an operational step only when the release/task explicitly authorizes it and the referenced implementation is present on the exact release commit.

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