# OPS-003 Worker Heartbeat Evidence

Evidence date: `2026-08-04`  
Branch: `develop/v1.0.0-completion`  
Verified head: `0ef036dcc1b68d90f1f2f7cab900e92b0c8b7b9d`  
Requirements: `OPS-001`, `OPS-003`

## Implemented behavior

- records stable process-manager worker identifiers, queue names, host hashes, release versions, and UTC heartbeat timestamps;
- validates command inputs and rejects non-scalar or out-of-policy identifiers without writing state;
- detects stale recorded workers using a bounded configurable maximum age;
- creates one deduplicated critical alert per stale worker and increments its occurrence count on repeated detection;
- stores only safe operational context in the alert;
- resolves the open stale-worker alert when the worker records a fresh heartbeat;
- schedules stale-heartbeat detection through Laravel Scheduler with overlap prevention and one-server coordination;
- leaves database uniqueness and persisted alert state as the durable operational record.

## Exact implementation references

- `app/Modules/Operations/Application/WorkerHeartbeatService.php`
- `app/Modules/Operations/Presentation/Console/RecordWorkerHeartbeatCommand.php`
- `app/Modules/Operations/Presentation/Console/CheckWorkerHeartbeatsCommand.php`
- `bootstrap/app.php`
- `routes/console.php`
- `tests/Feature/WorkerHeartbeatCommandTest.php`

## Verification environment

GitHub Actions run: `30870967798`

- runner: Ubuntu `24.04`
- PHP: `8.4.24`
- database: disposable MariaDB service
- coordination/cache/queue: disposable authenticated Redis service
- application timezone: `UTC`
- business timezone: `Asia/Tehran`

## Exact mandatory commands/gates

```bash
vendor/bin/pint --test --format=json
vendor/bin/phpstan analyse --no-progress --error-format=table
composer ci:forbidden-patterns
composer ci:architecture
composer validate --strict --no-check-publish
COLUMNS=240 php artisan test \
  --display-warnings \
  --fail-on-warning \
  --log-junit build/evidence/tests/junit.xml \
  --coverage-clover build/evidence/coverage/clover.xml
composer audit
composer ci:licenses
```

## Results

- repository preflight: passed;
- secret scan: passed;
- Pint: passed;
- PHPStan/Larastan: passed;
- forbidden-pattern and architecture policy: passed;
- dependency audit and license policy: passed;
- MariaDB/authenticated Redis test suite: **38 tests, 89 assertions, zero warnings, all passed**;
- test evidence artifact ID: `8877874039`;
- test evidence archive SHA-256: `3e425c34dfb6138581851cdf69465eb52fe16dd7eed2bd109010d91c51f839e0`.

## Failure and correction record

An earlier CI run correctly rejected unsafe casts of Symfony Console input values during PHPStan analysis. The command was changed to validate argument and option values as strings before use. The final verified head above passed all mandatory gates.

## Remaining phase gap

This evidence closes only the worker-heartbeat increment. Phase `0.2.0` remains open pending the remaining installer/bootstrap work and a target-like aaPanel/OpenLiteSpeed installation and rollback rehearsal.
