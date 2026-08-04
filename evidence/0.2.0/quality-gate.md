# Phase 0.2.0 quality gate

Status: `in-review`  
Scope: Laravel foundation, secure installer and target runtime readiness  
Code baseline: `0.2.0-dev`  
Latest verified implementation commit: `40925c97ac602b614befecd83a5b4d2403002463`

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
- allowlisted atomic `.env` creation/update with safe quoting and unrelated-line preservation
- cryptographically generated `APP_KEY` when absent
- private checksum-protected rollback snapshots and restoration/removal after downstream failure
- fixed-process finalization sequence: config clear, forced migrations and config cache
- protected finalization HTTP endpoint with secret-safe responses and session invalidation
- real tests for bootstrap success, replay, failure/retry, resume, environment rollback and migration failure
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

The consolidated bootstrap and operational-preflight milestone passed GitHub Actions run `30907255419` with 47 tests and 120 assertions.

The atomic environment-writer milestone passed GitHub Actions run `30908040319` with 54 tests and 170 assertions.

The complete Installer environment/finalization milestone passed all mandatory jobs in GitHub Actions run `30908827675`:

- Repository preflight: passed;
- Secret scan: passed;
- Pint: passed;
- PHPStan/Larastan, architecture and forbidden-pattern policies: passed;
- dependency audit and license policy: passed;
- MariaDB and authenticated Redis suite: **60 tests, 195 assertions, zero warnings**.

Detailed requirement evidence is retained in:

- [`INS-001-php-runtime-preflight.md`](INS-001-php-runtime-preflight.md)
- [`INS-001-operational-preflight-bootstrap.md`](INS-001-operational-preflight-bootstrap.md)
- [`INS-001-environment-finalization.md`](INS-001-environment-finalization.md)
- [`OPS-003-worker-heartbeats.md`](OPS-003-worker-heartbeats.md)

## Remaining closure evidence

- implement and test software-only atomic release activation and code rollback around the `current` symlink
- validate approved shared `.env` and storage links in a staged release
- add guarded release journal and failed-health rollback behavior
- connect Supervisor-managed workers to active heartbeat recording
- perform one clean installation rehearsal on an Ubuntu 22.04 aaPanel/OpenLiteSpeed staging host
- execute the preflight against the actual CLI PHP and LSPHP binaries and retain sanitized results
- verify the `current` symlink, web root, Supervisor workers and single scheduler Cron on that host
- run `php artisan health:check` after installation
- execute and record one target rollback rehearsal

Phase `0.2.0` must not be marked passed until target-like server evidence is attached. No production provider credentials are needed for the rehearsal.

## Next exact work package

Implement a filesystem-safe release activator and rollback service for the immutable `releases/<version>` and atomic `current` symlink model. It must validate containment and mandatory files, create only approved shared links, journal redacted state, atomically switch `current`, execute an injected health verifier, and restore the previous release on failure. Verify first activation, upgrade, failed-health rollback, invalid/traversal/symlink-escape targets, missing shared resources and interrupted temporary-link recovery through disposable filesystem tests and the complete CI pipeline.
