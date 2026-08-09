# Current Traceability Overlay

**Last reviewed:** 2026-08-09  
**Purpose:** authoritative current implementation/evidence status while the baseline matrix is incrementally reconciled.  
**Authority:** requirement wording remains in `docs/01-authoritative-requirements.md`; this overlay supersedes stale status cells in `docs/02-requirement-traceability-matrix.md`.

Read with `PROJECT_STATUS.md`, `docs/project-status.json`, `docs/33-current-risk-overlay.md`, and `docs/52-current-continuation-handoff.md`. The live working head always comes from Draft PR `#6`; never treat a SHA in this document as the current dispatch base.

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
| `0.4.0` | blocked-live / active | Issue `#7`; PasarGuard harness accepted; PR `#24` bootstrap + protected live execution remain; Marzban is a carried final-release gate |
| `0.5.0` | not closed; parallel-verified chain through bounded W-003 agent-pricing resolver | Issue `#8`; accepted traceability chain listed below |
| `0.6.0`–`1.0.0` | not-started except explicitly documented shared foundations | authoritative phase plan |

Phase `0.4.0` remains the active phase. Parallel Phase 0.5 evidence cannot be used to claim Phase 0.4, Phase 0.5, or release completion.

## Phase 0.4 accepted provider state

| Area / requirement | Current status | Accepted proof / remaining gate |
|---|---|---|
| Catalog/Offering/Capacity/Trial (`CAT-*`) | verified within accepted Phase 0.4 boundaries | bounded evidence/traceability docs `23`–`34` |
| `PRV-001` connection/version/capability | verified-offline; PasarGuard harness-verified/blocked-live; Marzban carried-release-gate | docs `36`, `41`, `43`, `44`; default-branch bootstrap PR `#24` remains unresolved |
| `PRV-002` lookup/create/adoption/effect mapping | verified-offline; PasarGuard blocked-live; Marzban carried-release-gate | controlled live adoption/idempotency rows remain |
| `PRV-003` failure/retry/reconciliation | verified-offline; controlled live uncertainty still blocked | timeout/5xx/429 live evidence remains |
| `SEC-001`, `SEC-002` provider controls | verified offline/harness scope | protected live credential acceptance and post-test hygiene remain |
| `QUA-001` | verified per accepted increment | exact-SHA CI, retained artifacts, independent digests |

PasarGuard `v5.2.1` guarded harness is accepted at implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2` / CI `#1013`, evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412` / CI `#1015`, **317 tests / 1730 assertions**.

Draft PR `#24` (`ops/provider-live-dispatch-bootstrap` -> `main`) remains a separate protected/human gate. Re-fetch it before action. No live dispatch, provider credential acceptance or Target activation is implied by repository bootstrap state. Marzban `v0.8.4` deployment-specific live acceptance remains a final-release gate.

## Parallel Phase 0.5 accepted traceability

Status: **parallel-verified** for thirteen bounded foundations:

1. `docs/45-phase-0.5-financial-ledger-traceability.md`;
2. `docs/46-phase-0.5-wallet-holds-traceability.md`;
3. `docs/48-phase-0.5-wallet-reconciliation-traceability.md`;
4. `docs/49-phase-0.5-wallet-maintenance-traceability.md`;
5. `docs/51-phase-0.5-wallet-transfer-traceability.md` (`WAL-003`);
6. `docs/53-phase-0.5-wallet-contention-traceability.md`;
7. `docs/54-phase-0.5-wallet-refund-traceability.md` (`WAL-004`);
8. `docs/55-phase-0.5-wallet-correction-traceability.md` (`WAL-005`);
9. `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md` (`PAY-002`, `PAY-003`, `WAL-001`);
10. `docs/57-phase-0.5-quote-pricing-traceability.md` (`BUY-002`);
11. `docs/58-phase-0.5-promotion-referral-pricing-rule-traceability.md` (W-001 stored promotion/referral pricing-rule resolution);
12. `docs/60-phase-0.5-promotion-usage-reservation-traceability.md` (W-002 promotion usage reservation/capacity + explicit release);
13. `docs/phase-0.5-agent-pricing-resolution-traceability.md` (W-003 stored most-specific agent-pricing resolver).

The product integration checkpoint immediately after W-003 merge is `fae391569e50a2f318e2ca06aa522605d385ce2a`; Draft PR `#6` CI `31295225638` / `#1313` passed all five mandatory jobs with **425 tests / 2623 assertions**. Artifact `test-evidence-31295225638`, ID `9032748596`, independently hashed to `1014ff3150301174f4637f99528652fabd5ab197d9ac9ce9fb3bc361764ab6d4`. Later MASTER-owned project-control commits may advance the live PR `#6` head and must receive their own final CI before Worker dispatch.

Accepted shared invariants include integer IRR, append-only balanced ledger authority, active-hold available-balance calculation, immutable compensating history, exact replay/conflict, independent-process MariaDB contention proof where required, authoritative payment evidence before accepted top-up capture, immutable Quote snapshots, immutable pricing-rule resolution identity, stable-rule promotion usage capacity and deterministic stored agent-pricing resolution.

## Current Phase 0.5 requirement state

| Requirement / area | Current accepted boundary | Still open |
|---|---|---|
| `PRO-001` | W-001 stored rule/version/resolution + W-002 authoritative stable-rule reservation/capacity and explicit release | successful-payment redeem/finalize, automatic Payment Intent-driven release, complete end-to-end promotion execution |
| `REF-001` | W-001 stores bounded deterministic referral pricing-source identity as part of immutable pricing-rule resolution | reward pending/release/reversal, payout, limits, anti-abuse, notifications and financial effects |
| `AGT-005` | W-003 stored/versioned most-specific action/offering/server/product resolver; fail-closed ambiguity; immutable result | accepted Quote does not yet consume resolver; full end-to-end agent pricing remains open |
| `PAY-001` | no accepted gateway/payment-method eligibility engine | full eligibility rules and fallback policy |
| `PAY-002`, `PAY-003` | accepted Payment Intent is bounded to authoritative external `wallet_top_up` settlement | genuine purchase/Order-bound payment authority and provider-specific purchase execution remain open |
| `PRO-002` | no accepted gift/service/wallet code lifecycle | complete requirement |
| payment methods/providers | shared provider contracts/offline Phase 0.4 adapters only | Phase 0.5 payment/provider packages, callbacks/reconciliation/refunds as separately scoped |
| `BUY-002` | immutable deterministic Quote accepted | later integration may consume accepted agent/promotion resolution without reinterpreting history |
| `BUY-001` / paid Order flow | not accepted | Phase `0.6.0` ownership; do not pull forward simply to fill Worker slots |

### W-001 — stored promotion/referral pricing-rule resolution

Accepted after independent review and history-preserving integration. It provides typed stored rule/version/config identity, deterministic qualification/precedence, explicit no-match, legitimate customer/agent pricing-subject authorization, administrator-authorized rule management, replay/conflict and MariaDB integrity/immutability. Caller-observed usage counts are not authoritative counters. Reservation/redemption and referral reward effects are outside that boundary.

### W-002 — promotion usage reservation/capacity + explicit release

Accepted Contract Revision 2 only. Capacity and serialization use stable `pricing_rule_id` across immutable rule versions; active reservations from earlier versions consume current stable-rule capacity, explicit release returns capacity, and independent-process MariaDB tests prove final-slot/cross-version behavior. It does **not** implement successful-payment redemption/finalization or automatic payment-intent release orchestration because the accepted Payment Intent is `wallet_top_up` only and cannot be treated as purchase authority.

### W-003 — stored most-specific agent-pricing resolver

Accepted Contract Revision 1 only. Stored/versioned agent pricing profiles/rules, integer-IRR override, deterministic most-specific action/offering/server/product selection, equal-specificity fail-closed behavior, explicit no-match, active-agent/current-profile authorization, discount-combination snapshot, exact replay/conflict and immutable historical resolution are verified. Accepted Quote semantics were intentionally unchanged; Quote consumption remains a later bounded capability.

## MASTER transition boundary

At this checkpoint W-001, W-002 and W-003 are closed as completed for their bounded contracts. No next-wave implementation Worker is intentionally pre-dispatched while the control-plane transition is being finalized.

The replacement MASTER must live-fetch PR `#6`, verify its exact final transition head and five-job CI, inspect Issues `#7` and `#8`, PR `#24`, open PRs/branches and recent closed Worker Issues/PRs, then recompute dependency and pairwise-conflict graphs. Candidate workstreams are not commitments until that recovery cycle marks them READY.

Throughput policy is now durable in `docs/development/multi-agent-orchestration.md`: target 4–5 genuinely independent Workers, use larger coherent capability slices instead of micro-task fragmentation, and review/integrate each ready Worker without waiting for an entire wave. Safety gates remain unchanged.

## Cross-phase carry-forward rules

1. Phase `0.4.0` stays active/open until applicable provider live rows and closure audit pass.
2. Source/offline/harness provider proof is never described as executed deployment acceptance.
3. Later financial work cannot weaken provider lookup/equivalence/idempotency/uncertainty/TLS/redaction/Target controls.
4. Financial effects always use fresh transaction/lock-based authoritative state; persisted snapshots are never authority.
5. Every accepted bounded increment requires its defined exact-SHA CI/evidence lifecycle, independent MASTER review and post-merge integration proof.
6. Refund/correction compensates immutable history rather than editing/deleting it.
7. Browser return never proves capture; no paid provisioning precedes authoritative capture.
8. Orders/provisioning/Service lifecycle remain Phase `0.6.0` and are not pulled forward into Phase `0.5.0` merely to increase parallelism.

Until the baseline matrix is regenerated, requirement wording comes from `docs/01-authoritative-requirements.md`, historical proof from bounded evidence/traceability files, current status from this overlay plus `PROJECT_STATUS.md`, and live head/CI only from Draft PR `#6`.
