# Testing and CI Contract

This document defines durable verification requirements: which checks are required, what they prove, and when evidence must be refreshed. Live run IDs, test counts, current failures, runner inventory, and repository setting state belong in GitHub.

Execution tools, standard GitHub-hosted CI routing, and optional self-hosted runner provisioning/qualification/lifecycle are owned by [`development/execution-infrastructure.md`](development/execution-infrastructure.md).

## Execution model

GitHub is the project source of truth. No Owner-maintained local or server checkout is assumed.

Ordinary runtime/CI commands execute through reviewed GitHub Actions on standard GitHub-hosted Linux runners. An Actions checkout is transient execution state for an exact GitHub revision, not a second source repository.

The exact `runs-on` selector in each workflow revision is authoritative for runner routing. Generic CI must use the pinned standard GitHub-hosted runner defined by `.github/workflows/ci.yml`; manual staging/provider workflows may retain explicit self-hosted selectors only as dormant/trusted operational contracts. Workflow/toolchain checks must validate the effective environment rather than assuming host-specific paths.

## Using CI

- A non-Draft PR targeting `main` triggers the applicable CI tier.
- Draft PRs stay quiet until marked Ready for review.
- `.github/workflows/ci.yml` defines intentional manual validation through `workflow_dispatch`, but a definition is callable only when current GitHub/default-branch registration exposes that workflow.
- A workflow file that exists only on an integration/task branch is code under review, not proof of a standing execution entrypoint.
- Historical Actions registry entries or old successful runs do not prove that a workflow is currently callable. Verify the current default-branch workflow tree/registration before depending on an invocation path.
- Runtime/readiness/provider workflow definitions remain narrow operational capabilities and are not substitutes for normal CI.

If the Master cannot execute MariaDB/Docker/shell work directly, that is not itself a blocker. Persist reversible work on GitHub and use the standard GitHub-hosted Actions path for authoritative generic runtime evidence when the current GitHub capability can invoke it. If the current Chat connector cannot invoke a required validation run, continue independent reversible implementation/review work and surface that invocation boundary before the decision that actually consumes exact-head evidence. Do not weaken Draft/merge/release policy merely to work around a tool limitation.

Delegate to an external Worker only when that materially improves execution or review.

## CI validation plan

CI classifies the complete effective change into independent validation needs. The profile name is only a summary; the job flags are authoritative. Unit, style, static/architecture, dependency/license, real-engine integration, runtime and operations are separate validation dimensions. Secret scanning remains mandatory for every executing merge-acceptance CI revision.

**Validation proportionality is a repository invariant.** Normal CI must select the narrowest high-signal validation that can falsify the behavior affected by the exact candidate. A broader job or test scope is justified only when the changed surface is cross-cutting, engine-dependent, changes the validation/toolchain contract, is ambiguous/unclassified, or is entering an explicit FULL/release backstop. Unknown evidence always fails closed to broader validation; known narrow surfaces must not fan out merely because a broader check exists. New CI jobs, path rules, matrices or automatic triggers must include a concrete assurance reason and regression coverage preventing accidental fan-out.

For a PR, the changed-path source is the exact checked-out merge candidate against its base parent. Manual `workflow_dispatch` without a diagnostic filter is an intentional FULL-validation route. Generic CI does not automatically rerun an already accepted integration after merge to protected `main`: the required PR gate validates the exact merge candidate and revalidates current head/base freshness after all applicable jobs, while the active branch ruleset prevents bypass integration. If that protection model changes, the no-rerun assumption must be re-evaluated before relying on it.

A `workflow_dispatch` with a non-empty `phpunit_filter` is different: it is an explicit troubleshooting route, not merge/release acceptance. Its validation plan is limited to the runtime contract plus the filtered real MariaDB/Redis integration execution and diagnostic evidence. It does not manufacture unrelated Unit/static/dependency/operations work, and its existing Secret scan/final required merge gate remain intentionally disabled so a successful diagnostic run cannot be mistaken for acceptance. A diagnostic filter that selects zero tests fails under the shared PHPUnit `failOnEmptyTestSuite` policy. An empty/missing filter falls back to the intentional manual FULL route.

The ruleset-required `Repository preflight` check is the final aggregate CI gate, not the early classifier job. It succeeds only after `Validation plan and repository control`, `Secret scan`, and every validation domain selected by the computed plan have succeeded; non-applicable jobs may be skipped. This keeps documentation/control-only changes fast while preventing an application PR from becoming merge-eligible before its required Unit/PHP/MariaDB/dependency/operations checks finish successfully.

Normal automated CI separates two questions that must not be conflated:

1. **Does this change require the real MariaDB/Redis engine?** Migrations, constraints, triggers, locking/concurrency and other engine-dependent behavior still require real-engine acceptance.
2. **How much Feature coverage is required for this exact PR?** Real-engine necessity does not automatically mean the complete unrelated Feature corpus must run.

The quality runner owns Unit tests together with applicable Pint/static/architecture checks so PHP setup and dependency installation are not duplicated in the real-engine job. Normal PR Pint validation uses Pint's Git diff scope against the merge candidate base so unchanged PHP files are not rescanned. FULL profiles, unfiltered manual FULL validation, unavailable/ambiguous PR ancestry, and global style-policy changes such as `.editorconfig` or `pint.json` retain whole-repository Pint. PHPStan, forbidden-pattern and architecture checks remain repository-wide when selected because their cross-module dependency surface has not been proven safe to narrow.

When integration is required, the MariaDB/Redis job uses one of these scopes:

- **TARGETED** — only when the repository selector can prove a bounded relationship from the candidate itself. Current accepted shapes are Feature-test-only changes and an entirely additive new module (the module does not exist in the base tree, its product files/migrations are additive, and changed Feature tests explicitly reference that new module). The exact changed Feature file(s) run against MariaDB 10.11 + authenticated Redis.
- **FULL** — the fail-safe fallback for existing-module product changes, modified/deleted migrations, global/config/runtime/dependency surfaces, ambiguous relationships, missing Feature evidence, or anything the selector does not explicitly understand. The complete Feature suite runs against MariaDB 10.11 + authenticated Redis.
- **DIAGNOSTIC** — an explicit filtered manual troubleshooting run. It is evidence for the selected diagnostic only and never merge acceptance by itself.

Normal PR and filtered diagnostic integration fail fast on the first PHPUnit error/failure. This changes only failed-run latency and diagnostics: a green TARGETED or FULL run still executes every test in its selected scope. Unfiltered manual FULL validation intentionally keeps the complete PHPUnit + coverage path as the explicit regression backstop.

The selector is deliberately conservative. It must not infer safety merely from similar filenames or module proximity, and it must fall back to FULL whenever the relationship is not directly evidenced. Narrowing the selector to additional product shapes requires regression tests proving both the new targeted case and representative fail-safe fallbacks.

| Changed behavior | Material validation |
|---|---|
| canonical docs / governance | affected planning/project-control checks |
| readiness/provider workflow definitions | project/control contract + mandatory Secret scan; privileged provider execution remains separately manual/gated and is never implied by CI |
| PHP application/config/schema | Unit + changed-PHP Pint + PHPStan/forbidden/architecture + MariaDB 10.11/Redis integration; TARGETED only when the conservative selector proves the candidate shape, otherwise FULL Feature |
| localization catalogs under `resources/lang/**` | Unit catalog contract + changed-PHP Pint; no MariaDB/Redis solely for localization data |
| Unit tests only | Unit + changed-PHP Pint; no MariaDB/Redis provisioning |
| Feature tests only | changed-PHP Pint + the changed Feature file(s) on MariaDB 10.11/Redis; unrelated Feature files are not rerun solely because a test file changed |
| Composer manifest/lock | Unit + Composer validation/audit/license + static/architecture + FULL real-engine Feature integration |
| `docker-compose.ci.yml` or `docker/mariadb/ci-init.sql` | Docker/runtime contract + FULL MariaDB 10.11/Redis Feature integration; unrelated Unit/static/dependency/operations jobs are not implied |
| operational/deployment entrypoints | shell/PHP operational syntax/entrypoint validation; separate High/Critical review/release gates still apply |
| unknown/unclassified | fail safe to every normal validation domain and FULL real-engine scope when integration applies |

Readiness/provider workflow definitions are control-plane surfaces, not application behavior. `staging-readiness.yml` retains its dedicated adversarial read-only verifier; provider workflow guards, runner selectors, concurrency and protected-operation constraints are enforced by the canonical project-control verifier. `provider-live-acceptance.yml` remains a privileged manual capability with its separate High/Critical review/Owner gates; classifying a YAML definition change as control-plane validation does not authorize or execute the live workflow. No wildcard `.github/workflows/*` downgrade exists.

Changes to the CI classifier/workflow/real-engine selector are self-modifying control-plane changes: representative classifier, selector and verifier-abuse cases run independently of the application integration result. A CI-policy change does not manufacture a MariaDB run when the effective diff cannot affect application/database behavior. Conversely, application/schema/database semantics that need the engine still require MariaDB 10.11 acceptance, but that acceptance may be TARGETED when the conservative selector proves a bounded candidate; engine requirement no longer implies an unconditional full Feature run.

The normal integration target is MariaDB 10.11 with authenticated Redis. Other compatible MariaDB lines are explicit task/release compatibility evidence, not an automatic matrix on every PR.

## Workflow permissions

Each workflow's explicit `permissions:` block is authoritative for its `GITHUB_TOKEN` access. Grant only the capability the workflow requires. Repository-local automation should prefer repository-scoped `GITHUB_TOKEN`; do not introduce a PAT merely to replace a capability that `GITHUB_TOKEN` already provides.

Repository settings/protection endpoints that the connected GitHub App cannot read must be treated as **unknown**, not as enabled or disabled by assumption. Use observable branch/API state, workflow source, actual run/job evidence, and Owner-visible settings when a decision truly depends on those controls.

## Evidence reuse and reruns

A green applicable validation plan proves the tested PR revision while the resulting tree remains materially unchanged. Revalidate after a base advance, conflict resolution, post-test edit, dependency/runtime change, or other material difference. PR concurrency cancels superseded safe runs, and preflight rejects a run that starts after the event's exact base/head has already drifted. Integration still refreshes exact candidate, target, mergeability and applicable checks immediately before merge.

For an unchanged revision:

- rerun a clearly transient failed job only when appropriate;
- do not rerun successful jobs without a concrete reason;
- fix deterministic failures instead of repeatedly rerunning them.

For an evolving candidate after a deterministic failure:

- return/keep the PR Draft while remediation is still changing the candidate;
- reproduce the failure with the narrowest authoritative test or filtered diagnostic that can distinguish the fix;
- do not launch broad merge-acceptance validation after every mechanical/style/static correction;
- mark the PR Ready again only when the candidate is stable enough for its applicable exact-head acceptance plan.

Routine successful checks are GitHub evidence. Do not manufacture per-task evidence files or large success artifacts.

## Reproducible verification commands

These commands may run in a reviewed Actions checkout or an explicitly delegated Worker environment. They do not imply an Owner local checkout.

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
bash scripts/ci/verify-planning.sh
bash scripts/ci/verify-project-control.sh
bash scripts/ci/test-validation-plan.sh
bash scripts/ci/test-feature-integration-scope.sh
php vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer validate --strict --no-check-publish
bash scripts/ci/forbidden-patterns.sh
bash scripts/ci/architecture.sh
composer audit --locked --abandoned=fail
bash scripts/ci/licenses.sh
composer test:quick
composer test:integration
```

Additional MariaDB compatibility can be requested explicitly, for example:

```bash
MARIADB_VERSION=11.4 composer test:integration
```

`composer test:quick` runs the Unit suite only and is fast feedback, not real-engine acceptance. `composer test` aliases `composer test:integration`; both provision disposable MariaDB/Redis dependencies and run the local full Unit + Feature suite. Normal PR CI may run a conservative TARGETED Feature set on the same real engine when the candidate itself proves that scope; otherwise it runs FULL Feature. Unfiltered manual FULL remains the complete regression/coverage backstop. MariaDB remains required for migrations, constraints, triggers, locking, concurrency and other engine-dependent acceptance.

The executable PHP/Composer runner contract is enforced by `scripts/ci/bootstrap-ci-toolchain.sh`; do not duplicate its exact extension/version checks here.

## Test design rules

- Redis coordination loss must not violate durable correctness.
- Time, randomness, external I/O, and providers should be deterministic/injectable in tests.
- Critical paths cover success, validation, authorization, exact replay, conflicting replay, concurrency/database conflict, and failure/uncertainty boundaries as applicable.
- Monetary tests use integer IRR or fixed-precision decimal, never floating-point money.
- Fakes prove local orchestration semantics only; they do not prove live provider compatibility.
- A test belongs in Unit only when it does not require real database/Redis semantics. Engine-dependent behavior belongs in Feature or an equally explicit real-engine acceptance surface.

## Required invariant coverage

When applicable, tests must prove:

- no paid provisioning before authoritative capture;
- duplicate callbacks/jobs/actions create no second durable effect;
- one provider transaction/redeemable value cannot settle twice;
- uncertain remote mutation is reconciled before retry;
- financial posting remains balanced and append-only;
- authorization is checked server-side at execution time;
- restricted data is not exposed through repository evidence.

## Failure policy

Do not suppress, weaken, quarantine, or repeatedly rerun a genuine deterministic failure until green. Financial, authorization, idempotency, schema, security, backup/restore, and release-integrity failures are blocking. Normal PR/filtered integration stops on the first error/failure so the actionable root cause is surfaced promptly instead of consuming the full timeout on cascading errors; this does not reduce successful-run coverage.
