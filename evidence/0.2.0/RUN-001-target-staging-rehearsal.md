# Phase 0.2.0 target staging rehearsal evidence

Requirements: `RUN-001`, `RUN-002`, `RUN-003`, `RUN-004`, `INS-001`, `OPS-001`, `OPS-003`, `SEC-001`, `SEC-008`, `SEC-010`, `QUA-011`, `QUA-013`

## Scope

This evidence records the sanitized disposable-host rehearsal that closes the Phase `0.2.0` target runtime gate. The target is Ubuntu 22.04 with aaPanel and OpenLiteSpeed. No production provider account, customer data or production credential was used.

## Verified environment

- aaPanel and OpenLiteSpeed active;
- PHP CLI `8.4.23` / `cli` and LSPHP `8.4.23` / `litespeed` with all required extensions;
- MariaDB `10.6.23` and password-authenticated localhost-only Redis `7.4.2`;
- staging document root `/www/acdomains/hell.hellservice.top/current/public`;
- valid HTTPS and public live/ready endpoints;
- shared environment, Installer lock and release journal mode `600`;
- Supervisor workers and one Scheduler Cron entry.

## Final CI baseline

Workflow-only cleanup head `a01008e14264b1dace3b11e417afbb68f27da6b9` passed self-hosted CI run `30958332620`.

- Repository preflight and canonical planning validation: passed;
- secret scan: passed;
- Pint: passed;
- PHPStan/Larastan, architecture and repository policy: passed;
- dependency audit and license policy: passed;
- MariaDB and authenticated Redis suite: **119 tests, 537 assertions, zero failures/errors/skips**;
- test artifact `8912044150`, SHA-256 `683827e46b885e9109f848093ef9b2602f9572c3d9536eaee9bb94e663bfaba1`;
- static artifact `8912035487`, SHA-256 `e6164e9f16623835b178a4bb73da86a93bd239a4df458b175d9dffe17375a9db`;
- dependency artifact `8912024348`, SHA-256 `27718e2d5db97eb1679378395876ed1f79f5dca2e8e02a344f2f2187a23084c6`;
- secret-scan artifact `8912018346`, SHA-256 `dc526852a79908431f412e078690a350a477fd113366eb3923565e79a0a8c684`.

## Final application deployment rehearsal

Verified application SHA `f3f460b1ae5deabfa1590a17b479e8de4d8bf2af` was deployed in Staging Core run `30958022781`.

Artifact:

- ID `8911885060`;
- SHA-256 `768339b067f359ace93d6b8056f86e5ce0183f5e7f62331718f4100570547802`.

Results:

- migration `2026_08_04_000500_expand_worker_heartbeat_queue`: passed;
- immutable Release A activation: passed;
- immutable Release B activation: passed;
- explicit rollback to Release A: passed;
- reactivation of Release B: passed;
- active release: `staging-b-f3f460b1ae5d`;
- five Supervisor workers: `RUNNING`;
- distinct fresh worker heartbeat rows: `5`;
- Scheduler heartbeat rows: `1`;
- initial worker health: healthy;
- controlled bulk-worker stale result: unhealthy with exactly the expected worker ID;
- unresolved stale alerts after controlled failure: `1`;
- health after recovery: healthy;
- unresolved stale alerts after recovery: `0`;
- exactly-one-Cron result: `1`;
- critical redacted health: healthy for application key, operational/business timezone, production debug, database, Redis, queue, cache and storage.

A duplicate idempotent rehearsal caused by temporary PR triggering also passed. Both staging workflows are now manual-only.

## Telegram contract rehearsal

Staging Telegram run `30958272387` passed.

Artifact:

- ID `8911998888`;
- SHA-256 `42aef797e0a7244c2b6da1046de54561f0ff8f0a702a3a68a90382068db0e006`.

Results:

- webhook configured to the expected HTTPS endpoint;
- initial and final `pending_update_count=0`;
- missing secret: HTTP `403`;
- incorrect secret: HTTP `403`;
- valid synthetic update: HTTP `200`;
- exact duplicate: HTTP `200`, one persisted effect;
- conflicting duplicate: HTTP `409`;
- one stranded update requeued;
- synthetic row count `1`, state `processed`, attempt count `1`;
- synthetic cleanup complete.

## Gate conclusion

The target installation, independent PHP runtimes, database/Redis, immutable release activation and rollback, Supervisor/Scheduler operation, worker heartbeat/stale-alert recovery, HTTPS health and secure Telegram ingress prerequisites passed on the disposable target-like host. No known Critical/High finding remains for Phase `0.2.0`.

Later backup/restore, updater, financial, authorization, provider, panel, provisioning, full Telegram UX and release-candidate gates remain mandatory in their own phases.
