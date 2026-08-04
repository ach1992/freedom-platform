# INS-001 PHP Runtime Preflight Evidence

Status: `passed-automated`  
Phase: `0.2.0`  
Requirements: `INS-001`, `SEC-007`, `QUA-011`  
Reviewed implementation commit: `f0bfa38e7b2b808316479ea8e6be24532515c522`  
GitHub Actions run: `30870232289`

## Scope

This increment replaces the installer runtime's single-process PHP check with independent, configurable checks for the target CLI PHP and OpenLiteSpeed LSPHP binaries.

The preflight now records and evaluates, separately for each runtime:

- absolute executable path;
- PHP version with minimum `8.4.0`;
- SAPI;
- loaded `php.ini` path;
- UTC timezone;
- required extensions, including CLI-only `pcntl`;
- `disable_functions`;
- memory, execution, upload and POST limits;
- OPcache state.

The probe is a fixed application-owned script executed with an argv array and bounded timeout. Installer input cannot inject a shell command. Process output and exception details are not exposed through the installer view.

## Code references

- `app/Modules/Installer/Application/PhpRuntimePreflight.php`
- `app/Modules/Installer/Presentation/Http/InstallerAccessController.php`
- `config/installer.php`
- `.env.example`
- `resources/views/installer/preflight.blade.php`
- `resources/lang/en/installer.php`
- `resources/lang/fa/installer.php`

## Test references

- `tests/Unit/Modules/Installer/PhpRuntimePreflightTest.php`
- existing installer boundary and complete application suite

Covered scenarios:

1. supported PHP 8.4 runtime with UTC and required extensions;
2. old PHP, non-UTC timezone, missing extension and missing `php.ini` fail closed;
3. relative/non-executable binary is rejected before process execution;
4. malformed probe output fails closed without exposing raw output.

## Commands and results

Executed by `.github/workflows/ci.yml` on GitHub-hosted Ubuntu 24.04 with PHP 8.4.24, disposable MariaDB 11.4, and authenticated Redis:

```text
vendor/bin/pint --test --format=json
vendor/bin/phpstan analyse --no-progress --error-format=table
composer ci:forbidden-patterns
composer ci:architecture
composer validate --strict --no-check-publish
COLUMNS=240 php artisan test --display-warnings --fail-on-warning --log-junit build/evidence/tests/junit.xml --coverage-clover build/evidence/coverage/clover.xml
composer audit
composer ci:licenses
```

Result from run `30870232289`:

- Repository preflight: passed;
- Secret scan: passed;
- Pint, Larastan/PHPStan and repository policies: passed;
- dependency and license policy: passed;
- MariaDB and Redis suite: **34 tests passed, 77 assertions, zero warnings**.

CI artifacts:

- `static-evidence-30870232289`;
- `test-evidence-30870232289`;
- dependency and preflight artifacts retained by the workflow.

## Remaining evidence

Automated checks cannot prove the production server's actual runtime paths or configuration. Phase `0.2.0` still requires a target-like aaPanel/OpenLiteSpeed rehearsal that executes this preflight against:

- `/www/server/php/84/bin/php`;
- `/usr/local/lsws/lsphp84/bin/lsphp`.

The rehearsal must retain the sanitized runtime results and verify installation, `current` symlink, document root, Supervisor workers, the single Scheduler Cron entry, critical health checks and rollback.
