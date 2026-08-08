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
| `RSK-042` | provider environment gate blocks acceptance | identified non-live work + guarded PasarGuard harness complete | protected PasarGuard Secrets/manual dispatch now; Marzban final-release environment later | Scheduled human gates |
| `RSK-043` | provider-observable read hash mistaken for create equality | separate canonical/read hash and provider create-equivalence proof | accepted offline evidence | Controlled |
| `RSK-044` | source/harness target discovery mistaken for operational Target acceptance | Targets remain disabled; route verifier requires active verified chain | explicit controlled Target activation acceptance | Blocked-live |
| `RSK-045` | externally disclosed test credential remains usable too long | repository/evidence never retain it; live workflow requires protected Secrets | protected use then rotate/revoke as applicable | Open credential-hygiene risk |

## Parallel Phase 0.5 financial risks

Overlay-local IDs remain temporary until the next full `docs/03-risk-register.md` regeneration.

| Overlay ID | Risk | Current control/evidence | Remaining exit condition | Status |
|---|---|---|---|---|
| `FIN-01` | concurrent wallet operations can over-reserve/double-effect | transactions, sorted row locks, unique command/hold/transfer keys, immutable ledger, hold-based available balance plus accepted six-test independent-process MariaDB contention boundary | repeat requirement-specific concurrency proof for each new refund/correction/payment feature | Controlled for accepted `WAL-002` foundation |
| `FIN-02` | persisted wallet snapshot is accidentally treated as financial authority | snapshots are append-only/non-authoritative; hold/reconciliation/transfer paths calculate from finalized ledger + active holds; reconciliation-vs-mutation contention is accepted | preserve invariant through refund/correction/payment/order work | Controlled with regression requirement |
| `FIN-03` | transfer hold and transfer state diverge on expiry/cleanup | generic cleanup excludes `wallet_transfer`; confirm-expiry and explicit cancellation coordinate state | optional future scheduled transfer-expiry lifecycle must use transfer service | Controlled; operational follow-up open |
| `FIN-04` | duplicate transfer/retry creates a second primary effect | unique transfer key, canonical payload hash, one hold/ledger link, confirmation replay plus accepted concurrent duplicate prepare/confirm proof | preserve through any future transfer extensions | Controlled |
| `FIN-05` | refund/correction mutates historical ledger instead of compensating it | finalized ledger/database guards are immutable | `WAL-004` and `WAL-005` must use explicit compensating entries, authorization/reason and exact replay/conflict | Next/Future Critical gate |
| `FIN-06` | fee/limit/bucket policy changes reinterpret an accepted transfer | immutable transfer policy snapshots and stable account references | future config changes must not rewrite historical transfers | Controlled |
| `FIN-07` | untouched expired pending transfer reserves funds indefinitely | expiry enforced on confirm; explicit cancel exists; generic cleanup intentionally excludes transfer holds | later coordinated transfer-expiry scheduler only if required | Known operational gap / Medium |
| `FIN-08` | later Payment Intent/provider work provisions before authoritative capture or duplicates capture/refund | ledger/hold/idempotency primitives exist but payment flows not implemented | Phase 0.5 payment boundaries + Phase 0.6 no-provision-before-capture evidence | Future Critical gate |
| `FIN-09` | concurrent partial refunds exceed captured/refundable source value | no refund implementation yet | `WAL-004`: source lock, cumulative-cap query, unique refund key and deterministic multi-process refund proof | Next High gate |
| `FIN-10` | refund destination/evidence mismatch causes double reimbursement or untraceable external payout | no refund implementation yet | `WAL-004`: explicit destination, wallet compensation vs manual-external evidence, override permission/reason/audit, exact replay/conflict | Next Critical gate |

## Latest accepted financial evidence

Dedicated Wallet Contention Verification:

- implementation verification SHA `903f326040c9acd0b31645fe8fae3a75f8a9fd27`, CI `31240159777` / `#1128`;
- evidence head `e7a0ae17d470beb40f4933e66c7b599e0837e120`, CI `31241459956` / `#1131`;
- both suites 352 tests / 2030 assertions;
- dedicated contention class 6 tests / 35 assertions with zero failures/errors/skips;
- evidence-head artifact `test-evidence-31241459956`, ID `9017163993`;
- independent digest `sha256:58544b56477c708b4e798b2ea83e673995e22b0ab53c19213914d1c2609af294`;
- evidence `evidence/0.5.0/wallet-contention-verification.md`;
- traceability `docs/53-phase-0.5-wallet-contention-traceability.md`.

The accepted financial chain through ledger, holds, reconciliation, maintenance, `WAL-003` transfer and dedicated contention is reusable but does not close Phase `0.5.0`.

## Financial safety decisions

1. IRR is integer at financial boundaries; no monetary float.
2. Finalized balanced ledger history is authoritative and append-only.
3. Active holds reserve value; persisted balance snapshots are derived evidence/cache only.
4. Financial execution uses a fresh transaction/lock-based authoritative read, never a cached snapshot as authorization.
5. Exact replay returns the accepted effect; materially changed replay conflicts and cannot overwrite it.
6. Refund/correction work must compensate accepted history rather than mutate/delete it.
7. Do not weaken MariaDB locking/isolation to make concurrency tests pass; deadlock/timeout must be surfaced/reconciled safely.
8. Cumulative refund eligibility must be checked while the source is locked; no concurrent path may exceed refundable value.
9. A manual external refund must not also create a wallet credit unless an explicit separately-authorized compensating workflow requires it.

## Current human gates

### PasarGuard now

Actual live execution still requires protected repository Actions Secrets plus manual dispatch of the guarded workflow. The available connector cannot create/update those Secrets or initiate a fresh dispatch. Never copy secret values into chat/repository evidence.

### Marzban final release

Marzban `v0.8.4` deployment acceptance remains mandatory and intentionally scheduled for final release acceptance.

Until applicable provider gates pass: no live-provider compatibility claim, no production Target activation, no Phase `0.4.0` closure and no final `1.0.0` acceptance.

## Next risk-reduction work

While the protected PasarGuard gate remains unavailable, the highest-value independent task is the `WAL-004` Refund / Reversal Foundation in `docs/52-current-continuation-handoff.md`, specifically closing `FIN-09` and `FIN-10` without weakening immutable-ledger or authorization guarantees.

At the next full risk-register regeneration, fold relevant overlay decisions into `docs/03-risk-register.md` without erasing historical definitions or falsely closing live/provider-dependent risks.
