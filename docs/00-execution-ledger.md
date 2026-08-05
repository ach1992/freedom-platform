# Execution Ledger

The authoritative product contract is `docs/specification/master-execution-prompt.md`. This ledger records repository delivery state only; it does not replace or redefine that contract.

## Current position

- Active branch: `develop/v1.0.0-completion`.
- Pull request: Draft PR #6 targeting `main`.
- `main` remains unchanged.
- Active phase: `0.4.0 — Catalog, Panels, and Offerings` under Issue #7.
- Last completed phase: `0.3.0 — Identity, Customers, Agents, and ACL`.
- Phase 0.3 closure trigger SHA: `979b0c99d79dbcc8cff273ccdfad2ea612796a0c`.
- Phase 0.3 mandatory CI: `31035712555` (`CI` run number `798`) — success.
- Automated suite at the Phase 0.3 boundary: 194 tests, 958 assertions.
- Test artifact: `test-evidence-31035712555`.
- Current branch head before the first Phase 0.4 verified increment: `bc425ff9d378a06837a4f229cd48e1cb19a029bb`.
- That head contains an initial catalog migration committed with `[skip ci]`; it is **Unverified** and is not a completed increment.

See `docs/22-phase-boundary-reconciliation.md` for the code-backed reconciliation of Issues, closed phases, and stale planning status text.

## Completed phases

### Phase 0.1.0 — Product specification and architecture

Status: completed and verified.

Delivered:

- authoritative requirements ledger and bidirectional traceability foundation;
- domain glossary, use cases, state machines, ERD/data model, permission catalogue and threat model;
- module boundaries, ADRs, test strategy, risk register and deployment/release planning;
- initial planning quality gate.

### Phase 0.2.0 — Foundation and installer skeleton

Status: completed and verified.

Delivered and additionally rehearsed beyond the minimum skeleton gate:

- Laravel/PHP 8.4 modular foundation and pinned CI/static/security tooling;
- secure installer preflight/finalization, immutable lock and resumable journal;
- MariaDB/authenticated Redis runtime, Outbox/idempotency foundations and base migrations;
- atomic release activation/rollback, OpenLiteSpeed target layout, Supervisor workers, one Scheduler Cron and heartbeat/stale-alert controls;
- authenticated Telegram webhook ingress, encrypted update persistence, duplicate/collision handling, queue handoff and stranded-update recovery;
- target-like aaPanel/OpenLiteSpeed and Telegram contract evidence.

Closure evidence: `evidence/0.2.0/PHASE-CLOSURE.md`.

### Phase 0.3.0 — Identity, Customers, Agents, and ACL

Status: completed and verified.

Delivered:

- Telegram-contact and SMS-OTP phone ownership verification;
- SMS provider adapters, rate limits, delivery evidence and fallback dispatch;
- customer status, tier, tag and append-only history services;
- agent application, review, approval, rejection, cooldown, reapplication and profile status lifecycle;
- multi-role administrator access with direct allow/deny/inherit overrides and explicit-deny precedence;
- short-lived sensitive-action approvals with independent approval and one-time consumption;
- database-enforced singleton Owner and protected two-party ownership transfer;
- encrypted national-ID, bank-card and full-name identity items with masked projections;
- privacy-safe customer My Account summary;
- administrator active, suspended and terminal revoked lifecycle with immediate authorization invalidation;
- request-fingerprint replay protection, row locking, current authorization revalidation, sanitized audit evidence and mandatory reasons/correlation IDs.

Closure evidence: `evidence/0.3.0/phase-closure-verification.md`.

## Active phase

### Phase 0.4.0 — Catalog, Panels, and Offerings

Status: in progress; no Green increment has yet been recorded.

Authoritative dependency order:

1. categories, products and optional variants with localized content, ordering, activation/archive, visibility and auditable history;
2. Plan Offerings, typed service modes, options and operation/add-on packages;
3. sales servers, encrypted panel connections, service targets, protocol profiles and capability discovery;
4. capacity, availability, customer/system server selection and disclosed compatible fallback;
5. custom-plan min/max/step rules and snapshotted calculation inputs;
6. trial policy, eligibility, identity/membership requirements, capacity and abuse controls;
7. Fake/Marzban/PasarGuard adapter contracts, connection tests and remote uncertain-result adoption;
8. Phase closure audit and mandatory CI evidence.

Price truth belongs to Plan Offerings, not Product/Variant identity rows. Ledger, customer/agent price resolution, immutable quotes and payment providers remain Phase 0.5. Order/provisioning/service state remains Phase 0.6.

## Later phases

| Phase | Goal | Status |
|---|---|---|
| 0.5.0 | Ledger, pricing, promotions, and payment providers | not started |
| 0.6.0 | Orders, provisioning, and service lifecycle | not started |
| 0.7.0 | Persian Telegram UX, support, content, membership, and broadcast | not started |
| 0.8.0 | Reporting, Operations Center, backup, restore, updater, and rollback | not started |
| 0.9.0 | Full regression, security hardening, performance, chaos, and release candidate | not started |
| 1.0.0 | Production package, documentation, handover, and deployment acceptance | not started |

## Non-negotiable delivery controls

- no merge or direct write to `main` during completion work;
- one bounded increment and one standard CI workflow at a time;
- no Task or phase is closed from documentation or schema presence alone;
- completion requires code, automated tests/CI on the exact SHA, and retained evidence/traceability;
- migrations are forward-only, reversible where practical and constraint-backed;
- monetary values use integer IRR at their authoritative financial boundary;
- secrets and sensitive values never enter logs, audit safe-data or evidence;
- every privileged mutation is authorized at execution time and records actor, reason, correlation ID and request fingerprint;
- retries/replays have exact-once business effect and payload conflicts fail closed;
- later work may not weaken any verified Phase 0.1–0.3 invariant.
