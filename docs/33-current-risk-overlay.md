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
| `FIN-01` | concurrent wallet/payment operations can over-reserve/double-effect | transactions, sorted row locks, unique command/hold/transfer/refund/correction keys, immutable ledger and accepted independent-process contention boundaries | repeat feature-specific proof for Payment Intent/capture/provider work | Controlled for accepted wallet/refund/correction foundations |
| `FIN-02` | persisted wallet snapshot is treated as financial authority | snapshots append-only/non-authoritative; wallet/refund/correction use finalized ledger + active holds and locked reads | preserve through Payment Intent/payment/order work | Controlled with regression requirement |
| `FIN-03` | transfer hold and transfer state diverge on expiry/cleanup | generic cleanup excludes `wallet_transfer`; confirm-expiry and explicit cancellation coordinate state | optional future scheduled transfer-expiry lifecycle must use transfer service | Controlled; operational follow-up open |
| `FIN-04` | duplicate transfer/retry creates a second primary effect | unique transfer key, canonical payload hash, one hold/ledger link, replay plus concurrent duplicate proof | preserve through transfer extensions | Controlled |
| `FIN-05` | refund/correction mutates historical ledger instead of compensating it | finalized-ledger guards + accepted `WAL-004` refund history + accepted `WAL-005` immutable correction preview/record and compensating effect | future provider/payment/order financial paths must preserve immutable compensation | Controlled for `WAL-004` + `WAL-005` |
| `FIN-06` | fee/limit/bucket policy changes reinterpret accepted transfer | immutable transfer policy snapshots and stable account references | future config changes must not rewrite history | Controlled |
| `FIN-07` | untouched expired pending transfer reserves funds indefinitely | expiry enforced on confirm; explicit cancel exists; generic cleanup excludes transfer holds | later coordinated transfer-expiry scheduler only if required | Known operational gap / Medium |
| `FIN-08` | Payment Intent/provider flow provisions before authoritative capture or duplicates capture/top-up/refund | ledger/hold/refund/correction idempotency primitives exist; browser/provider authority rules are specified | next `PAY-002`/`PAY-003`/`WAL-001` Payment Intent capture/top-up implementation + Phase 0.6 no-provision-before-capture evidence | Next Critical gate |
| `FIN-09` | concurrent partial refunds exceed captured/refundable value | immutable capture-time cap, locked source, cumulative/per-entry cap, unique refund key and two-process contention proof | provider-native refund paths need authoritative provider source/callback proof | Controlled for provider-independent `WAL-004` |
| `FIN-10` | refund destination/evidence mismatch causes double reimbursement/untraceable external payout | explicit wallet/manual-external destination, exact original allocations, evidence requirement, no wallet duplication, privileged override, safe audit | provider-native refunds need equivalent evidence/uncertainty controls | Controlled for provider-independent `WAL-004` |
| `FIN-11` | administrator correction bypasses authorization/confirmation or silently changes historical balance | accepted `WAL-005`: immutable preview/confirmation, execution-time authorization, current policy revalidation, compensating ledger, immutable DB guards, safe audit | future correction UI/API must preserve service boundary; payment/order controls remain separate | Controlled for `WAL-005` |
| `FIN-12` | large correction is self-approved/replayed or concurrent debits create negative availability | accepted `WAL-005`: policy-driven independent approval, distinct approver, atomic consumption, exact committed-approval replay binding, fresh available-balance lock, multi-process debit/duplicate/approval proof | future high-risk payment/provider actions need their own dual-control/idempotency evidence | Controlled for `WAL-005`; future payment gate remains |
| `FIN-13` | browser return/customer claim is mistaken for authoritative payment capture | master contract explicitly forbids browser-return authority; existing provider DTOs distinguish evidence/authority | implement enforced state transition and tests in next Payment Intent boundary | Next Critical gate |
| `FIN-14` | duplicate provider events/transactions credit cash wallet twice | existing ledger idempotency available but Payment Intent/provider consumption not implemented | next `PAY-003`/`WAL-001`: unique event/transaction/settlement keys + locked capture + duplicate contention proof | Next Critical gate |
| `FIN-15` | external top-up credits promotional/wrong wallet or credits before capture | stable wallet account model exists; `WAL-001` requires cash bucket and capture-first balanced posting | next Payment Intent/top-up boundary must bind exact active owned cash account and post only after authoritative capture | Next Critical gate |

## Latest accepted financial evidence — `WAL-005`

Wallet Correction / Approval Foundation:

- implementation `fd3d579d9f38004310d7ea638e351813d2f46ef5`, CI `31260299072` / `#1186` — 371 tests / 2197 assertions;
- implementation artifact `test-evidence-31260299072`, ID `9022602206`, digest `sha256:476e86bdf2bb30732460a4ca1ef9dd0640a1e06b52dbc0f4a15e06f70fa07b62`;
- evidence `ef5504081a42687eb712e9cd47306cd9dcc9a864`, CI `31260549403` / `#1188` — 371 / 2197;
- evidence artifact `test-evidence-31260549403`, ID `9022665227`, uploader and independently verified digest `sha256:ce6073f08fc56df8f4b37cba3dcf6d8bbc5a9fd6b0240becd1e1ffc9d3ecd971`;
- dedicated correction verification `11 tests / 98 assertions`, zero failures/errors/skips;
- evidence `evidence/0.5.0/wallet-correction-approval-foundation.md`;
- traceability `docs/55-phase-0.5-wallet-correction-traceability.md`.

The accepted financial chain through ledger, holds, reconciliation, maintenance, `WAL-003`, wallet contention, `WAL-004` and `WAL-005` is reusable but does not close Phase `0.5.0`.

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
14. `WAL-001` external top-up targets only the intended active owned cash wallet and may post only after authoritative captured provider evidence.
15. Duplicate provider/internal payment events must return the accepted settlement without a second wallet credit or later provisioning effect.

## Current human gates

### PasarGuard now

Actual live execution still requires protected repository Actions Secrets plus manual dispatch. The available connector cannot create/update those Secrets or initiate a fresh dispatch. Never copy secret values into chat/repository evidence.

### Marzban final release

Marzban `v0.8.4` deployment acceptance remains mandatory and intentionally scheduled for final release acceptance.

Until applicable provider gates pass: no live-provider compatibility claim, no production Target activation, no Phase `0.4.0` closure and no final `1.0.0` acceptance.

## Next risk-reduction work

While the protected PasarGuard gate remains unavailable, the highest-value independent task is Payment Intent + `WAL-001` External Cash-Wallet Top-up Settlement in `docs/52-current-continuation-handoff.md`, specifically closing the first bounded portions of `FIN-08`, `FIN-13`, `FIN-14` and `FIN-15` without weakening provider authority, ledger idempotency, wallet identity or Phase `0.6.0` ownership.

At the next full risk-register regeneration, fold relevant overlay decisions into `docs/03-risk-register.md` without erasing historical definitions or falsely closing live/provider-dependent risks.
