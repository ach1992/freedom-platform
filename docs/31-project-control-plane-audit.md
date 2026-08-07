# Project Control-Plane and Maintainability Audit

**Audit date:** 2026-08-07  
**Scope:** repository state through active Phase `0.4.0`, including specification, PR/Issue governance, source/module structure, migrations, tests, CI, evidence, staging automation, deployment documents, and continuation quality.  
**Purpose:** restore a reliable development control plane before accepting or extending the active Trial/Panel increment.

## 1. Executive conclusion

The project is not fundamentally off-course in its verified business and integrity foundations. The accepted Phase `0.2.0`, `0.3.0`, and earlier Phase `0.4.0` increments show unusually strong exact-SHA evidence, database constraints, replay/conflict controls, redaction, and fail-closed behavior.

The project did become harder to control because the **control plane drifted behind implementation**:

- current status is distributed across old README text, stale ledgers, phase documents, PR text, Issue comments, handoffs, and workflow logs;
- the long-running PR accumulated hundreds of commits and hundreds of files without one mandatory continuation entry point;
- self-hosted CI assumptions were implicit and one privileged setup action blocked execution;
- dependency and formatting failures accumulated inside an unverified increment;
- one-time/destructive staging workflows remain visible beside safe diagnostic workflows;
- architecture documents describe a larger target system than the modules currently implemented, while static enforcement covers only part of the declared boundary.

The correct response is not a rewrite or history cleanup. Rewriting history is prohibited and would destroy accepted evidence. The response is a focused stabilization layer: deterministic CI, one live status source, machine validation, explicit historical/superseded labels, stronger workflow safety, and bounded decomposition of current hotspots.

## 2. Audit method and evidence base

The audit used:

- the complete Master Execution Prompt and stable requirement catalogue;
- live PR `#6`, Issue `#7`, current branch/head, recent commits, and changed-file inventory;
- 484 changed paths across modules, migrations, tests, deployment, evidence, and documentation;
- accepted evidence/traceability through Custom Plan increment 5;
- the active Trial/Panel handoff and implementation files;
- mandatory CI workflow, self-hosted runner logs, artifacts, and failure reproduction;
- selected high-risk and high-complexity services, adapter contracts, migrations, tests, and staging workflows;
- documentation freshness and cross-document consistency.

This is a repository-level architecture, governance, quality, and operational audit. It does not claim a manual line-by-line security review of every source line, a penetration test, a performance certification, or real-provider compatibility.

## 3. What is working well

### 3.1 Requirement and evidence discipline

Accepted increments generally retain:

- implementation and evidence-head SHAs;
- exact CI run IDs;
- test/assertion counts;
- artifact names/IDs and independent SHA-256 digests;
- bounded evidence that distinguishes implemented controls from unsupported provider claims.

This practice should be preserved. It is the main reason the long-running branch can still be audited safely.

### 3.2 Integrity-first design

Current foundations consistently prefer:

- MariaDB transactions, row locks, unique constraints, checks, and append-only history;
- exact replay versus conflicting replay distinction;
- execution-time authorization;
- remote discovery/adoption after uncertainty;
- encrypted credentials and secret-free history/audit;
- explicit state/value types;
- fail-closed provider shells.

These align with the Master Prompt's financial, authorization, and remote-effect invariants.

### 3.3 Phase boundaries

The accepted Phase `0.4.0` increments generally avoided entering pricing resolution, Orders, payment capture, or provisioning orchestration early. The active Trial/Panel increment also states what it does not prove. This separation must remain explicit during cleanup.

### 3.4 Automated gates

The repository has meaningful gates for:

- Pint;
- PHPStan/Larastan;
- Composer validation/audit;
- license inventory;
- forbidden patterns;
- partial architecture rules;
- MariaDB/Redis integration tests;
- secret scanning;
- retained artifacts.

The issue was not absence of gates; it was an implicit runner/toolchain contract that prevented those gates from executing reliably.

## 4. Findings

### F-001 — No single mandatory continuation entry point

**Severity:** High  
**Status:** Remediated in stabilization branch; final CI pending.

A new chat or engineer previously had to infer state from README, PR text, Issue comments, ledgers, traceability, and the latest handoff. Several of those sources disagreed.

**Correction:**

- add `AGENTS.md` as mandatory operating contract;
- add `PROJECT_STATUS.md` as the single current human status;
- add schema-validated `docs/project-status.json`;
- add continuation, CI runner, increment lifecycle, and repository map documents;
- make lower-priority stale sources explicitly historical or reconcile them.

### F-002 — Status and traceability drift

**Severity:** High  
**Status:** Open; remediation in progress.

Examples found:

- README still described early planning/foundation work;
- `docs/00-execution-ledger.md` did not represent the current Phase `0.4.0` boundary;
- the global traceability matrix marked accepted Catalog/Panel/Offering/Capacity/Custom Plan work `not-started`;
- risk register review date and statuses preceded multiple verified phases;
- `docs/20-current-state-audit.md`, `docs/22-phase-boundary-reconciliation.md`, and PR body described old active work;
- active handoff was newer than the global project status documents.

**Risk:** a new engineer can duplicate work, enter the wrong phase, weaken a verified boundary, or cite an obsolete run.

**Correction:** reconcile current overlays and mark historical reports as superseded rather than deleting accepted history.

### F-003 — Self-hosted CI toolchain was implicit

**Severity:** Critical to delivery  
**Status:** Remediated in code; exact-head CI pending.

`shivammathur/setup-php` attempted `sudo` on the self-hosted runner and blocked because the runner user does not have interactive or unrestricted passwordless privilege. Later execution exposed environment-dependent PHP INI/JIT/PCOV behavior.

**Correction:**

- use the preinstalled PHP/Composer toolchain;
- select `/www/server/php/84/etc/php-cli.ini` explicitly;
- validate PHP 8.4, extensions, Composer 2.10.2, PCOV mode, and JIT state before expensive steps;
- disable JIT for CI;
- enable PCOV only for coverage;
- add step timeouts and document the single-runner queue behavior;
- never restore the privileged setup action on this runner.

### F-004 — Dependency advisory in the lockfile

**Severity:** High  
**Status:** Repair validated; commit/application pending at audit time.

`league/commonmark` `2.8.3` was reported under `CVE-2026-3066` / `GHSA-3f99-q5wh-69xq`. The bounded repair updates it to `2.9.0`, then runs full static, audit, license, and repository policy validation.

**Correction rule:** apply the exact lockfile repair; do not broaden into a general dependency update.

### F-005 — Active increment was not formatting-clean

**Severity:** Medium  
**Status:** Repair validated; commit/application pending at audit time.

Pint identified five active-increment files. The generated repair modifies formatting only in those paths.

**Correction rule:** preserve behavior, apply only the validated files, and run full CI afterward.

### F-006 — Temporary repair automation has write permission

**Severity:** High while present  
**Status:** Temporary; removal is a stabilization exit condition.

A bounded same-repository workflow was created to validate and commit the exact lockfile/formatting repair because the connector cannot atomically submit the generated lockfile and all formatted files. It is branch- and PR-bound, stages an allowlisted file set, and does not force-push.

**Risk:** any write-capable workflow is supply-chain-sensitive and must not become permanent project infrastructure.

**Correction:** delete the workflow and repair generator immediately after the repair commit is confirmed, then verify their absence through project-control CI.

### F-007 — Single runner can make “parallel” CI appear stuck

**Severity:** High operational friction  
**Status:** Open/documented.

All mandatory jobs target one self-hosted runner. New commits can supersede runs while an older job remains reported `in_progress`; queued repair and CI runs then cannot execute.

**Correction:**

- document one-runner serialization;
- use job and step timeouts;
- avoid repeated commits/dispatches while occupied;
- add a safe runner-health diagnostic procedure;
- consider a second identical isolated runner only after the current contract is stable and resource isolation is proven.

A second runner is an optimization, not a substitute for deterministic cleanup and concurrency controls.

### F-008 — Staging workflow sprawl and unsafe discoverability

**Severity:** Critical operational risk  
**Status:** Open.

Multiple `workflow_dispatch` workflows remain under `.github/workflows/staging-*.yml`. They mix:

- safe inventory/verification;
- one-time package/runtime installation;
- root-level apt and aaPanel installation;
- deployment of an old verified SHA;
- remote filesystem/database/runtime mutation;
- plain HTTP site configuration.

Several use older action pins and have no typed confirmation input. Their continued presence makes an accidental destructive or obsolete run too easy.

**Correction:**

1. create a staging workflow inventory with one of `approved-diagnostic`, `approved-controlled-mutation`, `historical-disabled`, or `remove`;
2. remove obsolete one-time workflows from active Actions after preserving history in Git;
3. require environment protection, exact ref/SHA input, typed confirmation, concurrency lock, current action pins, and sanitized evidence for any retained mutation workflow;
4. never use an old hard-coded application SHA as a current deployment source;
5. keep real remote mutations outside ordinary feature CI.

### F-009 — Target architecture and implemented architecture are mixed

**Severity:** Medium  
**Status:** Open.

`docs/05-architecture-overview.md` correctly describes the intended modular monolith, but its module table includes future modules not materialized in source. A new engineer can misread target boundaries as implemented services.

**Correction:** label each module as `implemented`, `foundation-only`, or `target`, and link implemented paths. Keep future architecture without presenting it as current capability.

### F-010 — Architecture enforcement is narrower than the architecture claims

**Severity:** High maintainability risk  
**Status:** Open.

Current `scripts/ci/architecture.sh` checks important Domain/framework and layer patterns, but does not fully enforce:

- declared module dependency graph;
- Application imports of another module's Infrastructure;
- table ownership/direct cross-module writes;
- Presentation-to-Application-only calls;
- dependency cycles;
- target versus implemented module declarations.

**Correction:** add an explicit machine-readable module map and incremental static checks. Do not attempt a broad rewrite of already verified services merely to satisfy a new purity model; first detect and classify existing exceptions, then migrate boundedly.

### F-011 — Application-service complexity hotspot

**Severity:** Medium now; High if extended without control  
**Status:** Open in active unverified increment.

`TrialReservationService` is approximately 800 lines and currently combines eligibility, membership, route/capacity decisions, persistence, reservation lifecycle, replay/conflict handling, admin regrant/reset, history/events, and hydration.

The behavior is security- and concurrency-sensitive, so an arbitrary refactor before baseline verification would add risk. However extending this class further will make review and correctness harder.

**Correction:** after the current implementation is green, decompose inside the same module into explicit collaborators such as:

- command/replay journal;
- actor/policy snapshot loader;
- route/capacity reservation transaction;
- reservation lifecycle transitions;
- trial history/event writer;
- receipt/query hydration.

Preserve transaction boundaries and tests; do not change accepted schema or behavior as part of the first extraction.

### F-012 — Large migrations/tests are carrying multiple responsibilities

**Severity:** Medium  
**Status:** Open.

The active trial migration and feature test are large because they encode substantial constraints, triggers, fixtures, and lifecycle behavior. This is understandable for a bounded foundation but raises review cost.

**Correction:**

- add an internal migration contract section in traceability;
- separate fixture builders and scenario helpers from assertions;
- keep one migration when atomic schema/trigger ordering requires it, but document sections and corrective migration strategy;
- avoid editing a verified historical migration after acceptance.

### F-013 — Coverage exists without a documented quality interpretation

**Severity:** Medium  
**Status:** Open.

CI requires non-empty Clover output, but there is no accepted numeric threshold or risk-based interpretation policy. A raw percentage alone is not sufficient for this project.

**Correction:** define per-increment coverage interpretation:

- all state transitions/invariants/authorization/remote idempotency branches must be explicitly mapped;
- report changed-line and critical-path gaps;
- use a numeric floor only after measuring the stable suite, so it does not reward low-value tests or penalize generated/framework code.

### F-014 — Deployment documents mix target and current commands

**Severity:** High operational ambiguity  
**Status:** Open.

Deployment/update runbooks contain future commands and release interfaces that are not all current executable capabilities. The documents contain caveats, but the distinction is not prominent enough for a new operator.

**Correction:** add a banner to every operational runbook:

- `current executable`;
- `target-state design`;
- `historical evidence`;
- exact required phase/evidence before use.

### F-015 — Long-running PR scale is a permanent navigation risk

**Severity:** Medium  
**Status:** Controlled by new control plane; open for monitoring.

PR `#6` contains hundreds of commits and changed files. This is intentional under the current branch policy and cannot be “fixed” by rewriting history.

**Correction:**

- retain exact bounded evidence;
- maintain one current status and one active handoff;
- add machine checks for stale/superseded files;
- keep future commits focused;
- use phase tags/releases and closure reports after accepted gates;
- do not ask reviewers to infer scope from the complete PR diff.

## 5. Architecture assessment by area

| Area | Assessment | Required action |
|---|---|---|
| Domain typing/value objects | Strong in implemented modules | preserve; add missing central error taxonomy over time |
| Application orchestration | Correctness-focused but some large services | bounded decomposition after green baseline |
| Infrastructure/adapters | Good contract/fake/fail-closed direction | real contract tests only against exact versions |
| Database | Strong constraints/locks/history emphasis | document table ownership and migration correction rules |
| Authorization | Strong Phase 0.3 foundations | preserve execution-time checks in every new service |
| Remote idempotency | Strong active design, still unverified | exact-head tests/evidence before acceptance |
| Telegram | Foundation only, appropriately separated | do not claim complete Persian UX yet |
| Installer/release operations | meaningful foundations and evidence | clearly mark executable versus target commands |
| CI | comprehensive gates, runtime drift exposed | deterministic wrapper, timeouts, runner-health procedure |
| Documentation | deep but fragmented/stale | one current status, schema, reconciliation, superseded labels |
| Staging automation | too many mixed-purpose workflows | inventory, guard, archive/delete obsolete workflows |

## 6. Stabilization plan

### Gate A — Restore executable CI

- deterministic self-hosted PHP wrapper;
- explicit CLI INI;
- JIT disabled;
- PCOV coverage validation;
- PHPStan 1 GiB contract;
- timeouts and exact executable logs.

### Gate B — Apply bounded existing failures

- `league/commonmark` security update only;
- five Pint-only repairs;
- remove temporary repair workflow/generator;
- mandatory CI on the exact resulting head.

### Gate C — Establish project control plane

- `AGENTS.md`;
- `PROJECT_STATUS.md`;
- schema-validated status JSON;
- continuation, CI, increment lifecycle, repository map, and contributor documents;
- project-control verification script integrated into preflight;
- README/ledger/traceability/risk/PR/Issue reconciliation;
- historical/superseded labels.

### Gate D — Reduce unsafe operational surface

- staging workflow inventory;
- remove or disable obsolete one-time/destructive workflows;
- protect retained mutation workflows;
- exact-head CI after cleanup.

### Gate E — Resume active Trial/Panel increment

- inspect exact implementation after stabilization;
- fix real code/test failures only;
- bounded decomposition where required for reviewability;
- implementation exact-SHA evidence;
- Trial/Panel evidence and traceability head;
- exact evidence-head CI;
- Issue/PR update.

## 7. Stabilization exit criteria

Feature development resumes only when:

- PR `#6` remains Draft, base `main`, correct branch;
- no temporary repair workflow/script remains;
- mandatory CI is green on the exact stabilization head;
- dependency audit is clear and licenses accepted;
- status Markdown/JSON and active handoff agree;
- project-control machine check passes;
- stale global docs are reconciled or explicitly superseded;
- obsolete staging workflows are removed or guarded and inventoried;
- no unsupported provider/production claim was introduced.

## 8. Decisions explicitly rejected

- no repository rewrite or new framework;
- no PR history rewrite/squash as a stabilization technique;
- no broad service refactor before a green baseline;
- no disabling Pint, PHPStan, audit, coverage, or secret scan;
- no unrestricted passwordless `sudo` for the runner;
- no reintroduction of privileged runtime setup inside ordinary CI;
- no activation of real Marzban/PasarGuard from shells or fake evidence;
- no deletion of accepted historical evidence merely because global status was stale.

## 9. Next mandatory sequence

1. clear the blocked self-hosted runner queue and let the bounded repair commit execute;
2. confirm exact lockfile and formatting changes;
3. remove temporary repair automation;
4. integrate and run project-control verification;
5. reconcile stale documents and staging workflows;
6. obtain exact-head green CI;
7. resume Trial/Panel verification from `docs/30-phase-0.4-trial-panel-handoff.md`.
