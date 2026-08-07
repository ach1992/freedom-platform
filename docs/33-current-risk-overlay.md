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
| `RSK-033` | obsolete/destructive staging workflow runs | historical workflows inert; readiness workflow is read-only; live PasarGuard workflow is manual, branch-gated and confirmation-gated | Controlled |
| `RSK-034` | temporary repair automation persists | repair workflow/generator removed and machine-gated | Closed |
| `RSK-036` | target architecture is mistaken for implemented capability | evidence/status vocabulary distinguishes foundation/offline/harness/live/verified | Controlled |

## Phase 0.4 provider risks

| ID | Risk | Current control | Remaining exit condition | Status |
|---|---|---|---|---|
| `RSK-038` | pinned source differs from deployed provider | exact source pins Marzban `v0.8.4` / PasarGuard `v5.2.1`; exact `/api/system` gates; Targets disabled | PasarGuard protected run reports accepted exact version/build; Marzban exact deployment is accepted at final release gate or re-reviewed if different | PasarGuard Blocked-live / Marzban Carried-release-gate |
| `RSK-039` | secondary integration reference overrides provider authority/security | upstream pinned provider source is authoritative; secondary examples cannot weaken Freedom Platform TLS/redaction/retry rules | already controlled for current source implementation | Controlled |
| `RSK-040` | uncertain provider mutation retry duplicates remote effect | authoritative lookup before create; provider-specific create-equivalence; uncertain result => discovery; offline classification; idempotency conflict preservation | controlled live timeout/5xx/429 evidence proves discovery before retry and no duplicate primary effect | Blocked-live / Critical |
| `RSK-041` | provider credential excessive privilege or leaks | encrypted validated credentials; redaction; guarded workflow reads PasarGuard API key only from Actions Secrets; no ordinary credential workflow input | protected secret configuration, least-privilege live auth acceptance and post-test credential hygiene | Blocked-live / Critical |
| `RSK-042` | provider environment gate blocks provider acceptance | all identified non-live provider work plus PasarGuard guarded harness completed; no temporary provider installed | PasarGuard Secrets + manual dispatch now; Marzban live environment at final release gate | Scheduled human gates |
| `RSK-043` | provider-observable read hash is mistaken for create equality | separate `canonicalHash` and `createEquivalenceHash`; resolver requires provider-specific preserved-field proof | implementation #995 / evidence #997 accepted | Controlled |
| `RSK-044` | source-contract/harness target discovery is mistaken for operational Target acceptance | target inventory remains disabled/declared; route verifier requires active + verified target/connection/protocol chain | explicit controlled live Target acceptance after applicable matrix rows pass | Blocked-live |
| `RSK-045` | a credential disclosed outside the protected runtime store remains usable longer than necessary | repository/workflows/evidence never retain the value; live workflow requires Actions Secrets rather than pasted/input credentials | configure protected secret, complete bounded test cycle, then rotate/revoke the externally disclosed credential | Open credential-hygiene risk |

## Other active Phase 0.4/repository risks

| ID | Risk | Current control | Status |
|---|---|---|---|
| `RSK-035` | `TrialReservationService` becomes difficult to review safely | accepted behavior baseline; avoid broad behavior-changing refactor; later bounded extraction only | Open reviewability risk, not current blocker |
| `RSK-037` | coverage artifact exists while critical branch is unmapped | non-empty Clover plus explicit invariant/scenario traceability; exact regression counts | Controlled per increment; final Phase 0.4 closure interpretation remains after live gates |

## Baseline risks relevant to provider gate

| Baseline ID | Current state |
|---|---|
| `RSK-001` duplicate retry/click/update causes duplicate effect | offline idempotency/discovery controls plus PasarGuard guarded sequence verified; real fault/replay proof pending |
| `RSK-002` remote success lost and local/remote diverge | authoritative discovery/adoption/conflict and provider-specific equivalence verified offline; live fault evidence pending |
| `RSK-005` duplicate paid provisioning | owning final proof remains Phase `0.6.0`; no paid provisioning is pulled into Phase 0.4 |
| `RSK-007` installed provider differs from docs/source | PasarGuard exact-version live gate pending; Marzban carried to final release acceptance |
| `RSK-010` credential/delivery leakage | controlled in code/tests/artifact scans; PasarGuard harness emits only sanitized facts; live redaction confirmation remains |
| `RSK-011` privileged admin/provider misuse | application authorization verified; provider least privilege remains live acceptance |
| `RSK-012` Redis loss affects correctness | MariaDB remains correctness barrier; provider tokens are not authority |
| `RSK-014` malicious update/deployment workflow | staging mutation surface controlled; full updater hardening remains later phase |
| `RSK-015` rollback/schema compatibility | open for later release phase; additive/corrective migration discipline remains |
| `RSK-027` production/staging credentials exposed | protected stores only; do not copy externally supplied values into repository/Issues/PR/evidence; rotate exposed test credentials after bounded use |

## Provider safety decisions

1. Marzban `v0.8.4` and PasarGuard `v5.2.1` remain exact pinned source contracts; no silent `latest` upgrade.
2. Source-level proof, guarded-harness proof and deployment-specific live proof are separate acceptance layers.
3. PasarGuard is the active live-test provider now; Marzban live acceptance is deferred to final project/release acceptance by owner decision on 2026-08-08.
4. The Marzban timing decision is not a requirement deletion; Issue `#7` and Phase `0.4.0` remain open.
5. Real provider gateways remain read-only at runtime until controlled live acceptance explicitly enables a capability in a later bounded change.
6. Real Targets remain disabled/unverified from source/offline/harness proof alone.
7. Authoritative lookup failure is never remote absence.
8. Create adoption requires provider-specific preserved-field `createEquivalenceHash`; generic read-state `canonicalHash` is not equality.
9. Missing equivalence proof means Manual Review/no create.
10. A timeout/5xx/malformed result after possible mutation is uncertain unless pre-effect failure is proved; discovery precedes any retry.
11. Conflicting idempotency-key reuse cannot overwrite the original primary effect.
12. No raw provider response body, token, password, API key, subscription URL or proxy/config secret enters ordinary repository logs/evidence.
13. PasarGuard live acceptance uses protected Actions Secrets and a manual exact-confirmation workflow; ordinary workflow inputs are not credential channels.
14. Do not install temporary provider panels merely to unblock evidence.
15. After the bounded test cycle, rotate/revoke any test credential that was disclosed outside its protected secret store.

## Accepted provider evidence chain

- Trial/Panel Fake foundation: implementation #931 / evidence #934;
- pinned read contracts: implementation #959 / evidence #961;
- pinned mutation contracts: implementation #978 / evidence #980;
- create-equivalence reconciliation: implementation `ab1e0d16d23460df4bf1ad9be4fcef0d16c37a43`, CI #995; evidence `a70b28cb984c23f1e219287e88d20014ee9f0310`, CI #997;
- PasarGuard guarded live harness: implementation `e18460357d306789cbbf85721f61a4e3a3bbb0e2`, CI #1013; evidence `71ca4b39df41bc9fcf725c30e9caba3285ee5412`, CI #1015;
- latest accepted suite: 317 tests / 1730 assertions;
- evidence-head artifact ID `9012490991`, independent digest `sha256:0b8cdd9772a5a4f54d719a794bc4b8d44e284345eb208e6460c9bc380df30b8f`.

## Current human gates

### PasarGuard now

Repository execution is ready. Actual live execution requires protected repository Actions Secrets named:

- `PASARGUARD_TEST_ORIGIN`;
- `PASARGUARD_TEST_API_KEY`.

Then `.github/workflows/provider-live-acceptance.yml` must be manually dispatched on `develop/v1.0.0-completion` with its exact confirmation value. The available GitHub connector cannot create/update Actions Secrets or initiate a new `workflow_dispatch`.

### Marzban final release

Marzban `v0.8.4` live acceptance remains mandatory but is intentionally scheduled for final project/release acceptance. No live Marzban compatibility or Target activation may be claimed before that gate runs.

Execution authority: `docs/41-phase-0.4-provider-live-acceptance-matrix.md`; current continuation handoff: `docs/44-phase-0.4-pasarguard-live-execution-handoff.md`.

Until the applicable gates pass:

- no unexecuted live-provider compatibility claim;
- no production Target activation;
- no Phase `0.4.0` closure;
- no final `1.0.0` release acceptance.

## Baseline register follow-up

At the next full risk-register regeneration, fold the current overlay decisions into `docs/03-risk-register.md` without erasing historical risk definitions or falsely closing live-dependent risks.
