# Execution Ledger

The authoritative product contract is `docs/specification/master-execution-prompt.md`. This ledger records accepted repository delivery boundaries; it does not redefine that contract.

For continuation, read `AGENTS.md`, `PROJECT_STATUS.md`, `docs/project-status.json`, and the active handoff. Always live-fetch Draft PR `#6` for the exact current head.

## Current position

- branch: `develop/v1.0.0-completion`;
- PR: Draft `#6`, base `main`;
- authoritative active Issue: `#7`;
- active phase: `0.4.0 — Catalog, Panels, and Offerings`;
- last completed phase: `0.3.0`;
- latest accepted Phase 0.4 boundary: Provider Create-Equivalence Reconciliation;
- Phase 0.4 provider work status: all currently identified non-live defects/evidence complete; controlled live-provider acceptance is blocked on owner-supplied test panels;
- live acceptance matrix: `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
- active handoff: `docs/42-phase-0.4-controlled-live-provider-handoff.md`.

`main` remains unchanged by this completion work. Phase `0.4.0` remains open until controlled live-provider acceptance and final closure audit pass.

## Completed phases

### Phase 0.1.0 — Product specification and architecture

Status: completed/verified.

Accepted scope includes authoritative requirements, glossary/use cases/state machines/data model, permission/threat model, ADR/module boundaries, test strategy, risk register, deployment/release planning, and planning quality gates.

### Phase 0.2.0 — Foundation, installer/runtime, operations, Telegram ingress

Status: completed/verified.

Closure evidence: `evidence/0.2.0/PHASE-CLOSURE.md`.

Accepted scope includes Laravel/PHP 8.4 foundation, secure installer/finalization, MariaDB/authenticated Redis runtime, Outbox/idempotency primitives, release activation/rollback, worker/scheduler health controls, and authenticated Telegram ingress/recovery.

### Phase 0.3.0 — Identity, Customers, Agents, ACL

Status: completed/verified.

Closure:

- SHA `979b0c99d79dbcc8cff273ccdfad2ea612796a0c`;
- CI `31035712555` / #798 — success;
- 194 tests / 958 assertions;
- evidence `evidence/0.3.0/phase-closure-verification.md`.

Accepted scope includes phone ownership/SMS OTP, customer lifecycle, agent lifecycle, multi-role ACL/direct overrides, short-lived independent sensitive approvals, Owner singleton/transfer, encrypted identity items, privacy-safe summaries, admin lifecycle and replay/conflict/locking/audit controls.

## Phase 0.4 accepted increments

Phase boundary: Catalog identity, Offerings, panels/targets, capacity, Custom Plans, Trials, fallback and Panel Adapter concerns belong here. Pricing/Quotes/ledger/payments remain Phase `0.5.0`; Orders/provisioning/Service lifecycle remain Phase `0.6.0`.

### 1 — Category/Product/Variant lifecycle

- implementation `f907fc463ff809dd2f5f69d1433e9c326f8574b0`, CI #802;
- evidence `570f0a7c873c5638d63488714c7c59500de8cbcc`, CI #803;
- 205 tests / 1006 assertions;
- `evidence/0.4.0/catalog-category-product-variant-lifecycle.md`;
- `docs/23-phase-0.4-catalog-traceability.md`.

### 2a — Secure Panel Connection foundation

- implementation `2174bc7844fde0282f75e6d258a7789dc588ecf8`, CI #806;
- evidence `22f04ed66ca3f9aefe4ee68d329bae6ff5e69706`, CI #807;
- 217 tests / 1072 assertions;
- `evidence/0.4.0/panel-connection-foundation.md`;
- `docs/24-phase-0.4-panel-connection-traceability.md`.

### 2b — Protocol Profile, Service Target, Sales Server

- implementation `7c48399a2be8a79ddb078f79684d73dd9869b322`, CI #811;
- evidence `e8e397cdfe44713f28b79b6cf98c89eff24d2b56`, CI #812;
- 227 tests / 1121 assertions;
- `evidence/0.4.0/panel-target-protocol-server-foundation.md`;
- `docs/25-phase-0.4-panel-inventory-traceability.md`.

Real Targets remain disabled/declared until accepted live adapter/capability evidence exists.

### 3 — Plan Offering foundation

- implementation `cf0971c068c46d8e71d4f7765fdda249d9a24471`, CI #816;
- evidence `88e8e27d9ab61bb8ac2815c411b12e6395b48d0a`, CI #817;
- 236 tests / 1171 assertions;
- `evidence/0.4.0/plan-offering-foundation.md`;
- `docs/26-phase-0.4-plan-offering-traceability.md`.

### 4a — Target Capacity Accounting

- implementation `72b19d556430b42d1065cb9ca9cdb5cd3125dfdc`, CI #822;
- evidence `f07ffbb8645ed2556debf6746e465fa5cc5e3b5b`, CI #823;
- 241 tests / 1204 assertions;
- `evidence/0.4.0/target-capacity-accounting.md`;
- `docs/27-phase-0.4-target-capacity-traceability.md`.

### 4b — Availability, Route Selection, Disclosed Fallback

- implementation `ca88704d34f7df68799d8673e30c3ff479529206`, CI #826;
- evidence `dd770add3bcc3f10e55d2636f83befc8c50cdd38`, CI #827;
- 247 tests / 1228 assertions;
- `evidence/0.4.0/route-selection-fallback.md`;
- `docs/28-phase-0.4-route-selection-traceability.md`.

### 5 — Custom Plan Policy and Calculation Snapshot

- implementation `8e62867277acdd39cd1471ed3d454ef25520bef8`, CI #833;
- evidence `0d34af0aa4f9f227fdf3cae74b4fd4717f199ddf`, CI #834;
- 255 tests / 1267 assertions;
- `evidence/0.4.0/custom-plan-policy-calculation.md`;
- `docs/29-phase-0.4-custom-plan-traceability.md`.

No Quote/Order/ledger/payment/provisioning/remote effect is claimed by this boundary.

### 6 — Trial Policy and Panel Adapter offline/Fake foundation

- implementation `b146c2c6aa902b4ed252d200121d63e422cd87f2`, CI #931;
- evidence `31a1854a2804bb0b2cf466c96887de7c5813b343`, CI #934;
- 298 tests / 1471 assertions;
- `evidence/0.4.0/trial-policy-panel-adapter-foundation.md`;
- `docs/34-phase-0.4-trial-panel-traceability.md`.

Accepted: Trial eligibility/reservation/capacity/abuse/fallback, common adapter contracts, deterministic Fake remote behavior, authoritative lookup/adoption/conflict, uncertainty discovery, mutation idempotency and fail-closed real-provider shells.

### 7 — Pinned Marzban/PasarGuard read contracts

- implementation `954973e505901208b5cef9348551e0c71ac027b6`, CI #959;
- evidence `23a8da1ce1327407ecd826daa87b334452883d77`, CI #961;
- 306 tests / 1522 assertions;
- evidence artifact ID `8993821250`, digest `sha256:9a45179652a3a88456c835c4d715aceef4efaf770c06b8246f0ebd68ca1b6fe6`;
- `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
- `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`.

Accepted provider pins: Marzban `v0.8.4`, PasarGuard `v5.2.1`. Runtime provider behavior remains read-only/fail-closed for mutations.

### 8 — Pinned provider mutation contract mapping

- implementation `15b824e955d040a6bf43405f7015aa85110a73e4`, CI #978;
- evidence `eec613c1cb5241d8fff621047086361f2753fd24`, CI #980;
- 313 tests / 1650 assertions;
- evidence artifact ID `8998628512`, digest `sha256:ac5caaaad4a83af04efd27f3c88cfeb857755e497bd07a46fa1037eadea4361a`;
- `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`;
- `docs/38-phase-0.4-panel-provider-mutation-contract-traceability.md`.

Accepted offline mappings: create/update expiry/update data/reset/suspend/activate/delete/rotation/delivery, result taxonomy, additive-data safety and provider create-equivalence. Runtime mutations remain disabled.

### 9 — Provider Create-Equivalence Reconciliation

Root cause reconciled: generic provider-observable `RemoteServiceSnapshot::canonicalHash` was still being used by the common resolver as create equality even though provider-specific equivalence mappers already existed.

Accepted correction:

- separate provider-observable `canonicalHash` from optional provider-specific `createEquivalenceHash`;
- require provider-specific preserved-field equivalence before automatic adoption;
- missing proof => Manual Review/no create;
- mismatch => conflict/no overwrite;
- successful-create snapshot must pass the same provider-specific equivalence validation;
- source gateways derive equivalence through the pinned mutation-contract mappers without enabling live mutation.

Implementation:

- SHA `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43`;
- CI `31223470871` / #995 — success;
- 315 tests / 1678 assertions;
- artifact `test-evidence-31223470871`, ID `9011256284`;
- independent digest `sha256:f3fc78ab55ba0adbb2814bb6d0de464736e897df36625b8f72f54b3f7b8fe95d`.

Evidence:

- SHA `a70b28cb984c23f1e219287e88d20014ee9f0310`;
- CI `31223749257` / #997 — success;
- 315 tests / 1678 assertions;
- artifact `test-evidence-31223749257`, ID `9011359876`;
- independent digest `sha256:140e6a45fe2ef9523dee0147f6131a1e2d1bcaf63548b57eea7b83d3f0d9b827`;
- `evidence/0.4.0/provider-create-equivalence-reconciliation.md`;
- `docs/40-phase-0.4-provider-create-equivalence-traceability.md`.

## Remaining Phase 0.4 gate

No remaining non-live provider defect is currently identified by the reconciliation audit.

The remaining provider acceptance is deployment-specific and defined exactly in:

- `docs/41-phase-0.4-provider-live-acceptance-matrix.md`;
- `docs/42-phase-0.4-controlled-live-provider-handoff.md`.

It requires owner-supplied controlled Marzban `v0.8.4` and PasarGuard `v5.2.1` test environments (or explicit re-review authorization for different exact builds), protected credentials, disposable test-user permission, live auth/version/target/create/adopt/mutation/delivery/fault/cleanup evidence, and a separate explicit Target activation decision.

Until that gate passes:

- real provider mutation/delivery capabilities remain unadvertised/fail closed;
- real Targets remain disabled/unverified;
- no live compatibility claim;
- no Phase `0.4.0` closure.

## Later phases

| Phase | Goal | Status |
|---|---|---|
| `0.5.0` | Ledger, pricing, promotions, payment providers | not started except shared foundations |
| `0.6.0` | Orders, provisioning, Service lifecycle | not started except shared foundations |
| `0.7.0` | Persian Telegram UX, support, content, membership, broadcast | not started except localized installer/identity strings |
| `0.8.0` | Reporting, Operations Center, backup/restore/updater/rollback | not started except runtime/worker/release foundations |
| `0.9.0` | hardening/performance/chaos/release candidate | not started |
| `1.0.0` | production package/docs/handover/deployment acceptance | not started |

## Non-negotiable delivery controls

- no merge, Ready, auto-merge, history rewrite, force-push, temporary branch, or direct `main` write;
- live head always comes from PR `#6`;
- no phase/increment closes from docs/schema/fake/interface presence alone;
- accepted code increments require exact implementation CI/artifact plus exact evidence-head CI;
- integer IRR at monetary boundaries;
- secrets never enter repository/chat/Issues/PR/evidence;
- privileged mutations re-authorize at execution time;
- replay/idempotency conflicts fail closed and preserve original primary effect;
- authoritative remote lookup precedes create;
- missing equivalence proof blocks adoption/create;
- uncertain remote results are discovered before retry;
- later work may not weaken accepted earlier boundaries.