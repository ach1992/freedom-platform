# Repository Operating Contract

This file defines the durable working rules for humans and AI agents. Chat history is optional context, never project state.

## Authority by kind of information

Do not use one document as authority for every kind of truth:

1. **Version 1 product scope and non-negotiable product/security/correctness requirements:** `docs/specification/master-execution-prompt.md`, with stable IDs indexed in `docs/01-authoritative-requirements.md`.
2. **Repository execution, branch, review, validation, and Agent rules:** this file and `CONTRIBUTING.md`.
3. **Current phase, backlog, priority, dependency, blocker, PR/review, and CI state:** live GitHub, starting from Program Issue `#3` and Draft integration PR `#6`.
4. **Durable architecture/security/testing/operations rules:** canonical references linked from `docs/README.md`.
5. **Historical implementation context:** Git/PR/Issue/workflow history.

The master specification is not a live task board. Historical instructions in it to create execution ledgers, mutable traceability matrices, per-phase status/evidence files, or similar coordination artifacts are superseded by this repository operating model; they must not be used to recreate retired documentation. This does **not** weaken any product, security, financial-integrity, provider, runtime, testing, restore, or release requirement.

When two sources of the same kind conflict, correct the stale/lower source instead of maintaining both.

## Branch and PR model

Only two branches are long-lived:

- `main` — release/default branch;
- `develop/v1.0.0-completion` — Version 1 integration branch.

Draft PR `#6` integrates `develop/v1.0.0-completion` into `main` and remains Draft until explicit final release acceptance.

Normal implementation/maintenance work uses a temporary task branch from the current integration head and a PR targeting `develop/v1.0.0-completion`. An active Phase may explicitly own one cumulative implementation branch/PR; when it does, continue on that branch instead of creating parallel task branches.

When a temporary branch appears no longer needed, the Master reports the branch name to the Owner. Branch cleanup is Owner-operated; the Master does not remove branches automatically or create branch-cleanup automation unless the Owner explicitly changes this policy later.

Never push product work directly to `main` or `develop/v1.0.0-completion`, rewrite shared history, force-push shared branches, self-merge a Worker PR, or enable auto-merge for high-risk work. An exceptional control-plane bootstrap on a protected/default branch must be explicitly Owner-authorized and documented in its GitHub Issue.

## Execution access

GitHub is the project's source location and current-state authority. Do not assume the Owner keeps another authoritative checkout on a personal machine, server, or staging host.

- **Normal Master execution:** the active ChatGPT Master self-executes dependency-safe READY work through the connected GitHub integration and repository-native GitHub capabilities whenever they can perform the task safely.
- **Persistent MCP workspace (when connected):** discover and reuse `AI_Server_Agent` for the repo-scoped checkout `/srv/ai-workspace/freedom-platform` as unprivileged user `aiworker`. Its Git remote uses SSH alias `github-freedom-platform`. Use it for ordinary repository editing, Git, diagnostics, and supported local commands; it is execution state only, never project authority. Never read or expose the backing SSH private key. Operational details and safety boundaries are in `docs/09-deployment-runbook.md`.
- **Authoritative runtime validation:** required PHP/Composer/MariaDB/Redis and repository CI evidence runs through reviewed GitHub Actions on the owner-controlled self-hosted runner `freedom-staging-runner` with labels `[self-hosted, Linux, X64, freedom-staging, php84]` unless an exact task explicitly establishes equivalent evidence elsewhere. An Actions checkout is transient execution state, not a second project source.
- **External Workers:** delegation is optional, not the default. Use an external coding/review Worker only when isolation, safe parallelism, specialist review, or a capability unavailable to the Master materially justifies it. Any Worker must publish all durable work back to GitHub for Master verification.
- **No Codex dependency:** repository-linked Codex Cloud may be used only as an optional external Worker when explicitly useful; it is not the normal or required execution path.
- **GitHub-native capability rule:** prefer the connected GitHub integration and existing reviewed repository workflows. Do not invent a personal local checkout, PAT relay, or generic remote shell merely for convenience.
- **Secrets:** existing secret identifiers, their workflow consumers/reserved status, GitHub Environment use, and provider/readiness entrypoints are documented in `docs/09-deployment-runbook.md`. Never ask the Owner to paste secret values into Chat.

Detailed capability routing is in `CONTRIBUTING.md`; CI execution is in `docs/06-test-strategy.md`.

## Task contract

Every bounded implementation task needs one GitHub Issue. Keep the contract as small as correctness allows.

Required information:

- parent/requirement or durable authority;
- observable goal/outcome;
- dependencies/base rule;
- bounded scope and meaningful exclusions;
- objective acceptance criteria;
- validation strategy;
- change risk and initial state.

Add protected areas, security/privacy, financial/provider, schema/migration, compatibility, runtime/operations, performance, or release constraints **only when they materially affect the task**. Do not require headings filled with `N/A`, repeated handoff prose, copied CI logs, or a separate completion report.

PRs use `.github/pull_request_template.md`. Sensitive paths are assigned in `.github/CODEOWNERS`; CODEOWNERS expresses intended ownership but does not by itself prove branch/ruleset enforcement.

High/Critical financial, authorization, security, provider, schema, deployment/release, secret, or irreversible work requires independent review and explicit Owner approval before merge unless that exact action was explicitly pre-authorized. The applicable CI tier must pass on the final candidate; merge style never substitutes for review or validation.

## Continuous execution

When the current objective is authorized and a dependency-safe Task Contract is `READY`, the Master should self-execute normal reversible engineering steps without asking for another Owner confirmation merely because the task is High/Critical risk or because one bounded slice finished. This includes task/branch/PR maintenance, implementation, targeted validation, CI preparation, self-review, corrections, and selecting or creating the next just-in-time READY task under the active phase.

Delegate only when there is a concrete execution/review benefit. Worker availability is capacity, not a prerequisite for progress.

A Master/agent stops only for a real boundary: an unresolved product/business-policy or architecture decision that cannot be derived safely, missing credentials/access/capability that blocks the required action, a material risk/scope escalation, destructive or irreversible action, production/deployment action, or an explicit merge/release approval gate. Historical Issue comments or handoff/checkpoint instructions that conflict with the current Program/Phase state are context only and must not create a new pause unless the live authoritative Issue still marks that gate active.

Owner approval requirements in this repository are action-scoped. Unless an Issue explicitly says otherwise, an approval required **before merge/release/destructive action** does not block reversible implementation, testing, review preparation, or continuation to the next READY task.

## Scope, architecture, and correctness

Work from the owning Issue and preserve accepted behavior outside scope.

The architecture is a modular monolith optimized for future change:

- keep Domain logic framework/infrastructure-independent;
- Presentation calls Application; Infrastructure implements ports/adapters;
- cross-module mutation/orchestration goes through explicit Application contracts;
- do not introduce a new cross-module Domain dependency or architecture-boundary exception merely to shorten a task;
- prefer module-local value types/snapshots or Application contracts when information crosses module boundaries;
- refactor when responsibility, reuse, coupling, change frequency, transaction boundaries, or testability justify it — **not** because a file exceeds an arbitrary line count;
- do not split cohesive behavior into many files solely to satisfy style metrics.

Release-blocking invariants:

- no paid provisioning before authoritative payment capture;
- no duplicate financial or remote effect;
- uncertain external results require authoritative lookup/reconciliation before retry;
- idempotency-key conflicts fail closed and preserve the accepted result;
- MariaDB transactions, locks, constraints, and immutable history are final correctness barriers;
- execution-time authorization is mandatory; UI visibility is not authorization;
- browser/customer assertions never prove capture;
- TLS verification is never disabled;
- fake/fixture/source-contract evidence never proves live provider compatibility.

## Security

Never request, retrieve, print, commit, log, attach, or quote secrets. Production credentials, OTPs, payment instruments, private provider payloads, subscription URLs, identity data, and real customer data do not belong in Chat, Git, Issues, PR text, fixtures, screenshots, or CI evidence.

Use prepared ORM/query-builder paths, validation, output escaping, least privilege, fail-closed authorization, bounded network I/O, and explicit redaction.

## Change protocol

For every change:

1. read the task Issue and relevant canonical docs;
2. inspect current implementation/tests before adding a concept;
3. make the smallest reliable change;
4. define and add behavior-focused success/failure/security/concurrency tests only where they provide signal;
5. run the appropriate checks in `CONTRIBUTING.md`/`docs/06-test-strategy.md` through the available reviewed execution path;
6. never weaken checks to manufacture a pass;
7. keep live progress in GitHub, not new handoff/status/evidence documents;
8. capture future-useful follow-up work as an actionable Issue rather than burying it in a completion narrative;
9. verify the live GitHub object/ref after every repository mutation.

## CI

Every executing GitHub Actions job runs on the owner-controlled self-hosted runner:

```yaml
runs-on: [self-hosted, Linux, X64, freedom-staging, php84]
```

GitHub-hosted runners are not a fallback. Exact runtime and quality requirements are in `docs/06-test-strategy.md`.

CI is risk-based:

- **CONTROL CI** is allowed only for PRs to `develop/v1.0.0-completion` whose complete diff is in the explicit documentation/governance-only allowlist. It runs repository/project-control validation and secret scanning.
- **FULL CI** is required for source, routes, bootstrap/config, schema/migrations, tests, dependencies, static/CI tooling, Docker/runtime/deployment/workflow changes, any unknown path, every PR targeting `main`, and intentional manual release validation.
- MariaDB `10.11` is the mandatory normal integration target. Other compatible MariaDB lines may be run for task-specific, manual, or release compatibility evidence when useful; they are not an automatic gate on every PR unless the task requires them.
- Unknown changes default to FULL.
- Draft PRs stay quiet. Marking a PR Ready triggers the applicable tier on the current revision.
- Draft integration PR `#6` does not re-run FULL merely because already-reviewed work merged into `develop`; it receives intentional FULL validation at the release review boundary.

Reuse green evidence when the tested resulting tree has not materially changed. Rerun only clearly transient failed jobs where possible. Never rerun a deterministic failure hoping for green; fix the cause first.

## Documentation and evidence

Do not create per-task handoff, overlay, current-state, risk, traceability, or evidence documents. Durable rules belong in an existing canonical document. Dynamic state belongs in GitHub.

Task-level implementation history is preserved by commits, PRs, Issues, reviews, and CI. `evidence/` is reserved for release-candidate/release records that have a real retention need.

## Human approval

Explicit Owner approval is required before merging high/critical-risk changes involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations unless the exact action was already authorized.
