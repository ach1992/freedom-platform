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
| `0.5.0` | not active/not closed; parallel-verified financial foundations | Issue `#8`, financial traceability through `docs/55-phase-0.5-wallet-correction-traceability.md` |
| `0.6.0`–`1.0.0` | not-started except explicitly documented shared foundations | authoritative phase plan |

Phase `0.4.0` remains open. Parallel Phase 0.5 evidence cannot be used to claim Phase 0.4 or release completion.

## Phase 0.4 accepted provider state

| Area / requirement | Current status | Accepted proof / remaining gate |
|---|---|---|
| Catalog/Offering/Capacity/Trial (`CAT-*`) | verified within accepted Phase 0.4 boundaries | bounded evidence/traceability docs `23`–`34` |
| `PRV-001` connection/version/capability | verified-offline; PasarGuard harness-verified/blocked-live; Marzban carried-release-gate | docs `36`, `41`, `43`, `44` |
| `PRV-002` lookup/create/adoption/effect mapping | verified-offline; PasarGuard blocked-live; Marzban carried-release-gate | provider contract/equivalence evidence; controlled live rows still required |
| `PRV-003` failure/retry/reconciliation | verified-offline; controlled live uncertainty still blocked | discovery-before-retry proof; timeout/5xx/429 live evidence remains |
| `SEC-001`, `SEC-002` provider controls | verified offline/harness scope | TLS, redaction, fail-closed and protected-secret handling; live credential acceptance remains |
| `QUA-001` | verified per accepted increment | exact-SHA CI, retained artifacts and independent digests |

PasarGuard `v5.2.1` guarded harness is accepted at implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2` / CI `#1013`, evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412` / CI `#1015`, 317 tests / 1730 assertions. Deployment-specific execution remains blocked on protected Actions Secrets plus manual dispatch. Real provider mutations/Targets remain fail-closed until applicable live acceptance passes.

Marzban `v0.8.4` source/offline proof is accepted; deployment-specific live acceptance is carried to final release acceptance.

## Parallel Phase 0.5 financial traceability

### `WAL-002` ledger / wallet authority

Status: **parallel-verified for the implemented ledger/hold/snapshot/reconciliation foundation**.

Accepted chain:

1. `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. `docs/53-phase-0.5-wallet-contention-traceability.md`.

Accepted invariants include integer IRR, append-only balanced ledger authority, active-hold available-balance calculation, non-authoritative snapshots/reconciliation, single terminal hold effects and independent-process MariaDB contention proof.

### `WAL-003` stable wallet transfer

Status: **parallel-verified**.

- implementation `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`, CI `#1084` — 346 / 1995;
- evidence `68f06fbd4ae9bb1bdba004968e57c84a13871e15`, CI `#1092` — 346 / 1995;
- evidence `evidence/0.5.0/wallet-transfer.md`, traceability `docs/51-phase-0.5-wallet-transfer-traceability.md`.

No automated scheduled cancellation of untouched expired pending transfers is claimed.

### `WAL-004` refund / reversal

Status: **parallel-verified for the provider-independent refund/reversal foundation**.

- implementation `0237f94cae67ff2ca31047af55420fed0ffe578a`, CI `31242702422` / `#1155` — 360 / 2099;
- evidence head `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`, CI `31242888656` / `#1156` — 360 / 2099;
- evidence artifact `test-evidence-31242888656`, ID `9017593626`, digest `sha256:a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`;
- dedicated refund verification `8 tests / 69 assertions`;
- evidence `evidence/0.5.0/wallet-refund-reversal-foundation.md`;
- traceability `docs/54-phase-0.5-wallet-refund-traceability.md`.

Accepted: immutable capture-time refundability, original eligible wallet-bucket compensation, manual-external evidence without duplicate wallet credit, cumulative/per-entry caps, privileged override, replay/conflict, immutable compensating history and over-refund/duplicate concurrency proof.

Provider-native refunds, Payment Intent state, exact-card-adjustment computation, Order/referral consequences and customer UX remain unclaimed.

### `WAL-005` balance correction / approval

Status: **parallel-verified**.

Implementation:

- SHA `fd3d579d9f38004310d7ea638e351813d2f46ef5`;
- CI `31260299072` / `#1186` — 371 tests / 2197 assertions;
- artifact `test-evidence-31260299072`, ID `9022602206`;
- independent digest `sha256:476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`.

Evidence:

- SHA `ef5504081a42687eb712e9cd47306cd9dcc9a864`;
- CI `31260549403` / `#1188` — 371 / 2197;
- artifact `test-evidence-31260549403`, ID `9022665227`;
- uploader and independent digest `sha256:ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`;
- dedicated correction verification `11 tests / 98 assertions`, zero failures/errors/skips;
- evidence `evidence/0.5.0/wallet-correction-approval-foundation.md`;
- traceability `docs/55-phase-0.5-wallet-correction-traceability.md`.

Accepted invariants:

- immutable correction key/payload plus deterministic preview and explicit confirmation;
- target is a stable active owned IRR wallet bucket and positive integer amount with explicit debit/credit direction;
- fresh finalized-ledger + active-hold authority under wallet locking;
- debit cannot create negative available balance;
- execution-time permission and current dual-approval policy revalidation; policy drift fails closed;
- large non-owner corrections use existing sensitive approval with distinct approver and atomic consumption;
- approved replay is bound to the exact committed approval ID;
- financial effect is a balanced compensating transaction against `system.wallet.correction.offset`, never ledger-history mutation;
- DB guards make accepted preview/correction identity/effect links immutable;
- real-MariaDB independent-process proof covers competing debits, duplicate execution and approved duplicate execution.

### Remaining Phase 0.5 requirement state

| Requirement / area | Current status | Next boundary |
|---|---|---|
| `WAL-001` external cash-wallet top-up / Payment Intent settlement | not-started | **next**: provider-independent Payment Intent + authoritative capture/top-up settlement |
| `PAY-001` eligibility | not-started except reusable account/wallet/provider-control foundations | implement minimum top-up eligibility then expand with method/provider work |
| `PAY-002` controlled intents / one captured settlement | not-started | next Payment Intent boundary |
| `PAY-003` duplicate external/internal event idempotency | not-started | next Payment Intent/provider-transaction boundary |
| deterministic pricing / immutable Quote snapshots | not-started | separate bounded pricing increment after top-up intent |
| promotions/referrals/agent pricing | not-started | after Quote/pricing foundation |
| payment methods/providers | not-started beyond shared provider contracts/offline Phase 0.4 adapters | later Issue `#8` increments; includes provider-native refund integration |

## Next boundary acceptance requirements

Payment Intent + `WAL-001` must:

1. reuse existing `PaymentIntentState` and `PaymentProvider` contracts;
2. bind an immutable top-up intent to one active owned **cash** wallet and positive integer IRR amount;
3. treat browser return/user claim as non-authoritative;
4. accept only matching normalized authoritative provider evidence for capture;
5. enforce one captured settlement/provider consumption per intent and unique provider event/transaction identity;
6. post exactly one balanced cash-wallet ledger top-up after capture;
7. return prior accepted result on exact duplicate event/capture and reject conflicts;
8. avoid Order/provisioning/service effects in this top-up-only Phase 0.5 boundary;
9. retain safe append-only payment/evidence/audit history;
10. include independent-process MariaDB duplicate-capture proof and exact implementation/evidence CI/artifact/digest lifecycle.

## Cross-phase carry-forward rules

1. Phase `0.4.0` stays active/open until applicable provider live rows and closure audit pass.
2. Source/offline/harness provider proof is never described as executed deployment acceptance.
3. Later financial work cannot weaken provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls.
4. Financial effects always use fresh transaction/lock-based authoritative state; persisted snapshots are never authority.
5. Every accepted bounded increment requires exact implementation CI/artifact plus exact evidence-head CI/artifact.
6. Refund/correction compensate immutable history rather than edit/delete it.
7. Browser return never proves capture; no paid provisioning precedes authoritative capture.
8. Orders/provisioning/Service lifecycle remain Phase `0.6.0` and are not pulled forward into Phase `0.5.0`.

Until the baseline matrix is regenerated, requirement wording comes from `docs/01-authoritative-requirements.md`, historical proof from bounded evidence/traceability files, current status from this overlay plus `docs/project-status.json`, and live head/CI only from Draft PR `#6`.
