# Current Traceability Overlay

**Last reviewed:** 2026-08-08  
**Purpose:** authoritative current implementation/evidence status while the baseline matrix is incrementally reconciled.  
**Authority:** requirement wording remains in `docs/01-authoritative-requirements.md`; this overlay supersedes stale status cells in `docs/02-requirement-traceability-matrix.md`.

Read with `PROJECT_STATUS.md`, `docs/project-status.json`, and the active handoff. The live working head always comes from Draft PR `#6`.

## Status vocabulary

- `verified`: exact implementation/evidence lifecycle accepted;
- `verified-offline`: deterministic implementation/source-contract proof accepted, with environment-specific live acceptance still required;
- `blocked-live`: all currently identified non-live work is complete and the next required evidence depends on an owner-supplied controlled environment;
- `foundation-only`: prerequisite exists but the owning later workflow remains incomplete;
- `not-started`: no accepted implementation claim.

## Phase status

| Phase | Status | Current authority |
|---|---|---|
| `0.1.0` | verified | planning/specification baseline |
| `0.2.0` | verified | `evidence/0.2.0/PHASE-CLOSURE.md` |
| `0.3.0` | verified | `evidence/0.3.0/phase-closure-verification.md` |
| `0.4.0` | blocked-live | non-live Catalog/Panels/Offerings increments accepted; controlled live-provider matrix remains |
| `0.5.0`–`1.0.0` | not-started except explicitly documented foundations | authoritative phase plan |

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
| `DAT-001`, `DAT-003` Phase 0.4 data constraints | verified within accepted boundaries | exact migrations/tests/evidence for UTC snapshots, FK/unique/check/trigger constraints |
| `ACL-002` Phase 0.4 admin mutations | verified within accepted services | execution-time authorization tests/evidence |
| `SEC-001`, `SEC-002` Phase 0.4 provider controls | verified-offline | TLS/redaction/fail-closed/credential/replay/uncertainty evidence; live environment gate remains |
| `QUA-001` | verified per accepted increment | exact-SHA CI, retained artifacts and independent digests |

## Panel/provider requirement reconciliation

### `PRV-001`

Status: **verified-offline / blocked-live**.

Accepted repository proof:

- encrypted/validated/versioned Panel Connection foundation;
- explicit common adapter capabilities/results/snapshots;
- deterministic Fake adapter;
- pinned Marzban `v0.8.4` and PasarGuard `v5.2.1` authentication/version/read/lookup/target source contracts;
- provider factories bound to pinned read gateways;
- real mutation/delivery capabilities remain unadvertised;
- newly created real Service Targets remain disabled/declared;
- operational route verification requires active Target, verified capabilities, current successful compatible connection/version evidence and active protocol assignment.

Remaining requirement evidence is deployment-specific: protected live authentication, exact running version/health/capability/target acceptance and explicit later Target activation proof.

### `PRV-002`

Status: **verified-offline / blocked-live**.

Accepted repository proof:

- authoritative username lookup before create;
- deterministic create coordinator and adoption/conflict behavior;
- provider-specific create-equivalence mappers using only provider-preserved fields;
- `RemoteServiceSnapshot::canonicalHash` is read-state evidence only;
- separate `createEquivalenceHash` is required for automatic adoption;
- missing equivalence proof => Manual Review/no create;
- mismatch => conflict/no overwrite;
- successful-create snapshot is revalidated through the same provider-specific equivalence path;
- conflicting idempotency-key reuse preserves the original primary effect.

Remaining live evidence: disposable real create/adopt/mismatch/replay/cleanup behavior on controlled panels.

### `PRV-003`

Status: **verified-offline / blocked-live**.

Accepted repository proof:

- unavailable authoritative lookup => no create;
- unavailable/invalid create-equivalence mapping => no create;
- uncertain create/mutation => authoritative discovery before retry;
- no immediate second create after uncertainty;
- offline mutation result classification distinguishes definitive, retryable-before-effect, uncertain-after-possible-effect and conflict/manual-review states.

Remaining live evidence: controlled timeout/5xx/rate-limit fault injection and authoritative reconciliation on the actual pinned provider deployments.

## Provider evidence chain

### Trial/Panel offline/Fake foundation

- implementation `b146c2c6aa902b4ed252d200121d63e422cd87f2`, CI #931;
- evidence `31a1854a2804bb0b2cf466c96887de7c5813b343`, CI #934;
- 298 tests / 1471 assertions.

### Pinned read contracts

- implementation `954973e505901208b5cef9348551e0c71ac027b6`, CI #959;
- evidence `23a8da1ce1327407ecd826daa87b334452883d77`, CI #961;
- 306 tests / 1522 assertions;
- evidence: `evidence/0.4.0/pinned-panel-provider-read-contracts.md`;
- traceability: `docs/36-phase-0.4-panel-provider-read-contract-traceability.md`.

### Pinned mutation contracts

- implementation `15b824e955d040a6bf43405f7015aa85110a73e4`, CI #978;
- evidence `eec613c1cb5241d8fff621047086361f2753fd24`, CI #980;
- 313 tests / 1650 assertions;
- evidence: `evidence/0.4.0/pinned-panel-provider-mutation-contracts.md`;
- traceability: `docs/38-phase-0.4-panel-provider-mutation-contract-traceability.md`.

### Provider create-equivalence reconciliation

- implementation `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43`, CI `31223470871` / #995;
- evidence `a70b28cb984c23f1e219287e88d20014ee9f0310`, CI `31223749257` / #997;
- 315 tests / 1678 assertions;
- implementation artifact ID `9011256284`, digest `sha256:f3fc78ab55ba0adbb2814bb6d0de464736e897df36625b8f72f54b3f7b8fe95d`;
- evidence artifact ID `9011359876`, digest `sha256:140e6a45fe2ef9523dee0147f6131a1e2d1bcaf63548b57eea7b83d3f0d9b827`;
- evidence: `evidence/0.4.0/provider-create-equivalence-reconciliation.md`;
- traceability: `docs/40-phase-0.4-provider-create-equivalence-traceability.md`.

## Deferred controlled live acceptance

Exact remaining rows are defined in `docs/41-phase-0.4-provider-live-acceptance-matrix.md`.

They cover protected authentication, exact version/health, target discovery, authoritative absence, create, provider-specific adoption/mismatch, idempotent replay/conflict, expiry/data/reset/suspend/activate/delete/rotation, delivery, timeout/5xx/rate-limit uncertainty, cleanup and final Target activation eligibility.

Every row is currently `deferred` and requires an owner-supplied controlled test panel. Source/offline evidence does not satisfy a live row.

## Later-phase foundations that remain incomplete

| Requirement group | Existing foundation | Owning future work |
|---|---|---|
| `PAY-002`, `PAY-003` | shared typed states/idempotency/outbox primitives | Phase `0.5.0` pricing/payment orchestration and reconciliation |
| `C2C-003`, `GFT-003` | interfaces/foundations where present | Phase `0.5.0` provider implementations/security evidence |
| `OPS-001`, `OPS-003` | redaction/outbox/worker heartbeat/release foundations | Phase `0.8.0` complete Operations Center and dead-letter/alert workflows |
| `INS-001`, `RUN-001`–`RUN-003` | Phase 0.2 installer/runtime/release evidence | final release environment re-verification |
| `CNT-001` | installer/identity translations | Phase `0.7.0` product localization/content system |

## Matrix reconciliation rule

Until the large baseline matrix is regenerated:

1. requirement wording comes from `docs/01-authoritative-requirements.md`;
2. accepted historical proof comes from bounded evidence/traceability files;
3. current status comes from this overlay and `docs/project-status.json`;
4. live head/CI comes only from GitHub PR `#6`;
5. source/offline provider proof must never be described as live-provider acceptance;
6. Phase `0.4.0` remains open until the controlled live matrix and final closure audit pass.