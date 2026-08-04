# INS-001 operational preflight and recoverable bootstrap evidence

Status: `verified`  
Requirements: `INS-001`, `SEC-004`, `SEC-007`, `QUA-011`, `QUA-013`  
Implementation head: `d82393631ac6d5a856847fee86e50218e1bcd9cd`  
GitHub Actions run: `30907255419`

## Delivered behavior

- one authoritative `InstallerBootstrapOrchestrator` replaced the duplicate coordinator path;
- ordered bootstrap steps are journaled as started, completed or failed;
- interrupted bootstrap resumes after the last completed step and retries the failed step;
- an existing permanent lock prevents replay;
- the permanent lock is activated only after every bootstrap step completes;
- bootstrap journal and lock files use atomic temporary-file replacement and mode `0600`;
- malformed bootstrap journal records fail closed;
- database and authenticated Redis checks remain part of the browser preflight;
- operational preflight now verifies allowlisted outbound HTTPS, minimum free disk, readable/writable runtime paths and expected owner/group;
- outbound probes use fixed HTTPS hosts, bounded timeouts, disabled redirects and do not render remote response bodies;
- production defaults require runtime paths to be owned by `www:www` and at least 1 GiB free disk.

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

## Environment

- GitHub-hosted Ubuntu `24.04.4` runner;
- PHP `8.4.24` with PCOV;
- locked Composer dependencies;
- disposable MariaDB from `docker-compose.ci.yml`;
- disposable password-authenticated Redis from `docker-compose.ci.yml`;
- application timezone `UTC` and business timezone `Asia/Tehran`.

## Results

- Repository preflight: passed;
- Secret scan: passed;
- Pint: passed;
- PHPStan/Larastan: passed;
- architecture policy: passed;
- forbidden-pattern policy: passed;
- dependency audit and license policy: passed;
- MariaDB/authenticated Redis suite: **47 tests, 120 assertions, zero warnings**;
- retained Actions test artifact: `test-evidence-30907255419`;
- retained static artifact: `static-evidence-30907255419`;
- retained dependency artifact: `dependency-evidence-30907255419`.

## Failures found and corrected

1. The traceability matrix had been accidentally reduced and failed canonical-ID validation. The canonical matrix was restored before implementation continued.
2. Two competing bootstrap coordination classes and placeholder tests existed. They were consolidated into one orchestrator with real success, replay, failure/retry and resume tests.
3. PHPStan identified an unvalidated decoded journal shape and incomplete iterable/posix types. Journal parsing was made exact and fail-closed, and the preflight types were corrected.
4. Pint identified formatting differences in the new installer files. All style findings were corrected before the successful run.

## Remaining Phase 0.2.0 gaps

- write and update the shared `.env` atomically without disclosing secret values;
- generate and persist `APP_KEY` securely when absent;
- create a rollback snapshot before environment mutation and restore it after a failed bootstrap;
- connect the orchestrator to the actual installation command/HTTP finalization flow and migration/config-cache steps;
- validate Supervisor-managed worker heartbeats on the target host;
- rehearse clean installation, health verification and rollback on aaPanel/OpenLiteSpeed infrastructure.

## Next exact work package

Objective: implement secret-safe, atomic and rollback-capable environment bootstrap and connect it to the authoritative installer orchestration.

Requirements: `INS-001`, `SEC-003`, `SEC-007`, `SEC-008`, `QUA-011`.

Inspect or modify:

- `app/Modules/Installer/Application/InstallerBootstrapOrchestrator.php`;
- new `app/Modules/Installer/Application/InstallerEnvironmentWriter.php`;
- installer configuration/service-provider bindings;
- installer console or HTTP finalization entry point;
- focused unit and feature tests.

Expected behavior:

1. accept only an explicit allowlist of environment keys;
2. generate a cryptographically secure Laravel `APP_KEY` only when absent;
3. preserve unrelated existing `.env` lines while replacing allowlisted keys deterministically;
4. create a private rollback snapshot/checksum before mutation;
5. write through a same-directory temporary file, apply mode `0600`, fsync/close and atomically rename;
6. never return, log or include secret values in exceptions/evidence;
7. restore the previous file, or remove a newly created file, if a later bootstrap step fails;
8. activate the permanent installer lock only after environment write, migrations and cache finalization complete;
9. treat an existing installer lock as a replay-safe no-op.

Required tests and gates:

- new environment creation and existing environment update;
- allowlist rejection and newline/quoting safety;
- generated-key format without secret disclosure;
- failure rollback for both pre-existing and newly created files;
- already-locked replay;
- bootstrap resume semantics;
- full Pint, PHPStan/Larastan, architecture, forbidden-pattern, secret, dependency/license and MariaDB/authenticated Redis suite.

Completion condition: all tests and GitHub Actions are green, exact evidence is retained, and Issue #4/PR #6 record the following dependency-safe package.
