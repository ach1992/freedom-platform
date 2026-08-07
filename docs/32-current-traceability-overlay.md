# Current Traceability Overlay

**Last reviewed:** 2026-08-08  
**Purpose:** authoritative current implementation/evidence status while the baseline matrix is incrementally reconciled.  
**Authority:** requirement wording remains in `docs/01-authoritative-requirements.md`; this overlay supersedes stale status cells in `docs/02-requirement-traceability-matrix.md`.

Read with `PROJECT_STATUS.md`, `docs/project-status.json`, and the active handoff. The live working head always comes from Draft PR `#6`.

## Status vocabulary

- `verified`: exact implementation/evidence lifecycle accepted;
- `verified-offline`: deterministic implementation/source-contract proof accepted, with deployment-specific live acceptance still required;
- `harness-verified`: guarded live harness implementation/evidence accepted, but the deployment has not yet been exercised;
- `blocked-live`: next required evidence depends on protected live execution or another human-controlled environment action;
- `carried-release-gate`: mandatory requirement intentionally scheduled for final release acceptance and not considered complete;
- `foundation-only`: prerequisite exists but the owning later workflow remains incomplete;
- `not-started`: no accepted implementation claim.

## Phase status

| Phase | Status | Current authority |
|---|---|---|
| `0.1.0` | verified | planning/specification baseline |
| `0.2.0` | verified | `evidence/0.2.0/PHASE-CLOSURE.md` |
| `0.3.0` | verified | `evidence/0.3.0/phase-closure-verification.md` |
| `0.4.0` | blocked-live | PasarGuard live harness is evidence-complete; protected live execution remains; Marzban live acceptance is a carried final-release gate |
| `0.5.0`–`1.0.0` | not-started except explicitly documented foundations | authoritative phase plan |

Phase `0.4.0` remains open. The owner scheduling decision to test Marzban at final project/release acceptance changes timing only; it does not delete the Marzban requirement or satisfy Issue `#7` closure.

## Phase 0.4 accepted functional boundaries

| Area / requirement | Current status | Accepted evidence |
|---|---|---|
| `CAT-001` category/product/variant lifecycle | verified | `evidence/0.4.0/catalog-category-product-variant-lifecycle.md`; `docs/23-phase-0.4-catalog-traceability.md` |
| `CAT-002` product/offering/panel inventory foundations | verified within Phase 0.4 scope | `docs/23-*` through `docs/26-*` and panel inventory evidence |
| `CAT-003` typed service mode | verified | Plan Offering evidence/traceability |
| `CAT-004` protocol profile/target assignment foundation | verified | `docs/25-phase-0.4-panel-inventory-traceability.md` |
| `CAT-005` Custom Plan policy/calculation snapshot | verified | `evidence/0.4.0/custom-plan-policy-calculation.md`; `docs/29-phase-0.4-custom-plan-traceability.md` |
| `CAT-006` Trial policy/eligibility/capacity/abuse/fallback | verified offline/database scope | `evidence/0.4.0/trial-policy-panel-adapter-foundation.md`; `docs/34-phase-0.4-trial-panel-traceability.md` |
| `CAT-008` capacity/availability/selection/disclosed fallback | verified | `docs/27-*`, `docs/28-*`, Trial integration evidence |
| `DAT-001`, `DAT-003` Phase 0.4 data constraints | verified within accepted boundaries | exact migrations/tests/evidence for UTC snapshots, FK/unique/check/trigger constraints and provider data-update guards |
| `ACL-002` Phase 0.4 admin mutations | verified within accepted services | execution-time authorization tests/evidence |
| `SEC-001`, `SEC-002` Phase 0.4 provider controls | verified-offline / harness-verified for PasarGuard | TLS/redaction/fail-closed/credential/replay/uncertainty evidence; PasarGuard guarded live harness; deployment evidence pending |
| `QUA-001` | verified per accepted increment | exact-SHA CI, retained artifacts and independently checked digests |

## Panel/provider requirement reconciliation

### `PRV-001`

Status: **verified-offline; PasarGuard harness-verified / blocked-live; Marzban carried-release-gate**.

Accepted repository proof:

- encrypted/validated/versioned Panel Connection foundation;
- explicit common adapter capabilities/results/snapshots;
- deterministic Fake adapter;
- pinned Marzban `v0.8.4` and PasarGuard `v5.2.1` authentication/version/read/lookup/target source contracts;
- provider factories bound to pinned read gateways;
- PasarGuard guarded live harness enforces exact `5.2.1` before mutation, HTTPS/TLS verification, no redirects, API-base/inbound/group discovery and protected secret inputs;
- real mutation/delivery capabilities remain unadvertised;
- newly created real Service Targets remain disabled/declared;
- operational route verification still requires active Target, verified capabilities, current successful compatible connection/version evidence and active protocol assignment.

Remaining PasarGuard evidence is deployment-specific protected authentication/version/health/target execution. Marzban deployment proof remains mandatory at final release acceptance.

### `PRV-002`

Status: **verified-offline; PasarGuard harness-verified / blocked-live; Marzban carried-release-gate**.

Accepted repository proof:

- authoritative username lookup before create;
- deterministic create coordinator and adoption/conflict behavior;
- provider-specific create-equivalence mappers using only provider-preserved fields;
- `RemoteServiceSnapshot::canonicalHash` is read-state evidence only;
- separate `createEquivalenceHash` is required for automatic adoption;
- missing equivalence proof => Manual Review/no create;
- mismatch => conflict/no overwrite;
- successful-create snapshot is revalidated through the same provider-specific equivalence path;
- conflicting idempotency-key reuse preserves the original primary effect;
- PasarGuard guarded harness prepares one-create, post-read equivalence, mismatch, expiry/data/reset/status/rotation/delete and final absence checks with `finally` cleanup.

Remaining PasarGuard live evidence includes actual remote effects, coordinator-level adoption/idempotency replay and cleanup on the controlled deployment. Equivalent Marzban live proof remains carried to final release acceptance.

### `PRV-003`

Status: **verified-offline; PasarGuard harness partially prepared / blocked-live; Marzban carried-release-gate**.

Accepted repository proof:

- unavailable authoritative lookup => no create;
- unavailable/invalid create-equivalence mapping => no create;
- uncertain create/mutation => authoritative discovery before retry;
- no immediate second create after uncertainty;
- offline mutation result classification distinguishes definitive, retryable-before-effect, uncertain-after-possible-effect and conflict/manual-review states;
- PasarGuard harness preserves discovery-before-cleanup/delete and safe delivery redaction.

Remaining live evidence: controlled timeout/5xx/429 fault injection, post-effect authoritative reconciliation and real integration idempotency behavior. Marzban equivalent live proof remains a final-release gate.

## Provider evidence chain

### Trial/Panel offline/Fake foundation

- implementation `b146c2c6aa902b4ed252d200121d63e422cd87f2`, CI #931;
- evidence `31a1854a2804bb0b2cf466c96887de7c5813b343`, CI #934;
- 298 tests / 1471 assertions.

### Pinned read contracts

- implementation `954973e505901208b5cef9348551e0c71ac027b6`, CI #959;
- evidence `23a8da1ce1327407ecd826daa87b334452883d77`, CI #961;
- 306 tests / 1522 assertions;
- `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
- `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`.

### Pinned mutation contracts

- implementation `15b824e955d040a6bf43405f7015aa85110a73e4`, CI #978;
- evidence `eec613c1cb5241d8fff621047086361f2753fd24`, CI #980;
- 313 tests / 1650 assertions;
- `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`;
- `docs/38-phase-0.4-panel-provider-mutation-contract-traceability.md`.

### Provider create-equivalence reconciliation

- implementation `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43`, CI `31223470871` / #995;
- evidence `a70b28cb984c23f1e219287e88d20014ee9f0310`, CI `31223749257` / #997;
- 315 tests / 1678 assertions;
- evidence artifact ID `9011359876`, digest `sha256:140e6a45fe2ef9523dee0147f6131a1e2d1bcaf63548b57eea7b83d3f0d9b827`;
- `evidence/0.4.0/provider-create-equivalence-reconciliation.md`;
- `docs/40-phase-0.4-provider-create-equivalence-traceability.md`.

### PasarGuard guarded live-acceptance harness

Implementation:

- SHA `e18460357d306789cbbf85721f61a4e3a3bbb0e2`;
- CI `31226863010` / #1013 — success;
- 317 tests / 1730 assertions;
- artifact ID `9012421937`;
- digest `sha256:72d10f54f0347ac743471c78ea4401a4b9268a763e6653cb2a3c17bf4e2608e6`.

Evidence:

- SHA `71ca4b39df41bc9fcf725c30e9caba3285ee5412`;
- CI `31227084007` / #1015 — success;
- 317 tests / 1730 assertions;
- artifact ID `9012490991`;
- digest `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`;
- `evidence/0.4.0/pasarguard-live-acceptance-harness.md`;
- `docs/43-phase-0.4-pasarguard-live-harness-traceability.md`.

This boundary proves the guarded harness only. No successful live PasarGuard connectivity or remote mutation is claimed yet.

## Current live-provider schedule

The generic execution matrix remains `docs/41-phase-0.4-provider-live-acceptance-matrix.md`.

Current execution authority is `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`:

- PasarGuard `v5.2.1` is the provider scheduled for live acceptance now;
- its guarded workflow requires protected repository Actions Secrets and manual dispatch;
- Marzban `v0.8.4` live acceptance is intentionally deferred to final project/release acceptance by owner decision on 2026-08-08;
- Marzban is not removed from the product contract and Issue `#7` remains open;
- source/offline/harness evidence never satisfies an unexecuted live row.

The PasarGuard harness prepares authentication/version/discovery/absence/create/equivalence/mismatch/expiry/data/reset/suspend/activate/rotation/delivery/delete/cleanup rows. Coordinator-level adoption/idempotency, controlled timeout/5xx/429 fault rows and explicit Target activation remain separately pending.

## Later-phase foundations and carry-forward rule

| Requirement group | Existing foundation | Owning future work |
|---|---|---|
| `PAY-002`, `PAY-003` | shared typed states/idempotency/outbox primitives | Phase `0.5.0` pricing/payment orchestration and reconciliation |
| `C2C-003`, `GFT-003` | interfaces/foundations where present | Phase `0.5.0` provider implementations/security evidence |
| `OPS-001`, `OPS-003` | redaction/outbox/worker heartbeat/release foundations | Phase `0.8.0` complete Operations Center and dead-letter/alert workflows |
| `INS-001`, `RUN-001`–`RUN-003` | Phase 0.2 installer/runtime/release evidence | final release environment re-verification |
| `CNT-001` | installer/identity translations | Phase `0.7.0` product localization/content system |

Later-phase implementation may proceed under the owner-approved provider scheduling exception only while Phase `0.4.0` remains explicitly open. No later-phase work may weaken provider invariants or be used to claim Phase 0.4/release completion before the carried live gates pass.

## Matrix reconciliation rule

Until the large baseline matrix is regenerated:

1. requirement wording comes from `docs/01-authoritative-requirements.md`;
2. accepted historical proof comes from bounded evidence/traceability files;
3. current status comes from this overlay and `docs/project-status.json`;
4. live head/CI comes only from GitHub PR `#6`;
5. source/offline/harness provider proof must never be described as executed live-provider acceptance;
6. Phase `0.4.0` remains open until PasarGuard's applicable live rows, the carried Marzban final live gate and the final closure audit pass.
