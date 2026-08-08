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
| `FIN-01` | concurrent wallet operations can over-reserve/double-effect | transactions, sorted row locks, unique command/hold/transfer/refund keys, immutable ledger and accepted independent-process contention boundaries | repeat requirement-specific concurrency proof for each new correction/payment feature | Controlled for accepted wallet/refund foundations |
| `FIN-02` | persisted wallet snapshot is accidentally treated as financial authority | snapshots append-only/non-authoritative; wallet/refund paths use finalized ledger + active holds and locked authoritative reads | preserve invariant through correction/payment/order work | Controlled with regression requirement |
| `FIN-03` | transfer hold and transfer state diverge on expiry/cleanup | generic cleanup excludes `wallet_transfer`; confirm-expiry and explicit cancellation coordinate state | optional future scheduled transfer-expiry lifecycle must use transfer service | Controlled; operational follow-up open |
| `FIN-04` | duplicate transfer/retry creates a second primary effect | unique transfer key, canonical payload hash, one hold/ledger link, confirmation replay plus accepted concurrent duplicate prepare/confirm proof | preserve through any future transfer extensions | Controlled |
| `FIN-05` | refund/correction mutates historical ledger instead of compensating it | finalized ledger guards + accepted `WAL-004` immutable refund/refundability/allocation guards and compensating ledger reversal | `WAL-005` must use equivalent immutable compensating correction records/effects | Controlled for refund; next correction gate |
| `FIN-06` | fee/limit/bucket policy changes reinterpret an accepted transfer | immutable transfer policy snapshots and stable account references | future config changes must not rewrite historical transfers | Controlled |
| `FIN-07` | untouched expired pending transfer reserves funds indefinitely | expiry enforced on confirm; explicit cancel exists; generic cleanup intentionally excludes transfer holds | later coordinated transfer-expiry scheduler only if required | Known operational gap / Medium |
| `FIN-08` | later Payment Intent/provider work provisions before authoritative capture or duplicates capture/refund | ledger/hold/refund idempotency primitives exist but payment flows not implemented | Phase 0.5 payment boundaries + Phase 0.6 no-provision-before-capture evidence | Future Critical gate |
| `FIN-09` | concurrent partial refunds exceed captured/refundable source value | immutable capture-time refundable cap, locked source, cumulative/per-entry cap, unique refund key and accepted two-process refund contention proof | provider-native/payment refund paths need their own authoritative source/callback evidence | Controlled for provider-independent `WAL-004` |
| `FIN-10` | refund destination/evidence mismatch causes double reimbursement or untraceable external payout | explicit wallet/manual-external destination, exact original method/bucket allocations, required manual evidence, no wallet duplication, privileged override, safe audit, replay/conflict | provider-native refund integrations need equivalent provider evidence/uncertainty controls | Controlled for provider-independent `WAL-004` |
| `FIN-11` | administrator correction bypasses authorization/confirmation or silently changes historical balance | no accepted correction implementation yet | `WAL-005`: execution-time permission, exact preview/confirmation binding, compensating entries, immutable correction record and safe audit | Next Critical gate |
| `FIN-12` | large correction is self-approved/replayed or concurrent debits create negative availability | existing sensitive-action approval primitives and wallet locks are available but not wired to correction | `WAL-005`: policy-driven Owner/dual approval, distinct approver, approval consumption, available-balance lock, duplicate/concurrent proof | Next Critical gate |

## Latest accepted financial evidence

Wallet Refund / Reversal Foundation (`WAL-004`):

- implementation SHA `0237f94cae67ff2ca31047af55420fed0ffe578a`, CI `31242702422` / `#1155` — 360 tests / 2099 assertions;
- implementation artifact `test-evidence-31242702422`, ID `9017545543`, digest `sha256:be509996d098ee7f1354a9dc1fe949a224b2ead42c15ae145c2e12ad59890cdc`;
- evidence head `716ddb4f26b5672ed3d60aabd3f80bd7e7f50acc`, CI `31242888656` / `#1156` — 360 / 2099;
- evidence artifact `test-evidence-31242888656`, ID `9017593626`, digest `sha256:a1fb5c20a95e54bc63f14d40e02fbf19fc2f19c3b60d1b1e17a066a73f408052`;
- dedicated feature suite `6 tests / 52 assertions` and contention suite `2 / 17`, zero failures/errors/skips;
- evidence `evidence/0.5.0/wallet-refund-reversal-foundation.md`;
- traceability `docs/54-phase-0.5-wallet-refund-traceability.md`.

The accepted financial chain through ledger, holds, reconciliation, maintenance, `WAL-003`, dedicated wallet contention and `WAL-004` is reusable but does not close Phase `0.5.0`.

## Financial safety decisions

1. IRR is integer at financial boundaries; no monetary float.
2. Finalized balanced ledger history is authoritative and append-only.
3. Active holds reserve value; persisted balance snapshots are derived evidence/cache only.
4. Financial execution uses a fresh transaction/lock-based authoritative read, never a cached snapshot as authorization.
5. Exact replay returns the accepted effect; materially changed replay conflicts and cannot overwrite it.
6. Refund/correction work must compensate accepted history rather than mutate/delete it.
7. Do not weaken MariaDB locking/isolation to make concurrency tests pass; deadlock/timeout must be surfaced/reconciled safely.
8. Cumulative refund eligibility is checked while the source is locked; no concurrent path may exceed refundable value.
9. Manual external refund must not also create wallet credit; evidence/reference is mandatory and safe audit does not copy raw values.
10. Source refundability is capture-time immutable metadata; it cannot be retrofitted after ledger finalization.
11. Administrator corrections must not become a bypass for refund/payment/order state machines.
12. Large corrections require policy-driven Owner/dual approval; dual approval must bind to the exact preview/payload and use a distinct approver.

## Current human gates

### PasarGuard now

Actual live execution still requires protected repository Actions Secrets plus manual dispatch of the guarded workflow. The available connector cannot create/update those Secrets or initiate a fresh dispatch. Never copy secret values into chat/repository evidence.

### Marzban final release

Marzban `v0.8.4` deployment acceptance remains mandatory and intentionally scheduled for final release acceptance.

Until applicable provider gates pass: no live-provider compatibility claim, no production Target activation, no Phase `0.4.0` closure and no final `1.0.0` acceptance.

## Next risk-reduction work

While the protected PasarGuard gate remains unavailable, the highest-value independent task is the `WAL-005` Balance Correction / Approval Foundation in `docs/52-current-continuation-handoff.md`, specifically closing `FIN-11` and `FIN-12` without weakening immutable-ledger, available-balance, authorization or dual-control guarantees.

At the next full risk-register regeneration, fold relevant overlay decisions into `docs/03-risk-register.md` without erasing historical definitions or falsely closing live/provider-dependent risks.
