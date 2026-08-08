# Current Risk Overlay

**Last reviewed:** 2026-08-08  
**Purpose:** current delivery/control risk status while preserving definitions/history in `docs/03-risk-register.md`.  
**Rule:** this overlay governs current status where baseline risk status is stale. Read with `PROJECT_STATUS.md` and `docs/52-current-continuation-handoff.md`.

## Controlled project-control risks

| ID | Risk | Current control/evidence | Status |
|---|---|---|---|
| `RSK-024` | vulnerable/incompatible locked dependency | mandatory dependency audit/license/static/full suite | Controlled |
| `RSK-026` | implicit/inconsistent PHP runtime breaks CI | explicit self-hosted PHP 8.4/Composer/PCOV contract | Controlled for CI; production remains deployment acceptance |
| `RSK-031` | status drift misdirects continuation | `AGENTS.md`, `PROJECT_STATUS.md`, status JSON/schema, machine project-control verification, `docs/52-current-continuation-handoff.md` | Controlled at this checkpoint |
| `RSK-032` | single self-hosted runner throttles work | branch concurrency, timeouts and deterministic runner contract | Controlled operationally; throughput constraint remains |
| `RSK-033` | obsolete/destructive staging workflow runs | historical workflows inert; readiness read-only; PasarGuard live workflow manual/branch/confirmation gated | Controlled |
| `RSK-034` | temporary repair automation persists | repair workflow/generator removed and machine-gated | Closed |
| `RSK-036` | target architecture is mistaken for implemented capability | explicit verified/offline/harness/live/parallel status vocabulary | Controlled |

## Phase 0.4 provider risks

| ID | Risk | Current control | Remaining exit condition | Status |
|---|---|---|---|---|
| `RSK-038` | pinned source differs from deployed provider | exact pins Marzban `v0.8.4` / PasarGuard `v5.2.1`; exact version gates; Targets disabled | PasarGuard protected live run; Marzban final-release live acceptance/re-review | PasarGuard Blocked-live / Marzban Carried-release-gate |
| `RSK-039` | secondary integration reference overrides provider authority/security | pinned upstream source remains authoritative; no example may weaken TLS/redaction/retry rules | already controlled for current source implementation | Controlled |
| `RSK-040` | uncertain provider mutation retry duplicates remote effect | lookup-before-create, provider-specific create equivalence, uncertainty => discovery, idempotency conflict preservation | controlled timeout/5xx/429 live evidence and no duplicate primary effect | Blocked-live / Critical |
| `RSK-041` | provider credential excessive privilege or leaks | encrypted validated credentials; redaction; protected Actions Secret inputs only | least-privilege live auth acceptance and post-test credential hygiene | Blocked-live / Critical |
| `RSK-042` | provider environment gate blocks acceptance | all identified non-live work + guarded PasarGuard harness complete | PasarGuard Secrets/manual dispatch now; Marzban final-release environment later | Scheduled human gates |
| `RSK-043` | provider-observable read hash is mistaken for create equality | separate `canonicalHash` / provider-specific `createEquivalenceHash` | implementation #995 / evidence #997 accepted | Controlled |
| `RSK-044` | source/harness target discovery is mistaken for operational Target acceptance | Targets remain disabled; route verifier requires active verified chain | explicit controlled Target activation acceptance | Blocked-live |
| `RSK-045` | externally disclosed test credential remains usable too long | repository/evidence never retain it; live workflow requires protected Secrets | protected use then rotate/revoke as applicable | Open credential-hygiene risk |

## Parallel Phase 0.5 financial risks

The IDs below are overlay-local financial risk labels until the next full `docs/03-risk-register.md` regeneration.

| Overlay ID | Risk | Current control/evidence | Remaining exit condition | Status |
|---|---|---|---|---|
| `FIN-01` | concurrent wallet operations can over-reserve/double-effect despite correct single-process tests | transactions, row locks, unique command/hold/transfer keys, immutable ledger, hold-based available balance; WAL-003 exact replay | dedicated real-MariaDB multi-process contention verification in `docs/52-current-continuation-handoff.md` | Open verification / High |
| `FIN-02` | persisted wallet snapshot is accidentally treated as financial authority | snapshots are append-only/non-authoritative; hold/reconciliation/transfer paths calculate from finalized ledger + active holds under locks | keep this invariant through all future payment/order work; contention proof must include reconciliation race | Controlled with regression requirement |
| `FIN-03` | transfer hold and transfer state diverge on expiry/cleanup | generic hold cleanup excludes `wallet_transfer`; confirm-expiry and explicit cancellation coordinate transfer + hold state; DB terminal guards | optional future scheduled transfer-expiry lifecycle must use transfer service, never generic raw hold release | Controlled; operational follow-up open |
| `FIN-04` | duplicate transfer/retry creates a second primary effect | unique transfer key, canonical payload hash, one hold, one ledger link, confirmation-key replay, stored ledger-effect verification | multi-process duplicate prepare/confirm proof | Controlled in deterministic tests; concurrency proof open |
| `FIN-05` | refund/correction mutates historical ledger instead of compensating it | immutable finalized ledger and DB guards already accepted | `WAL-004` and `WAL-005` must use explicit compensating entries, authorization/reason and exact replay/conflict | Future Critical gate |
| `FIN-06` | fee/limit/bucket policy changes reinterpret an accepted transfer | transfer snapshots immutable amount/fee/total/policy/business date and stable recipient/account references | future admin/config changes must not rewrite historical transfers | Controlled |
| `FIN-07` | untouched expired pending transfer reserves funds indefinitely | expiry is enforced on confirm and explicit cancel exists; generic cleanup intentionally excludes transfer holds to prevent state split | later dedicated transfer-expiry scheduler only if product/ops requires it, with coordinated terminal transition | Known operational gap / Medium |
| `FIN-08` | later Payment Intent/provider work provisions before authoritative capture or duplicates capture/refund | ledger/hold/idempotency primitives exist but payment flows not yet implemented | Phase 0.5 payment boundaries + Phase 0.6 no-provision-before-capture evidence | Future Critical gate |

## Accepted financial evidence chain

- Financial Ledger Foundation: implementation/evidence CI `#1037/#1039`, 326 tests / 1777 assertions;
- Wallet Holds / Available Balance / Capture / Release: `#1046/#1048`, 331 / 1834;
- Wallet Reconciliation Snapshots / Expired-Hold Cleanup: `#1058/#1060`, 334 / 1886;
- Wallet Maintenance Operations: `#1066/#1068`, 338 / 1905;
- Stable Wallet Transfer (`WAL-003`): implementation `8360d1ac99485d1bea146bf21e22e8a336cd8e7a`, CI `#1084`; evidence `68f06fbd4ae9bb1bdba004968e57c84a13871e15`, CI `#1092`; 346 / 1995; evidence-head artifact ID `9015258508`; digest `sha256:f1bee9300338b0d326e9c0cf47ea978147a6e72688e598c0717f7ae180b29a78`.

The financial chain is reusable but does not close Phase `0.5.0`. Dedicated contention, `WAL-001`, `WAL-004`, `WAL-005`, pricing/Quote/promotions and payment providers remain open.

## Other active/repository risks

| ID | Risk | Current control | Status |
|---|---|---|---|
| `RSK-035` | `TrialReservationService` reviewability degrades | accepted behavior baseline; avoid broad behavior-changing refactor | Open reviewability risk, not blocker |
| `RSK-037` | coverage artifact exists while critical branch is unmapped | non-empty Clover + invariant/scenario traceability + exact regression counts | Controlled per increment; final closure interpretation later |

## Provider safety decisions

1. Marzban `v0.8.4` and PasarGuard `v5.2.1` remain exact pins; no silent `latest` upgrade.
2. Source proof, guarded-harness proof and deployment live proof are separate layers.
3. PasarGuard is the current live-test provider; Marzban live acceptance is carried to final release acceptance.
4. Phase `0.4.0` / Issue `#7` stays open; timing is not scope deletion.
5. Real provider gateways/Targets remain fail-closed until explicit live acceptance.
6. Authoritative lookup failure is never remote absence.
7. Adoption requires provider-specific accepted create equivalence; generic read-state hash is not equality.
8. Missing proof => Manual Review/no create; mismatch => conflict/no overwrite.
9. Any possible-effect timeout/5xx/malformed result is uncertain unless pre-effect failure is proved; discovery precedes retry.
10. Conflicting idempotency-key reuse cannot overwrite the original primary effect.
11. Raw provider responses, credentials, subscription URLs/config material never enter ordinary evidence/logs.

## Financial safety decisions

1. IRR is integer at financial boundaries; no monetary float.
2. Finalized balanced ledger history is authoritative and append-only.
3. Active holds reserve value; persisted balance snapshots are derived evidence/cache only.
4. Financial execution must use a fresh transaction/lock-based authoritative read, never a cached snapshot as authorization.
5. Exact replay returns the accepted effect; materially changed replay conflicts and cannot overwrite it.
6. Refund/correction work must compensate accepted history rather than mutate/delete it.
7. Do not weaken MariaDB locking/isolation to make contention tests pass; deadlock/timeout must be surfaced/reconciled safely.

## Current human gates

### PasarGuard now

Actual live execution requires protected repository Actions Secrets:

- `PASARGUARD_TEST_ORIGIN`;
- `PASARGUARD_TEST_API_KEY`.

Then manually dispatch `.github/workflows/provider-live-acceptance.yml` on `develop/v1.0.0-completion` with the exact confirmation value. The available connector cannot create/update those Secrets or initiate a fresh dispatch.

### Marzban final release

Marzban `v0.8.4` deployment acceptance remains mandatory and intentionally scheduled for final release acceptance.

Until applicable provider gates pass: no live-provider compatibility claim, no production Target activation, no Phase `0.4.0` closure and no final `1.0.0` acceptance.

## Next risk-reduction work

If the protected PasarGuard gate is still unavailable, the highest-value independent next task is the Dedicated Wallet Contention Verification specified in `docs/52-current-continuation-handoff.md`. It should close `FIN-01`/the remaining explicit `WAL-002` concurrency proof before new refund/correction/payment behavior is layered on top.

At the next full risk-register regeneration, fold relevant overlay decisions into `docs/03-risk-register.md` without erasing historical definitions or falsely closing live/concurrency-dependent risks.
