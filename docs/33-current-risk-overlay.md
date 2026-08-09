# Current Risk Overlay

**Last reviewed:** 2026-08-09  
**Purpose:** current delivery/control risk status while preserving definitions/history in `docs/03-risk-register.md`.  
**Rule:** this overlay governs current status where baseline risk status is stale. Read with `PROJECT_STATUS.md`, `docs/32-current-traceability-overlay.md`, and `docs/52-current-continuation-handoff.md`. Live head/CI truth comes only from Draft PR `#6`.

## Controlled project-control risks

| ID | Risk | Current control/evidence | Status |
|---|---|---|---|
| `RSK-024` | vulnerable/incompatible locked dependency | mandatory dependency audit/license/static/full suite on current development branch | Controlled on integration; Draft PR `#24` retains a separate old-main dependency-audit failure until reworked/accepted |
| `RSK-026` | implicit/inconsistent PHP runtime breaks CI | explicit self-hosted PHP 8.4/Composer/PCOV contract | Controlled for CI; production remains deployment acceptance |
| `RSK-031` | status drift misdirects continuation | `AGENTS.md`, refreshed `PROJECT_STATUS.md`, status JSON/schema, overlays, continuation handoff and machine project-control verification | Controlled by transition checkpoint; final transition-head CI still required before dispatch |
| `RSK-032` | single self-hosted runner throttles work | branch concurrency, deterministic runner contract, larger Worker capability slices, avoidable-CI-churn reduction | Operational throughput constraint remains; implementation parallelism may exceed CI parallelism |
| `RSK-033` | obsolete/destructive staging workflow runs | historical workflows inert; readiness read-only; live provider workflow manual/branch/confirmation gated | Controlled; default-branch bootstrap remains separate human gate |
| `RSK-034` | temporary repair automation persists | repair workflow/generator removed and machine-gated | Closed |
| `RSK-036` | target architecture is mistaken for implemented capability | explicit verified/offline/harness/live/parallel status vocabulary and bounded non-claims | Controlled |
| `RSK-046` | orchestration fragmentation creates many micro-PRs with little capability progress | throughput policy targets 4–5 independent Workers and meaningful coherent capability slices; ready Workers reviewed immediately | Controlled direction established; monitor next waves and enlarge contracts when dependency graph permits |

## Phase 0.4 provider risks

| ID | Risk | Current control | Remaining exit condition | Status |
|---|---|---|---|---|
| `RSK-038` | pinned source differs from deployed provider | exact pins Marzban `v0.8.4` / PasarGuard `v5.2.1`; exact version gates; Targets disabled | PasarGuard protected live run; Marzban final-release live acceptance/re-review | PasarGuard Blocked-live / Marzban Carried-release-gate |
| `RSK-039` | secondary integration reference overrides provider authority/security | pinned upstream source remains authoritative; no example may weaken TLS/redaction/retry rules | preserve through live execution | Controlled |
| `RSK-040` | uncertain provider mutation retry duplicates remote effect | lookup-before-create, provider-specific create equivalence, uncertainty => discovery, idempotency conflict preservation | controlled timeout/5xx/429 live evidence and no duplicate primary effect | Blocked-live / Critical |
| `RSK-041` | provider credential excessive privilege or leaks | encrypted validated credentials; redaction; protected Actions Secret inputs only | least-privilege live auth acceptance and post-test credential hygiene | Blocked-live / Critical |
| `RSK-042` | provider environment/default-branch gate blocks acceptance | non-live work + guarded PasarGuard harness complete; Draft PR `#24` provides bounded bootstrap path | deliberately resolve PR `#24`, then protected manual dispatch; Marzban environment later | Scheduled human/bootstrap gates |
| `RSK-043` | provider-observable read hash mistaken for create equality | separate canonical/read hash and provider create-equivalence proof | accepted offline evidence | Controlled |
| `RSK-044` | source/harness target discovery mistaken for operational Target acceptance | Targets remain disabled; route verifier requires active verified chain | explicit controlled Target activation acceptance | Blocked-live |
| `RSK-045` | test/live credential remains usable longer than necessary | repository/evidence never retain values; live workflow requires protected Secrets | protected use then rotate/revoke as applicable | Open credential-hygiene risk |

### Bootstrap retention risk

Draft PR `#24` remains open/Draft on `ops/provider-live-dispatch-bootstrap` -> `main`. The bootstrap and `safety/main-2026-08-08-pre-provider-bootstrap` branches remain required until PR `#24` is deliberately merged or explicitly abandoned/replaced and rollback/default-branch dispatch state is resolved. Do not delete them to simplify Worker management.

## Parallel Phase 0.5 financial/pricing risks

Overlay-local IDs remain temporary until the next full `docs/03-risk-register.md` regeneration.

| Overlay ID | Risk | Current control/evidence | Remaining exit condition | Status |
|---|---|---|---|---|
| `FIN-01` | concurrent wallet/payment operations over-reserve or double-effect | accepted transactions/locks/unique identities, immutable ledger and real-MariaDB contention boundaries | repeat feature-specific proof for future provider/order financial effects | Controlled for accepted wallet/top-up boundaries |
| `FIN-02` | persisted wallet snapshot is treated as financial authority | snapshots non-authoritative; execution uses finalized ledger + active holds/locked reads | preserve through future payment/order work | Controlled |
| `FIN-05` | refund/correction mutates historical ledger | accepted WAL-004/WAL-005 compensating immutable history and execution-time guards | provider/order refund paths must preserve compensation semantics | Controlled |
| `FIN-08` | Payment Intent/provider flow provisions before authoritative capture or duplicates effect | accepted `wallet_top_up` intent requires authoritative settled evidence and one settlement/top-up effect; no Order/provisioning side effect | genuine purchase-bound payment authority and later Phase 0.6 no-provision-before-capture evidence | Controlled for wallet top-up; purchase-payment gate remains High/Critical |
| `FIN-13` | browser return/customer claim is mistaken for capture | accepted top-up authority split rejects non-authoritative browser-like evidence | real purchase/provider transports must preserve authority split | Controlled for accepted top-up boundary |
| `FIN-17` | accepted Quote is reinterpreted by later pricing/config changes | immutable integer-IRR `BUY-002` Quote snapshot/hash/expiry and DB immutability | later agent/promotion/payment-method integrations must consume immutable resolved identities without reinterpretation | Controlled for Quote; integration risk remains |
| `FIN-18` | mutable promotion/referral rules change pricing identity or cross-user resolution abuses subject identity | accepted W-001 stored immutable rule/version/resolution, deterministic qualification, admin-authorized rule management, legitimate customer/agent subject authorization | referral reward effects and later complete promotion execution need separate effect/concurrency proof | Controlled for stored resolution; High residual for effects |
| `FIN-19` | promotion use limits reset across revisions or concurrent reserve/release oversubscribes capacity | accepted W-002 stable `pricing_rule_id` capacity/serialization, immutable version snapshot, exact release, cross-version independent-process contention | successful-payment redemption/finalization and automatic payment-driven release require genuine purchase authority and terminal-race proof | Controlled for reservation/release; High residual for redemption orchestration |
| `FIN-20` | agent price selection is ambiguous, stale or retroactively reinterpreted | accepted W-003 stored/versioned resolver, deterministic most-specific rule, fail-closed equal specificity, active-agent/current-profile auth, immutable result | Quote consumption/integration must preserve accepted resolver identity and discount-combination policy | Controlled for resolver; integration risk remains High |
| `FIN-21` | current `wallet_top_up` Payment Intent is incorrectly reused as purchase authority to unlock promotion redemption | W-002 architecture decision explicitly forbids treating wallet funding as purchase settlement; redemption deferred | introduce/consume genuine purchase-bound authority at correct architecture boundary before redemption | Open / High |

## Latest accepted product checkpoint

The ordered W-002/W-003 wave culminated at integration product baseline `fae391569e50a2f318e2ca06aa522605d385ce2a`.

Post-merge Draft PR `#6` CI `31295225638` / `#1313` passed all five mandatory jobs with **425 tests / 2623 assertions**. Artifact `test-evidence-31295225638`, ID `9032748596`, was independently downloaded and hashed to `1014ff3150301174f4637f99528652fabd5ab197d9ac9ce9fb3bc361764ab6d4`, matching uploader digest.

MASTER-owned project-control commits after that product baseline are not product acceptance increments. Their final exact head must pass project-control/preflight and all mandatory CI before the new MASTER dispatches implementation work.

## Financial safety decisions

1. IRR is integer at financial/pricing boundaries; monetary float is forbidden.
2. Finalized balanced ledger history is authoritative and append-only.
3. Active holds reserve value; persisted balance snapshots/caches are not financial authority.
4. Financial execution uses fresh transaction/lock-based authoritative reads.
5. Exact replay returns the accepted effect/snapshot; materially changed reuse conflicts and cannot overwrite it.
6. Refund/correction compensates accepted history rather than mutating/deleting it.
7. MariaDB locking/isolation is not weakened to satisfy concurrency tests; deadlock/timeout must surface/reconcile safely.
8. Browser return/redirect/customer submission is never capture authority.
9. External top-up posts only after matching authoritative settled provider evidence and only to the intended active owned cash wallet.
10. Duplicate provider/internal payment events cannot produce a second settlement/top-up ledger effect.
11. Safe provider evidence is normalized, secret/raw-field filtered, append-only and bounded.
12. Accepted Quotes are immutable integer-IRR historical snapshots and are not purchase/payment authority by themselves.
13. Promotion/referral/agent/payment-method resolution must persist the exact resolved identity consumed by pricing; mutable policy cannot retroactively reinterpret accepted history.
14. Promotion usage capacity uses authoritative stored lifecycle state, not caller-observed usage inputs.
15. W-002 release is a primitive, not proof that current wallet-top-up Payment Intents drive promotion release/redemption.
16. Agent pricing override is resolved before discount; Quote integration must respect the W-003 snapshotted discount-combination policy.

## Current human/protected gates

### PasarGuard

The live path remains blocked before dispatch by unresolved default-branch bootstrap PR `#24`, then requires protected repository Actions Secrets and manual guarded dispatch. Never copy secret values into Chat or repository evidence.

### Marzban final release

Marzban `v0.8.4` deployment acceptance remains mandatory and intentionally scheduled for final release acceptance.

### High/Critical Worker merges

Independent MASTER review plus explicit owner approval remains required unless the exact risk has been pre-authorized. Final PR `#6` -> `main` always requires explicit owner release acceptance.

## Next risk-reduction / throughput work

No implementation Worker is pre-dispatched by the transition checkpoint. The replacement MASTER must first verify the final control-plane head/CI and rebuild the dependency/conflict graph. It should then target 4–5 genuinely independent Workers with larger coherent capability slices where safe. Candidate areas include full AGT-005 Quote consumption, PAY-001, PRO-002, REF-001 reward lifecycle, payment/provider packages, and remaining PRO-001 redemption only after correct purchase-payment authority exists.

If those areas share a blocking contract/schema, stabilize the smallest common prerequisite first. Do not manufacture micro-tasks or pull Phase `0.6.0` Order/provisioning ownership forward just to increase Worker count.

At the next full risk-register regeneration, fold relevant overlay decisions into `docs/03-risk-register.md` without erasing historical definitions or falsely closing live/provider-dependent risks.
