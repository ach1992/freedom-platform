# Current Traceability Overlay

**Last reviewed:** 2026-08-08  
**Purpose:** authoritative current implementation/evidence status while the baseline matrix is incrementally reconciled.  
**Authority:** requirement wording remains in `docs/01-authoritative-requirements.md`; this overlay supersedes stale status cells in `docs/02-requirement-traceability-matrix.md`.

Read with `PROJECT_STATUS.md`, `docs/project-status.json`, `docs/33-current-risk-overlay.md`, and `docs/52-current-continuation-handoff.md`. The live working head always comes from Draft PR `#6`.

## Status vocabulary

- `verified`: exact implementation/evidence lifecycle accepted;
- `verified-offline`: deterministic/source-contract proof accepted, deployment-specific live acceptance still required;
- `harness-verified`: guarded live harness accepted but deployment not yet exercised;
- `blocked-live`: next required proof depends on protected live execution/human-controlled action;
- `carried-release-gate`: mandatory requirement intentionally scheduled for final release acceptance;
- `parallel-verified`: evidence-complete later-phase bounded foundation accepted while its owning phase is not active/closed;
- `not-started`: no accepted implementation claim.

## Phase status

| Phase | Current status | Authority |
|---|---|---|
| `0.1.0` | verified | planning/specification baseline |
| `0.2.0` | verified | `evidence/0.2.0/PHASE-CLOSURE.md` |
| `0.3.0` | verified | `evidence/0.3.0/phase-closure-verification.md` |
| `0.4.0` | blocked-live / active | PasarGuard live harness accepted; protected deployment execution remains; Marzban is a carried final-release gate |
| `0.5.0` | not active/not closed; parallel-verified financial foundations | Issue `#8`, financial traceability docs through `docs/54-phase-0.5-wallet-refund-traceability.md` |
| `0.6.0`–`1.0.0` | not-started except explicitly documented shared foundations | authoritative phase plan |

Phase `0.4.0` remains open. Parallel Phase 0.5 evidence cannot be used to claim Phase 0.4 or release completion.

## Phase 0.4 accepted provider state

| Area / requirement | Current status | Accepted proof / remaining gate |
|---|---|---|
| Catalog/Offering/Capacity/Trial (`CAT-*`) | verified within accepted Phase 0.4 boundaries | bounded evidence/traceability docs `23`–`34` |
| `PRV-001` connection/version/capability | verified-offline; PasarGuard harness-verified/blocked-live; Marzban carried-release-gate | docs `36`, `41`, `43`, `44` |
| `PRV-002` lookup/create/adoption/effect mapping | verified-offline; PasarGuard blocked-live; Marzban carried-release-gate | provider contract/equivalence evidence; controlled live rows still required |
| `PRV-003` failure/retry/reconciliation | verified-offline; controlled live uncertainty still blocked | authoritative discovery-before-retry proof; timeout/5xx/429 live evidence remains |
| `SEC-001`, `SEC-002` provider controls | verified offline/harness scope | TLS, redaction, fail-closed and protected-secret handling; live credential acceptance remains |
| `QUA-001` | verified per accepted increment | exact-SHA CI, retained artifacts and independent digests |

PasarGuard `v5.2.1` guarded harness is accepted at implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2` / CI `#1013`, evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412` / CI `#1015`, 317 tests / 1730 assertions. Deployment-specific execution remains blocked on protected Actions Secrets plus manual dispatch. Real provider mutations/Targets remain fail-closed until applicable live acceptance passes.

Marzban `v0.8.4` source/offline proof is accepted; deployment-specific live acceptance is carried to final release acceptance by owner scheduling decision.

## Parallel Phase 0.5 financial traceability

### `WAL-002` ledger / wallet authority

Status: **parallel-verified for the currently implemented ledger/hold/snapshot/reconciliation foundation**.

Accepted chain:

1. Financial Ledger Foundation — `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. Wallet Holds / Available Balance / Capture / Release — `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. Wallet Reconciliation Snapshots / Expired-Hold Cleanup — `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. Wallet Maintenance Operations — `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. Dedicated Wallet Contention Verification — `docs/53-phase-0.5-wallet-contention-traceability.md` and `evidence/0.5.0/wallet-contention-verification.md`.

Accepted invariants include integer IRR, append-only balanced ledger authority, active-hold available-balance calculation, non-authoritative snapshots/reconciliation, single terminal hold effects and independent-process MariaDB contention proof for same-wallet reservation/terminal/replay/reconciliation races.

Dedicated contention implementation `903f326040c9acd0b31645fe8fae3a75f8a9fd27` passed CI `31240159777` / `#1128`; evidence head `e7a0ae17d470beb40f4933e66c7b599e0837e120` passed `31241459956` / `#1131`, both 352 tests / 2030 assertions.

### `WAL-003` stable wallet transfer

Status: **parallel-verified**.

- implementation `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`, CI `#1084` — 346 tests / 1995 assertions;
- evidence `68f06fbd4ae9bb1bdba004968e57c84a13871e15`, CI `#1092` — 346 / 1995;
- evidence `evidence/0.5.0/wallet-transfer.md`, traceability `docs/51-phase-0.5-wallet-transfer-traceability.md`;
- dedicated duplicate prepare/confirm concurrency regression is accepted by `docs/53-phase-0.5-wallet-contention-traceability.md`.

No automated scheduled cancellation of untouched expired pending transfers is claimed.

### `WAL-004` refund / reversal

Status: **parallel-verified for the provider-independent refund/reversal foundation**.

- implementation `0237f94cae67ff2ca31047af55420fed0ffe578a`, CI `31242702422` / `#1155` — 360 tests / 2099 assertions;
- implementation artifact `test-evidence-31242702422`, ID `9017545543`, digest `sha256:be509996d098ee7f1354a9dc1fe949a224b2ead42c15ae145c2e12ad59890cdc`;
- evidence head `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`, CI `31242888656` / `#1156` — 360 / 2099;
- evidence artifact `test-evidence-31242888656`, ID `9017593626`, digest `sha256:a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`;
- dedicated refund foundation `6 tests / 52 assertions`; refund contention `2 tests / 17 assertions`, zero failures/errors/skips;
- evidence `evidence/0.5.0/wallet-refund-reversal-foundation.md`;
- traceability `docs/54-phase-0.5-wallet-refund-traceability.md`.

Accepted invariants:

- source refundability is immutable capture-time ledger metadata, never retrofitted after finalization;
- refundable total can be lower than captured total, preserving a future non-refundable adjustment boundary without inventing provider computation;
- wallet refunds return only to exact original eligible wallet bucket/accounts;
- manual external refunds require evidence/reference and do not also create wallet credit;
- cumulative and per-source-entry caps are checked under source locking;
- exact replay returns one accepted effect; changed key reuse conflicts;
- destination override is privileged/reasoned/audited;
- refund history is immutable compensating ledger history;
- independent-process MariaDB proof prevents concurrent over-refund and duplicate primary refund effects.

Provider-native refunds, Payment Intent state, exact-card-adjustment computation, Order/referral consequences and customer UX remain unclaimed.

### Remaining Phase 0.5 requirement state

| Requirement / area | Current status | Next boundary |
|---|---|---|
| `WAL-005` correction/approval | not-started | next: immutable compensating administrator correction + preview/confirmation/dual-approval/concurrency proof |
| `WAL-001` external wallet top-up / Payment Intent settlement | not-started | after wallet/refund/correction foundations |
| deterministic pricing / immutable Quote snapshots | not-started | separate bounded pricing increment |
| promotions/referrals/agent pricing | not-started | after Quote/pricing foundation |
| payment methods/providers | not-started | later Issue `#8` increments; includes provider-native refund integration |

## Cross-phase carry-forward rules

1. Phase `0.4.0` stays active/open until applicable provider live rows and closure audit pass.
2. Source/offline/harness provider proof is never described as executed deployment acceptance.
3. Later financial work cannot weaken provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls.
4. Financial effects always use fresh transaction/lock-based authoritative state; persisted snapshots are never authority.
5. Every accepted bounded increment requires exact implementation CI/artifact plus exact evidence-head CI/artifact.
6. Refund/correction must compensate immutable history rather than edit/delete it.
7. Corrections cannot bypass refund/payment/order state-machine ownership.
8. Orders/provisioning/Service lifecycle remain Phase `0.6.0` and are not pulled forward into Phase `0.5.0`.

Until the baseline matrix is regenerated, requirement wording comes from `docs/01-authoritative-requirements.md`, historical proof from bounded evidence/traceability files, current status from this overlay plus `docs/project-status.json`, and live head/CI only from Draft PR `#6`.
