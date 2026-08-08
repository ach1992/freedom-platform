# Current Traceability Overlay

**Last reviewed:** 2026-08-09  
**Purpose:** authoritative current implementation/evidence status while the baseline matrix is incrementally reconciled.  
**Authority:** requirement wording remains in `docs/01-authoritative-requirements.md`; this overlay supersedes stale status cells in `docs/02-requirement-traceability-matrix.md`.

Read with `PROJECT_STATUS.md`, `docs/project-status.json`, `docs/33-current-risk-overlay.md`, and `docs/52-current-continuation-handoff.md`. The live working head always comes from Draft PR `#6`; never treat a SHA in this document as the current head.

## Status vocabulary

- `verified`: exact implementation/evidence lifecycle accepted;
- `verified-offline`: deterministic/source-contract proof accepted, deployment-specific live acceptance still required;
- `harness-verified`: guarded live harness accepted but deployment not yet exercised;
- `blocked-live`: next required proof depends on protected live execution/human-controlled action;
- `carried-release-gate`: mandatory requirement intentionally scheduled for final release acceptance;
- `parallel-verified`: evidence-complete later-phase bounded foundation accepted while its owning phase is not active/closed;
- `implementation-verified`: exact implementation-head CI/artifact accepted but exact evidence-head gate remains pending;
- `not-started`: no accepted implementation claim.

## Phase status

| Phase | Current status | Authority |
|---|---|---|
| `0.1.0` | verified | planning/specification baseline |
| `0.2.0` | verified | `evidence/0.2.0/PHASE-CLOSURE.md` |
| `0.3.0` | verified | `evidence/0.3.0/phase-closure-verification.md` |
| `0.4.0` | blocked-live / active | PasarGuard harness accepted; default-branch dispatch bootstrap + protected live execution remain; Marzban is a carried final-release gate |
| `0.5.0` | not active/not closed; parallel-verified chain through `BUY-002`; Worker `#25` candidate is implementation-verified pending evidence-head gate/MASTER review | Issue `#8`; latest accepted traceability `docs/57-phase-0.5-quote-pricing-traceability.md`; candidate `docs/58-phase-0.5-promotion-referral-pricing-rule-traceability.md` |
| `0.6.0`–`1.0.0` | not-started except explicitly documented shared foundations | authoritative phase plan |

Phase `0.4.0` remains open. Parallel Phase 0.5 evidence cannot be used to claim Phase 0.4, Phase 0.5, or release completion.

## Phase 0.4 accepted provider state

| Area / requirement | Current status | Accepted proof / remaining gate |
|---|---|---|
| Catalog/Offering/Capacity/Trial (`CAT-*`) | verified within accepted Phase 0.4 boundaries | bounded evidence/traceability docs `23`–`34` |
| `PRV-001` connection/version/capability | verified-offline; PasarGuard harness-verified/blocked-live; Marzban carried-release-gate | docs `36`, `41`, `43`, `44`; default-branch bootstrap PR `#24` remains unresolved |
| `PRV-002` lookup/create/adoption/effect mapping | verified-offline; PasarGuard blocked-live; Marzban carried-release-gate | controlled live adoption/idempotency rows remain |
| `PRV-003` failure/retry/reconciliation | verified-offline; controlled live uncertainty still blocked | timeout/5xx/429 live evidence remains |
| `SEC-001`, `SEC-002` provider controls | verified offline/harness scope | protected live credential acceptance and post-test hygiene remain |
| `QUA-001` | verified per accepted increment | exact-SHA CI, retained artifacts, independent digests |

PasarGuard `v5.2.1` guarded harness is accepted at implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2` / CI `#1013`, evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412` / CI `#1015`, 317 tests / 1730 assertions.

The deployment path currently depends on Draft PR `#24` (`ops/provider-live-dispatch-bootstrap` → `main`). Its last inspected CI `31240183151` / `#1129` passed preflight, secret scan, static quality, and MariaDB/Redis tests but failed `Dependency and license policy` because `composer audit --locked --abandoned=fail` exited non-zero. No live dispatch or Target activation is accepted from that state. `main` remains unchanged by the bootstrap stream, and the safety snapshot branch remains retained until the bootstrap decision is resolved.

Marzban `v0.8.4` source/offline proof is accepted; deployment-specific live acceptance remains a final-release gate.

## Parallel Phase 0.5 accepted traceability

Status: **parallel-verified** for ten bounded foundations:

1. `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. `docs/51-phase-0.5-wallet-transfer-traceability.md` (`WAL-003`);
6. `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. `docs/54-phase-0.5-wallet-refund-traceability.md` (`WAL-004`);
8. `docs/55-phase-0.5-wallet-correction-traceability.md` (`WAL-005`);
9. `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md` (`PAY-002`, `PAY-003`, `WAL-001`);
10. `docs/57-phase-0.5-quote-pricing-traceability.md` (`BUY-002`).

Accepted shared invariants include integer IRR, append-only balanced ledger authority, active-hold available-balance calculation, immutable compensating history, exact replay/conflict, independent-process MariaDB contention proof, authoritative payment evidence before top-up capture, and immutable deterministic Quote snapshots.

### `PAY-002` / `PAY-003` / `WAL-001` Payment Intent / external cash-wallet top-up

Status: **parallel-verified**.

Implementation:

- SHA `6db9dde7032114ab81c95fdf371530997e65f21c`;
- CI `31265449681` / `#1214` — all mandatory jobs success;
- full suite **384 tests / 2292 assertions**;
- dedicated top-up verification **13 / 95**;
- artifact `test-evidence-31265449681`, ID `9024026199`;
- independent digest `sha256:3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`.

Evidence:

- SHA `76ea847d4d1f626893b925bbfe5263e1dc1398e4`;
- CI `31265901691` / `#1220` — all mandatory jobs success;
- full suite **384 / 2292**;
- artifact `test-evidence-31265901691`, ID `9024152227`;
- independent digest `sha256:dff70424074d138f59a8c9bfb03e5196f3827142fe964b043c09ef53e15392e7`.

Accepted: immutable intent identity/replay/conflict, active owned IRR cash-wallet target, browser/non-authoritative evidence cannot capture, normalized authoritative settled evidence is mandatory, provider event/transaction uniqueness, one captured settlement, one balanced clearing-to-cash ledger effect, stable replay after later wallet deactivation, bounded safe evidence, and real-MariaDB duplicate/cross-intent contention proof.

No real gateway/provider, `PAY-001`, provider-native refund, Order/provisioning, or payment UX is claimed.

### `BUY-002` deterministic Pricing / immutable Quote

Status: **parallel-verified**.

Implementation:

- SHA `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`;
- CI `31267071664` / `#1233` — all mandatory jobs success;
- full suite **392 tests / 2355 assertions**;
- dedicated Quote suite **8 / 63**;
- artifact `test-evidence-31267071664`, ID `9024501375`;
- independent digest `sha256:899a26d7553f4fd37c2102da986fc876f0a8e8151b683ff36e2f300acb9ad754`.

Evidence:

- SHA `07840d417e64eb30bf31f17bb9e26c1fe549eef3`;
- CI `31267346707` / `#1236` — all mandatory jobs success;
- full suite **392 / 2355**;
- dedicated Quote suite **8 / 63**;
- artifact `test-evidence-31267346707`, ID `9024577868`, size `116261` bytes;
- independent digest `sha256:ba2710a544ccd07c72a56c616f7215406175ca8fdc93bc770c47ad474b275e83`.

Accepted: immutable Quote identity, exact replay/conflict, integer-IRR base/override/discount/final components, override-before-discount arithmetic, Offering ID/code/version/configuration-hash snapshot, bounded configuration snapshot/hash, explicit expiry, current tier/agent reference validation for resolved inputs, later Offering-change stability, DB immutability/forgery guards, and no wallet/payment/provider/paid-Order/provisioning side effect.

No `BUY-001`, complete `PRO-001`, `REF-001`, `AGT-005`, `PAY-001`, provider execution, or Phase `0.6.0` behavior is claimed.

### Worker candidate `#25` — stored promotion/referral pricing-rule resolution

Status: **implementation-verified; not yet parallel-verified/accepted**.

- Worker `W-001`, Contract Revision `1`, PR `#26` → `develop/v1.0.0-completion`;
- implementation SHA `2be331fd4fe51ef4f7ee58ca2a82a178128bba09`;
- implementation CI `31284018788` / `#1256` — all mandatory jobs successful;
- full suite **399 / 2415**;
- dedicated promotion/referral pricing-rule suite **7 / 60**;
- unchanged Quote regression **8 / 63**;
- artifact `test-evidence-31284018788`, ID `9029282011`;
- independent digest `sha256:ebf72d8ea7822895cea0d306c866358f4d049201503895a13dce87fcce000fae`;
- evidence candidate `evidence/0.5.0/promotion-referral-pricing-rule-resolution.md`;
- traceability candidate `docs/58-phase-0.5-promotion-referral-pricing-rule-traceability.md`.

The candidate establishes immutable rule/version/resolution identity, deterministic qualification/precedence, explicit no-match, bounded referral pricing-source input, exact replay/conflict, execution-time authorization, and MariaDB integrity/immutability guards. It does **not** become accepted traceability until its exact evidence head passes mandatory CI and MASTER independently reviews/integrates it.

Reservation/redemption/release/counters, referral payout/reversal/notifications, `AGT-005`, `PAY-001`, provider effects, Orders and provisioning are not proven by this candidate.

## Remaining Phase 0.5 requirement state

| Requirement / area | Current status | Next boundary |
|---|---|---|
| `PRO-001` discount/promotion rules | Worker `#25` implementation-verified candidate for stored definition/qualification/resolution only; not yet accepted | exact evidence-head gate + MASTER review; reservation/redemption/release/contention remains separate |
| `REF-001` referral reward lifecycle | Worker `#25` candidate adds bounded deterministic referral pricing-source identity only; reward lifecycle remains unaccepted | exact evidence-head gate + MASTER review; payout/pending/release/reversal later |
| `AGT-005` most-specific agent pricing | not-started beyond existing agent profile/reference foundations | after generic pricing-rule foundation is accepted |
| `PAY-001` payment-method eligibility | not-started except reusable account/wallet/provider-control foundations | after pricing rules / agent pricing |
| payment methods/providers | not-started beyond shared provider contracts/offline Phase 0.4 adapters | later Issue `#8` increments; includes provider-native refund integration |
| `BUY-001` purchase sequence | not-started as an accepted Order flow | Phase `0.6.0` ownership; do not pull forward |

## Current independent boundary — Worker `#25` evidence / MASTER review

The stored promotion/referral/pricing-rule implementation is complete on the Worker branch and implementation-head CI is green. The immediate boundary is exact evidence-head CI/artifact verification and independent MASTER review of PR `#26`.

Even if PR `#26` is accepted, keep full promotion reserve/redeem/release, referral payout/reversal, most-specific `AGT-005`, `PAY-001`, provider execution, Order payment, and provisioning outside this first rule-resolution boundary unless separately scoped and independently evidenced.

## Cross-phase carry-forward rules

1. Phase `0.4.0` stays active/open until applicable provider live rows and closure audit pass.
2. Source/offline/harness provider proof is never described as executed deployment acceptance.
3. Later financial work cannot weaken provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls.
4. Financial effects always use fresh transaction/lock-based authoritative state; persisted snapshots are never authority.
5. Every accepted bounded increment requires exact implementation CI/artifact plus exact evidence-head CI/artifact and independent digest inspection.
6. Refund/correction compensates immutable history rather than editing/deleting it.
7. Browser return never proves capture; no paid provisioning precedes authoritative capture.
8. Orders/provisioning/Service lifecycle remain Phase `0.6.0` and are not pulled forward into Phase `0.5.0`.

Until the baseline matrix is regenerated, requirement wording comes from `docs/01-authoritative-requirements.md`, historical proof from bounded evidence/traceability files, current status from this overlay plus `PROJECT_STATUS.md`, and live head/CI only from Draft PR `#6`.