# Operational Document Status

**Reviewed:** 2026-08-10  
**Purpose:** prevent target-state deployment, installer, update, restore, backup, staging, and provider instructions from being mistaken for currently approved executable operations.

This file classifies operational material. It does not replace live project state in `PROJECT_STATUS.md`, `docs/project-status.json`, or GitHub.

## Status vocabulary

- `current-executable` — implemented, reviewed, and approved for the named environment and exact boundary;
- `foundation-executable` — a bounded primitive exists, but the complete operational journey does not;
- `target-state` — design/acceptance contract for a later phase; do not execute as a current runbook;
- `historical-evidence` — preserved record of a prior controlled run; not a current instruction;
- `disabled` — intentionally unavailable until rebuilt under a current contract.

## Operational document classification

| Path | Status | Safe interpretation |
|---|---|---|
| `docs/09-deployment-runbook.md` | target-state with Phase 0.2 foundations | final production deployment contract; individual commands are not automatically approved now |
| `docs/10-release-checklist.md` | target-state release gate | final release checklist; unchecked future-phase items are not current defects |
| `docs/18-deployment-and-operations.md` | target-state architecture with selected foundations | operational design/acceptance contract; verify every referenced tool at the exact live head |
| `docs/19-ci-quality-gates.md` | current-executable | mandatory repository CI contract |
| `docs/development/ci-runner-contract.md` | current-executable | self-hosted repository CI runtime contract; not production PHP/LSPHP acceptance |
| `docs/development/staging-workflow-inventory.md` | current-executable governance | classification of visible staging workflows |
| `evidence/0.2.0/` | historical-evidence | accepted evidence for exact recorded boundaries; not proof of current host state |
| `deploy/bin/release-switch.php` | foundation-executable | guarded release-switch primitive; not a complete signed updater |
| `deploy/bin/queue-worker-with-heartbeat.sh` | foundation-executable | bounded worker wrapper; requires exact deployment review before use |
| `deploy/supervisor/freedom-platform.conf` | foundation-executable template | render/review for the exact release and host before installation |
| `deploy/staging/` | historical-evidence / disabled by default | retained source/history is not execution authorization |
| `.github/workflows/staging-readiness.yml` | current-executable read-only diagnostic | sanitized local runner/host facts after typed confirmation; no deployment/provider mutation |
| other `.github/workflows/staging-*.yml` | disabled historical stubs | no operational use unless explicitly rebuilt under a current contract |

## Production execution rule

No production deployment/update/restore action is approved until all applicable gates refer to the same exact release SHA/package:

1. signed/checksummed release package and manifest;
2. mandatory implementation/release CI;
3. target-like fresh-install evidence;
4. update and rollback rehearsal;
5. encrypted backup and restore rehearsal;
6. schema compatibility and rollback decision;
7. owner-approved maintenance window and rollback point;
8. current target preflight for CLI PHP and OpenLiteSpeed PHP;
9. protected secrets supplied through approved runtime channels, never Chat/repository/command history.

A command shown in a target-state document never waives these gates.

## Staging execution rule

`Staging Readiness` is the only standing staging workflow classified as current-executable, and it is read-only.

Any future staging mutation path must be introduced through a focused current change with:

- protected environment approval;
- exact SHA/package/checksum input;
- typed high-risk confirmation;
- least privilege;
- backup/rollback where applicable;
- concurrency lock and timeout;
- sanitized retained evidence;
- phase-specific accepted implementation CI.

Do not keep temporary staging mutation/bootstrap infrastructure merely because it might be useful later.

## Provider execution rule

Real Telegram, SMS, panel, payment, bank, gift-card, rate, or blockchain operations require, as applicable:

- exact dated official/installed contract evidence;
- fake/fixture/contract tests already green;
- protected credentials stored outside repository/Chat;
- sandbox/test or explicitly controlled low-risk environment;
- result classification and uncertain-result handling;
- redacted retained evidence;
- explicit activation decision for production-capable targets/providers;
- Owner action only where required by `AGENTS.md`.

Accepted source, fake, contract, offline, or harness work is reusable engineering evidence, but it is not proof of the exact deployed provider/version.

The temporary default-branch provider-live bootstrap from PR `#24` was intentionally abandoned during repository cleanup. A persistent provider-live bootstrap is forbidden. Final Marzban/PasarGuard deployment acceptance must use the smallest fresh protected execution path appropriate to the final release boundary and is tracked by the final release acceptance work.

No source/offline/harness result alone enables a production Service Target or authorizes real provider mutation.

## Before any operational command

1. fetch Draft PR `#6` and use its exact live head;
2. read `PROJECT_STATUS.md`, `docs/project-status.json`, and this file;
3. confirm the intended document/tool is classified for the named environment/action;
4. inspect the actual workflow/script at the exact head;
5. identify privileges, writes, external effects, downtime, backup, rollback, and evidence path;
6. verify protected inputs are supplied without disclosure;
7. stop when any prerequisite is missing, stale, or ambiguous.

Historical success does not authorize a new execution against a different SHA, environment, provider version, or credential set.
