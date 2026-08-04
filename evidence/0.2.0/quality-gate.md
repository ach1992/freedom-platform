# Phase 0.2.0 quality gate

Status: `target-rehearsal-required`  
Scope: Laravel foundation, secure installer and target runtime readiness  
Code baseline: `0.2.0-dev`  
Latest software-side verified commit: `766ff01481fe716ffe082f4e171250b79197552a`

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
- immutable release validation and approved shared-resource linking
- exclusive, journaled atomic `current` symlink activation
- fixed critical redacted health verification and automatic restoration of the previous release
- explicit guarded compatible code rollback through the same controls
- secret-safe `deploy/bin/release-switch.php` CLI wiring with subprocess tests
- liveness, readiness and critical dependency health checks
- transactional Outbox, deterministic idempotency keys and safe/redacted payload handling
- explicit order, payment and provisioning state-transition foundations
- typed payment, verification and panel adapter contracts
- MariaDB base/reliability/operations migrations
- authenticated Redis queue configuration with enforced timeout invariant
- aaPanel/OpenLiteSpeed deployment, Supervisor and scheduler templates
- queue lifecycle heartbeat reporting while idle, before/after jobs and after job exceptions
- unique per-process Supervisor heartbeat identity and queue-group metadata
- stale threshold aligned above the longest worker timeout
- active worker heartbeat persistence, stale-worker detection, deduplicated critical alerts and recovery resolution
- automated validation of exactly one Scheduler Cron entry
- CI evidence for style, static analysis, architecture, secrets, dependencies, licenses and runtime tests

## Automated evidence

| Milestone | GitHub Actions run | Result |
|---|---:|---|
| Foundation baseline | `30790038444` | 30 tests, 55 assertions |
| Dual PHP runtime preflight | `30870232289` | 34 tests, 77 assertions |
| Worker heartbeat persistence/alerts | `30870967798` | 38 tests, 89 assertions |
| Consolidated bootstrap and operational preflight | `30907255419` | 47 tests, 120 assertions |
| Atomic environment writer | `30908040319` | 54 tests, 170 assertions |
| Installer environment/finalization | `30908827675` | 60 tests, 195 assertions |
| Release activation, CLI wiring and Supervisor runtime docs | `30910236970` | **77 tests, 267 assertions, zero failures/errors/warnings** |

Run `30910236970` passed:

- Repository preflight and canonical traceability validation;
- Secret scan;
- Pint;
- PHPStan/Larastan;
- architecture and forbidden-pattern policies;
- dependency audit and license policy;
- complete MariaDB and password-authenticated Redis suite.

Detailed requirement evidence is retained in:

- [`INS-001-php-runtime-preflight.md`](INS-001-php-runtime-preflight.md)
- [`INS-001-operational-preflight-bootstrap.md`](INS-001-operational-preflight-bootstrap.md)
- [`INS-001-environment-finalization.md`](INS-001-environment-finalization.md)
- [`OPS-003-worker-heartbeats.md`](OPS-003-worker-heartbeats.md)
- [`RUN-002-release-worker-runtime.md`](RUN-002-release-worker-runtime.md)

## Remaining closure evidence

All currently identified Phase `0.2.0` software-side packages are implemented and green. The remaining gate is target-like infrastructure evidence:

- one clean installation rehearsal on Ubuntu 22.04 with aaPanel/OpenLiteSpeed;
- actual independent CLI PHP and LSPHP preflight output;
- actual MariaDB and password-authenticated Redis checks;
- aaPanel/OpenLiteSpeed document root resolving to `current/public`;
- actual shared `.env`/storage ownership and permissions;
- actual release-switch activation and compatible rollback;
- actual Supervisor `reread`/`update`, all configured processes `RUNNING`, and distinct fresh heartbeat rows;
- controlled stale-worker alert and recovery;
- exactly one Scheduler Cron entry;
- live/ready HTTP and critical redacted health checks;
- retained sanitized command/results and aaPanel/vhost snapshot.

Phase `0.2.0` must not be marked passed or Issue #4 closed until this target rehearsal succeeds. No production provider credentials are needed, but root/aaPanel/Supervisor access or owner-executed commands are required.

## Next exact work package

Perform the target aaPanel/OpenLiteSpeed rehearsal defined in `evidence/0.2.0/RUN-002-release-worker-runtime.md` and `docs/09-deployment-runbook.md`. Use fake/sandbox application integrations, retain no secrets, and update Issue #4/PR #6 with exact commands, output summaries, environment facts, release IDs, heartbeat/alert evidence, rollback result and final health state.

If target access is not immediately available, the next dependency-safe code package may begin Phase `0.3.0` identity/customer/agent/authorization schema and domain foundations from Issue #5, but Phase `0.2.0` remains an open release gate.
