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
- `implementation-verified`: exact implementation-head CI/artifact accepted but exact evidence-head gate still pending;
- `not-started`: no accepted implementation claim.

## Phase status

| Phase | Current status | Authority |
|---|---|---|
| `0.1.0` | verified | planning/specification baseline |
| `0.2.0` | verified | `evidence/0.2.0/PHASE-CLOSURE.md` |
| `0.3.0` | verified | `evidence/0.3.0/phase-closure-verification.md` |
| `0.4.0` | blocked-live / active | PasarGuard live harness accepted; protected deployment execution remains; Marzban is a carried final-release gate |
| `0.5.0` | not active/not closed; parallel financial chain with current implementation-verified candidate | Issue `#8`, accepted through `docs/55-phase-0.5-wallet-correction-traceability.md`; current candidate `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md` |
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

### Accepted wallet authority chain

Status: **parallel-verified** for accepted boundaries through `WAL-005`.

Accepted traceability:

1. `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. `docs/51-phase-0.5-wallet-transfer-traceability.md` (`WAL-003`);
6. `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. `docs/54-phase-0.5-wallet-refund-traceability.md` (`WAL-004`);
8. `docs/55-phase-0.5-wallet-correction-traceability.md` (`WAL-005`).

Accepted invariants include integer IRR, append-only balanced ledger authority, active-hold available-balance calculation, non-authoritative snapshots/reconciliation, single terminal hold effects, stable transfer/refund/correction identities, immutable compensating history and independent-process MariaDB contention proof.

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
- evidence `evidence/0.5.0/wallet-refund-reversal-foundation.md`;
- traceability `docs/54-phase-0.5-wallet-refund-traceability.md`.

Provider-native refunds, Payment Intent state, exact-card-adjustment computation, Order/referral consequences and customer UX remain unclaimed.

### `WAL-005` balance correction / approval

Status: **parallel-verified**.

- implementation `fd3d579d9f38004310d7ea638e351813d2f46ef5`, CI `31260299072` / `#1186` — 371 / 2197;
- evidence `ef5504081a42687eb712e9cd47306cd9dcc9a864`, CI `31260549403` / `#1188` — 371 / 2197;
- evidence artifact `test-evidence-31260549403`, ID `9022665227`, digest `sha256:ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`;
- evidence `evidence/0.5.0/wallet-correction-approval-foundation.md`;
- traceability `docs/55-phase-0.5-wallet-correction-traceability.md`.

### `PAY-002` / `PAY-003` / `WAL-001` Payment Intent / external cash-wallet top-up

Status: **implementation-verified; exact evidence-head gate pending**.

Implementation:

- SHA `6db9dde7032114ab81c95fdf371530997e65f21c`;
- CI `31265449681` / `#1214` — mandatory jobs success;
- full suite `384 tests / 2292 assertions`;
- dedicated Payment Intent/top-up verification `13 tests / 95 assertions`, zero failures/errors/skips;
- artifact `test-evidence-31265449681`, ID `9024026199`;
- uploader and independent digest `sha256:3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`;
- evidence candidate `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`;
- traceability candidate `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

Implementation-verified invariants:

- immutable Payment Intent creation identity and exact replay/conflict;
- existing `PaymentIntentState` reused; no parallel state vocabulary;
- external top-up binds one active owned IRR cash wallet and positive integer-IRR amount;
- browser/non-authoritative evidence cannot capture;
- first capture requires matching normalized authoritative settled provider evidence;
- provider event/transaction uniqueness and database authority guards block duplicate/cross-intent financial reuse;
- one accepted settlement posts exactly one finalized two-entry clearing-to-cash `wallet_external_top_up` ledger transaction;
- accepted settlement replay remains stable even after later wallet deactivation and cannot create a new effect;
- safe provider evidence is sensitive-field filtered, append-only and DB-bounded to 32 fields / 8192 bytes;
- independent-process real-MariaDB duplicate/cross-intent contention proves one primary top-up effect and bounded failure behavior.

This is not yet `parallel-verified` because exact evidence-head CI/artifact remains mandatory.

### Remaining Phase 0.5 requirement state

| Requirement / area | Current status | Next boundary |
|---|---|---|
| `PAY-002` / `PAY-003` / `WAL-001` top-up settlement | implementation-verified | exact evidence-head gate, then accept |
| `PAY-001` payment-method eligibility | not-started except reusable account/wallet/provider-control foundations | after pricing or with provider-method work; do not conflate with top-up settlement |
| deterministic pricing / immutable Quote snapshots (`BUY-002`) | not-started | **next independent implementation after WAL-001 evidence acceptance** |
| promotions/referrals/agent pricing | not-started | after Quote/pricing foundation |
| payment methods/providers | not-started beyond shared provider contracts/offline Phase 0.4 adapters | later Issue `#8` increments; includes provider-native refund integration |

## `WAL-001` evidence-head acceptance requirements

The current candidate becomes accepted only when the final evidence/status head:

1. remains on Draft PR `#6` / `develop/v1.0.0-completion`;
2. passes preflight/project control, secret scan, static quality, dependency/license and full MariaDB/authenticated-Redis suite;
3. retains the test evidence artifact with actual counts;
4. has an independently downloaded/recomputed SHA-256 digest;
5. preserves evidence/traceability statements without introducing a live-provider, Order/provisioning, pricing or Phase closure claim.

## Next independent boundary — `BUY-002` deterministic Pricing / Quote snapshot

After `WAL-001` evidence acceptance, the first pricing increment must snapshot base price, account/agent override input, discount input/result, final price, integer-IRR currency, validity and immutable configuration identity. Exact replay/conflict and database immutability must prevent later Offering/pricing changes from reinterpreting an accepted Quote.

Do not pull promotions/referrals/provider execution or Order/provisioning ownership into the first Quote boundary.

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
