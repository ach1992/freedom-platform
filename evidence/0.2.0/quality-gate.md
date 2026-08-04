# Phase 0.2.0 quality gate

Status: `passed`  
Scope: Laravel foundation, secure Installer, release/runtime controls and target rehearsal  
Final verified application SHA: `f3f460b1ae5deabfa1590a17b479e8de4d8bf2af`  
Final workflow-only head: `a01008e14264b1dace3b11e417afbb68f27da6b9`

## Delivered

- Laravel 13 / PHP 8.4 modular-monolith foundation and locked dependency graph;
- strict self-hosted CI with cached PHP 8.4 container, MariaDB, authenticated Redis, PCOV, Pint, PHPStan/Larastan, architecture, secret, dependency and license gates;
- secure Installer access, dual CLI/LSPHP preflight, operational environment checks, recoverable bootstrap, atomic environment writer and permanent Installer lock;
- immutable release layout, journaled atomic activation, critical health verification, automatic failed-health restoration and explicit compatible rollback;
- aaPanel/OpenLiteSpeed vhost and HTTPS target runtime;
- one Scheduler Cron, separated Supervisor worker groups and per-process heartbeat identities;
- stale-worker detection, deduplicated critical alert creation and recovery resolution;
- secure idempotent Telegram webhook ingress foundation and real staging contract rehearsal.

## Final automated evidence

Self-hosted CI run `30958332620` passed every mandatory job:

- Repository preflight;
- canonical planning/traceability validation;
- secret scan;
- Pint;
- PHPStan/Larastan;
- architecture and forbidden-pattern policy;
- dependency audit and license policy;
- MariaDB/authenticated Redis suite: **119 tests, 537 assertions, zero failures/errors/skips**.

Artifacts:

| Evidence | Artifact | SHA-256 |
|---|---:|---|
| Tests/coverage/services | `8912044150` | `683827e46b885e9109f848093ef9b2602f9572c3d9536eaee9bb94e663bfaba1` |
| Static quality | `8912035487` | `e6164e9f16623835b178a4bb73da86a93bd239a4df458b175d9dffe17375a9db` |
| Dependency/license | `8912024348` | `27718e2d5db97eb1679378395876ed1f79f5dca2e8e02a344f2f2187a23084c6` |
| Secret scan | `8912018346` | `dc526852a79908431f412e078690a350a477fd113366eb3923565e79a0a8c684` |

## Target evidence

Staging Core run `30958022781` passed with artifact `8911885060` (`768339b067f359ace93d6b8056f86e5ce0183f5e7f62331718f4100570547802`).

- CLI/LSPHP `8.4.23` and required extensions: passed;
- MariaDB `10.6.23`, authenticated Redis `7.4.2`: passed;
- migration, seed and production caches: passed;
- Release A/B activation, rollback and reactivation: passed;
- five workers and five fresh heartbeats: passed;
- one Scheduler heartbeat and one Cron: passed;
- controlled stale alert and recovery: passed;
- redacted critical health: healthy;
- Installer lock/environment/journal permissions: passed.

Staging Telegram run `30958272387` passed with artifact `8911998888` (`42aef797e0a7244c2b6da1046de54561f0ff8f0a702a3a68a90382068db0e006`). Secret rejection, valid ingestion, exact duplicate idempotency, collision rejection, stranded-update recovery, one processing attempt, cleanup and final zero pending updates all passed.

## Gate conclusion

Phase `0.2.0` is passed. Its acceptance does not imply completion of later identity/authorization, catalog, financial, provisioning, Telegram UX, backup/restore, updater, hardening or release-package gates.

Detailed evidence:

- [`INS-001-php-runtime-preflight.md`](INS-001-php-runtime-preflight.md)
- [`INS-001-operational-preflight-bootstrap.md`](INS-001-operational-preflight-bootstrap.md)
- [`INS-001-environment-finalization.md`](INS-001-environment-finalization.md)
- [`OPS-003-worker-heartbeats.md`](OPS-003-worker-heartbeats.md)
- [`RUN-001-target-staging-rehearsal.md`](RUN-001-target-staging-rehearsal.md)
- [`RUN-002-release-worker-runtime.md`](RUN-002-release-worker-runtime.md)
- [`PHASE-CLOSURE.md`](PHASE-CLOSURE.md)
