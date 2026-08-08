# Current Risk Overlay

**Last reviewed:** 2026-08-08  
**Purpose:** current delivery/control risk status while preserving definitions/history in `docs/03-risk-register.md`.  
**Rule:** this overlay governs current status where baseline risk status is stale. Read with `PROJECT_STATUS.md`, `docs/32-current-traceability-overlay.md`, and `docs/52-current-continuation-handoff.md`.

## Controlled project-control risks

| ID | Risk | Current control/evidence | Status |
|---|---|---|---|
| `RSK-024` | vulnerable/incompatible locked dependency | mandatory dependency audit/license/static/full suite | Controlled |
| `RSK-026` | implicit/inconsistent PHP runtime breaks CI | explicit self-hosted PHP 8.4/Composer/PCOV contract | Controlled for CI; production remains deployment acceptance |
| `RSK-031` | status drift misdirects continuation | `AGENTS.md`, `PROJECT_STATUS.md`, status JSON/schema, machine project-control verification, current handoff | Controlled |
| `RSK-032` | single self-hosted runner throttles work | branch concurrency, timeouts and deterministic runner contract | Controlled operationally; throughput constraint remains |
| `RSK-033` | obsolete/destructive staging workflow runs | historical workflows inert; readiness read-only; live provider workflow manual/branch/confirmation gated | Controlled |
| `RSK-034` | temporary repair automation persists | repair workflow/generator removed and machine-gated | Closed |
| `RSK-036` | target architecture is mistaken for implemented capability | explicit verified/offline/harness/live/parallel status vocabulary | Controlled |

## Phase 0.4 provider risks

| ID | Risk | Current control | Remaining exit condition | Status |
|---|---|---|---|---|
| `RSK-038` | pinned source differs from deployed provider | exact pins Marzban `v0.8.4` / PasarGuard `v5.2.1`; exact version gates; Targets disabled | PasarGuard protected live run; Marzban final-release live acceptance/re-review | PasarGuard Blocked-live / Marzban Carried-release-gate |
| `RSK-039` | secondary integration reference overrides provider authority/security | pinned upstream source remains authoritative; no example may weaken TLS/redaction/retry rules | already controlled for current source implementation | Controlled |
| `RSK-040` | uncertain provider mutation retry duplicates remote effect | lookup-before-create, provider-specific create equivalence, uncertainty => discovery, idempotency conflict preservation | controlled timeout/5xx/429 live evidence and no duplicate primary effect | Blocked-live / Critical |
| `RSK-041` | provider credential excessive privilege or leaks | encrypted validated credentials; redaction; protected Actions Secret inputs only | least-privilege live auth acceptance and post-test credential hygiene | Blocked-live / Critical |
| `RSK-042` | provider environment gate blocks acceptance | non-live work + guarded PasarGuard harness complete | protected PasarGuard Secrets/manual dispatch now; Marzban final-release environment later | Scheduled human gates |
| `RSK-043` | provider-observable read hash mistaken for create equality | separate canonical/read hash and provider create-equivalence proof | accepted offline evidence | Controlled |
| `RSK-044` | source/harness target discovery mistaken for operational Target acceptance | Targets remain disabled; route verifier requires active verified chain | explicit controlled Target activation acceptance | Blocked-live |
| `RSK-045` | externally disclosed test credential remains usable too long | repository/evidence never retain it; live workflow requires protected Secrets | protected use then rotate/revoke as applicable | Open credential-hygiene risk |

## Parallel Phase 0.5 financial risks

Overlay-local IDs remain temporary until the next full `docs/03-risk-register.md` regeneration.

| Overlay ID | Risk | Current control/evidence | Remaining exit condition | Status |
|---|---|---|---|---|
| `FIN-01` | concurrent wallet/payment operations can over-reserve/double-effect | transactions, sorted row locks, unique command/hold/transfer/refund/correction keys, immutable ledger; current WAL-001 candidate adds intent/event/transaction/settlement uniqueness and bounded real-MariaDB capture workers | exact WAL-001 evidence-head gate, then repeat feature-specific proof for pricing/provider/order work | Controlled for accepted wallet/refund/correction; implementation-verified for WAL-001 |
| `FIN-02` | persisted wallet snapshot is treated as financial authority | snapshots append-only/non-authoritative; wallet/refund/correction/top-up use finalized ledger + active holds/locked reads where relevant | preserve through pricing/payment/order work | Controlled with regression requirement |
| `FIN-03` | transfer hold and transfer state diverge on expiry/cleanup | generic cleanup excludes `wallet_transfer`; confirm-expiry and explicit cancellation coordinate state | optional future scheduled transfer-expiry lifecycle must use transfer service | Controlled; operational follow-up open |
| `FIN-04` | duplicate transfer/retry creates a second primary effect | unique transfer key, canonical payload hash, one hold/ledger link, replay plus concurrent duplicate proof | preserve through transfer extensions | Controlled |
| `FIN-05` | refund/correction mutates historical ledger instead of compensating it | finalized-ledger guards + accepted `WAL-004` refund history + accepted `WAL-005` immutable correction preview/record and compensating effect | future provider/payment/order financial paths must preserve immutable compensation | Controlled for `WAL-004` + `WAL-005` |
| `FIN-06` | fee/limit/bucket policy changes reinterpret accepted transfer | immutable transfer policy snapshots and stable account references | future config changes must not rewrite history | Controlled |
| `FIN-07` | untouched expired pending transfer reserves funds indefinitely | expiry enforced on confirm; explicit cancel exists; generic cleanup excludes transfer holds | later coordinated transfer-expiry scheduler only if required | Known operational gap / Medium |
| `FIN-08` | Payment Intent/provider flow provisions before authoritative capture or duplicates capture/top-up/refund | WAL-001 candidate requires authoritative settled evidence, one settlement and one top-up ledger effect; no Order/provisioning side effect exists in this boundary | exact evidence-head acceptance; later Phase 0.6 no-provision-before-capture evidence and provider-native flows | Implementation-verified for top-up; later Critical gate remains |
| `FIN-09` | concurrent partial refunds exceed captured/refundable value | immutable capture-time cap, locked source, cumulative/per-entry cap, unique refund key and two-process contention proof | provider-native refund paths need authoritative provider source/callback proof | Controlled for provider-independent `WAL-004` |
| `FIN-10` | refund destination/evidence mismatch causes double reimbursement/untraceable external payout | explicit wallet/manual-external destination, exact original allocations, evidence requirement, no wallet duplication, privileged override, safe audit | provider-native refunds need equivalent evidence/uncertainty controls | Controlled for provider-independent `WAL-004` |
| `FIN-11` | administrator correction bypasses authorization/confirmation or silently changes historical balance | accepted `WAL-005`: immutable preview/confirmation, execution-time authorization, current policy revalidation, compensating ledger, immutable DB guards, safe audit | future correction UI/API must preserve service boundary; payment/order controls remain separate | Controlled for `WAL-005` |
| `FIN-12` | large correction is self-approved/replayed or concurrent debits create negative availability | accepted `WAL-005`: policy-driven independent approval, distinct approver, atomic consumption, exact committed-approval replay binding, fresh available-balance lock, multi-process debit/duplicate/approval proof | future high-risk payment/provider actions need their own dual-control/idempotency evidence | Controlled for `WAL-005`; future payment gate remains |
| `FIN-13` | browser return/customer claim is mistaken for authoritative payment capture | WAL-001 candidate enforces `Success + Authoritative + Settled`, exact amount/provider/currency match; non-authoritative browser-like evidence cannot persist/capture | exact evidence-head acceptance; real provider transport adapters must preserve this authority split | Implementation-verified / evidence gate pending |
| `FIN-14` | duplicate provider events/transactions credit cash wallet twice | WAL-001 candidate: provider event/transaction uniqueness, immutable replay validation, one settlement, deterministic ledger command, independent duplicate/cross-intent contention proof | exact evidence-head acceptance; provider-specific callbacks/reconciliation need equivalent proof | Implementation-verified / evidence gate pending |
| `FIN-15` | external top-up credits promotional/wrong wallet or credits before capture | WAL-001 candidate binds active owned IRR cash account, rejects promotional target, posts clearing-to-cash only after authoritative capture, DB settlement guard verifies linkage | exact evidence-head acceptance; payment-method eligibility/provider transport remain later | Implementation-verified / evidence gate pending |
| `FIN-16` | provider safe evidence stores unbounded/raw sensitive payload or credentials | WAL-001 candidate filters forbidden sensitive keys and raw body/payload fields; DB requires JSON object <=32 fields and <=8192 bytes; artifact safe scan found no provider secret values | exact evidence-head acceptance and provider-adapter regression | Implementation-verified / evidence gate pending |
| `FIN-17` | accepted Quote is reinterpreted by later pricing/config changes or monetary rounding | existing offering/custom-plan arithmetic is integer IRR but no accepted Phase 0.5 immutable Quote boundary exists | next `BUY-002` Pricing/Quote snapshot implementation with immutable inputs/version/validity and replay/conflict proof | Next pricing gate |

## Current implementation evidence — `PAY-002` / `PAY-003` / `WAL-001`

Payment Intent / External Cash-Wallet Top-up candidate:

- implementation `6db9dde7032114ab81c95fdf371530997e65f21c`, CI `31265449681` / `#1214` — **384 tests / 2292 assertions**;
- dedicated top-up verification **13 tests / 95 assertions**, zero failures/errors/skips;
- artifact `test-evidence-31265449681`, ID `9024026199`;
- uploader and independent digest `sha256:3cf3c1c52b3aac72e4cf7f10aa4567ee516a9edeb7c3528c6beb064c20f6b80b`;
- evidence candidate `evidence/0.5.0/payment-intent-wallet-top-up-settlement.md`;
- traceability candidate `docs/56-phase-0.5-payment-intent-wallet-top-up-traceability.md`.

This is **not accepted yet** because exact evidence-head CI/artifact/digest remains mandatory.

The retained implementation artifact contains exactly JUnit, full test log, Clover coverage and two dependency-service evidence files. Independent scanning found no known CI credential value, bearer/basic authorization value, private-key header or raw provider secret material.

## Financial safety decisions

1. IRR is integer at financial boundaries; no monetary float.
2. Finalized balanced ledger history is authoritative and append-only.
3. Active holds reserve value; persisted balance snapshots are derived evidence/cache only.
4. Financial execution uses fresh transaction/lock-based authoritative reads.
5. Exact replay returns the accepted effect; materially changed replay conflicts and cannot overwrite it.
6. Refund/correction compensates accepted history rather than mutating/deleting it.
7. Do not weaken MariaDB locking/isolation to make concurrency tests pass; deadlock/timeout must surface/reconcile safely.
8. Cumulative refund eligibility is checked while source is locked; no concurrent path may exceed refundable value.
9. Manual external refund must not also create wallet credit; evidence/reference is mandatory and safe audit excludes raw values.
10. Source refundability is capture-time immutable metadata; it cannot be retrofitted after ledger finalization.
11. Administrator correction cannot bypass refund/payment/order state machines.
12. Large correction dual approval binds the exact preview/requester and requires a distinct approver; approved replay binds the exact accepted approval ID.
13. Browser return/redirect/customer submission is not capture authority.
14. `WAL-001` external top-up targets only the intended active owned cash wallet and may post only after matching authoritative settled provider evidence.
15. Duplicate provider/internal payment events cannot produce a second settlement/top-up ledger effect; accepted replay returns the immutable accepted IDs.
16. Safe provider evidence is normalized, secret/raw-field filtered, append-only and bounded before it can become retained settlement evidence.
17. Pricing/Quote must remain deterministic integer IRR and immutable across later configuration changes; no quote may become payment authority by itself.

## Current human gates

### PasarGuard now

Actual live execution still requires protected repository Actions Secrets plus manual dispatch. The available connector cannot create/update those Secrets or initiate a fresh dispatch. Never copy secret values into chat/repository evidence.

### Marzban final release

Marzban `v0.8.4` deployment acceptance remains mandatory and intentionally scheduled for final release acceptance.

Until applicable provider gates pass: no live-provider compatibility claim, no production Target activation, no Phase `0.4.0` closure and no final `1.0.0` acceptance.

## Next risk-reduction work

First complete the exact evidence-head gate for the implementation-verified `PAY-002` / `PAY-003` / `WAL-001` top-up boundary. Once accepted, the highest-value independent task is deterministic Pricing / immutable Quote snapshot (`BUY-002`), specifically reducing `FIN-17` without pulling promotions, provider execution, Order or provisioning ownership forward.

At the next full risk-register regeneration, fold relevant overlay decisions into `docs/03-risk-register.md` without erasing historical definitions or falsely closing live/provider-dependent risks.
