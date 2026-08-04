# Phase 0.2.0 quality gate

Status: `in-review`  
Scope: Laravel foundation and installer skeleton  
Code baseline: `0.2.0-dev`  
Latest verified implementation commit: `0ef036dcc1b68d90f1f2f7cab900e92b0c8b7b9d`

## Delivered

- Laravel 13 / PHP 8.4 application and modular-monolith boundaries
- locked Composer dependency graph and pinned GitHub Actions
- configuration and secret-handling framework
- SSH-issued, expiring, one-time installer access with HTTPS enforcement and lock boundary
- independent, configurable CLI PHP and OpenLiteSpeed LSPHP preflight probes
- runtime inspection for version, SAPI, loaded `php.ini`, timezone, extensions, disabled functions, limits and OPcache
- liveness, readiness and critical dependency health checks
- transactional Outbox, deterministic idempotency keys and safe/redacted payload handling
- explicit order, payment and provisioning state-transition foundations
- typed payment, verification and panel adapter contracts
- MariaDB base/reliability/operations migrations
- authenticated Redis queue configuration with enforced timeout invariant
- aaPanel/OpenLiteSpeed deployment, Supervisor and scheduler templates
- active worker heartbeat persistence, stale-worker detection, deduplicated critical alerts, and recovery resolution
- CI evidence for style, static analysis, architecture, secrets, dependencies, licenses and runtime tests

## Automated evidence

The original foundation baseline passed GitHub Actions run `30790038444` with 30 tests and 55 assertions.

The dual-runtime preflight increment passed all mandatory jobs in GitHub Actions run `30870232289` with 34 tests and 77 assertions.

The worker-heartbeat increment passed all mandatory jobs in GitHub Actions run `30870967798`:

- Repository preflight: passed;
- Secret scan: passed;
- Pint, Larastan/PHPStan, architecture and forbidden-pattern policies: passed;
- dependency audit and license policy: passed;
- MariaDB and authenticated Redis suite: **38 tests, 89 assertions, zero warnings**.

Detailed requirement evidence is retained in:

- [`INS-001-php-runtime-preflight.md`](INS-001-php-runtime-preflight.md)
- [`OPS-003-worker-heartbeats.md`](OPS-003-worker-heartbeats.md)

## Remaining closure evidence

- complete database, authenticated Redis, Telegram, outbound-network, disk-space and filesystem/ownership installer checks
- implement journaled atomic environment/bootstrap operations and final permanent installer lock
- connect Supervisor-managed workers to active heartbeat recording
- perform one clean installation rehearsal on an Ubuntu 22.04 aaPanel/OpenLiteSpeed staging host
- execute the preflight against the actual CLI PHP and LSPHP binaries and retain sanitized results
- verify the `current` symlink, web root, Supervisor workers and single scheduler Cron on that host
- run `php artisan app:health --critical` after installation
- execute and record one rollback rehearsal

Phase `0.2.0` must not be marked passed until target-like server evidence is attached. No production provider credentials are needed for the rehearsal.
