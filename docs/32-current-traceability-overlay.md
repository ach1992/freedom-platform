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
| `0.5.0` | not active/not closed; parallel-verified financial foundations | Issue `#8`, financial traceability docs through `docs/53-phase-0.5-wallet-contention-traceability.md` |
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

Accepted invariants:

- monetary IRR is integer;
- finalized balanced ledger history is append-only authority;
- active holds reduce current available balance without rewriting ledger history;
- captures create one balanced effect and release creates no ledger rewrite;
- persisted wallet snapshots are append-only derived evidence/cache only and cannot authorize a debit/hold/capture/transfer;
- reconciliation recalculates from ledger + active holds and does not repair history by mutation;
- bounded maintenance uses the existing Scheduler and generic cleanup excludes transfer holds;
- independent-process MariaDB contention proof now covers same-wallet over-reservation, capture/release terminal races, duplicate ledger command keys and reconciliation racing mutation.

Dedicated contention implementation verification head `903f326040c9acd0b31645fe8fae3a75f8a9fd27` passed CI `31240159777` / `#1128` with 352 tests / 2030 assertions. Combined evidence head `e7a0ae17d470beb40f4933e66c7b599e0837e120` passed CI `31241459956` / `#1131` with the same counts; evidence artifact `9017163993`, digest `sha256:58544b56477c708b4e798b2ea83e673995e22b0ab53c19213914d1c2609af294`.

This closes the previously explicit dedicated contention-verification gap for the accepted `WAL-002` foundation. Future `WAL-004`, `WAL-005`, `WAL-001` and provider/payment features require their own concurrency/idempotency evidence.

### `WAL-003` stable wallet transfer

Status: **parallel-verified**.

- implementation `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`, CI `#1084` — 346 tests / 1995 assertions;
- evidence `68f06fbd4ae9bb1bdba004968e57c84a13871e15`, CI `#1092` — 346 / 1995;
- evidence `evidence/0.5.0/wallet-transfer.md`, traceability `docs/51-phase-0.5-wallet-transfer-traceability.md`;
- dedicated duplicate prepare/confirm concurrency regression is additionally accepted by the contention boundary above.

No automated scheduled cancellation of untouched expired pending transfers is claimed.

### Remaining Phase 0.5 requirement state

| Requirement / area | Current status | Next boundary |
|---|---|---|
| `WAL-004` refund/reversal | not-started | next: provider-independent compensating refund foundation + concurrency proof |
| `WAL-005` correction/approval | not-started | after refund foundation |
| `WAL-001` external wallet top-up / Payment Intent settlement | not-started | after wallet/refund/correction foundations |
| deterministic pricing / immutable Quote snapshots | not-started | separate bounded pricing increment |
| promotions/referrals/agent pricing | not-started | after Quote/pricing foundation |
| payment methods/providers | not-started | later Issue `#8` increments |

## Cross-phase carry-forward rules

1. Phase `0.4.0` stays active/open until applicable provider live rows and closure audit pass.
2. Source/offline/harness provider proof is never described as executed deployment acceptance.
3. Later financial work cannot weaken provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls.
4. Financial effects always use fresh transaction/lock-based authoritative state; persisted snapshots are never authority.
5. Every accepted bounded increment requires exact implementation CI/artifact plus exact evidence-head CI/artifact.
6. Refund/correction must compensate immutable history rather than edit/delete it.
7. Orders/provisioning/Service lifecycle remain Phase `0.6.0` and are not pulled forward into Phase `0.5.0`.

Until the baseline matrix is regenerated, requirement wording comes from `docs/01-authoritative-requirements.md`, historical proof from bounded evidence/traceability files, current status from this overlay plus `docs/project-status.json`, and live head/CI only from Draft PR `#6`.
