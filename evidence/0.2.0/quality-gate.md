# Phase 0.2.0 quality gate

Status: `in-review`  
Scope: Laravel foundation, secure installer and target runtime readiness  
Code baseline: `0.2.0-dev`  
Latest verified implementation commit: `d82393631ac6d5a856847fee86e50218e1bcd9cd`

## Delivered

- Laravel 13 / PHP 8.4 application and modular-monolith boundaries
- locked Composer dependency graph and pinned GitHub Actions
- configuration and secret-handling framework
- SSH-issued, expiring, one-time installer access with HTTPS enforcement and lock boundary
- independent, configurable CLI PHP and OpenLiteSpeed LSPHP preflight probes
- runtime inspection for version, SAPI, loaded `php.ini`, timezone, extensions, disabled functions, limits and OPcache
- database and authenticated Redis installer checks
- allowlisted outbound HTTPS checks with bounded timeouts and redirects disabled
- minimum free-disk, runtime path readability/writability and expected owner/group checks
- one authoritative recoverable bootstrap orchestrator
- atomic private bootstrap journal and permanent installer lock
- real tests for bootstrap success, already-locked replay, failure/retry and resume
- liveness, readiness and critical dependency health checks
- transactional Outbox, deterministic idempotency keys and safe/redacted payload handling
- explicit order, payment and provisioning state-transition foundations
- typed payment, verification and panel adapter contracts
- MariaDB base/reliability/operations migrations
- authenticated Redis queue configuration with enforced timeout invariant
- aaPanel/OpenLiteSpeed deployment, Supervisor and scheduler templates
- active worker heartbeat persistence, stale-worker detection, deduplicated critical alerts and recovery resolution
- CI evidence for style, static analysis, architecture, secrets, dependencies, licenses and runtime tests

## Automated evidence

The original foundation baseline passed GitHub Actions run `30790038444` with 30 tests and 55 assertions.

The dual-runtime preflight increment passed all mandatory jobs in GitHub Actions run `30870232289` with 34 tests and 77 assertions.

The worker-heartbeat increment passed all mandatory jobs in GitHub Actions run `30870967798` with 38 tests and 89 assertions.

The consolidated bootstrap and operational-preflight milestone passed all mandatory jobs in GitHub Actions run `30907255419`:

- Repository preflight: passed;
- Secret scan: passed;
- Pint: passed;
- PHPStan/Larastan, architecture and forbidden-pattern policies: passed;
- dependency audit and license policy: passed;
- MariaDB and authenticated Redis suite: **47 tests, 120 assertions, zero warnings**.

Detailed requirement evidence is retained in:

- [`INS-001-php-runtime-preflight.md`](INS-001-php-runtime-preflight.md)
- [`INS-001-operational-preflight-bootstrap.md`](INS-001-operational-preflight-bootstrap.md)
- [`OPS-003-worker-heartbeats.md`](OPS-003-worker-heartbeats.md)

## Remaining closure evidence

- implement secret-safe, journaled and atomic shared `.env`/`APP_KEY` mutation with rollback
- connect the authoritative bootstrap orchestrator to environment mutation, migrations, cache finalization and the permanent lock
- connect Supervisor-managed workers to active heartbeat recording
- perform one clean installation rehearsal on an Ubuntu 22.04 aaPanel/OpenLiteSpeed staging host
- execute the preflight against the actual CLI PHP and LSPHP binaries and retain sanitized results
- verify the `current` symlink, web root, Supervisor workers and single scheduler Cron on that host
- run `php artisan app:health --critical` after installation
- execute and record one rollback rehearsal

Phase `0.2.0` must not be marked passed until target-like server evidence is attached. No production provider credentials are needed for the rehearsal.

## Next exact work package

Implement `InstallerEnvironmentWriter` and its integration with `InstallerBootstrapOrchestrator` for allowlisted, secret-safe, atomic `.env` writes, private rollback snapshots and restoration after downstream bootstrap failure. Verify new/existing file behavior, key generation, secret non-disclosure, replay and rollback through focused tests and the complete CI pipeline.
