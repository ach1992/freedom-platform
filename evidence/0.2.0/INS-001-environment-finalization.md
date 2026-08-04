# INS-001 atomic environment and finalization evidence

Status: `verified`  
Requirements: `INS-001`, `SEC-003`, `SEC-007`, `SEC-008`, `QUA-011`, `QUA-013`  
Implementation head: `40925c97ac602b614befecd83a5b4d2403002463`  
GitHub Actions run: `30908827675`

## Delivered behavior

- installer environment input is restricted to an explicit configuration allowlist;
- control characters and unknown keys are rejected with generic errors that do not contain submitted values;
- a cryptographically secure 32-byte Laravel `APP_KEY` is generated only when an existing non-empty key is absent;
- unrelated `.env` comments and entries are preserved while managed keys are deterministically replaced and deduplicated;
- values are quoted with backslash, double-quote and dollar escaping;
- environment writes use a same-directory temporary file, checked write/flush, `fsync` when available, mode `0600`, and atomic rename;
- a private snapshot plus SHA-256 metadata is retained before the first mutation and protected by an exclusive file lock;
- an existing environment is restored byte-for-byte after downstream failure;
- a newly created environment is removed after downstream failure;
- snapshot files are removed only after finalization succeeds;
- finalization sequence is fixed as environment write, `config:clear`, `migrate --force`, and `config:cache`;
- the permanent Installer lock is activated only after all finalization steps pass;
- fixed argv are executed by `Symfony Process` through an absolute PHP binary and Artisan path with bounded timeout;
- process output and submitted secrets are not included in exceptions or HTTP responses;
- the finalization endpoint remains behind HTTPS, Installer availability, one-time unlock session, and rate limiting;
- the endpoint invalidates the unlock session after success and returns only the final status;
- an existing permanent Installer lock is a replay-safe no-op.

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
- Composer `2.10.2` and locked dependencies;
- disposable MariaDB;
- disposable password-authenticated Redis;
- application timezone `UTC`;
- business timezone `Asia/Tehran`.

## Results

- Repository preflight: passed;
- Secret scan: passed;
- Pint: passed;
- PHPStan/Larastan: passed;
- architecture policy: passed;
- forbidden-pattern policy: passed;
- dependency audit and license policy: passed;
- MariaDB/authenticated Redis suite: **60 tests, 195 assertions, zero warnings**;
- retained Actions test artifact: `test-evidence-30908827675`, artifact ID `8892181111`;
- retained static and dependency artifacts are attached to Actions run `30908827675`.

## Failure and correction record

1. PHPStan required exact generic return types on the environment writer; the annotations were corrected before adding finalization.
2. The first HTTP finalization run failed one test with HTTP `429`, because two tests shared the same rate-limiter identity. Production throttling was retained and test requests were isolated with distinct documentation-range IP addresses.
3. No secret value from the test fixtures appeared in HTTP responses, process exceptions, committed evidence, or CI summaries.

## Remaining Phase 0.2.0 gaps

- implement and rehearse software-only atomic release activation and code rollback around the `current` symlink;
- validate shared `.env` and storage links in a staged release;
- add guarded release journal and failed-health rollback behavior;
- connect Supervisor-managed worker processes to active heartbeat recording in the production command line;
- perform a clean installation, Supervisor, health and rollback rehearsal on an Ubuntu 22.04 aaPanel/OpenLiteSpeed host.

## Next exact work package

Objective: implement a filesystem-safe release activator and rollback service for the immutable `releases/<version>` plus atomic `current` symlink model.

Requirements: `RUN-001`, `RUN-002`, `RUN-003`, `INS-001`, `SEC-010`, `QUA-011`.

Inspect or modify:

- new deployment application/infrastructure services under `app/Modules/Operations` or `app/Modules/Installer`;
- `deploy/` templates;
- `docs/09-deployment-runbook.md`;
- `docs/18-update-rollback-runbook.md`;
- focused filesystem/system tests.

Expected behavior:

1. validate an absolute deployment root and a strict release identifier;
2. require the candidate directory to be inside `<root>/releases` and reject traversal or symlink escape;
3. require candidate `artisan`, `public/index.php`, `composer.lock`, and approved shared `.env`/storage targets;
4. link only the shared `.env` and shared storage through relative symlinks;
5. capture the previous resolved release before activation;
6. atomically replace `current` using a temporary symlink and rename;
7. run a supplied candidate health verifier after activation;
8. restore the previous `current` target automatically when verification fails;
9. refuse rollback when the target is outside releases or missing mandatory files;
10. write a redacted private journal containing release IDs, status, timestamps and hashes only;
11. prove success, first activation, failed health rollback, invalid target, traversal, symlink escape and interrupted temporary-link recovery in disposable filesystem tests.

Required gates: full PHPUnit/MariaDB/Redis suite, Pint, PHPStan/Larastan, architecture, forbidden patterns, secret scan, Composer audit/license and traceability validation.

Completion condition: all CI jobs are green, exact evidence is retained, and the only remaining Phase `0.2.0` work requiring owner involvement is target aaPanel/OpenLiteSpeed/Supervisor validation or clearly documented software-only gaps.
