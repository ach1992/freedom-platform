# Backup and Restore Runbook

Requirements: `BAK-001`, `BAK-002`

Default schedules: database every 10 minutes; full daily; pre-update on every update

## Backup policy

A successful backup is a consistent snapshot whose manifest, checksums, authenticated encryption, retention metadata, and required destination acknowledgements all verify. Creating an archive alone is not success.

Backup content:

- MariaDB logical dump produced without a password in process arguments;
- non-secret configuration and release/schema manifests;
- private-media metadata and files according to configured policy;
- checksum manifest before encryption and encrypted-part checksums after encryption;
- application/release/schema version, UTC timestamp, backup type, and restore prerequisites.

Secrets are excluded unless a separately documented key policy encrypts them. The backup encryption key is never stored in the repository, archive, Telegram, log, command line, or the same storage location as the backup.

## Scheduled operation

The Laravel Scheduler invokes backup commands; no backup-specific Cron entry is allowed. Each run uses a distributed lock plus a database run record and idempotency key.

Manual commands, run as `www` from the current release:

```bash
cd /www/acdomains/hell.hellpservice.ir/current
/www/server/php/84/bin/php artisan backup:create --type=database --reason=operator-request
/www/server/php/84/bin/php artisan backup:create --type=full --reason=operator-request
/www/server/php/84/bin/php artisan backup:verify --latest --deep --redact
```

Expected: commands return `0`, print only a tracking ID, and create a successful run record. Evidence path:

```text
/www/acdomains/hell.hellpservice.ir/shared/storage/app/private/operations/evidence/<release>/backup/<tracking-id>/
```

The implementation creates a protected temporary MariaDB option file or equivalent file-descriptor mechanism, mode `0600`, invokes `mariadb-dump` with `--defaults-extra-file`, validates the exit code, and reliably removes plaintext temporary material. It must not use `--password=<value>`.

## Telegram destination

When enabled, upload the encrypted archive in parts no larger than 45 MB. Send a manifest containing safe backup ID, release/schema version, UTC time, part count, sizes, and checksums. Record each Telegram acknowledgement. A missing part leaves the backup `incomplete`; resumable retry sends only unacknowledged parts.

Verify destination permissions without exposing identifiers:

```bash
/www/server/php/84/bin/php artisan backup:destination:test --destination=telegram --redact
```

## Retention

Defaults are 7 daily, 4 weekly, 6 monthly, plus release snapshots. Retention deletes only backups that are verified, outside required retention, not held for an incident/audit, and not the sole known-good restore point. Deletion is audited and must never target an unresolved path or glob.

## Restore preflight

Restore is destructive and requires Owner permission, confirmation, and optionally second approval. Before production restore:

1. Declare incident/change ID, target backup ID, expected data-loss window, and approvers.
2. Confirm the selected backup is from a trusted source and all encrypted-part checksums match.
3. Confirm the decryption key through a hidden input or protected key file; do not paste it into chat or command arguments.
4. Verify software/schema compatibility and available disk space.
5. Stop new financial/provisioning work and drain or pause affected queues.
6. Create and independently verify a current-state safety backup.
7. Rehearse into an isolated database and restore workspace first.

Create a short-lived restore token over SSH through hidden input:

```bash
cd /www/acdomains/hell.hellpservice.ir/current
sudo -u www /www/server/php/84/bin/php artisan restore:authorize --ttl=15m
```

The command must not print the decryption key. Use the returned one-time token only in the protected restore UI or hidden-input CLI flow.

## Isolated dry run

Run as `www`:

```bash
/www/server/php/84/bin/php artisan restore:preflight --backup-id=<BACKUP_ID> --isolated --deep --redact
```

The application must:

- verify manifest/signature, encrypted-part checksums, decryption authentication, release and schema compatibility;
- decrypt only inside `/www/acdomains/hell.hellpservice.ir/shared/restore-work/<random-id>` with mode `0700`;
- restore into a newly created isolated database with a non-production name;
- validate migrations/schema, foreign keys, ledger balance, payments/orders/provisioning, service ownership, and private-file manifest;
- delete plaintext workspace on success or failure after evidence hashes are recorded.

Expected: `PASS` with a restore tracking ID and no secrets. Any mismatch blocks production restore.

## Production restore

After dry run approval:

```bash
cd /www/acdomains/hell.hellpservice.ir/current
sudo -u www /www/server/php/84/bin/php artisan down --render='errors::503'
supervisorctl stop 'freedom-platform-workers:*'
sudo -u www /www/server/php/84/bin/php artisan restore:execute --backup-id=<BACKUP_ID> --authorization-id=<AUTHORIZATION_ID> --redact
```

The restore command must re-run preflight, verify the current-state safety backup, restore database and configured private files transactionally where possible, run only compatible safe migrations, and write an append-only restore report.

Post-restore, as `root`/`www` as indicated:

```bash
supervisorctl start 'freedom-platform-workers:*'
sudo -u www /www/server/php/84/bin/php artisan queue:restart
sudo -u www /www/server/php/84/bin/php artisan reconciliation:critical --fail-on-difference
sudo -u www /www/server/php/84/bin/php artisan health:check --redact
sudo -u www /www/server/php/84/bin/php artisan telegram:webhook:verify --redact
sudo -u www /www/server/php/84/bin/php artisan up
```

Verify readiness, worker/Scheduler heartbeats, a read-only customer/service lookup, and a controlled test journey. Do not enable live financial automation while reconciliation differs.

## Restore failure and recovery

- Keep maintenance mode enabled and workers stopped.
- Preserve the restore journal and sanitized logs.
- Do not repeatedly apply migrations or edit ledger/payment records.
- Restore the current-state safety backup using the same verified workflow when safe.
- Escalate any unexplained ledger, payment, provider-transaction, order, or service-ownership difference as Critical.

A production release cannot pass until a full target-like restore rehearsal from a generated encrypted backup has succeeded and its report is linked from the traceability matrix.
