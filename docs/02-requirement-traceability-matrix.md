# Requirement Traceability Matrix

Baseline: Master Execution Prompt `1.0.0`

Matrix status: `active`

Implementation/test/evidence status: `in-progress`.

## Foundation implementation cross-references

| Requirements | Exact partial references | Remaining gap |
|---|---|---|
| `INS-001`, `SEC-007`, `QUA-011` | Installer access boundary, dual PHP runtime preflight, runtime health probe integration, installer preflight UI, `app/Modules/Installer/Application/InstallerBootstrapJournal.php`, `tests/Unit/Modules/Installer/InstallerBootstrapJournalTest.php`, CI pending after increment | Final lock activation, complete bootstrap orchestration, remaining outbound/disk/ownership checks, target aaPanel/OpenLiteSpeed installation and rollback evidence |
| `OPS-001`, `OPS-003`, `RUN-003`, `RUN-004` | `app/Modules/Operations/Application/WorkerHeartbeatService.php`; heartbeat record/check commands; `routes/console.php`; `tests/Feature/WorkerHeartbeatCommandTest.php`; `evidence/0.2.0/OPS-003-worker-heartbeats.md`; CI run `30870967798` | Real Supervisor integration and target-server heartbeat validation |

## Evidence schema

```yaml
requirement_id: INS-001
commands: []
result: in-progress
artifacts: []
```
