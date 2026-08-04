# Phase 0.2 release activation and worker runtime evidence

Status: `verified-software-side`  
Requirements: `RUN-001`, `RUN-002`, `RUN-003`, `RUN-004`, `OPS-001`, `OPS-003`, `INS-001`, `SEC-008`, `SEC-010`, `QUA-011`, `QUA-013`  
Verified head: `766ff01481fe716ffe082f4e171250b79197552a`  
GitHub Actions run: `30910236970`

## Delivered release behavior

- `FilesystemReleaseActivator` validates an absolute deployment root and direct `releases`/`shared` directories;
- release identifiers use a strict bounded character policy and traversal-style `..` values are rejected;
- candidate directories must be direct, non-symlink children of `releases` and cannot escape through a symlink;
- candidates require readable regular `artisan`, `public/index.php`, and `composer.lock` files;
- shared `.env` and `storage` must be direct resources beneath `shared`;
- only approved relative links to `../../shared/.env` and `../../shared/storage` are created;
- conflicting candidate paths are rejected rather than replaced;
- activation uses an exclusive private lock, a temporary `.current.next` symlink, and atomic rename;
- a stale temporary symlink is recovered, while a regular file at that path is refused;
- the previous release is captured before activation;
- `ArtisanReleaseHealthVerifier` runs only the fixed redacted critical health command with an absolute PHP binary and bounded timeout;
- failed candidate verification restores the previous release, or removes a failed first activation;
- explicit compatible rollback passes through the same containment, file, shared-link, journal, health, and atomic-switch controls;
- release journal records only release IDs, action/status, previous release, timestamp, and `composer.lock` SHA-256 with mode `0600`;
- `deploy/bin/release-switch.php` provides a secret-safe CLI entry point and returns only status metadata or the generic line `Release switch failed.`;
- CLI wiring is covered through real subprocess tests, not only service-level tests.

## Delivered worker-runtime behavior

- Supervisor templates enable heartbeats independently for critical, provisioning/default, and bulk workers;
- each Supervisor process receives a unique `WORKER_NAME` derived from program and process number;
- each group receives explicit queue-group metadata;
- heartbeat reporting is registered for queue idle loops, before jobs, after jobs, and after job exceptions;
- writes are throttled to at most once per configured interval;
- a heartbeat persistence failure logs only generic event and exception-class metadata and does not terminate the worker;
- stale threshold defaults to `480` seconds, above the longest configured worker timeout of `300` seconds;
- Scheduler stale checking uses the same configuration value;
- automated policy tests confirm exactly one Scheduler Cron entry and heartbeat settings on all Supervisor groups.

## Exact CI commands

```bash
bash scripts/ci/verify-planning.sh
vendor/bin/pint --test --format=json
composer validate --strict --no-check-publish
vendor/bin/phpstan analyse --no-progress --error-format=table
composer ci:forbidden-patterns
composer ci:architecture
composer audit --locked --abandoned=fail --format=json
composer ci:licenses
docker compose -f docker-compose.ci.yml up -d --wait
php artisan config:clear --ansi
COLUMNS=240 php artisan test \
  --display-warnings \
  --fail-on-warning \
  --log-junit build/evidence/tests/junit.xml \
  --coverage-clover build/evidence/coverage/clover.xml
docker compose -f docker-compose.ci.yml down --volumes
```

## CI environment

- GitHub-hosted Ubuntu `24.04.4`;
- PHP `8.4.24` with PCOV;
- Composer `2.10.2`, locked dependencies;
- disposable MariaDB;
- disposable password-authenticated Redis;
- application timezone `UTC`;
- business timezone `Asia/Tehran`.

## Results

- Repository preflight and canonical traceability validation: passed;
- Secret scan: passed;
- Pint: passed;
- PHPStan/Larastan: passed;
- architecture and forbidden-pattern policies: passed;
- Composer audit and license policy: passed;
- MariaDB/authenticated Redis suite: **77 tests, 267 assertions, zero failures/errors/warnings**;
- test artifact: `test-evidence-30910236970`, artifact ID `8892771615`, SHA-256 digest `2c740ac01809ae2ada4127e3bde91c2ea7227702305f4c8dda0c154fe243c94f`;
- static artifact: `static-evidence-30910236970`, artifact ID `8892762785`;
- dependency artifact: `dependency-evidence-30910236970`, artifact ID `8892753326`;
- Gitleaks SARIF artifact: ID `8892743512`.

## Failures found and corrected

1. PHPStan rejected an always-false condition while removing a failed symlink; cleanup was made idempotent and non-branching.
2. PHPStan required a generic return contract for the activation-lock callback; exact template annotations were added.
3. Public activation/rollback return annotations were narrower than the shared internal switch result; contracts were aligned.
4. The prior Installer HTTP tests shared a rate-limit identity and one received `429`; production throttling was preserved and test identities were isolated.

## Phase gate status

The Phase `0.2.0` software-side foundation is now implemented and green for:

- Installer access/preflight;
- atomic environment finalization and rollback;
- migrations/cache finalization;
- permanent Installer lock;
- atomic release activation and automatic health rollback;
- live queue lifecycle heartbeat reporting;
- Supervisor and single-Cron runtime templates.

Phase `0.2.0` remains `in-progress`, because the authoritative gate requires a target-like aaPanel/OpenLiteSpeed rehearsal. No production credentials are required, but root/aaPanel/Supervisor access to the target or disposable equivalent is required.

## Next exact work package

Objective: perform and retain the target aaPanel/OpenLiteSpeed Phase `0.2.0` installation, worker, health, activation, and rollback rehearsal.

Requirements: `INS-001`, `RUN-001`, `RUN-002`, `RUN-003`, `RUN-004`, `OPS-001`, `OPS-003`, `SEC-007`, `SEC-008`, `SEC-010`, `QUA-011`, `QUA-013`.

Target facts to verify:

- Ubuntu 22.04/aaPanel/OpenLiteSpeed host;
- CLI PHP `/www/server/php/84/bin/php` and LSPHP `/usr/local/lsws/lsphp84/bin/lsphp`;
- project root `/www/acdomains/hell.hellpservice.ir` or an approved disposable target-equivalent path;
- document root resolves to `current/public`;
- application ownership `www:www` and no `0777` runtime paths;
- MariaDB plus password-authenticated Redis;
- active system Cron and Supervisor.

Exact rehearsal sequence:

1. create `releases` and private `shared` layout with correct ownership/modes;
2. stage this exact Git SHA as two disposable release IDs so activation and rollback can both be exercised;
3. execute independent browser preflight for CLI PHP, LSPHP, DB, authenticated Redis, HTTPS, outbound allowlist, disk, permissions, ownership, and UTC;
4. finalize a fake/sandbox environment through the protected Installer path and confirm migrations, config cache, private journal/snapshot cleanup, permanent lock, and no secret output;
5. set OpenLiteSpeed document root to `current/public` and retain a redacted aaPanel/vhost snapshot;
6. activate the first staged release with `deploy/bin/release-switch.php` and retain its redacted JSON/journal metadata;
7. install exactly one Scheduler Cron entry and the version-controlled Supervisor configuration;
8. run Supervisor `reread`/`update`, verify every worker is `RUNNING`, and confirm distinct fresh heartbeat rows;
9. stop one non-critical worker, verify one deduplicated stale-worker alert after `480` seconds, restart it, and verify resolution;
10. verify `/health/live`, `/health/ready`, and `artisan health:check --critical --json --redact`;
11. activate the second candidate, perform an explicit compatible rollback to the first, verify health, then reactivate the intended candidate;
12. retain exact sanitized commands/results under Phase `0.2.0` evidence and update Issue #4/PR #6.

Completion condition: target rehearsal evidence passes with no Critical/High finding; Issue #4 is closed; Phase `0.3.0` becomes the active implementation phase. This package requires owner-provided target server/root/aaPanel access or owner execution of the documented command sequence.
