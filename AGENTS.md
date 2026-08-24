# Repository Operating Contract

This file defines the durable working rules for humans and AI agents. Chat history is optional context, never project state.

## Authority by kind of information

Do not use one document as authority for every kind of truth:

1. **Version 1 product scope and non-negotiable product/security/correctness requirements:** `docs/specification/master-execution-prompt.md`, with stable IDs indexed in `docs/01-authoritative-requirements.md`.
2. **Repository execution, branch, review, and Agent rules:** this file and `CONTRIBUTING.md`.
3. **Current phase, backlog, priority, dependency, blocker, PR/review, and CI state:** live GitHub, starting from Program Issue `#3` and Draft integration PR `#6`.
4. **Durable architecture/security/testing/execution-infrastructure/operations rules:** canonical references linked from `docs/README.md`.
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

GitHub is the project's source location and current-state authority. Execution capability does not change that authority.

The canonical map for the connected GitHub integration, `AI_Server_Agent` workspace, GitHub Actions self-hosted runners, external Workers, staging/deployment targets, toolchain ownership, and runner add/replace/quarantine/remove/qualification is [`docs/development/execution-infrastructure.md`](docs/development/execution-infrastructure.md).

Agents must follow these boundaries regardless of which execution path is available:

- prefer an already-supported capability over inventing a new PAT relay, personal checkout, generic remote shell, or duplicate automation path;
- execution workspaces/checkouts are not project authority; durable work returns to GitHub;
- do not read/expose credentials merely because a connector/server can access them;
- use GitHub Actions for authoritative repository CI/runtime evidence when real PHP/Docker/MariaDB/Redis execution is required;
- runner display names and physical hosts are operational inventory, not workflow dependencies;
- external Workers are optional capacity, never project authority.

Detailed development routing is in `CONTRIBUTING.md`; test/CI semantics are in `docs/06-test-strategy.md`; privileged deployment/runtime operations are in `docs/09-deployment-runbook.md`.

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

For this repository, required independent review is dispatched only by giving the Owner a ready-to-paste prompt for a fresh ChatGPT chat. Do not request GitHub/Copilot reviewers or dispatch independent review through any other tool, agent, service, or platform. The canonical relay contract is in `CONTRIBUTING.md`.

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

1. read the task Issue and only the canonical docs relevant to the decision;
2. inspect current implementation/tests before adding a concept;
3. make the smallest reliable change;
4. add behavior-focused success/failure/security/concurrency tests only where they provide signal;
5. run the applicable checks from `docs/06-test-strategy.md` through a supported execution path from `docs/development/execution-infrastructure.md`;
6. never weaken checks to manufacture a pass;
7. keep live progress in GitHub, not new handoff/status/evidence/infrastructure-inventory documents;
8. capture future-useful follow-up work as an actionable Issue rather than burying it in completion prose;
9. verify the live GitHub object/ref after every repository mutation.

## CI

Every executing GitHub Actions job runs on an owner-controlled self-hosted runner. The exact workflow `runs-on` selector is the routing authority; runner capability/lifecycle rules live in `docs/development/execution-infrastructure.md` and test/CI semantics live in `docs/06-test-strategy.md`.

CI is risk-based and signal-driven. `.github/workflows/ci.yml` computes independent validation needs from the complete diff instead of treating every non-doc change as one all-or-nothing suite.

- Secret scanning remains mandatory for every executing CI revision.
- Repository/planning checks run when their canonical control/document surfaces change; they are not ceremonial application-test prerequisites.
- PHP style/static/architecture, dependency/license policy, MariaDB/Redis integration, Docker/runtime validation and operational-entrypoint validation are selected independently when the changed behavior can affect those contracts.
- MariaDB `10.11` is the mandatory normal integration target when application/database semantics are affected. It is not started for a change that independent validation proves cannot affect those semantics.
- Unknown/ambiguous paths fail safely to the strongest validation plan. Removing an application/control prerequisite never downgrades validation.
- Draft PRs stay quiet. Marking a PR Ready triggers validation on the current revision. Superseded safe runs are cancelled; guarded external-effect workflows retain non-cancellation where interruption would itself be unsafe.
- Draft integration PR `#6` stays quiet during normal development and receives intentional release validation at the final review boundary.

Reuse green evidence when the tested resulting tree has not materially changed. Rerun only clearly transient failed jobs where possible. Never rerun a deterministic failure hoping for green; fix the cause first.

## Documentation and evidence

Documentation topology and ownership are canonical in `docs/README.md`. One kind of durable truth has one owner; other files link to it instead of copying it.

Do not create per-task handoff, overlay, current-state, risk, traceability, infrastructure-inventory, or evidence documents. Dynamic task/runner/CI state belongs in GitHub and operational systems; task history belongs in commits, PRs, Issues, reviews, and CI.

`evidence/` is reserved for release-candidate/release records that have a real retention need.

## Human approval

Explicit Owner approval is required before merging high/critical-risk changes involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations unless the exact action was already authorized.
