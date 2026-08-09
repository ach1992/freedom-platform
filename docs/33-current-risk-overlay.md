# Current Risk Overlay

**Last reviewed:** 2026-08-09  
**Purpose:** current delivery/control risk status while preserving definitions/history in `docs/03-risk-register.md`.  
**Rule:** this overlay governs current status where baseline risk status is stale. Read with `PROJECT_STATUS.md`, `docs/32-current-traceability-overlay.md`, and `docs/52-current-continuation-handoff.md`. Live head/CI truth comes only from Draft PR `#6`.

## Controlled project-control risks

| ID | Risk | Current control/evidence | Status |
|---|---|---|---|
| `RSK-024` | vulnerable/incompatible locked dependency | mandatory dependency audit/license/static/full suite | Controlled on current development branch; PR `#24` bootstrap still has a separate old-main dependency-audit failure |
| `RSK-026` | implicit/inconsistent PHP runtime breaks CI | explicit self-hosted PHP 8.4/Composer/PCOV contract | Controlled for CI; production remains deployment acceptance |
| `RSK-031` | status drift misdirects continuation | `AGENTS.md`, `PROJECT_STATUS.md`, status JSON/schema, machine project-control verification, current handoff | Controlled |
| `RSK-032` | single self-hosted runner throttles work | branch concurrency, timeouts and deterministic runner contract | Controlled operationally; throughput constraint remains |
| `RSK-033` | obsolete/destructive staging workflow runs | historical workflows inert; readiness read-only; live provider workflow manual/branch/confirmation gated | Controlled; default-branch bootstrap not yet accepted |
| `RSK-034` | temporary repair automation persists | repair workflow/generator removed and machine-gated | Closed |
| `RSK-036` | target architecture is mistaken for implemented capability | explicit verified/offline/harness/live/parallel status vocabulary | Controlled |

## Phase 0.4 provider risks

| ID | Risk | Current control | Remaining exit condition | Status |
|---|---|---|---|---|
| `RSK-038` | pinned source differs from deployed provider | exact pins Marzban `v0.8.4` / PasarGuard `v5.2.1`; exact version gates; Targets disabled | PasarGuard protected live run; Marzban final-release live acceptance/re-review | PasarGuard Blocked-live / Marzban Carried-release-gate |
| `RSK-039` | secondary integration reference overrides provider authority/security | pinned upstream source remains authoritative; no example may weaken TLS/redaction/retry rules | preserve through live execution | Controlled |
| `RSK-040` | uncertain provider mutation retry duplicates remote effect | lookup-before-create, provider-specific create equivalence, uncertainty => discovery, idempotency conflict preservation | controlled timeout/5xx/429 live evidence and no duplicate primary effect | Blocked-live / Critical |
| `RSK-041` | provider credential excessive privilege or leaks | encrypted validated credentials; redaction; protected Actions Secret inputs only | least-privilege live auth acceptance and post-test credential hygiene | Blocked-live / Critical |
| `RSK-042` | provider environment/default-branch gate blocks acceptance | non-live work + guarded PasarGuard harness complete; Draft PR `#24` provides bounded bootstrap path | resolve PR `#24` dependency-policy failure, accept bootstrap, then protected manual dispatch; Marzban environment later | Scheduled human/bootstrap gates |
| `RSK-043` | provider-observable read hash mistaken for create equality | separate canonical/read hash and provider create-equivalence proof | accepted offline evidence | Controlled |
| `RSK-044` | source/harness target discovery mistaken for operational Target acceptance | Targets remain disabled; route verifier requires active verified chain | explicit controlled Target activation acceptance | Blocked-live |
| `RSK-045` | externally disclosed test credential remains usable too long | repository/evidence never retain it; live workflow requires protected Secrets | protected use then rotate/revoke as applicable | Open credential-hygiene risk |

### Bootstrap retention risk

Draft PR `#24` remains open on `ops/provider-live-dispatch-bootstrap`; its CI `31240183151` / `#1129` failed only the dependency/license job at `composer audit --locked --abandoned=fail`, while preflight, secret scan, static quality, and MariaDB/Redis tests passed. `main` remains unchanged from bootstrap base `1227cce28aedd2d799f2cd510891309deaacd0fb`.

The bootstrap branch and `safety/main-2026-08-08-pre-provider-bootstrap` snapshot should remain until PR `#24` is deliberately merged or abandoned/replaced and rollback/default-branch dispatch state is resolved. Premature deletion would remove the active bootstrap head or its rollback reference without reducing a project risk.

## Parallel Phase 0.5 financial risks

Overlay-local IDs remain temporary until the next full `docs/03-risk-register.md` regeneration.

| Overlay ID | Risk | Current control/evidence | Remaining exit condition | Status |
|---|---|---|---|---|
| `FIN-01` | concurrent wallet/payment operations can over-reserve/double-effect | transactions, sorted row locks, unique command/hold/transfer/refund/correction/intent/event/settlement identities, immutable ledger, bounded real-MariaDB contention workers | repeat feature-specific proof for future provider/order financial effects | Controlled for accepted wallet/top-up boundaries |
| `FIN-02` | persisted wallet snapshot is treated as financial authority | snapshots append-only/non-authoritative; wallet/refund/correction/top-up use finalized ledger + active holds/locked reads where relevant | preserve through pricing/payment/order work | Controlled with regression requirement |
| `FIN-03` | transfer hold and transfer state diverge on expiry/cleanup | generic cleanup excludes `wallet_transfer`; confirm-expiry and explicit cancellation coordinate state | optional future scheduled transfer-expiry lifecycle must use transfer service | Controlled; operational follow-up open |
| `FIN-04` | duplicate transfer/retry creates a second primary effect | unique transfer key, canonical payload hash, one hold/ledger link, replay plus concurrent duplicate proof | preserve through transfer extensions | Controlled |
| `FIN-05` | refund/correction mutates historical ledger instead of compensating it | finalized-ledger guards + accepted `WAL-004` refund history + accepted `WAL-005` correction history and compensating effects | future provider/payment/order financial paths must preserve immutable compensation | Controlled |
| `FIN-06` | fee/limit/bucket policy changes reinterpret accepted transfer | immutable transfer policy snapshots and stable account references | preserve through future config changes | Controlled |
| `FIN-07` | untouched expired pending transfer reserves funds indefinitely | expiry enforced on confirm; explicit cancel exists; generic cleanup excludes transfer holds | later coordinated transfer-expiry scheduler only if required | Known operational gap / Medium |
| `FIN-08` | Payment Intent/provider flow provisions before authoritative capture or duplicates capture/top-up/refund | accepted `PAY-002`/`PAY-003`/`WAL-001` requires authoritative settled evidence, one settlement, one top-up ledger effect, and creates no Order/provisioning effect | real provider flows plus later Phase 0.6 no-provision-before-capture evidence | Controlled for provider-independent top-up; later Critical gate remains |
| `FIN-09` | concurrent partial refunds exceed captured/refundable value | immutable capture-time cap, locked source, cumulative/per-entry cap, unique refund key and two-process contention proof | provider-native refund paths need authoritative provider source/callback proof | Controlled for provider-independent `WAL-004` |
| `FIN-10` | refund destination/evidence mismatch causes double reimbursement/untraceable external payout | explicit wallet/manual-external destination, exact original allocations, evidence requirement, no wallet duplication, privileged override, safe audit | provider-native refunds need equivalent evidence/uncertainty controls | Controlled for provider-independent `WAL-004` |
| `FIN-11` | administrator correction bypasses authorization/confirmation or silently changes historical balance | accepted `WAL-005`: immutable preview/confirmation, execution-time authorization, policy revalidation, compensating ledger, immutable DB guards, safe audit | future UI/API must preserve service boundary | Controlled |
| `FIN-12` | large correction is self-approved/replayed or concurrent debits create negative availability | accepted `WAL-005`: independent approval, distinct approver, atomic consumption, exact replay binding, fresh available-balance lock, multi-process proof | future high-risk provider/order actions need their own dual-control evidence | Controlled for `WAL-005`; future gate remains |
| `FIN-13` | browser return/customer claim is mistaken for authoritative payment capture | accepted top-up requires `Success + Authoritative + Settled`, exact amount/provider/currency match; non-authoritative browser-like evidence cannot capture | real provider transports must preserve authority split | Controlled for accepted top-up boundary |
| `FIN-14` | duplicate provider events/transactions credit cash wallet twice | accepted top-up has event/transaction uniqueness, immutable replay validation, one settlement, deterministic ledger command, duplicate/cross-intent contention proof | provider-specific callbacks/reconciliation need equivalent proof | Controlled for accepted top-up boundary |
| `FIN-15` | external top-up credits promotional/wrong wallet or credits before capture | accepted top-up binds active owned IRR cash wallet, rejects promotional target, posts clearing-to-cash only after authoritative capture, DB guard verifies linkage | payment-method/provider transport remain later | Controlled for accepted top-up boundary |
| `FIN-16` | provider safe evidence stores unbounded/raw sensitive payload or credentials | accepted top-up filters forbidden/raw fields; DB requires JSON object <=32 fields and <=8192 bytes; implementation/evidence artifact scans found no known secrets | provider-adapter regression remains mandatory | Controlled for accepted top-up boundary |
| `FIN-17` | accepted Quote is reinterpreted by later pricing/config changes or monetary rounding | accepted `BUY-002` immutable Quote: integer IRR, exact replay/conflict, Offering version/hash snapshot, explicit validity, DB update/delete/hash guards | future promotion/referral/agent/payment-method rule layers must snapshot their own resolved identity and must not reinterpret accepted Quotes | Controlled for `BUY-002`; later pricing layers open |
| `FIN-18` | mutable promotion/referral/pricing rules silently change eligibility/price, cross-user resolution abuses pricing identity, or future reservation/reward execution duplicates effects | Worker `#25` Revision 2 corrected candidate at implementation `6724d570a73bd470d70b8d17f9e0422bec0c5257` / CI `#1266` adds immutable rule/version/resolution identity, exact replay/conflict, deterministic qualification/priority, fail-closed ambiguity, administrator-authorized rule management, legitimate customer/agent subject authorization with cross-user rejection, and MariaDB guards | exact evidence-head gate + MASTER review/integration; reservation/redemption/release counters and referral reward/payout/reversal still need separate contention/effect proof | Corrected implementation-verified candidate / High residual |

## Latest accepted financial evidence

### Payment Intent / external cash-wallet top-up

- implementation `6db9dde7032114ab81c95fdf371530997e65f21c`, CI `31265449681` / `#1214` — **384 / 2292**;
- evidence `76ea847d4d1f626893b925bbfe5263e1dc1398e4`, CI `31265901691` / `#1220` — **384 / 2292**;
- evidence artifact `test-evidence-31265901691`, ID `9024152227`;
- independent digest `sha256:dff70424074d138f59a8c9bfb03e5196f3827142fe964b043c09ef53e15392e7`;
- evidence `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`;
- traceability `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

### Deterministic Pricing / immutable Quote (`BUY-002`)

- implementation `16ebe0f9579f8ecb913ffd860bf6a9ba25567263`, CI `31267071664` / `#1233` — **392 / 2355**, Quote **8 / 63**;
- evidence `07840d417e64eb30bf31f17bb9e26c1fe549eef3`, CI `31267346707` / `#1236` — **392 / 2355**, Quote **8 / 63**;
- evidence artifact `test-evidence-31267346707`, ID `9024577868`, size `116261` bytes;
- independent digest `sha256:ba2710a544ccd07c72a56c616f7215406175ca8fdc93bc770c47ad474b275e83`;
- evidence `evidence/0.5.0/quote-pricing-snapshot.md`;
- traceability `docs/57-phase-0.5-quote-pricing-traceability.md`.

## Current Worker evidence candidate — stored promotion/referral pricing-rule resolution

This candidate is intentionally **not** listed as accepted financial evidence until the evidence-head gate and MASTER integration decision complete.

- Issue `#25`, Worker `W-001`, Contract Revision `2`, PR `#26`;
- previous reviewed head `836adbfdf5380dae4854671f22de83cd3f0a3a69`;
- corrected implementation `6724d570a73bd470d70b8d17f9e0422bec0c5257`, CI `31285667745` / `#1266` — **399 / 2429**;
- dedicated pricing-rule suite **7 / 74**; unchanged Quote regression **8 / 63**;
- implementation artifact `test-evidence-31285667745`, ID `9029733870`, size `119611` bytes;
- independent digest `sha256:3657ac08fbc1ef75d413018e27c3950f4f58bfbe4e7c8bd13be9b1498fcc3f1c`;
- evidence candidate `evidence/0.5.0/promotion-referral-pricing-rule-resolution.md`;
- traceability candidate `docs/58-phase-0.5-promotion-referral-pricing-rule-traceability.md`.

Revision 2 keeps rule create/revise behind existing administrator authorization/audit, while pricing resolution uses a typed user-subject context: actor user must equal pricing subject before replay/persistence, and authoritative current subject state must be active `customer|agent`. Cross-user resolution fails closed. The resolution table no longer requires an administrator FK and replay identity no longer binds an arbitrary administrator ID.

The candidate reduces `FIN-18` for mutable-rule reinterpretation, nondeterministic precedence, ambiguous configuration, replay/conflict, and the reviewed cross-user/administrator-coupling flaw at the stored resolution boundary. It does not reduce future duplicate reservation/redemption/reward-effect risk because no authoritative counters or payout state transitions are implemented here.

## Financial safety decisions

1. IRR is integer at financial/pricing boundaries; monetary float is forbidden.
2. Finalized balanced ledger history is authoritative and append-only.
3. Active holds reserve value; persisted balance snapshots are derived evidence/cache only.
4. Financial execution uses fresh transaction/lock-based authoritative reads.
5. Exact replay returns the accepted effect/snapshot; materially changed reuse conflicts and cannot overwrite it.
6. Refund/correction compensates accepted history rather than mutating/deleting it.
7. MariaDB locking/isolation is not weakened to satisfy concurrency tests; deadlock/timeout must surface/reconcile safely.
8. Browser return/redirect/customer submission is never capture authority.
9. External top-up may post only after matching authoritative settled provider evidence and only to the intended active owned cash wallet.
10. Duplicate provider/internal payment events cannot produce a second settlement/top-up ledger effect.
11. Safe provider evidence is normalized, secret/raw-field filtered, append-only and bounded.
12. Accepted Quotes are immutable integer-IRR historical snapshots and cannot become payment/purchase authority by themselves.
13. Later promotion/referral/agent/payment-method rule resolution must persist the resolved rule/configuration identity consumed by pricing; mutable policy must not retroactively reinterpret accepted Quotes.
14. A stored promotion/referral rule-resolution layer must not treat observed usage inputs as authoritative redemption counters; reservation/redemption/reward execution requires a separately scoped concurrency/effect boundary.
15. Promotion/referral rule management remains administrator-authorized, but customer/agent pricing resolution must bind to the legitimate pricing subject and must not require, fabricate, or repurpose administrator authority; cross-user resolution fails closed.

## Current human gates

### PasarGuard

The live path is still blocked before dispatch by unresolved default-branch bootstrap PR `#24`, then requires protected repository Actions Secrets and manual guarded dispatch. Never copy secret values into chat/repository evidence.

### Marzban final release

Marzban `v0.8.4` deployment acceptance remains mandatory and intentionally scheduled for final release acceptance.

Until applicable provider gates pass: no live-provider compatibility claim, no production Target activation, no Phase `0.4.0` closure, and no final `1.0.0` acceptance.

## Next risk-reduction work

The immediate Worker boundary is exact evidence-head CI/artifact verification and independent MASTER review of PR `#26`. If accepted, the next `FIN-18` reduction must separately prove promotion reservation/redemption/release contention and referral reward-effect semantics without pulling `AGT-005`, `PAY-001`, provider execution, Order, or provisioning ownership forward.

At the next full risk-register regeneration, fold relevant overlay decisions into `docs/03-risk-register.md` without erasing historical definitions or falsely closing live/provider-dependent risks.
