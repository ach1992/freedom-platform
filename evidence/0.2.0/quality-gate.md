# Phase 0.2.0 quality gate

Status: `in-review`  
Scope: Laravel foundation and installer skeleton  
Code baseline: `0.2.0-dev`  
Latest reviewed implementation commit: `f0bfa38e7b2b808316479ea8e6be24532515c522`

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
- CI evidence for style, static analysis, architecture, secrets, dependencies, licenses and runtime tests

## Automated evidence

The original foundation baseline passed GitHub Actions run `30790038444` with 30 tests and 55 assertions.

The dual-runtime preflight increment passed all mandatory jobs in GitHub Actions run `30870232289`:

- Repository preflight: passed;
- Secret scan: passed;
- Pint, Larastan/PHPStan, architecture and forbidden-pattern policies: passed;
- dependency audit and license policy: passed;
- MariaDB 11.4 and authenticated Redis suite: **34 tests, 77 assertions, zero warnings**.

Detailed requirement evidence is retained in [`INS-001-php-runtime-preflight.md`](INS-001-php-runtime-preflight.md).

## Remaining closure evidence

- complete database, authenticated Redis, Telegram, outbound-network, disk-space and filesystem/ownership installer checks
- implement journaled atomic environment/bootstrap operations and final permanent installer lock
- implement active worker heartbeat writes and stale-worker alerting
- perform one clean installation rehearsal on an Ubuntu 22.04 aaPanel/OpenLiteSpeed staging host
- execute the new preflight against the actual CLI PHP and LSPHP binaries and retain sanitized results
- verify the `current` symlink, web root, Supervisor workers and single scheduler Cron on that host
- run `php artisan app:health --critical` after installation
- execute and record one rollback rehearsal

Phase `0.2.0` must not be marked passed until target-like server evidence is attached. No production provider credentials are needed for the rehearsal.
