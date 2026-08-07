# Current Risk Overlay

**Last reviewed:** 2026-08-07  
**Purpose:** record current delivery/control risks while preserving the original risk catalogue in `docs/03-risk-register.md`.  
**Rule:** requirement and risk definitions remain in the baseline register; this overlay governs current status when the baseline status is stale.

## Current release blockers

| ID | Risk | Impact | Current control | Status / exit condition |
|---|---|---:|---|---|
| `RSK-024` | vulnerable or incompatible locked dependency | High | bounded `league/commonmark` update to a patched version; full audit/license/static/test verification | Open — repair validated, exact branch application and green CI required |
| `RSK-026` | inconsistent or implicit PHP runtime breaks CI or production assumptions | High | explicit host CLI binary/INI, extension/version checks, JIT off, PCOV coverage mode; separate LSPHP remains a deployment gate | Open — exact-head CI must prove CLI contract; LSPHP is not inferred |
| `RSK-031` | project status drift sends a new engineer into the wrong phase or causes duplicate work | High | `AGENTS.md`, single status Markdown/JSON, continuation runbook, current traceability overlay, machine check | Open — all global docs/PR/Issue must be reconciled and CI check pass |
| `RSK-032` | a stuck single self-hosted runner job blocks all newer CI and repair work | High | explicit timeouts, branch concurrency, runner contract, no repeated dispatch/commit storm | Open — current queue must clear; add safe runner-health operation |
| `RSK-033` | obsolete/destructive staging workflow is run accidentally or against a stale SHA | Critical | inventory and classify every `staging-*` workflow; remove obsolete workflows; guard retained mutations | Open — no mutation workflow is approved merely because it exists |
| `RSK-034` | temporary write-capable repair workflow persists and becomes a supply-chain path | Critical | same-repo/branch condition, exact file allowlist, non-force push, immediate removal after repair | Open — workflow and generator must be deleted before stabilization gate |
| `RSK-035` | active Trial service becomes too complex to review safely | High | freeze feature extension; green baseline; decompose by lifecycle/replay/persistence responsibilities without behavior change | Open — bounded extraction after CI baseline, before significant extension |
| `RSK-036` | target architecture is mistaken for implemented capability | High | repository map, implemented/foundation/target labels, evidence-required claims | Open — architecture/runbooks/status docs must be reconciled |
| `RSK-037` | coverage artifact is present but critical branches remain unmapped | High | non-empty Clover plus requirement/scenario mapping; later measured changed-line/risk interpretation | Open — define interpretation before Phase 0.4 closure |

## Existing high/critical risks relevant to the active increment

| Baseline ID | Current relevance | Status |
|---|---|---|
| `RSK-001` | duplicate retry/click/update causing duplicate remote or future financial effect | Controlled in verified foundations; active Trial/Panel extension unverified |
| `RSK-002` | lost remote success and local/remote divergence | Active — Fake/coordinator discovery behavior implemented but unverified |
| `RSK-005` | duplicate paid provisioning | Design/foundation only; final proof belongs to Phase `0.6.0` |
| `RSK-007` | installed Marzban/PasarGuard differs from documentation | Open — deferred input; shells must remain fail-closed |
| `RSK-010` | credential/delivery artifact leakage | Active — redaction tests exist; exact-head verification required |
| `RSK-011` | privileged admin misuse | Controlled in Phase `0.3.0`; every Trial/Panel mutation must re-authorize |
| `RSK-012` | Redis loss affects correctness | Controlled by MariaDB final barriers; active Trial capacity must preserve this |
| `RSK-014` | malicious update/deployment/backup or operational workflow | Open — staging workflow cleanup is immediate control-plane work |
| `RSK-015` | code rollback incompatible with schema | Open for later releases; current migrations require forward/corrective discipline |
| `RSK-027` | production/staging credentials exposed | Controlled — secrets remain in protected stores and are never retrieved into evidence |

## Risk decisions for stabilization

1. Do not rewrite or squash the long-running branch to improve readability; exact accepted SHAs and evidence would be damaged.
2. Do not grant unrestricted passwordless `sudo` to the Actions runner.
3. Do not disable or relax Pint, PHPStan, dependency audit, license, coverage, secret, migration, or architecture gates.
4. Do not run staging mutation workflows during repository cleanup.
5. Do not refactor the active Trial service broadly until a deterministic green baseline exists.
6. Do not claim real panel compatibility from Fake adapter or unavailable shells.
7. Do not treat cleanup-only artifacts from failed jobs as test evidence.

## Stabilization risk acceptance rule

No current High/Critical risk is accepted merely to resume feature velocity. The stabilization gate closes only when:

- patched lockfile and formatting repair are committed;
- temporary repair automation is absent;
- mandatory exact-head CI is green;
- status/control documents agree and their machine check passes;
- unsafe staging workflow surface is reduced and documented;
- active Trial/Panel scope remains explicitly unverified until its own exact-SHA lifecycle completes.

## Baseline register follow-up

At the next accepted phase gate, fold this overlay into `docs/03-risk-register.md`:

- update review date;
- add `RSK-031` through `RSK-037`;
- attach exact control/evidence paths;
- close only risks with executable evidence;
- preserve deferred owner/provider decisions.
