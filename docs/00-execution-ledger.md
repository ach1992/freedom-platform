# Execution Ledger

The authoritative product contract is `docs/specification/master-execution-prompt.md`. This ledger records accepted repository delivery boundaries; it does not replace or redefine that contract.

For live continuation, read `AGENTS.md`, `PROJECT_STATUS.md`, and `docs/project-status.json`. Always fetch PR `#6` for the exact current head.

## Current position

- Active branch: `develop/v1.0.0-completion`.
- Pull request: Draft PR `#6` targeting `main`.
- Authoritative active Issue: `#7`.
- `main` remains unchanged by completion work.
- Active phase: `0.4.0 — Catalog, Panels, and Offerings`.
- Last completed phase: `0.3.0 — Identity, Customers, Agents, and ACL`.
- Last independently verified Phase 0.4 boundary: Custom Plan Policy and Calculation Snapshot.
- Active unverified increment: Trial Policy and Panel Adapter Foundation.
- Current stabilization mode: restore deterministic CI and project-control consistency before accepting or extending the active increment.

## Completed phases

### Phase 0.1.0 — Product specification and architecture

Status: completed and verified.

Delivered:

- authoritative requirements ledger and bidirectional traceability foundation;
- domain glossary, use cases, state machines, ERD/data model, permission catalogue and threat model;
- module boundaries, ADRs, test strategy, risk register and deployment/release planning;
- initial planning quality gate.

### Phase 0.2.0 — Foundation, installer/runtime, operations, and Telegram ingress

Status: completed and verified.

Delivered and rehearsed:

- Laravel/PHP 8.4 modular foundation and locked CI/static/security tooling;
- secure installer preflight/finalization, immutable lock and resumable journal;
- MariaDB/authenticated Redis runtime, Outbox/idempotency foundations and base migrations;
- atomic release activation/rollback, OpenLiteSpeed target layout, Supervisor workers, one Scheduler Cron and heartbeat/stale-alert controls;
- authenticated Telegram webhook ingress, encrypted update persistence, duplicate/collision handling, queue handoff and stranded-update recovery;
- target-like aaPanel/OpenLiteSpeed and Telegram contract evidence.

Closure evidence: `evidence/0.2.0/PHASE-CLOSURE.md`.

### Phase 0.3.0 — Identity, Customers, Agents, and ACL

Status: completed and verified.

Closure boundary:

- closure SHA: `979b0c99d79dbcc8cff273ccdfad2ea612796a0c`;
- mandatory CI: `31035712555` / run `#798` — success;
- suite: 194 tests, 958 assertions;
- artifact: `test-evidence-31035712555`;
- evidence: `evidence/0.3.0/phase-closure-verification.md`.

Delivered:

- Telegram-contact and SMS-OTP phone ownership verification;
- SMS provider adapters, rate limits, delivery evidence and fallback dispatch;
- customer status, tier, tag and append-only history services;
- agent application/review/profile lifecycle;
- multi-role administrator access with direct allow/deny/inherit overrides and explicit-deny precedence;
- short-lived sensitive-action approvals with independent approval and one-time consumption;
- database-enforced singleton Owner and protected two-party ownership transfer;
- encrypted national-ID, bank-card and full-name identity items with masked projections;
- privacy-safe customer account summary;
- administrator lifecycle with immediate authorization invalidation;
- replay/conflict protection, row locking, execution-time authorization, sanitized audit evidence and mandatory reasons/correlation IDs.

## Active phase

### Phase 0.4.0 — Catalog, Panels, and Offerings

Status: active under Issue `#7`.

Phase boundary:

- Catalog identity, offerings, panels/targets, capacity, custom plans, trials, disclosed fallback and panel adapters belong here.
- Resolved customer/agent pricing, promotions, immutable Quote, ledger and payment providers remain Phase `0.5.0`.
- Order, provisioning orchestration and service lifecycle remain Phase `0.6.0`.

### Verified increment 1 — Category/Product/Variant lifecycle

Implementation:

- SHA: `f907fc463ff809dd2f5f69d1433e9c326f8574b0`;
- CI: `31047128887` / run `#802` — success.

Evidence:

- SHA: `570f0a7c873c5638d63488714c7c59500de8cbcc`;
- CI: `31047402302` / run `#803` — success;
- suite: 205 tests, 1006 assertions;
- evidence: `evidence/0.4.0/catalog-category-product-variant-lifecycle.md`;
- traceability: `docs/23-phase-0.4-catalog-traceability.md`.

### Verified increment 2a — Secure Panel Connection foundation

Implementation:

- SHA: `2174bc7844fde0282f75e6d258a7789dc588ecf8`;
- CI: `31053240018` / run `#806` — success.

Evidence:

- SHA: `22f04ed66ca3f9aefe4ee68d329bae6ff5e69706`;
- CI: `31053486538` / run `#807` — success;
- suite: 217 tests, 1072 assertions;
- evidence: `evidence/0.4.0/panel-connection-foundation.md`;
- traceability: `docs/24-phase-0.4-panel-connection-traceability.md`.

### Verified increment 2b — Protocol Profile, Service Target, and Sales Server

Implementation:

- SHA: `7c48399a2be8a79ddb078f79684d73dd9869b322`;
- CI: `31059517586` / run `#811` — success.

Evidence:

- SHA: `e8e397cdfe44713f28b79b6cf98c89eff24d2b56`;
- CI: `31059726267` / run `#812` — success;
- suite: 227 tests, 1121 assertions;
- artifact digest: `sha256:c3e21de27a21f9b1685ca5728187f66f0c9b3747082ad410573235891edb9f0e`;
- evidence: `evidence/0.4.0/panel-target-protocol-server-foundation.md`;
- traceability: `docs/25-phase-0.4-panel-inventory-traceability.md`.

Targets remained disabled with declared capabilities until adapter evidence exists.

### Verified increment 3 — Plan Offering foundation

Implementation:

- SHA: `cf0971c068c46d8e71d4f7765fdda249d9a24471`;
- CI: `31061752803` / run `#816` — success.

Evidence:

- SHA: `88e8e27d9ab61bb8ac2815c411b12e6395b48d0a`;
- CI: `31061978803` / run `#817` — success;
- suite: 236 tests, 1171 assertions;
- artifact digest: `sha256:cccaa861121ff15fa1b00a395bcd1bd2de4507a01f78afc6090936fec731037f`;
- evidence: `evidence/0.4.0/plan-offering-foundation.md`;
- traceability: `docs/26-phase-0.4-plan-offering-traceability.md`.

### Verified increment 4a — Target Capacity Accounting

Implementation:

- SHA: `72b19d556430b42d1065cb9ca9cdb5cd3125dfdc`;
- CI: `31065079295` / run `#822` — success.

Evidence:

- SHA: `f07ffbb8645ed2556debf6746e465fa5cc5e3b5b`;
- CI: `31065264371` / run `#823` — success;
- suite: 241 tests, 1204 assertions;
- digest: `sha256:61b18070bfce7c68542eb3597d8738f9344f26a4729c7c167feff09e9effa435`;
- evidence: `evidence/0.4.0/target-capacity-accounting.md`;
- traceability: `docs/27-phase-0.4-target-capacity-traceability.md`.

### Verified increment 4b — Availability, Route Selection, and Disclosed Fallback

Implementation:

- SHA: `ca88704d34f7df68799d8673e30c3ff479529206`;
- CI: `31067121678` / run `#826` — success.

Evidence:

- SHA: `dd770add3bcc3f10e55d2636f83befc8c50cdd38`;
- CI: `31067304499` / run `#827` — success;
- suite: 247 tests, 1228 assertions;
- artifact: `test-evidence-31067304499`;
- digest: `sha256:0bb4cf0a9d57b027ef9c3dd30ac7d1d2aeb160a752218c10420367984f3343ff`;
- evidence: `evidence/0.4.0/route-selection-fallback.md`;
- traceability: `docs/28-phase-0.4-route-selection-traceability.md`.

Production selection remains fail-closed until real adapter evidence exists.

### Verified increment 5 — Custom Plan Policy and Calculation Snapshot

Implementation:

- SHA: `8e62867277acdd39cd1471ed3d454ef25520bef8`;
- CI: `31071843621` / run `#833` — success;
- suite: 255 tests, 1267 assertions.

Evidence:

- SHA: `0d34af0aa4f9f227fdf3cae74b4fd4717f199ddf`;
- CI: `31102652203` / run `#834` — success;
- artifact: `test-evidence-31102652203`;
- artifact ID: `8968201643`;
- digest: `sha256:5ec6b6dd94e1305c17312650abdc94a5253521834911c26eb48f8decbd105cb9`;
- evidence: `evidence/0.4.0/custom-plan-policy-calculation.md`;
- traceability: `docs/29-phase-0.4-custom-plan-traceability.md`.

This boundary creates no Quote, Order, ledger, payment, provisioning, or remote panel effect.

## Active unverified increment

### Increment 6 — Trial Policy and Panel Adapter Foundation

Status: unverified. Authoritative handoff: `docs/30-phase-0.4-trial-panel-handoff.md`.

Bounded implementation includes:

- trial policy/reservation, eligibility, capacity, abuse controls, and fallback;
- Panel Adapter common contract;
- deterministic `FakePanelAdapter`;
- remote identity lookup and exact-match adoption;
- mismatch conflict/manual review;
- uncertain-result discovery before retry;
- mutation idempotency journal;
- request/snapshot/capability/result validation;
- credential and delivery-artifact redaction;
- unavailable Marzban/PasarGuard shells that fail closed.

No implementation or evidence SHA after the Custom Plan boundary is accepted until mandatory exact-head CI, artifact evidence, and an evidence-head CI pass.

## Stabilization overlay

Before active increment verification continues:

1. restore deterministic self-hosted CI execution;
2. apply bounded dependency/formatting repairs;
3. remove temporary repair automation;
4. reconcile project status, traceability, risk, README, PR, and Issue text;
5. reduce unsafe staging workflow surface;
6. pass mandatory CI on the exact stabilization head.

See `docs/31-project-control-plane-audit.md`.

## Later phases

| Phase | Goal | Status |
|---|---|---|
| `0.5.0` | Ledger, pricing, promotions, and payment providers | not started |
| `0.6.0` | Orders, provisioning, and service lifecycle | not started |
| `0.7.0` | Persian Telegram UX, support, content, membership, and broadcast | not started |
| `0.8.0` | Reporting, Operations Center, backup, restore, updater, and rollback | not started |
| `0.9.0` | Full regression, security hardening, performance, chaos, and release candidate | not started |
| `1.0.0` | Production package, documentation, handover, and deployment acceptance | not started |

## Non-negotiable delivery controls

- no merge, Ready for review, auto-merge, history rewrite, temporary branch, or direct write to `main`;
- live head always comes from PR `#6`;
- no phase/increment is closed from documentation, schema, fake, or interface presence alone;
- completion requires code, mandatory CI on exact implementation SHA, retained evidence, and mandatory CI on exact evidence head;
- monetary values use integer IRR at authoritative boundaries;
- secrets and sensitive values never enter logs, audit safe-data, screenshots, Issues, PR text, or evidence;
- privileged mutations re-authorize at execution time and record actor/reason/correlation/replay evidence;
- retries/replays have exact-once business effect and payload conflicts fail closed;
- authoritative remote lookup precedes create; uncertain remote results are discovered before retry;
- later work may not weaken any verified Phase `0.1.0`–`0.4.0` boundary.
