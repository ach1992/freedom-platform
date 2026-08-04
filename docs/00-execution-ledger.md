# Execution Ledger

Document version: `0.1.1`  
Source baseline: Master Execution Prompt `1.0.0` dated `2026-08-03`  
Ledger state: `active`  
Implementation state: `foundation-development-active`

## Purpose and rules

This is the program control ledger. It records phase scope, ownership, decisions, dependencies, required evidence, and closure gates. `in-progress` means a reviewed partial implementation exists; it never means the phase gate has passed. Documentation-only entries do not constitute runtime test evidence.

## Delivery ledger

| Phase | Scope | Status | Current evidence |
|---|---|---|---|
| `0.1.0` | Requirements, architecture, risks, test strategy and traceability | `passed` | `evidence/0.1.0/quality-gate.md` |
| `0.2.0` | Foundation, installer, health, Outbox, idempotency, runtime/deployment | `in-progress` | Runtime preflight and worker-heartbeat evidence are green; target aaPanel/OpenLiteSpeed rehearsal remains |
| `0.3.0` | Identity, customers, agents, ACL, OTP/SMS and audit | `planned` | Issue `#5` |
| `0.4.0` | Catalog, offerings, panels, capacity, custom plans and trials | `planned` | Issue `#7` |
| `0.5.0` | Ledger, wallet, pricing, promotions and payment providers | `planned` | Issue `#8` |
| `0.6.0` | Orders, provisioning, services and notifications | `planned` | Issue `#9` |
| `0.7.0` | Telegram UX, content, membership, support and broadcast | `planned` | Issue `#10` |
| `0.8.0` | Reports, Operations Center, backup/restore and updater | `planned` | Issue `#11` |
| `0.9.0` | Regression, security, load/chaos and release candidate | `planned` | Issue `#12` |
| `1.0.0` | Production package, reports, runbooks and handover | `planned` | Issue `#13` |

## Latest verified increment

Requirements: `OPS-001`, `OPS-003`, `RUN-003`, `RUN-004`

Delivered:

- worker heartbeat persistence and validation;
- stale-worker detection;
- deduplicated critical alerts;
- automatic alert resolution after recovery;
- Scheduler command with overlap prevention and one-server coordination;
- feature tests and retained evidence.

Verification:

- implementation head: `0ef036dcc1b68d90f1f2f7cab900e92b0c8b7b9d`;
- GitHub Actions run: `30870967798`;
- result: 38 tests, 89 assertions, all mandatory jobs passed;
- evidence: `evidence/0.2.0/OPS-003-worker-heartbeats.md`.

## Current owner boundary

No owner input is required for the next software-only foundation increments. Owner/server involvement will be required when the target aaPanel/OpenLiteSpeed installation, Supervisor heartbeat validation, and rollback rehearsal are ready.

## Gate policy

- Stop the release when a financial invariant, authorization boundary, provisioning idempotency, restore rehearsal, or Critical/High security gate fails.
- No author alone approves financial, authorization, installer, updater, backup, or provider-integration changes.
- Every merge references requirement IDs and automated tests.
- Every test claim records exact command, environment, result, and evidence path.
- Phase closure is prohibited while required implementation, tests, commands, results, or evidence remain incomplete.
