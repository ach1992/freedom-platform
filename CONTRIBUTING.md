# Contributing

This file owns the repository's **development workflow**: branches, Issues/PRs, review/integration behavior, and how to select authoritative validation. Execution tools/hosts are deliberately documented elsewhere so changing infrastructure does not require editing the development process in several places.

## Workflow

1. On first ownership, Master/chat rotation, or Phase transition, recover `AGENTS.md`, Program Issue `#3`, the active Phase, and any current Task/PR. During continuous work under an unchanged recovered Phase/cutline, retain that baseline and refresh only decision-relevant deltas; do not reread unchanged Program/closed history for every task. For the active change, read the owning Task Issue when one is required/present; for bounded FAST work, identify the existing requirement/Phase/Program authority in the PR.
2. Confirm the relevant parent phase/dependencies and inspect the current `main` head from GitHub.
3. Work on one temporary task branch unless the active Phase explicitly owns one cumulative implementation branch/PR.
4. Open the PR against `main`. For substantive multi-commit work, open it as Draft by default.
5. Keep the PR Draft while implementation/self-review corrections are still expected. **Ready means the current candidate is intended to consume acceptance CI.** Mark it Ready only after the implementation has converged and focused validation/self-review make a merge-gate run useful. If material correction work appears after Ready, convert back to Draft before further correction pushes; do not leave a changing candidate Ready and repeatedly pay for superseded broad CI.
   With two independent WIP streams targeting `main`, normally advance only one into Ready/review/integration at a time if its merge would stale the other's evidence; keep the other Draft until the first target update is reconciled.
6. Do not merge your own Worker PR.

`main` is the default and primary integration branch. Normal product work reaches it through reviewed PRs rather than direct pushes. Current project state and source live in GitHub; there is no Owner-maintained local/server project checkout to synchronize and no repository status snapshot to maintain.

When a temporary branch appears no longer needed, the Master reports its name to the Owner. Branch cleanup is Owner-operated; do not delete branches automatically or add branch-deletion automation unless the Owner explicitly changes this policy later.

Do not insert a human checkpoint between ordinary reversible steps. When the current objective is authorized and dependency-safe executable work exists, continue implementation, targeted validation, PR maintenance, self-review/correction, and outcome-linked follow-on work. Stop only for a real blocker/decision/capability boundary or for the specific action that is explicitly approval-gated.

## Execution and validation routing

Use [`docs/development/execution-infrastructure.md`](docs/development/execution-infrastructure.md) for the canonical boundaries and lifecycle of the connected GitHub integration, `AI_Server_Agent` workspace, standard GitHub-hosted CI, optional trusted self-hosted runners, external Workers, and staging/deployment targets.

Use [`docs/06-test-strategy.md`](docs/06-test-strategy.md) for the canonical validation plan, evidence freshness, reproducible verification commands, MariaDB/Redis requirements, and CI failure policy.

Use [`docs/09-deployment-runbook.md`](docs/09-deployment-runbook.md) only for deployment, backup/restore, update/rollback, protected Environments, and privileged/live runtime operations.

A capability being technically reachable does not change its authority/safety boundary. GitHub remains project truth; execution workspaces and runner checkouts remain execution state.

### GitHub Actions and secrets

Workflow YAML for the exact revision is authoritative for job `permissions:`, `runs-on`, secrets and Environment use. Do not infer permissions or runner capability from repository defaults, a runner display name, or historical runs.

Secret values are write-only operational state. Never request or paste a PAT, SSH key, password, provider key, bot token, `.env`, registration/remove token, or other secret into Chat, Git, Issues, PRs, logs, screenshots, or repository evidence.

## Task and PR contracts

Task-contract persistence is owned by `AGENTS.md`. Use `.github/ISSUE_TEMPLATE/task.yml` whenever those rules require a dedicated Task Contract; use the bounded FAST path only when the exact `AGENTS.md` criteria are satisfied. Do not create a ceremonial Issue merely to satisfy process, and do not broaden the FAST path by copying or reinterpreting its criteria here.

When a Task Issue exists, keep it limited to the implementation/review facts required by `AGENTS.md`. Add security/data/financial/provider/schema/runtime/compatibility/protected-area detail only when it matters. Do not create fields or documents merely to say `N/A`, repeat GitHub state, or preserve test logs already available from CI.

A Task Issue represents its **whole meaningful outcome**, not merely the first PR or first technical seam. Keep it open across cohesive partial PRs when the acceptance/dependency/risk/rollback/validation boundary remains the same. Use `Refs #...` for a partial PR and `Closes #...` only when that PR actually satisfies the full Task acceptance. Prefer updating the existing contract/revision for bounded in-outcome refinement over closing it and creating a mechanically similar sibling.

After verified integration/closure, reconcile any acceptance checklist or explicit mutable current-state text in an owning Issue when one exists. Exact commit/run identity belongs to native Git/PR/check state; do not create a comment ledger for every candidate or CI rerun. Use comments for material decisions/blockers/review results/approval boundaries/explicit pauses or a recovery summary that native state cannot express. An `Initial state` field remains historical contract input and does not need rewriting.

Pull requests use `.github/pull_request_template.md`: identify the durable authority (and owning Issue when one exists), summarize the change, state material risk/impact, and provide the focused verification or applicable CI result. Record a nonclaim only when adjacent scope could otherwise be misunderstood. The PR body is not a candidate ledger: GitHub already owns current base/head SHA, mergeability and check/run identity. Do not rewrite the body after every push/rerun merely to copy those fields; update it when scope/risk/acceptance/nonclaims or stable review-useful evidence materially changes.

Sensitive paths are assigned in `.github/CODEOWNERS`. CODEOWNERS identifies intended ownership; actual merge enforcement depends on repository protection/rulesets. Verify live repository settings before relying on protection as an enforced fact.

High/Critical work involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations requires independent review and explicit Owner approval before merge unless that exact merge/action was already authorized. This gate does not by itself block reversible implementation, testing, review preparation, or continuation to other dependency-safe executable work.

## Independent review relay

When independent review is required, the permitted project dispatch mechanism is an **Owner-relayed fresh ChatGPT chat**.

1. The authoring Master first lets implementation and effective-diff self-review converge, completes the applicable exact-head validation for that candidate, then gives the Owner one ready-to-paste `INDEPENDENT REVIEW CHAT` prompt. Do not request independent review while planned implementation/self-review correction remains.
2. The prompt identifies the repository/PR/change, exact integration target/base SHA, exact candidate HEAD SHA, owning Issue/contract and acceptance criteria, risk level, material review boundaries/invariants, and validation evidence tied to that exact candidate.
3. The Owner opens a new ChatGPT chat and pastes the prompt. That fresh chat reviews read-only and returns the exact candidate SHA reviewed, a verdict of `APPROVE` or `CHANGES_REQUIRED`, and evidence-backed findings classified as `BLOCKER`, `REQUIRED`, or `OPTIONAL`.
4. The Owner relays the complete review result back to the authoring Master. The Master reconciles every finding and refreshes candidate/target/CI/review freshness before relying on the review or integrating.
5. Candidate, target, contract, or material effective-diff drift invalidates the affected independent review; generate a new exact review packet rather than reusing a stale verdict.
6. For remediation after `CHANGES_REQUIRED`, the fresh packet identifies the prior reviewed candidate and the exact delta to the corrected candidate. The new verdict always binds the current exact candidate, but unchanged prior analysis may be reused when its assumptions remain valid; the reviewer widens back to the full affected surface whenever the correction changes those assumptions. Do not force ceremonial whole-diff rediscovery solely because a bounded correction changed the SHA.

### Defensive review-packet formulation

Independent-review packets are defensive software-assurance artifacts. Preserve the exact envelope, complete material review scope, adversarial depth, and applicable HIGH_ASSURANCE requirements, while expressing review challenges primarily as properties and invariants to verify rather than as procedural misuse instructions.

- Prefer exact file/symbol/test locators, expected invariants, failure conditions, and required regression evidence over copied step-by-step exploitation sequences when the reviewer can inspect the authoritative repository directly.
- It is valid to name language/runtime/container/database mechanism families that are material to the invariant, but do not turn the relay packet into operational instructions for exploitation, credential abuse, or live-system mutation when an equivalent property-based review question is sufficient.
- This is a presentation constraint only. Never omit a material security boundary, concurrency case, restricted-data concern, failure mode, acceptance criterion, or required validation merely to make the packet easier for a platform to accept.
- A platform refusal, hidden response, or safety limitation is not review evidence and never satisfies an independent-review gate. Reformulate the same exact review envelope using bounded defensive/property-based language and the same Owner-relayed fresh ChatGPT mechanism; keep the material scope unchanged.
- Do not evade or defeat platform safety controls. If an equivalent compliant review packet still cannot be reviewed, surface the missing review capability/gate instead of substituting self-review, weakening the contract, or manufacturing approval.

Do not request GitHub/Copilot reviewers or dispatch independent review through another agent/service merely to satisfy this repository rule. If repository/platform protection independently requires a native approval object, treat that as a separate integration gate and surface it without fabricating or bypassing it.

## Merge method

Merge style never substitutes for review or the applicable green CI tier.

- **Squash** documentation, governance, generated/mechanical cleanup, and task branches whose intermediate commits have no durable audit value.
- Preserve multiple implementation commits with **merge** or **rebase** only when those boundaries are intentional, reviewable, and useful for later audit/debugging.
- Never rewrite shared long-lived history merely to make it look tidy.

## Repository bootstrap

When a reviewed execution environment needs a repository runtime, the base toolchain is defined by the execution-infrastructure and test-strategy references. A normal application bootstrap is:

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
cp .env.example .env
php artisan key:generate
```

Use development-only database, Redis, Telegram, SMS, panel, and payment values. Prefer deterministic fakes unless the task explicitly owns a controlled integration test. Never use production/staging secrets or real customer data in a development/test execution environment.

## Verification

Select the narrowest safe verification plan from [`docs/06-test-strategy.md`](docs/06-test-strategy.md). That document and the current workflow/source own executable commands and CI semantics; do not maintain another copied command matrix here.

`composer test:quick` runs the Unit suite for fast local feedback only; it does not prove migrations, constraints, triggers, locking, or concurrency. Use focused tests while iterating, then the applicable MariaDB-backed validation. `composer test` aliases the full `composer test:integration` path. MariaDB 10.11 remains the mandatory normal integration target when application/database semantics are affected; additional versions are explicit compatibility evidence, not an automatic per-PR matrix.

## Coding and architecture

Repository architecture/correctness rules are canonical in `AGENTS.md` and `docs/05-architecture-overview.md`. Follow the owning module and preserve accepted behavior outside task scope. Do not create a second coding-rule catalog here.

## Documentation

Durable documentation ownership and the intentionally small documentation set are defined by [`docs/index.md`](docs/index.md). The root `README.md` remains user-facing and is not a development-state or engineering-policy authority. Live state belongs in GitHub; task history belongs in Git/Issues/PRs/CI. Update the canonical owner of a rule instead of adding a compensating copy elsewhere.
