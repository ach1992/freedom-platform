# Phase 0.2.0 target staging rehearsal evidence

Requirements: `RUN-001`, `RUN-002`, `RUN-003`, `RUN-004`, `INS-001`, `OPS-001`, `OPS-003`, `SEC-001`, `SEC-008`, `SEC-010`, `QUA-011`, `QUA-013`

## Scope

This evidence records the sanitized target-host rehearsal required to close the Phase `0.2.0` foundation gate. The host is a disposable Ubuntu staging VPS using aaPanel and OpenLiteSpeed. No production provider account, payment credential, customer data, or production secret was used.

## Verified environment

- Ubuntu `22.04` target host;
- aaPanel installed and active;
- OpenLiteSpeed installed and serving the configured staging virtual host;
- PHP CLI and LSPHP `8.4.23`, independently inspected with required extensions present;
- MariaDB `10.6.23` and authenticated localhost-only Redis `7.4.2`;
- Composer production dependencies installed from the lock file;
- application document root set to `/www/acdomains/hell.hellservice.top/current/public`;
- valid HTTPS certificate, automatic Certbot renewal and OpenLiteSpeed restart hook;
- shared `.env`, Installer lock and release journal retained with mode `600`.

## Exact execution controls

The rehearsal used the version-controlled controls below:

```text
deploy/staging/prepare-php-functions.sh
deploy/staging/prepare-runtime-paths.sh
deploy/staging/recover-operations-migration.sh
deploy/staging/core-deploy.sh <verified-commit-sha>
deploy/bin/release-switch.php
```

Application operations executed by the deployment control included:

```text
/www/server/php/84/bin/php artisan optimize:clear --no-ansi --no-interaction
/www/server/php/84/bin/php artisan migrate --force --no-ansi --no-interaction
/www/server/php/84/bin/php artisan db:seed --force --no-ansi --no-interaction
/www/server/php/84/bin/php artisan config:cache --no-ansi --no-interaction
/www/server/php/84/bin/php artisan route:cache --no-ansi --no-interaction
/www/server/php/84/bin/php artisan view:cache --no-ansi --no-interaction
/www/server/php/84/bin/php artisan health:check --critical --json --redact --no-ansi --no-interaction
/www/server/php/84/bin/php artisan operations:check-worker-heartbeats --max-age=120 --json --no-ansi --no-interaction
```

## Results

### Initial target rehearsal

- GitHub Actions run: `30939724164`;
- artifact ID: `8904686608`;
- artifact ZIP SHA-256: `bc5048c72ec641208f21c0365978e6936df83562b14ddbbedd571c0acaa01e65`;
- immutable Release A activation: passed;
- immutable Release B activation: passed;
- explicit rollback to Release A: passed;
- reactivation of Release B: passed;
- five distinct Supervisor worker processes and heartbeat rows: passed;
- exactly one Scheduler heartbeat and one Cron entry: passed;
- controlled stale-worker alert creation and recovery resolution: passed;
- redacted critical health check: passed;
- Installer permanent lock mode `600`: passed.

### HTTPS and public health

- GitHub Actions run: `30940469345`;
- public `/health/live`: HTTP `200`;
- public `/health/ready`: HTTP `200`;
- local and public HTTPS verification: passed;
- certificate renewal timer and restart hook: installed.

### Latest application deployment rehearsal

- verified application SHA: `949aa723772f90aaa0171fb1ea29ba8e57c9f6df`;
- GitHub Actions deployment run: `30943711851`;
- deployment artifact ID: `8906271720`;
- deployment artifact ZIP SHA-256: `21008a3cd8c6e39dc30ade609abb2d25ecd91aef28676f6117374099b243f8e5`;
- active release: `staging-b-949aa723772f`;
- migration `2026_08_04_000400_extend_telegram_ingress_foundation`: passed;
- A/B activation, explicit rollback and reactivation: passed;
- five Supervisor workers: running;
- worker heartbeat count: `5`;
- Scheduler heartbeat count: `1`;
- stale alert count after controlled failure: at least `1`;
- unresolved stale alerts after recovery: `0`;
- exactly-one-Cron check: `1`;
- critical health status: healthy.

## CI correlation

Application SHA `949aa723772f90aaa0171fb1ea29ba8e57c9f6df` passed GitHub Actions run `30943562438`:

- Repository preflight and canonical traceability: passed;
- secret scan: passed;
- Pint: passed;
- PHPStan/Larastan, architecture and repository policy: passed;
- dependency audit and license policy: passed;
- MariaDB and authenticated Redis suite: **108 tests, 491 assertions, zero failures**;
- test artifact ID: `8906139420`;
- test artifact ZIP SHA-256: `936f68f11e424e21b4e4f400c15000f8948db99e3aa347f9c0b0aeff50f0fdb9`.

## Gate conclusion

The target aaPanel/OpenLiteSpeed installation, release activation/rollback, Scheduler, Supervisor heartbeat, stale-alert recovery, health and HTTPS portions of Phase `0.2.0` passed on the disposable staging host. No Critical/High finding remains for the Phase `0.2.0` foundation gate.

Later production release readiness still requires the later-phase backup/restore, updater, provider, financial, authorization and complete acceptance gates defined by the authoritative specification; this Phase result does not waive them.
