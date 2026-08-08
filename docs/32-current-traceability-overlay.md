# Current Traceability Overlay

**Last reviewed:** 2026-08-08  
**Purpose:** authoritative current implementation/evidence status while the baseline matrix is incrementally reconciled.  
**Authority:** requirement wording remains in `docs/01-authoritative-requirements.md`; this overlay supersedes stale status cells in `docs/02-requirement-traceability-matrix.md`.

Read with `PROJECT_STATUS.md`, `docs/project-status.json`, and `docs/52-current-continuation-handoff.md`. The live working head always comes from Draft PR `#6`.

## Status vocabulary

- `verified`: exact implementation/evidence lifecycle accepted;
- `verified-offline`: deterministic/source-contract proof accepted, deployment-specific live acceptance still required;
- `harness-verified`: guarded live harness accepted but deployment not yet exercised;
- `blocked-live`: next required proof depends on protected live execution/human-controlled action;
- `carried-release-gate`: mandatory requirement intentionally scheduled for final release acceptance;
- `parallel-verified`: evidence-complete later-phase bounded foundation accepted while its owning phase is not active/closed;
- `partial-verified`: part of a requirement is evidence-complete while an explicitly identified proof remains open;
- `not-started`: no accepted implementation claim.

## Phase status

| Phase | Current status | Authority |
|---|---|---|
| `0.1.0` | verified | planning/specification baseline |
| `0.2.0` | verified | `evidence/0.2.0/PHASE-CLOSURE.md` |
| `0.3.0` | verified | `evidence/0.3.0/phase-closure-verification.md` |
| `0.4.0` | blocked-live / active | PasarGuard live harness accepted; protected deployment execution remains; Marzban is a carried final-release gate |
| `0.5.0` | not active/not closed; parallel-verified financial foundations | Issue `#8`, docs `45`–`51` |
| `0.6.0`–`1.0.0` | not-started except explicitly documented shared foundations | authoritative phase plan |

Phase `0.4.0` remains open. Parallel Phase 0.5 evidence cannot be used to claim Phase 0.4 or release completion.

## Phase 0.4 accepted functional boundaries

| Area / requirement | Current status | Accepted proof |
|---|---|---|
| `CAT-001` category/product/variant lifecycle | verified | `evidence/0.4.0/catalog-category-product-variant-lifecycle.md`; `docs/23-phase-0.4-catalog-traceability.md` |
| `CAT-002` product/offering/panel inventory foundations | verified within Phase 0.4 scope | docs `23`–`26` and panel inventory evidence |
| `CAT-003` typed service mode | verified | Plan Offering evidence/traceability |
| `CAT-004` protocol profile/target assignment foundation | verified | `docs/25-phase-0.4-panel-inventory-traceability.md` |
| `CAT-005` Custom Plan policy/calculation | verified | `evidence/0.4.0/custom-plan-policy-calculation.md`; `docs/29-phase-0.4-custom-plan-traceability.md` |
| `CAT-006` Trial policy/eligibility/capacity/abuse/fallback | verified offline/database scope | `evidence/0.4.0/trial-policy-panel-adapter-foundation.md`; `docs/34-phase-0.4-trial-panel-traceability.md` |
| `CAT-008` capacity/availability/selection/fallback | verified | docs `27`–`28` |
| `PRV-001` provider connection/version/capability | verified-offline; PasarGuard harness-verified/blocked-live; Marzban carried-release-gate | docs `36`, `43`, `41`, `44` |
| `PRV-002` lookup/create/adoption/effect mapping | verified-offline; PasarGuard harness-verified/blocked-live; Marzban carried-release-gate | docs `38`, `40`, `43` |
| `PRV-003` failure/retry/reconciliation | verified-offline; controlled live uncertainty still blocked | create-equivalence + guarded harness evidence |
| `SEC-001`, `SEC-002` provider controls | verified offline/harness scope | TLS, redaction, fail-closed, protected secret and retry evidence |
| `QUA-001` | verified per accepted increment | exact-SHA CI, retained artifacts and independent digests |

## Provider acceptance state

### PasarGuard `v5.2.1`

The guarded live harness is evidence-complete:

- implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2`, CI `31226863010` / `#1013`;
- evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412`, CI `31227084007` / `#1015`;
- 317 tests / 1730 assertions;
- evidence artifact ID `9012490991`, digest `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`.

Deployment-specific execution remains blocked on protected repository Actions Secrets plus manual workflow dispatch. Coordinator-level adoption/idempotency, controlled timeout/5xx/429 reconciliation and explicit Target activation remain separate live rows after the guarded run.

### Marzban `v0.8.4`

Pinned source/offline contracts are accepted. Owner decision on 2026-08-08 carries deployment-specific live acceptance to final project/release acceptance. The requirement remains mandatory for `1.0.0`.

Real provider mutation/delivery capabilities and Service Targets remain fail-closed/disabled until applicable live acceptance passes.

## Parallel Phase 0.5 financial traceability

### Ledger / wallet authority (`WAL-002` foundations)

Status: **partial-verified**.

Accepted evidence chain:

1. Financial Ledger Foundation — implementation `259e29e6f93c2b36cd36c2f40bf669789eaab62f` / CI `#1037`; evidence `76c00a1c458c12ccc07dc658ee2f69e14389e65c` / CI `#1039`; `docs/45-phase-0.5-financial-ledger-traceability.md`.
2. Wallet Holds / Available Balance / Capture / Release — implementation `89775a1b9c3d70839e2f6ece36dadd5e6e30fcdf` / CI `#1046`; evidence `781a2dd63d999b2e01d99cd6888f3c9cbfda9f26` / CI `#1048`; `docs/46-phase-0.5-wallet-holds-traceability.md`.
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — implementation `c73eab20e1a875db8db1c4e60d73d0e3edaff96a` / CI `#1058`; evidence `57719972291b0553e76da6f8db50a8190a807044` / CI `#1060`; `docs/48-phase-0.5-wallet-reconciliation-traceability.md`.
4. Wallet Maintenance Operations — implementation `87392cca0a1a00ee87a1b5074386dd691a4094c6` / CI `#1066`; evidence `fa0cc2056459f171c32e0260422a465891766629` / CI `#1068`; `docs/49-phase-0.5-wallet-maintenance-traceability.md`.

Accepted invariants:

- monetary IRR is integer;
- finalized balanced ledger history is append-only authority;
- active holds reduce current available balance without rewriting ledger history;
- captures create one balanced effect and release creates no ledger rewrite;
- persisted wallet snapshots are append-only derived evidence/cache only and cannot authorize a debit/hold/capture/transfer;
- reconciliation recalculates from ledger + active holds and does not repair history by mutation;
- bounded maintenance uses the existing Scheduler and generic cleanup excludes transfer holds.

Remaining explicit `WAL-002` proof: dedicated multi-process MariaDB contention/stress for competing holds, terminal hold races, duplicate ledger command keys, duplicate transfer execution and reconciliation concurrent with mutation.

### Stable wallet transfer (`WAL-003`)

Status: **parallel-verified**.

- implementation `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`, CI `31235232052` / `#1084` — 346 tests / 1995 assertions;
- evidence `68f06fbd4ae9bb1bdba004968e57c84a13871e15`, CI `31235552266` / `#1092` — 346 tests / 1995 assertions;
- evidence artifact ID `9015258508`, digest `sha256:f1bee9300338b0d326e9c0cf47ea978147a6e72688e598c0717f7ae180b29a78`;
- evidence `evidence/0.5.0/wallet-transfer.md`;
- traceability `docs/51-phase-0.5-wallet-transfer-traceability.md`.

Accepted proof includes default-disabled explicit policy, stable recipient ID, execution-time eligibility revalidation, integer amount/fee/limit snapshots, hold-only prepare, explicit confirmation, one atomic balanced sender/recipient/fee posting, exact replay/conflict, cancellation/expiry without transfer posting, transfer-hold cleanup isolation and DB terminal/immutability barriers.

No automated scheduled cancellation of untouched expired pending transfers is claimed.

### Remaining Phase 0.5 requirement state

| Requirement / area | Current status | Next boundary |
|---|---|---|
| remaining `WAL-002` contention proof | not-started | Dedicated Wallet Contention Verification in `docs/52-current-continuation-handoff.md` |
| `WAL-001` external wallet top-up / Payment Intent settlement | not-started | after wallet correctness/refund/correction foundations |
| `WAL-004` refund/reversal | not-started | compensating immutable entries, replay/conflict and authorization |
| `WAL-005` correction/approval | not-started | explicit correction reason + approval + compensating ledger history |
| deterministic pricing / immutable Quote snapshots | not-started | separate bounded pricing increment |
| promotions/referrals/agent pricing | not-started | after Quote/pricing foundation |
| payment methods/providers | not-started | later Issue `#8` increments |

## Cross-phase carry-forward rules

1. Phase `0.4.0` stays active/open until applicable provider live rows and closure audit pass.
2. Source/offline/harness provider proof is never described as executed deployment acceptance.
3. Later financial work cannot weaken provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls.
4. Financial effects always use fresh transaction/lock-based authoritative state; persisted snapshots are never authority.
5. Every accepted bounded increment requires exact implementation CI/artifact plus exact evidence-head CI/artifact.
6. Orders/provisioning/Service lifecycle remain Phase `0.6.0` and are not pulled forward into Phase `0.5.0`.

Until the baseline matrix is regenerated, requirement wording comes from `docs/01-authoritative-requirements.md`, historical proof from bounded evidence/traceability files, current status from this overlay plus `docs/project-status.json`, and live head/CI only from Draft PR `#6`.
