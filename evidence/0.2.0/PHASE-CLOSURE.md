# Phase 0.2.0 closure record

Requirements: `INS-001`, `OPS-001`, `OPS-003`, `RUN-001`, `RUN-002`, `RUN-003`, `RUN-004`, `SEC-001`, `SEC-008`, `SEC-010`, `QUA-001`, `QUA-011`, `QUA-013`

## Final verified application baseline

- application SHA deployed to staging: `f3f460b1ae5deabfa1590a17b479e8de4d8bf2af`;
- final workflow-only head after manual-trigger cleanup: `a01008e14264b1dace3b11e417afbb68f27da6b9`;
- self-hosted CI run: `30958332620`;
- complete MariaDB/authenticated Redis suite: **119 tests, 537 assertions, zero failures/errors/skips**;
- test artifact: `8912044150`, SHA-256 `683827e46b885e9109f848093ef9b2602f9572c3d9536eaee9bb94e663bfaba1`;
- static artifact: `8912035487`, SHA-256 `e6164e9f16623835b178a4bb73da86a93bd239a4df458b175d9dffe17375a9db`;
- dependency artifact: `8912024348`, SHA-256 `27718e2d5db97eb1679378395876ed1f79f5dca2e8e02a344f2f2187a23084c6`;
- secret-scan artifact: `8912018346`, SHA-256 `dc526852a79908431f412e078690a350a477fd113366eb3923565e79a0a8c684`.

## Target rehearsal

Staging Core run `30958022781` passed with artifact `8911885060`, SHA-256 `768339b067f359ace93d6b8056f86e5ce0183f5e7f62331718f4100570547802`.

Verified facts:

- PHP CLI `8.4.23` / `cli` and LSPHP `8.4.23` / `litespeed`, required extensions present;
- MariaDB `10.6.23` and authenticated Redis `7.4.2`;
- heartbeat queue migration `2026_08_04_000500_expand_worker_heartbeat_queue` applied;
- Release A activation, Release B activation, explicit rollback to A and reactivation of B passed;
- active release `staging-b-f3f460b1ae5d`;
- five Supervisor worker processes running with five distinct fresh heartbeats;
- one Scheduler heartbeat and exactly one Cron entry;
- controlled stale-worker detection created one unresolved alert and recovery resolved it to zero;
- Installer lock, shared environment and release journal retained with mode `600`;
- critical redacted health result `healthy` for application key, timezones, production debug, MariaDB, Redis, queue, cache and storage.

## Telegram staging contract

Staging Telegram run `30958272387` passed with artifact `8911998888`, SHA-256 `42aef797e0a7244c2b6da1046de54561f0ff8f0a702a3a68a90382068db0e006`.

- configured webhook targets the expected HTTPS endpoint;
- `pending_update_count=0` before and after verification;
- missing and incorrect webhook secrets return HTTP `403`;
- valid synthetic update returns HTTP `200`;
- exact duplicate returns HTTP `200` without a second effect;
- conflicting duplicate returns HTTP `409`;
- one stranded update was requeued, processed once and cleaned up;
- final synthetic state is `processed`, attempt count `1`.

## Gate conclusion

The Phase `0.2.0` target installation, runtime, activation/rollback, Scheduler, Supervisor heartbeat, stale-alert recovery, HTTPS health and secure Telegram ingress prerequisites passed on the disposable aaPanel/OpenLiteSpeed staging host. No known Critical/High finding remains for this Phase. Later backup/restore, finance, authorization, providers, panels, provisioning and release-candidate gates remain mandatory in their own phases.
