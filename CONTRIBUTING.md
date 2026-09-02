# Contributing

This file owns the repository's **development workflow**: branches, Issues/PRs, review/integration behavior, and how to select authoritative validation. Execution tools/hosts are deliberately documented elsewhere so changing infrastructure does not require editing the development process in several places.

## Workflow

1. Read `AGENTS.md`, Program Issue `#3`, and the GitHub Issue for the task.
2. Confirm the task's parent phase/dependencies and inspect the current head of `develop/v1.0.0-completion` / Draft PR `#6` from GitHub.
3. Work on one temporary task branch unless the active Phase explicitly owns one cumulative implementation branch/PR.
4. Open the PR against `develop/v1.0.0-completion`.
5. Keep the PR Draft while it is changing; mark it Ready only when the intended validation should run.
6. Do not merge your own Worker PR.

`main` is the release/default branch, not a normal development target. Current project state and source live in GitHub; there is no Owner-maintained local/server project checkout to synchronize and no repository status snapshot to maintain.

When a temporary branch appears no longer needed, the Master reports its name to the Owner. Branch cleanup is Owner-operated; do not delete branches automatically or add branch-deletion automation unless the Owner explicitly changes this policy later.

Do not insert a human checkpoint between ordinary reversible steps. When the current objective is authorized and READY work exists, continue implementation, targeted validation, PR maintenance, self-review/correction, and dependency-safe follow-on task selection. Stop only for a real blocker/decision/capability boundary or for the specific action that is explicitly approval-gated.

## Execution and validation routing

Use [`docs/development/execution-infrastructure.md`](docs/development/execution-infrastructure.md) for the canonical boundaries and lifecycle of the connected GitHub integration, `AI_Server_Agent` workspace, GitHub Actions self-hosted runners, external Workers, and staging/deployment targets.

Use [`docs/06-test-strategy.md`](docs/06-test-strategy.md) for the canonical validation plan, evidence freshness, reproducible verification commands, MariaDB/Redis requirements, and CI failure policy.

Use [`docs/09-deployment-runbook.md`](docs/09-deployment-runbook.md) only for deployment, backup/restore, update/rollback, protected Environments, and privileged/live runtime operations.

A capability being technically reachable does not change its authority/safety boundary. GitHub remains project truth; execution workspaces and runner checkouts remain execution state.

### GitHub Actions and secrets

Workflow YAML for the exact revision is authoritative for job `permissions:`, `runs-on`, secrets and Environment use. Do not infer permissions or runner capability from repository defaults, a runner display name, or historical runs.

Secret values are write-only operational state. Never request or paste a PAT, SSH key, password, provider key, bot token, `.env`, registration/remove token, or other secret into Chat, Git, Issues, PRs, logs, screenshots, or repository evidence.

## Task and PR contracts

Use `.github/ISSUE_TEMPLATE/task.yml` for bounded engineering work. The Issue needs enough information to implement and review safely: parent/requirements, outcome, dependencies, bounded scope, objective acceptance criteria, validation strategy, risk, and initial state.

Add security/data/financial/provider/schema/runtime/compatibility/protected-area detail only when it matters. Do not create fields or documents merely to say `N/A`, repeat GitHub state, or preserve test logs already available from CI.

After verified integration/closure, reconcile any acceptance checklist or explicit mutable current-state text in the owning Issue. Keep exact commit/run/review receipts in GitHub comments, PRs, and CI rather than copying them into repository status documents; an `Initial state` field remains historical contract input and does not need rewriting.

Pull requests use `.github/pull_request_template.md`: link the Issue, summarize the change, state material risk/impact, and provide the focused verification or applicable CI result. Record a nonclaim only when adjacent scope could otherwise be misunderstood.

Sensitive paths are assigned in `.github/CODEOWNERS`. CODEOWNERS identifies intended ownership; actual merge enforcement depends on repository protection/rulesets. Verify live repository settings before relying on protection as an enforced fact.

High/Critical work involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations requires independent review and explicit Owner approval before merge unless that exact merge/action was already authorized. This gate does not by itself block reversible implementation, testing, review preparation, or continuation to another dependency-safe READY task.

## Independent review relay

When independent review is required, the permitted project dispatch mechanism is an **Owner-relayed fresh ChatGPT chat**.

1. The authoring Master completes applicable exact-head validation and effective-diff self-review, then gives the Owner one ready-to-paste `INDEPENDENT REVIEW CHAT` prompt.
2. The prompt identifies the repository/PR/change, exact integration target/base SHA, exact candidate HEAD SHA, owning Issue/contract and acceptance criteria, risk level, material review boundaries/invariants, and validation evidence tied to that exact candidate.
3. The Owner opens a new ChatGPT chat and pastes the prompt. That fresh chat reviews read-only and returns the exact candidate SHA reviewed, a verdict of `APPROVE` or `CHANGES_REQUIRED`, and evidence-backed findings classified as `BLOCKER`, `REQUIRED`, or `OPTIONAL`.
4. The Owner relays the complete review result back to the authoring Master. The Master reconciles every finding and refreshes candidate/target/CI/review freshness before relying on the review or integrating.
5. Candidate, target, contract, or material effective-diff drift invalidates the affected independent review; generate a new exact review packet rather than reusing a stale verdict.

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

`composer test:quick` is fast feedback only and does not prove migrations, constraints, triggers, locking, or concurrency. MariaDB 10.11 remains the mandatory normal integration target when application/database semantics are affected; additional versions are explicit compatibility evidence, not an automatic per-PR matrix.

## Coding and architecture

Repository architecture/correctness rules are canonical in `AGENTS.md` and `docs/05-architecture-overview.md`. Follow the owning module and preserve accepted behavior outside task scope. Do not create a second coding-rule catalog here.

## Documentation

Durable documentation ownership and the intentionally small documentation set are defined by [`docs/README.md`](docs/README.md). Live state belongs in GitHub; task history belongs in Git/Issues/PRs/CI. Update the canonical owner of a rule instead of adding a compensating copy elsewhere.
