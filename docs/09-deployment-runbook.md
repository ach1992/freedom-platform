# aaPanel/OpenLiteSpeed Deployment Runbook

Target: Ubuntu 22.04 LTS, aaPanel, OpenLiteSpeed, PHP 8.4, MariaDB, authenticated Redis

Domain: `hell.hellpservice.ir`

Root: `/www/acdomains/hell.hellpservice.ir`

Run as: `root` only for host configuration; application commands as `www`

This runbook is executable only after the release manifest, checksums/signature, installer, health command, and release package exist. Replace `<RELEASE>` and `<PACKAGE>` with values from the signed release. Never paste a credential into a shell command or chat.

## 1. Change controls and backup

Before touching production:

- approved release and maintenance window;
- successful CI, staging install, restore, and update/rollback evidence for the same Git SHA;
- no active financial capture/provisioning/reconciliation operation;
- current queue state recorded;
- a successful encrypted current-state backup copied to an independent destination;
- tested previous release path and rollback compatibility recorded.

Verify the last backup and current release as `root`:

```bash
readlink -f /www/acdomains/hell.hellpservice.ir/current
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan backup:status --latest --redact
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan operations:pending-financial --fail-if-active
```

Expected: an absolute release path, a verified encrypted backup, and no unsafe active operation. Stop if any command fails.

## 2. Host preflight

Run through SSH as `root`:

```bash
/www/server/php/84/bin/php -v
/usr/local/lsws/lsphp84/bin/lsphp -v
/usr/local/bin/composer --version
systemctl is-active cron
command -v redis-cli
supervisorctl version
timedatectl show --property=Timezone --value
```

Both PHP binaries must report PHP 8.4; Cron must be active; the Redis client and Supervisor controller must be available; the host operational timezone should be UTC. aaPanel may manage Redis outside the distro's `redis-server.service`, so authenticated Redis readiness is verified through the installer instead of assuming a systemd unit name. The installer must separately inspect extensions, `php.ini`, `disable_functions`, limits, OPcache, MariaDB, authenticated Redis, HTTPS, outbound connectivity, disk, and permissions.

Create the fixed layout once:

```bash
install -d -o www -g www -m 0750 /www/acdomains/hell.hellpservice.ir/releases
install -d -o www -g www -m 0750 /www/acdomains/hell.hellpservice.ir/shared/storage
install -d -o www -g www -m 0700 /www/acdomains/hell.hellpservice.ir/shared/backups
install -d -o www -g www -m 0700 /www/acdomains/hell.hellpservice.ir/shared/update-packages
install -d -o www -g www -m 0700 /www/acdomains/hell.hellpservice.ir/shared/restore-work
install -d -o www -g www -m 0700 /www/acdomains/hell.hellpservice.ir/shared/provider-certificates
```

Do not create `.env` manually with credentials on the command line. The browser installer or a hidden-input Artisan command writes it atomically with mode `0600`.

## 3. Verify and stage a release

Place the package, detached signature when used, and published checksum file in `/www/acdomains/hell.hellpservice.ir/shared/update-packages/`. Run as `root`:

```bash
cd /www/acdomains/hell.hellpservice.ir/shared/update-packages
sha256sum --check SHA256SUMS
```

Expected: every listed file reports `OK`. If release signing is enabled, verify with the public key and exact command stated in the release manifest. A checksum copied from the same untrusted package location is insufficient; compare it to the authenticated GitHub Release metadata.

Extract only with the version-controlled bootstrap tool supplied by the release; it must reject path traversal, symlink escape, wrong manifest, incompatible PHP/schema, existing release destination, and unsigned/unchecked content. Expected interface:

```bash
cd /www/acdomains/hell.hellpservice.ir
sudo -u www ./shared/update-packages/bootstrap-release --package "shared/update-packages/<PACKAGE>" --release "<RELEASE>"
```

The tool creates `/www/acdomains/hell.hellpservice.ir/releases/<RELEASE>`, links `storage` and the shared `.env`, installs locked production dependencies as `www`, and writes a redacted journal. Do not substitute an ad-hoc `unzip` on production.

## 4. First installation

For a new installation only:

1. In aaPanel, create the site `hell.hellpservice.ir` and enable HTTPS.
2. Set the site document root to `/www/acdomains/hell.hellpservice.ir/current/public`.
3. Select PHP 8.4 and enable URL rewrite/front-controller handling for `public/index.php`.
4. Deny access to hidden files and ensure no alias exposes the project or shared root.
5. Activate the staged release only through the bootstrap's atomic symlink operation.
6. Open the one-time installer URL using its short-lived setup token.
7. Enter MariaDB, Redis, Telegram, Owner, and optional integration secrets into protected secret fields.
8. Complete preflight, migrations, seed, webhook registration, health checks, and lock creation.

The installer must end by creating `/www/acdomains/hell.hellpservice.ir/shared/installer.lock`; subsequent installer requests return `404` or `410`. Never share the setup URL or capture it in screenshots.

Raw OpenLiteSpeed configuration is intentionally not shipped: aaPanel owns/regenerates the vhost file. The approved source of truth is the document root above plus an exported, redacted aaPanel vhost snapshot stored with deployment evidence.

## 5. Scheduler and workers

Install exactly one Scheduler Cron entry from `deploy/cron/freedom-platform.cron` using aaPanel Cron or the system crontab. Verify:

```bash
crontab -l | grep -F '/www/acdomains/hell.hellpservice.ir/current' | wc -l
```

Expected: `1`.

Copy `deploy/supervisor/freedom-platform.conf` to `/etc/supervisor/conf.d/freedom-platform.conf`, then run as `root`:

```bash
supervisorctl reread
supervisorctl update
supervisorctl status 'freedom-platform-workers:*'
```

Expected: all configured processes become `RUNNING`; critical payments/provisioning are isolated from broadcast/report queues. Each process receives a unique `WORKER_NAME`, queue-group metadata, heartbeat interval `30`, and stale threshold `480`. The application records heartbeats while idle and before/after/after-failure job lifecycle events; a heartbeat write failure is logged generically and does not terminate the worker.

After at least one minute, verify database heartbeat freshness through the application command:

```bash
sudo -u www /www/server/php/84/bin/php \
  /www/acdomains/hell.hellpservice.ir/current/artisan \
  operations:check-worker-heartbeats --max-age=480 --json
```

Expected: every configured Supervisor process has a distinct current heartbeat. Stop one non-critical worker during the staging rehearsal, confirm one deduplicated critical alert is created after the threshold, restart it, and confirm the alert resolves.

## 6. Permissions

Application source is immutable to the web process except shared runtime paths. Run as `root` after staging:

```bash
chown -R www:www /www/acdomains/hell.hellpservice.ir/releases/<RELEASE>
find /www/acdomains/hell.hellpservice.ir/releases/<RELEASE> -type d -exec chmod 0750 {} \;
find /www/acdomains/hell.hellpservice.ir/releases/<RELEASE> -type f -exec chmod 0640 {} \;
chmod 0750 /www/acdomains/hell.hellpservice.ir/releases/<RELEASE>/artisan
chmod 0600 /www/acdomains/hell.hellpservice.ir/shared/.env
```

Writable runtime directories live under shared `storage`; do not grant `0777`. Confirm the HTTP/queue user is `www` before applying ownership.

## 7. Activation and verification

Before activation, production `.env` must set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`, `SESSION_ENCRYPT=true`, and `REDIS_QUEUE_RETRY_AFTER=420` or a larger reviewed value. The Redis retry interval must remain greater than every Supervisor worker timeout.

The staged release must contain regular readable `artisan`, `public/index.php`, and `composer.lock`. Shared `.env` and shared `storage` must exist as direct resources under `/www/acdomains/hell.hellpservice.ir/shared`. Do not pre-create conflicting `.env` or `storage` paths inside the candidate.

Activate the candidate only through the verified release switch primitive:

```bash
sudo -u www /www/server/php/84/bin/php \
  /www/acdomains/hell.hellpservice.ir/releases/<RELEASE>/deploy/bin/release-switch.php \
  --root=/www/acdomains/hell.hellpservice.ir \
  --release=<RELEASE> \
  --php=/www/server/php/84/bin/php \
  --journal=/www/acdomains/hell.hellpservice.ir/shared/release-journal.json \
  --timeout=60
```

The command:

- validates the release identifier and realpath containment;
- rejects traversal, candidate symlink escape, unsafe `current`, unapproved shared-resource paths, and missing mandatory files;
- creates only relative links to `../../shared/.env` and `../../shared/storage`;
- records the previous release and `composer.lock` SHA-256 in a mode-`0600` redacted journal;
- removes only a stale `.current.next` symlink and refuses a regular file at that path;
- atomically renames `.current.next` to `current`;
- runs the fixed critical redacted health command against the activated candidate;
- restores the previous `current`, or removes failed first activation, if health verification fails.

Do not substitute `ln -sfn` because it bypasses containment, journaling, shared-link and automatic-health rollback controls.

Then run:

```bash
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan optimize
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan queue:restart
supervisorctl reread
supervisorctl update
supervisorctl status 'freedom-platform-workers:*'
curl --fail --silent --show-error https://hell.hellpservice.ir/health/live
curl --fail --silent --show-error https://hell.hellpservice.ir/health/ready
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan health:check --critical --json --redact
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan reconciliation:critical --fail-on-difference
```

Verify webhook secret validation with the application smoke command; do not send a forged public request containing the real secret:

```bash
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan telegram:webhook:verify --redact
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan scheduler:heartbeat:verify
sudo -u www /www/server/php/84/bin/php /www/acdomains/hell.hellpservice.ir/current/artisan workers:heartbeat:verify
```

Expected: all exit `0`, readiness is healthy, reconciliation has no unexplained difference, and Scheduler/worker heartbeats are current. Execute a test-bot onboarding and a Fake-provider purchase/provision/delivery journey before enabling live gateways.

Store redacted evidence under `storage/app/private/operations/evidence/<RELEASE>/deployment/<UTC timestamp>/` and export a sanitized copy for the release record.

## 8. Failure and rollback

If failure occurs before the symlink switch, leave `current` unchanged and delete nothing until the staged journal is reviewed. A failed candidate health check performed by `release-switch.php` automatically restores the previous release. If further failure occurs after activation:

1. Enter maintenance mode if customer/financial safety is affected.
2. Stop new provider captures/provisioning through the Operations Center.
3. Preserve logs and the private release journal.
4. Confirm schema compatibility before any code rollback.
5. Run the guarded release switch with `--rollback`; never edit the symlink manually.
6. If schema is incompatible, restore the verified pre-update backup and explicitly accept the documented data-loss window.
7. Re-run health, reconciliation, webhook, queue, Scheduler and worker checks before reopening.

Compatible code rollback command:

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

Never run `migrate:rollback` blindly and never edit financial rows to make a deployment appear healthy. Full update and rollback policy remains in `docs/18-update-rollback-runbook.md`.

## 9. Target rehearsal evidence checklist

The Phase `0.2.0` target rehearsal must retain sanitized evidence for the exact Git SHA and include:

- independent CLI PHP and LSPHP preflight outputs;
- aaPanel/OpenLiteSpeed document root resolving to `current/public`;
- shared `.env` mode `0600`, shared storage, candidate links and `current` resolution;
- release-switch activation JSON and private-journal metadata without secrets;
- live/ready HTTP checks and `health:check --critical --json --redact`;
- exactly one Scheduler Cron entry;
- Supervisor `reread`, `update`, process status and unique fresh worker heartbeat rows;
- controlled stale-worker alert creation and recovery;
- explicit compatible rollback to the previous candidate followed by health checks;
- reactivation of the intended release and final healthy state.

Do not attach real credentials, raw `.env`, Telegram secrets, provider output, database passwords, or unrestricted aaPanel screenshots to the issue or repository.
