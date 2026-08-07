# Current Risk Overlay

**Last reviewed:** 2026-08-07  
**Purpose:** record current delivery/control risks while preserving the original risk catalogue in `docs/03-risk-register.md`.  
**Rule:** requirement and risk definitions remain in the baseline register; this overlay governs current status when the baseline status is stale.

## Stabilization risks now controlled

| ID | Risk | Control/evidence | Current status |
|---|---|---|---|
| `RSK-024` | vulnerable or incompatible locked dependency | `league/commonmark` patched to `2.9.0`; audit/license/static/full suite green on accepted increment-6 evidence boundary | Controlled |
| `RSK-026` | implicit/inconsistent PHP runtime breaks CI | explicit PHP binary/CLI INI, JIT off, PCOV coverage mode, Composer/extension checks | Controlled for CI; production LSPHP remains a separate deployment gate |
| `RSK-031` | project status drift misdirects a new engineer | `AGENTS.md`, `PROJECT_STATUS.md`, status JSON/schema, continuation runbook, machine verification | Controlled; boundary must be advanced after every accepted increment |
| `RSK-032` | one self-hosted runner can block newer work | timeouts, branch concurrency, explicit runner contract and no dispatch storm | Controlled operationally; single-runner capacity remains a throughput constraint |
| `RSK-033` | obsolete/destructive staging workflow is accidentally run | legacy workflows replaced by inert historical stubs; only guarded read-only readiness remains | Controlled |
| `RSK-034` | temporary write-capable repair automation persists | temporary workflow/generator removed and machine-gated against reappearance | Closed |
| `RSK-036` | target architecture mistaken for implemented capability | architecture/status/repository-map labels distinguish target/foundation/candidate/verified | Controlled; keep evidence-based claims |

## Active high/critical risks

| ID | Risk | Impact | Current control | Exit condition |
|---|---|---:|---|---|
| `RSK-035` | `TrialReservationService` becomes too complex to review safely | High | accepted green behavior baseline; no broad behavior-changing refactor | bounded extraction preserving schema, locks, transactions, replay and tests before major extension |
| `RSK-037` | coverage artifact exists while a critical branch is unmapped | High | non-empty Clover plus explicit invariant/scenario traceability | Phase 0.4 closure records critical-path/changed-line interpretation; raw percentage alone is not acceptance |
| `RSK-038` | source-level provider contract differs from the later deployed Marzban/PasarGuard instance | High | pin upstream tags now, validate reported `/api/system` version later, keep targets disabled | dedicated final test panels match reviewed version/build or adapters are re-reviewed before activation |
| `RSK-039` | external integration reference (Mirza Bot) is mistaken for provider authority or copied with weaker security practices | High | upstream provider tags take precedence; Mirza Bot is secondary reference only | concrete gateway code uses Freedom Platform TLS/SSRF/redaction/idempotency contracts and upstream tests/models |
| `RSK-040` | provider mutation returns an uncertain result and a retry duplicates remote effect | Critical | authoritative username lookup before create; source-contract failure taxonomy; discovery before every retry | offline fault tests plus final controlled live timeout/fault-harness evidence |
| `RSK-041` | provider credential has excessive privilege or leaks into logs/evidence | Critical | encrypted `PanelCredentials`, redaction, no secrets in chat/artifacts; PasarGuard least-privilege API key preferred | offline redaction tests and final protected credential/permission acceptance |
| `RSK-042` | live provider testing blocks unrelated development because no panel is installed | High delivery risk | owner explicitly deferred live tests; source-contract work continues; real targets fail closed | live gate executes near final integration when owner supplies dedicated test panels |

## Existing baseline risks relevant to the active provider increment

| Baseline ID | Current relevance | Status |
|---|---|---|
| `RSK-001` | duplicate retry/click/update causes duplicate remote/future financial effect | Remote-effect foundation verified; concrete gateway must preserve it |
| `RSK-002` | remote success is lost and local/remote state diverges | Active — discovery/adoption foundation verified; HTTP gateway uncertainty classification remains current work |
| `RSK-005` | duplicate paid provisioning | Design/foundation only; final proof belongs to Phase `0.6.0` |
| `RSK-007` | installed Marzban/PasarGuard differs from documentation/source | Active but intentionally deferred-live; pinned source tags are `v0.8.4` and `v5.2.1` |
| `RSK-010` | credential or delivery-artifact leakage | Controlled in accepted foundation; concrete HTTP gateway must keep raw bodies/tokens/subscription material out of normal evidence |
| `RSK-011` | privileged admin/provider misuse | application authorization verified; provider credential must also be least privilege where supported |
| `RSK-012` | Redis loss affects correctness | MariaDB remains final correctness barrier; provider token cache cannot become an authority |
| `RSK-014` | malicious update/deployment/operational workflow | staging mutation surface controlled; full update/backup hardening remains later phase |
| `RSK-015` | code rollback incompatible with schema | Open for later release work; use additive/corrective migration discipline |
| `RSK-027` | production/staging credentials exposed | Controlled policy — protected stores only; never retrieve values into repository/chat/evidence |

## Provider-specific risk decisions

1. Marzban `v0.8.4` and PasarGuard `v5.2.1` are the current pinned source contracts; no silent `latest` upgrade.
2. Mirza Bot is used to cross-check practical endpoint sequencing only; its persistence, token caching, logging, TLS, retry and error handling are not copied.
3. Absence of installed test panels is **not** a Phase 0.4 development blocker.
4. Concrete adapters may be implemented and accepted offline through deterministic HTTP contract tests while real targets remain disabled.
5. Final live acceptance is mandatory before production target activation and will use owner-provided dedicated test panels/credentials.
6. PasarGuard API-key authentication should prefer a dedicated least-privilege key when final infrastructure permits it; username/password remains a supported contract path but not an excuse for broad privilege.
7. A 5xx/timeout/malformed response after a possible mutation is uncertain unless the client can prove the request was not sent. Do not classify it retryable merely to simplify orchestration.
8. No raw provider response body, token, password, API key, subscription URL or configuration link enters `safeMessage`, logs, Issue/PR text or ordinary evidence.

## Current release/development gate

The next provider source-contract increment is accepted only when:

- concrete pinned gateway code is present without enabling live targets by default;
- authentication/version/target/lookup/create/mutation/error/uncertainty/redaction contract tests pass;
- exact implementation SHA mandatory CI passes;
- retained artifact and independent digest are recorded;
- bounded evidence/traceability is committed;
- exact evidence-head mandatory CI passes.

Live provider testing is a separate deferred acceptance gate, not a substitute for the offline exact-SHA lifecycle and not a reason to halt unrelated phases.

## Baseline register follow-up

At the next full risk-register reconciliation:

- update review date;
- fold `RSK-031` through `RSK-042` into `docs/03-risk-register.md`;
- attach exact control/evidence paths;
- close only risks with executable evidence;
- preserve the owner decision that live Marzban/PasarGuard testing occurs near final integration, before production activation.
