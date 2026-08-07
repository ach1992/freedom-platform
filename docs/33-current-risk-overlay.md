# Current Risk Overlay

**Last reviewed:** 2026-08-08  
**Purpose:** current delivery/control risk status while preserving definitions/history in `docs/03-risk-register.md`.  
**Rule:** this overlay governs current status where baseline risk status is stale.

## Controlled project-control risks

| ID | Risk | Current control/evidence | Status |
|---|---|---|---|
| `RSK-024` | vulnerable/incompatible locked dependency | patched lockfile; mandatory audit/license/static/full suite | Controlled |
| `RSK-026` | implicit/inconsistent PHP runtime breaks CI | explicit self-hosted PHP 8.4/Composer/PCOV contract and checks | Controlled for CI; production runtime remains deployment acceptance |
| `RSK-031` | status drift misdirects continuation | `AGENTS.md`, `PROJECT_STATUS.md`, status JSON/schema, continuation runbook, machine project-control verification | Controlled; advanced after each accepted boundary |
| `RSK-032` | single self-hosted runner throttles work | branch concurrency, timeouts, deterministic runner contract | Controlled operationally; throughput constraint remains |
| `RSK-033` | obsolete/destructive staging workflow runs | historical workflows inert; guarded readiness is read-only | Controlled |
| `RSK-034` | temporary repair automation persists | repair workflow/generator removed and machine-gated | Closed |
| `RSK-036` | target architecture is mistaken for implemented capability | evidence/status vocabulary distinguishes foundation/offline/live/verified | Controlled |

## Phase 0.4 provider risks

| ID | Risk | Current control | Remaining exit condition | Status |
|---|---|---|---|---|
| `RSK-038` | pinned source differs from deployed Marzban/PasarGuard | exact source pins `v0.8.4` / `v5.2.1`; exact `/api/system` checks; Targets disabled | controlled live panels report accepted exact versions/builds, or exact supplied build is re-reviewed | Blocked-live |
| `RSK-039` | secondary integration reference overrides provider authority or security | upstream pinned provider source is authoritative; secondary examples do not override Freedom Platform TLS/redaction/retry rules | already controlled for current source implementation | Controlled |
| `RSK-040` | uncertain provider mutation retry duplicates remote effect | authoritative lookup before create; provider-specific create-equivalence; uncertain result => discovery; offline fault classification; idempotency conflict preservation | controlled live timeout/5xx fault evidence proves no duplicate effect | Blocked-live / Critical |
| `RSK-041` | provider credential excessive privilege or leaks | encrypted validated credentials; redaction; PasarGuard least-privilege API key preferred; no secret in evidence | protected live credential/permission acceptance on owner panel | Blocked-live / Critical |
| `RSK-042` | lack of installed provider panel blocks unrelated source work | all identified non-live provider work completed; no temporary panels installed; exact live matrix defined | owner supplies controlled panels/credentials for live gate | Human blocker |
| `RSK-043` | provider-observable read hash is mistaken for create equality | separate `canonicalHash` and `createEquivalenceHash`; resolver requires provider-specific preserved-field proof | accepted implementation #995 and evidence #997 | Controlled |
| `RSK-044` | source-contract target discovery is mistaken for operational Target acceptance | target inventory remains disabled/declared; route verifier requires active + verified target/connection/protocol chain | explicit controlled live Target acceptance after matrix passes | Blocked-live |

## Other active Phase 0.4/repository risks

| ID | Risk | Current control | Status |
|---|---|---|---|
| `RSK-035` | `TrialReservationService` becomes difficult to review safely | accepted behavior baseline; avoid broad behavior-changing refactor; later bounded extraction only | Open reviewability risk, not current blocker |
| `RSK-037` | coverage artifact exists while critical branch is unmapped | non-empty Clover plus explicit invariant/scenario traceability; exact regression counts | Controlled per increment; final Phase 0.4 closure interpretation still required after live gate |

## Baseline risks relevant to provider gate

| Baseline ID | Current state |
|---|---|
| `RSK-001` duplicate retry/click/update causes duplicate effect | offline provider idempotency/discovery controls verified; live provider effect proof blocked on controlled panel |
| `RSK-002` remote success lost and local/remote diverge | authoritative discovery/adoption/conflict and provider-specific equivalence verified offline; live fault evidence pending |
| `RSK-005` duplicate paid provisioning | owning final proof remains Phase `0.6.0`; no paid provisioning is pulled into Phase 0.4 |
| `RSK-007` installed provider differs from docs/source | blocked-live through exact-version acceptance matrix |
| `RSK-010` credential/delivery leakage | controlled in offline code/tests/artifact scans; live redaction confirmation remains |
| `RSK-011` privileged admin/provider misuse | application authorization verified; provider least privilege remains live acceptance |
| `RSK-012` Redis loss affects correctness | MariaDB remains correctness barrier; provider tokens are not authority |
| `RSK-014` malicious update/deployment workflow | staging mutation surface controlled; full updater hardening remains later phase |
| `RSK-015` rollback/schema compatibility | open for later release phase; additive/corrective migration discipline remains |
| `RSK-027` production/staging credentials exposed | protected stores only; never retrieve values into repository/chat/evidence |

## Provider safety decisions

1. Marzban `v0.8.4` and PasarGuard `v5.2.1` remain exact pinned source contracts; no silent `latest` upgrade.
2. Source-level proof and deployment-specific proof are separate acceptance layers.
3. Real provider gateways remain read-only at runtime until controlled live acceptance explicitly enables a capability in a later bounded change.
4. Real Targets remain disabled/unverified from source/offline proof alone.
5. Authoritative lookup failure is never remote absence.
6. Create adoption requires provider-specific preserved-field `createEquivalenceHash`; generic read-state `canonicalHash` is not equality.
7. Missing equivalence proof means Manual Review/no create.
8. A timeout/5xx/malformed result after possible mutation is uncertain unless pre-effect failure is proved; discovery precedes any retry.
9. Conflicting idempotency-key reuse cannot overwrite the original primary effect.
10. No raw provider response body, token, password, API key, subscription URL or proxy/config secret enters ordinary logs/evidence.
11. PasarGuard live acceptance should prefer a dedicated least-privilege API key where supported.
12. Do not install temporary provider panels merely to unblock evidence.

## Accepted provider evidence chain

- Trial/Panel Fake foundation: implementation #931 / evidence #934;
- pinned read contracts: implementation #959 / evidence #961;
- pinned mutation contracts: implementation #978 / evidence #980;
- create-equivalence reconciliation: implementation `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43`, CI #995; evidence `a70b28cb984c23f1e219287e88d20014ee9f0310`, CI #997;
- latest accepted suite: 315 tests / 1678 assertions;
- evidence-head artifact ID `9011359876`, independent digest `sha256:140e6a45fe2ef9523dee0147f6131a1e2d1bcaf63548b57eea7b83d3f0d9b827`.

## Current human gate

The remaining Phase 0.4 provider dependency is owner provision of controlled Marzban `v0.8.4` and PasarGuard `v5.2.1` test environments (or explicit authorization to re-review a different exact build), with protected credentials and permission to create/delete disposable uniquely prefixed test users.

The execution checklist is `docs/41-phase-0.4-provider-live-acceptance-matrix.md`; continuation handoff is `docs/42-phase-0.4-controlled-live-provider-handoff.md`.

Until that dependency is supplied:

- no live provider compatibility claim;
- no real mutation acceptance;
- no production Target activation;
- no Phase `0.4.0` closure.

## Baseline register follow-up

At the next full risk-register regeneration, fold the current overlay decisions into `docs/03-risk-register.md` without erasing historical risk definitions or falsely closing live-dependent risks.