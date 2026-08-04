# Update and Rollback Runbook

Requirement: `UPD-001`

Deployment model: immutable versioned releases with atomic `current` symlink

## Preconditions

An update is allowed only when:

- package signature/trusted checksum and manifest are published through an authenticated release channel;
- package version, PHP/Laravel requirements, schema range, migration strategy, and rollback compatibility are explicit;
- CI and target-like staging evidence match the package Git SHA;
- a verified pre-update backup exists;
- no unsafe payment/provisioning/restore/reconciliation work is active;
- enough disk exists for package, new release, temporary files, and backup;
- the operator has Owner permission and required second approval.

Run the read-only checks as `www`:

```bash
cd /www/acdomains/hell.hellpservice.ir/current
/www/server/php/84/bin/php artisan update:preflight --package=/www/acdomains/hell.hellpservice.ir/shared/update-packages/<PACKAGE> --redact
/www/server/php/84/bin/php artisan operations:pending-financial --fail-if-active
/www/server/php/84/bin/php artisan backup:verify --latest --deep --redact
```

Stop on any non-zero result.

## Update execution

Run through the protected update UI or as `www` with an authorization ID created by the hardened approval flow:

```bash
cd /www/acdomains/hell.hellpservice.ir/current
/www/server/php/84/bin/php artisan update:execute --package=/www/acdomains/hell.hellpservice.ir/shared/update-packages/<PACKAGE> --authorization-id=<AUTHORIZATION_ID> --redact
```

The updater must perform, journal, and verify these stages:

1. Re-verify signature/checksums, manifest, compatibility, archive paths, and disk.
2. Create and verify an encrypted pre-update backup.
3. Extract to a new immutable release; never overwrite `current`.
4. Run `composer install --no-dev --classmap-authoritative --no-interaction --no-progress` as `www` against the lockfile.
5. Link only approved shared resources; never copy `.env` into the release.
6. Run package self-test and static checks.
7. Enter maintenance only when required; drain/pause affected worker queues.
8. Run expand/contract-compatible migrations and record the schema version.
9. Run smoke/readiness checks against the staged release.
10. Atomically switch `current`.
11. clear/rebuild Laravel caches, issue `queue:restart`, and verify Supervisor.
12. Verify HTTPS, webhook, MariaDB, authenticated Redis, Scheduler, workers, providers, critical reconciliation, and a controlled journey.
13. Exit maintenance and write an immutable redacted update report.

Evidence path:

```text
/www/acdomains/hell.hellpservice.ir/shared/storage/app/private/operations/evidence/<release>/update/<tracking-id>/
```

## Post-update commands

```bash
readlink -f /www/acdomains/hell.hellpservice.ir/current
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan about
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan health:check --redact
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan reconciliation:critical --fail-on-difference
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan scheduler:heartbeat:verify
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan workers:heartbeat:verify
supervisorctl status 'freedom-platform-workers:*'
```

Expected: `current` resolves to the intended immutable release; all commands exit `0`; heartbeats are current; no reconciliation difference exists.

## Rollback decision

| Situation | Action |
|---|---|
| Failure before symlink switch | Abort; keep current release; preserve staged release/journal for analysis |
| Failure after switch; schema backward-compatible | Enter maintenance, stop affected workers, atomic symlink switch to previous release, restart and verify |
| Schema incompatible but reversible migration explicitly tested | Run the versioned rollback procedure, then switch code and verify |
| Schema incompatible/non-reversible | Restore verified pre-update backup; explicitly accept its data-loss window |
| Financial/reconciliation anomaly | Freeze automation; treat as Critical; do not use ad-hoc data edits |

## Code rollback

Use the updater's guarded rollback; do not run `ln -sfn` by hand because compatibility and journal checks would be bypassed:

```bash
cd /www/acdomains/hell.hellpservice.ir/current
sudo -u www /www/server/php/84/bin/php artisan update:rollback --to=<PREVIOUS_RELEASE> --authorization-id=<AUTHORIZATION_ID> --redact
```

The command must validate target manifest/schema compatibility, enter maintenance, drain workers, atomically switch the symlink, rebuild caches, restart workers, run smoke and reconciliation checks, and produce a rollback report. It must refuse incompatible rollback.

If backup restore is required, follow `docs/17-backup-restore-runbook.md`. Never run a generic `php artisan migrate:rollback` on production.

## Interrupted update recovery

- Before symlink switch: current remains authoritative; mark staged execution failed and resume cleanup only through its journal.
- After symlink switch but before completion: keep maintenance, inspect `current`, schema version, migration journal, and worker state; choose guarded completion or rollback.
- Never re-run an uncertain migration without its idempotency/compatibility check.
- Preserve package, manifest, backup ID, logs, timestamps, and symlink state as incident evidence.

Every release candidate must demonstrate success, failed smoke-test rollback, failed migration handling, interrupted-before/after-switch recovery, and incompatible rollback rejection in staging.

## Verified low-level release switch primitive

The repository now includes the software-only activation/rollback primitive at `deploy/bin/release-switch.php`. It is intended for the Installer/bootstrap flow and as the final guarded symlink operation used by the future updater. It does not replace package signature, backup, schema-compatibility, approval, queue-drain, or reconciliation controls described above.

Before running it, the staged candidate must already exist at `/www/acdomains/hell.hellpservice.ir/releases/<RELEASE>` and include readable regular files `artisan`, `public/index.php`, and `composer.lock`. The shared directory must contain regular `.env` and directory `storage`; the candidate must not contain conflicting `.env` or `storage` paths.

Activate as `www`:

```bash
sudo -u www /www/server/php/84/bin/php \
  /www/acdomains/hell.hellpservice.ir/releases/<RELEASE>/deploy/bin/release-switch.php \
  --root=/www/acdomains/hell.hellpservice.ir \
  --release=<RELEASE> \
  --php=/www/server/php/84/bin/php \
  --journal=/www/acdomains/hell.hellpservice.ir/shared/release-journal.json \
  --timeout=60
```

The command validates containment, creates only the approved relative links to `../../shared/.env` and `../../shared/storage`, records the current release, atomically renames `.current.next` to `current`, and runs this fixed candidate-health command in a new process:

```bash
/www/server/php/84/bin/php artisan health:check --critical --json --redact --no-ansi --no-interaction
```

If candidate health fails, the previous `current` target is restored automatically. If it was a first activation, the failed `current` link is removed. The command prints only redacted status metadata on success and the generic line `Release switch failed.` on failure.

Perform an explicit compatible code rollback as `www`:

```bash
sudo -u www /www/server/php/84/bin/php \
  /www/acdomains/hell.hellpservice.ir/current/deploy/bin/release-switch.php \
  --root=/www/acdomains/hell.hellpservice.ir \
  --release=<PREVIOUS_RELEASE> \
  --php=/www/server/php/84/bin/php \
  --journal=/www/acdomains/hell.hellpservice.ir/shared/release-journal.json \
  --timeout=60 \
  --rollback
```

Do not use this low-level rollback when schema compatibility is unknown or false. Follow the rollback decision table, and use backup restore when required. Never replace it with `ln -sfn`; that bypasses containment, shared-link, health, journal, and automatic-restore checks.

After either command, verify:

```bash
readlink -f /www/acdomains/hell.hellpservice.ir/current
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan health:check --critical --json --redact
supervisorctl reread
supervisorctl update
supervisorctl status 'freedom-platform-workers:*'
```

The private journal `/www/acdomains/hell.hellpservice.ir/shared/release-journal.json` records only release identifiers, action/status, timestamp, previous release, and `composer.lock` SHA-256. It must remain mode `0600` and must never contain environment values or provider output.
