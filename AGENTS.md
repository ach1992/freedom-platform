# Repository Operating Contract

This file defines the durable working rules for humans and AI agents. Chat history is optional context, never project state.

## Authority by kind of information

Do not use one document as authority for every kind of truth:

1. **Version 1 product scope and non-negotiable product/security/correctness requirements:** `docs/specification/master-execution-prompt.md`, with stable IDs indexed in `docs/01-authoritative-requirements.md`.
2. **Repository execution, branch, review, and Agent rules:** this file and `CONTRIBUTING.md`.
3. **Current phase, backlog, priority, dependency, blocker, PR/review, and CI state:** live GitHub, starting from Program Issue `#3` and the active Phase/task Issues and PRs.
4. **Durable architecture/security/testing/execution-infrastructure/operations rules:** canonical references linked from `docs/index.md`.
5. **Historical implementation context:** Git/PR/Issue/workflow history.

The master specification is not a live task board. Historical instructions in it to create execution ledgers, mutable traceability matrices, per-phase status/evidence files, or similar coordination artifacts are superseded by this repository operating model; they must not be used to recreate retired documentation. This does **not** weaken any product, security, financial-integrity, provider, runtime, testing, restore, or release requirement.

The root `README.md` is deliberately user-facing: product overview, getting-started/use guidance, supported runtime baseline, security notice, and links to deeper material. It is not project-state, recovery, governance, architecture, or engineering-policy authority.

When two sources of the same kind conflict, correct the stale/lower source instead of maintaining both.

Master/chat rotation is a **bounded recovery event**, not permission to restart project analysis. Recover current repository/target identity, Program #3, the active Phase cutline, current Task/PR when one exists, and only the canonical references needed for the next decision. If those authorities remain coherent, continue them. Do not replay closed Issue/PR history, reread the full root specification, rerun a phase-wide audit/cutline, or recreate READY/planning artifacts merely because the chat/Master changed; broaden recovery only when current evidence materially contradicts the retained state or the completion/dependency shape changed.

## Branch and PR model

`main` is the only long-lived branch. It is both the GitHub default branch and the primary integration branch.

Normal implementation/maintenance work uses a temporary task branch from the current `main` head and a PR targeting `main`. An active Phase may explicitly own one cumulative temporary implementation branch/PR; when it does, continue on that branch instead of creating parallel task branches.

For substantive work expected to need multiple implementation/self-review commits, open and keep the PR as **Draft** while the candidate is still changing. Draft is the implementation/correction state; **Ready for review is an acceptance-CI signal**, not merely a visibility state. Mark Ready only when the intended implementation has converged, focused validation and self-review have completed far enough that the current candidate is expected to consume merge-gate CI, and no planned material correction remains. If material implementation or self-review work is discovered after Ready, convert the PR back to Draft before further correction pushes; return to Ready only after the candidate stabilizes again. This rule never permits skipping the final applicable CI/review gates.

Merging normal work to `main` is integration, not a production release or deployment. Version/release acceptance and production actions remain separately gated by their owning Issues, release rules, and deployment controls.

When a temporary branch appears no longer needed, the Master reports the branch name to the Owner. Branch cleanup is Owner-operated; the Master does not remove branches automatically or create branch-cleanup automation unless the Owner explicitly changes this policy later.

Never push product work directly to `main`, rewrite shared history, force-push shared branches, self-merge a Worker PR, or enable auto-merge for high-risk work. An exceptional direct protected-branch change must be explicitly Owner-authorized and documented in its GitHub Issue.

## Execution access

GitHub is the project's source location and current-state authority. Execution capability does not change that authority.

The canonical map for the connected GitHub integration, `AI_Server_Agent` workspace, standard GitHub-hosted CI, optional private/trusted self-hosted runners, external Workers, staging/deployment targets, toolchain ownership, and runner lifecycle is [`docs/development/execution-infrastructure.md`](docs/development/execution-infrastructure.md).

Agents must follow these boundaries regardless of which execution path is available:

- prefer an already-supported capability over inventing a new PAT relay, personal checkout, generic remote shell, or duplicate automation path;
- execution workspaces/checkouts are not project authority; durable work returns to GitHub;
- do not read/expose credentials merely because a connector/server can access them;
- use GitHub Actions for authoritative repository CI/runtime evidence when real PHP/Docker/MariaDB/Redis execution is required;
- runner display names and physical hosts are operational inventory, not workflow dependencies;
- external Workers are optional capacity, never project authority.

Detailed development routing is in `CONTRIBUTING.md`; test/CI semantics are in `docs/06-test-strategy.md`; privileged deployment/runtime operations are in `docs/09-deployment-runbook.md`.

## Task contract

Persist a dedicated GitHub Task Contract Issue when it materially improves implementation, review, coordination, recovery, or risk control. It is required for High/Critical work, delegated or cross-session work, material dependency/decision sequencing, or a substantive product outcome whose acceptance/state cannot be represented safely by an existing requirement/Phase/Program authority plus one reviewable bounded FAST PR. Substantive behavior alone does **not** force a new Issue when the Low/Medium FAST criteria below are genuinely satisfied.

Do **not** create a ceremonial Issue for bounded Low/Medium-risk self-executed FAST work when all of these are true:

- authority can be linked to an existing requirement, Phase/Program Issue, or other durable source;
- scope, acceptance, risk, and validation fit clearly in one reviewable PR;
- the work is reversible and introduces no material schema, security/authorization, provider, deployment/release, secret, or production boundary;
- no separate coordination state is needed beyond the PR/Git history.

When a Task Contract Issue is warranted, right-size it to the **minimum meaningful outcome**, not the smallest implementation seam. It must be no smaller than a coherent acceptance boundary and no larger than remains safely reviewable. A Task Contract stays open until its full accepted outcome is complete; integrating one partial PR does not justify closing the Issue and opening a mechanically similar sibling when the acceptance, dependency, ownership, risk, rollback/release, and validation boundaries remain aligned. Multiple cohesive PRs may reference one Task Contract when that improves reviewability; partial PRs use `Refs`, and only the PR that actually completes the contract uses `Closes`. Bounded refinements that remain inside the same outcome should update the existing contract/revision instead of manufacturing a new child Issue. Create a sibling Task only when a material boundary actually changes or continuing the same contract would become unsafe/mixed-purpose/unreviewable.

When a Task Contract Issue is warranted, record:

- parent/requirement or durable authority;
- observable goal/outcome;
- dependencies/base rule;
- bounded scope and meaningful exclusions;
- objective acceptance criteria;
- validation strategy;
- change risk and initial state.

Add protected areas, security/privacy, financial/provider, schema/migration, compatibility, runtime/operations, performance, or release constraints **only when they materially affect the task**. Keep the Task Issue contract-like, not an investigation/status diary: implementation discoveries stay in code/PR/review unless they materially change goal, scope, acceptance, dependency, risk, or validation. Increment a Contract Revision only for such material contract change, not for ordinary commits, self-review corrections, rebases, candidate SHAs, or CI reruns. Do not require headings filled with `N/A`, repeated handoff prose, copied CI logs, or a separate completion report.

Every PR still names its authority and review boundary. When there is no dedicated Task Issue, the PR links the existing requirement/Phase/Program authority and carries the bounded scope, risk, and verification needed to review the change safely. PRs use `.github/pull_request_template.md`. Sensitive paths are assigned in `.github/CODEOWNERS`; CODEOWNERS expresses intended ownership but does not by itself prove branch/ruleset enforcement.

Classify risk from the **actual change**, not from the Phase label or the mere presence of a keyword/surface. Touching a migration, administrator UI, provider abstraction, queue, or release-related module does not automatically make a task High/Critical. Additive/backward-compatible schema, bounded internal configuration, read-only provider/status presentation, or other clearly reversible changes may remain Low/Medium when blast radius, existing-data compatibility, privacy/security impact, external-effect uncertainty, and rollback are correspondingly bounded. High/Critical is reserved for materially dangerous integrity/security/privacy boundaries, uncertain or non-idempotent external effects, destructive/compatibility-sensitive data change, difficult rollback, release/production consequence, or equivalent blast radius. Never downgrade risk merely to avoid a required gate.

High/Critical financial, authorization, security, provider, schema, deployment/release, secret, or irreversible work requires independent review and explicit Owner approval before merge unless that exact action was explicitly pre-authorized. The applicable CI tier must pass on the final candidate; merge style never substitutes for review or validation.

When one meaningful High/Critical outcome remains safely reviewable as one candidate and its acceptance/dependency/risk/rollback/validation boundaries are aligned, prefer one stabilized PR and one final independent-review/approval cycle over a sequence of mechanically similar High-risk PRs. Split only when a material boundary or reviewability reason actually requires it; never combine work merely to evade review.

For this repository, required independent review is dispatched only by giving the Owner a ready-to-paste prompt for a fresh ChatGPT chat. Do not request GitHub/Copilot reviewers or dispatch independent review through any other tool, agent, service, or platform. The canonical relay contract is in `CONTRIBUTING.md`.

## Continuous execution

When the current objective is authorized and any required Task Contract is `READY` — or bounded FAST work has clear durable authority without a dedicated Task Issue — the Master should self-execute normal reversible engineering steps without asking for another Owner confirmation merely because the task is High/Critical risk or because one bounded slice finished. This includes task/branch/PR maintenance, implementation, targeted validation, CI preparation, self-review and corrections. Continue the current meaningful outcome until its acceptance is actually satisfied; then select the next dependency-safe meaningful outcome. Create a new Task Contract only when no current contract properly owns that work and the persistence rules above require one.

Delegate only when there is a concrete execution/review benefit. Worker availability is capacity, not a prerequisite for progress.

A Master/agent stops only for a real boundary: an unresolved product/business-policy or architecture decision that cannot be derived safely, missing credentials/access/capability that blocks the required action, a material risk/scope escalation, destructive or irreversible action, production/deployment action, or an explicit merge/release approval gate. Historical Issue comments or handoff/checkpoint instructions that conflict with the current Program/Phase state are context only and must not create a new pause unless the live authoritative Issue still marks that gate active.

Owner approval requirements in this repository are action-scoped. Unless an Issue explicitly says otherwise, an approval required **before merge/release/destructive action** does not block reversible implementation, testing, review preparation, or continuation to other dependency-safe executable work.

## Scope, architecture, and correctness

Work from the owning Task Issue when one is required/present; otherwise work from the existing requirement/Phase/Program authority that owns the bounded FAST outcome. Preserve accepted behavior outside scope.

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

1. read the owning Task Issue when one is required/present, otherwise the existing requirement/Phase/Program authority, plus only the canonical docs relevant to the decision;
2. inspect current implementation/tests before adding a concept;
3. make the smallest reliable change **inside the accepted meaningful outcome**; do not use "smallest change" as a reason to split one coherent contract into seam-sized Issues/PRs;
4. add behavior-focused success/failure/security/concurrency tests only where they provide signal;
5. run the applicable checks from `docs/06-test-strategy.md` through a supported execution path from `docs/development/execution-infrastructure.md`;
6. never weaken checks to manufacture a pass;
7. keep live progress in GitHub, not new handoff/status/evidence/infrastructure-inventory documents;
8. reuse an existing parent/backlog authority for follow-up when it already fits; create a separate follow-up Issue only when the work is distinct, actionable, likely to be executed, and benefits from independent tracking — do not manufacture Issues for cosmetic/speculative/duplicate cleanup;
9. verify the live GitHub object/ref after every repository mutation.

## CI

Ordinary repository CI runs on standard GitHub-hosted Linux runners. Manual staging/provider workflows may retain explicit self-hosted selectors only as trusted operational contracts and must not have a connected public-repository runner unless that boundary is deliberately re-authorized. The exact workflow `runs-on` selector is the routing authority; execution boundaries live in `docs/development/execution-infrastructure.md` and test/CI semantics live in `docs/06-test-strategy.md`.

CI is risk-based and signal-driven. `.github/workflows/ci.yml` computes independent validation needs from the complete diff instead of treating every non-doc change as one all-or-nothing suite.

- Secret scanning remains mandatory for every executing CI revision.
- Repository/planning checks run when their canonical control/document surfaces change; they are not ceremonial application-test prerequisites.
- PHP style/static/architecture, dependency/license policy, MariaDB/Redis integration, Docker/runtime validation and operational-entrypoint validation are selected independently when the changed behavior can affect those contracts.
- MariaDB `10.11` is the mandatory normal integration target when application/database semantics are affected. It is not started for a change that independent validation proves cannot affect those semantics.
- Unknown/ambiguous paths fail safely to the strongest validation plan. Removing an application/control prerequisite never downgrades validation.
- Draft PRs stay quiet. Marking a PR Ready triggers validation on the current revision. Superseded safe runs are cancelled; guarded external-effect workflows retain non-cancellation where interruption would itself be unsafe.

Reuse green evidence when the tested resulting tree has not materially changed. Rerun only clearly transient failed jobs where possible. Never rerun a deterministic failure hoping for green; fix the cause first.

## Documentation and evidence

Documentation topology and ownership are canonical in `docs/index.md`. One kind of durable truth has one owner; other files link to it instead of copying it.

Do not create per-task handoff, overlay, current-state, risk, traceability, infrastructure-inventory, or evidence documents. Dynamic task/runner/CI state belongs in GitHub and operational systems; task history belongs in commits, PRs, Issues, reviews, and CI.

Native Git/PR/check state owns exact commit and CI identities. Do not post a checkpoint comment for every pushed SHA, superseded CI run, self-review correction, or routine readiness refresh. Add/update durable GitHub prose only when it records a material contract/cutline change, blocker/decision, review result that must be reconciled, approval boundary, explicit pause/handoff, or a concise recovery summary that cannot be inferred from the owning PR/Issue/checks.

`evidence/` is reserved for release-candidate/release records that have a real retention need.

## Human approval

Explicit Owner approval is required before merging high/critical-risk changes involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations unless the exact action was already authorized.
