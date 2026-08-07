# Operational Document Status

**Reviewed:** 2026-08-07  
**Purpose:** prevent target-state deployment, installer, update, restore, backup, and staging instructions from being mistaken for currently approved executable operations.

## Status vocabulary

- `current-executable`: implemented, reviewed, and approved for the named environment and exact boundary;
- `foundation-executable`: a bounded primitive exists, but the complete operational journey does not;
- `target-state`: design/acceptance contract for a later phase; do not execute as a current runbook;
- `historical-evidence`: preserved record of a prior controlled run; not a current instruction;
- `disabled`: intentionally unavailable until rebuilt under a current contract.

## Current operational documents

| Path | Status | Safe interpretation |
|---|---|---|
| `docs/09-deployment-runbook.md` | target-state with Phase 0.2 foundations | production deployment procedure for the final signed release; commands referencing future Artisan/bootstrap tools are not currently approved |
| `docs/10-release-checklist.md` | target-state release gate | checklist for `0.9.0`/`1.0.0`; unchecked items are not current defects unless their owning phase is active |
| `docs/18-deployment-and-operations.md` | target-state architecture with selected foundations | design/acceptance contract; verify each command/tool exists before use |
| `docs/19-ci-quality-gates.md` | current-executable | current mandatory repository CI contract |
| `docs/development/ci-runner-contract.md` | current-executable | current self-hosted CI runtime contract only; not production PHP/LSPHP acceptance |
| `docs/development/staging-workflow-inventory.md` | current-executable governance | authoritative classification of visible staging workflows |
| `evidence/0.2.0/` | historical-evidence | accepted staging/runtime evidence for the exact recorded SHAs; not proof of current host state |
| `deploy/bin/release-switch.php` | foundation-executable | guarded release-switch primitive; not a complete signed updater |
| `deploy/bin/queue-worker-with-heartbeat.sh` | foundation-executable | queue worker wrapper for the documented runtime; requires current deployment review |
| `deploy/supervisor/freedom-platform.conf` | foundation-executable template | must be rendered/reviewed for the exact release/environment before installation |
| `deploy/staging/` | historical-evidence / disabled by default | scripts remain in Git history/source for audit; no script is approved unless referenced by a current guarded workflow/runbook |
| `.github/workflows/staging-readiness.yml` | current-executable read-only diagnostic | may collect sanitized local runner/host facts after typed confirmation; performs no deployment/provider mutation |
| other `.github/workflows/staging-*.yml` | disabled historical stubs | impossible job condition; no operational use |

## Production execution rule

No production deployment/update/restore command may be run until all of these refer to the same exact release SHA/package:

1. signed/checksummed release package and manifest;
2. mandatory implementation/release CI;
3. staging fresh-install evidence;
4. staging update and rollback evidence;
5. encrypted backup and restore rehearsal;
6. schema compatibility and rollback decision;
7. owner-approved maintenance window and rollback point;
8. current target preflight for both CLI PHP and LSPHP;
9. protected secrets supplied through the installer/runtime, never chat or command history.

A command shown in a target-state document does not waive these gates.

## Staging execution rule

Only `Staging Readiness` is currently approved, and it is read-only. Any later staging mutation workflow must be reintroduced through a focused change with:

- protected environment approval;
- exact SHA/package/checksum input;
- typed high-risk confirmation;
- least privilege;
- backup/rollback;
- concurrency lock and timeout;
- sanitized retained evidence;
- phase-specific accepted implementation CI.

## Provider execution rule

Real Telegram, SMS, panel, payment, banking, gift-card, rate, or blockchain operations require:

- an exact dated official/installed contract note;
- fake/fixture contract tests already green;
- protected credentials already stored outside repository/chat;
- test/sandbox or controlled low-risk environment;
- explicit result classification and uncertain-result handling;
- redacted evidence;
- owner action only when required by `AGENTS.md`.

The current unavailable Marzban/PasarGuard shells and Fake adapter are not authorization to test or activate a real provider.

## Before using any operational command

1. fetch PR `#6` and exact current head;
2. read `PROJECT_STATUS.md` and this status file;
3. confirm the document/tool is listed `current-executable` for the intended environment;
4. inspect the actual command/script at the exact head;
5. identify privilege, writes, external effects, downtime, backup, rollback, and evidence path;
6. stop when any prerequisite is missing or ambiguous.
