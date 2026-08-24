# Testing and CI Contract

This document defines durable verification requirements and the lifecycle contract for repository self-hosted runners. Live run IDs, test counts, current failures, runner inventory, display names, host addresses, and repository setting state belong in GitHub.

## Execution model

GitHub is the project source of truth. No Owner-maintained local or server checkout is assumed.

The active ChatGPT Master normally self-executes repository work through the connected GitHub integration. Runtime commands execute through reviewed GitHub Actions on owner-controlled self-hosted runners. External Workers are optional when isolation, parallelism, specialist review, or a missing capability materially justifies delegation; Codex Cloud is not a required/default execution path.

An Actions checkout is transient execution state for an exact GitHub revision, not a second source repository.

## Self-hosted runner contract

Every executing repository workflow job uses an owner-controlled self-hosted runner. The exact `runs-on` selector in the workflow revision being executed is the routing authority. Durable documentation intentionally does **not** duplicate custom label strings or runner display names: changing a host, display name, or capability label must not require hunting through several documents.

A label is a capability claim, not proof. A runner is eligible for a job only when it matches every label in that job's `runs-on` selector. Runner display names are operational inventory only and must never be used as durable workflow routing dependencies.

GitHub-hosted runners are not a fallback.

### Base machine and toolchain requirements

A runner may receive repository routing labels only when all requirements needed by the jobs it can receive are true:

- supported 64-bit Linux on x64 hardware for the current Docker/service-container workflows;
- a dedicated unprivileged runner service account; workflow steps must not require general-purpose root access;
- the GitHub Actions runner application installed and managed as a service so the runner returns after a normal reboot;
- outbound TLS connectivity required by GitHub Actions and the repositories/package registries used by the workflow; TLS verification must not be disabled;
- Git, Bash, `jq`, Docker Engine with Compose, and the normal OS utilities used by repository scripts;
- the PHP/Composer environment accepted by [`../scripts/ci/bootstrap-self-hosted-toolchain.sh`](../scripts/ci/bootstrap-self-hosted-toolchain.sh). That script is authoritative for exact PHP 8.4 extensions, PCOV behavior, Composer version, JIT requirements, and effective runtime paths;
- enough local storage for transient checkouts, Composer dependencies, Docker images/containers, and diagnostic artifacts without relying on global cleanup during a job;
- no production credentials, customer data, subscription material, private backups, or other production-sensitive state in runner workspaces.

Do not copy the runner package version, registration token, remove token, PHP extension list, or machine-specific path inventory into this document. Use the current GitHub runner setup instructions and repository bootstrap script so those values have one live owner.

### Capacity and capability qualification

Hardware eligibility is workload-based, not vendor-, hostname-, or vCPU-count-based. A runner receives only the capability labels for workloads it has actually demonstrated it can execute reliably.

For normal application/database integration eligibility:

- **Memory:** 2 GiB physical RAM is the floor for the current repository workload; 4 GiB or more is preferred when the host also runs unrelated services. Swap is recovery headroom, not normal CI capacity.
- **CPU:** no CPU vendor or core-count requirement is durable. The suite contains materially single-threaded work, so per-core performance matters more than nominal vCPU count.
- **Storage:** no disk model is mandated. Durable synchronous-write performance and stable latency matter more than headline sequential throughput.
- **Safety margin:** the current complete MariaDB 10.11 + authenticated Redis integration suite must pass under normal host load with at least one-third of the workflow's existing timeout remaining. A host that merely finishes close to timeout is not performance-compatible.
- **Stability:** repeated runs must not depend on stale CI containers, accumulated workspace state, host swap thrashing, abnormal CPU steal, or unrelated resource spikes.

A host may still be useful for lighter validation even when it does not qualify for integration work. When workflows distinguish workload classes, route by semantic capability labels rather than by runner name. A weak runner must not receive a stronger capability label merely to increase apparent pool size.

Co-locating unrelated services on a runner host is not assumed or required. If it is done, those services must not expose production secrets to workflow code and the runner must still satisfy the same performance margin under normal co-located load.

### Adding or replacing a runner

Use this sequence so a newly registered machine does not become trusted merely because it is online:

1. **Determine intended capability.** Inspect the current workflow `runs-on` selectors and this document. Decide which job classes the host is expected to satisfy; do not invent labels from an old runner.
2. **Prepare the host.** Create/use a dedicated unprivileged service account, install the required base tools, and satisfy the current repository toolchain contract. Keep host-specific credentials and provisioning details outside Git.
3. **Register from GitHub's current instructions.** In repository **Settings -> Actions -> Runners -> New self-hosted runner**, select the actual OS/architecture and run the download/configuration commands GitHub displays. Those commands contain the current runner release and a time-limited registration token and therefore must not be copied into Chat, Issues, PRs, or durable docs.
4. **Use an operational name only.** Give the runner a unique display name useful to an operator. Do not make workflows depend on it. Keep GitHub's default OS/architecture labels truthful.
5. **Install as a service.** On systemd Linux use the runner-provided `svc.sh` service interface from the runner installation directory and verify the service is running. The service user must be the intended unprivileged runner account.
6. **Qualify before normal routing.** A new/rebuilt host should initially avoid normal workload capability labels where possible. Verify the repository toolchain, Docker access, available resources, and then run a controlled representative validation for the intended capability. If a temporary qualification label/path is required, remove it on failure rather than leaving an unqualified host in the normal pool.
7. **Grant only proven capability labels.** After qualification passes with the required safety margin, assign the exact current custom labels needed by the applicable workflow selector and verify the runner is `Idle` in GitHub. When replacing a runner, reassign required custom labels explicitly; do not assume they transfer from the old registration.
8. **Verify routing.** Run the narrowest safe repository validation that proves the intended job class can land on and complete on the host. Labels alone are never acceptance evidence.

Do not hardcode the GitHub runner release URL, registration/remove token, runner ID, machine IP, provider plan, hostname, or display name in repository docs or workflows.

### Temporarily quarantining a runner

Quarantine is preferred over deleting a runner when the host may be repaired or requalified.

1. Confirm the runner is not executing an active GitHub Actions job.
2. Remove the workload capability label that makes it eligible for the affected jobs, or stop the runner service when the whole host must be taken offline.
3. Verify in **Settings -> Actions -> Runners** that it is no longer eligible/online for the affected route.
4. Diagnose and repair the host without weakening workflow validation.
5. Re-run capability qualification before restoring the removed routing label or service eligibility.

Do not hard-pin a workflow to another runner display name as a quarantine workaround.

### Removing a runner

Permanent removal is an operational lifecycle action, not a source-code change:

1. Ensure no job is active and that removing the runner will not unintentionally leave a required capability with zero healthy capacity.
2. Quarantine it from normal routing first.
3. Open repository **Settings -> Actions -> Runners**, select the runner, choose **Remove**, and use the current removal command GitHub displays on the runner host. The generated remove token is time-limited and must remain private.
4. If the host is no longer accessible, use GitHub's force-removal path instead of fabricating local cleanup evidence.
5. Verify the runner registration is absent from GitHub before deleting the runner installation directory or repurposing the machine.
6. Remove host files/services only after registration and service state are understood. Do not use broad `docker system prune`, workspace deletion, or filesystem cleanup as a substitute for runner removal.

### Maintenance and runner-health failures

The GitHub runner application updates itself by default; the host OS and project toolchain remain owner-managed. Requalify a runner after a material VM resize/migration, storage-class change, OS/toolchain rebuild, repeated timeout, or other evidence that could change capability.

When one runner is materially slower than another on the same exact revision:

- classify runner/host capacity before changing product code;
- inspect CPU performance/steal, memory/swap pressure, synchronous storage latency, Docker state, stale CI resources, and competing workloads;
- clean only resources proven to belong to finished CI work and only while no job is active;
- do not raise job timeouts, skip tests, disable durability, or repeatedly rerun an unchanged timeout merely to obtain green status;
- quarantine a runner that cannot meet its claimed capability until repaired and requalified.

The pool needs enough qualified capacity for required job classes; it does **not** need every registered runner to have identical hardware or to qualify for every job.

## Using the runner

- A non-Draft PR targeting `develop/v1.0.0-completion` triggers the applicable CI tier.
- Draft PRs stay quiet until marked Ready for review.
- `.github/workflows/ci.yml` defines intentional manual validation through `workflow_dispatch`, but a definition is callable only when current GitHub/default-branch registration exposes that workflow.
- A workflow file that exists only on an integration/task branch is code under review, not proof of a standing execution entrypoint.
- Historical Actions registry entries or old successful runs do not prove that a workflow is currently callable. Verify the current default-branch workflow tree/registration before depending on an invocation path.
- Runtime/readiness/provider workflow definitions remain narrow operational capabilities and are not substitutes for normal CI.

If the Master cannot execute MariaDB/Docker/shell work directly, that is not itself a blocker. Persist reversible work on GitHub and use the self-hosted Actions path for authoritative runtime evidence when the current GitHub capability can invoke it. If the current Chat connector cannot invoke a required validation run, continue independent reversible implementation/review work and surface that invocation boundary before the decision that actually consumes exact-head evidence. Do not weaken Draft/merge/release policy merely to work around a tool limitation.

Delegate to an external Worker only when that materially improves execution or review.

## CI validation plan

CI classifies the complete PR diff into independent validation needs. The profile name is only a summary; the job flags are authoritative. Secret scanning remains mandatory for every executing CI revision.

| Changed behavior | Material validation |
|---|---|
| canonical docs / governance | affected planning/project-control checks |
| bounded read-only control plane | project/control contract + dedicated read-only workflow verifier |
| PHP application/config/schema | Pint + PHPStan/forbidden/architecture + MariaDB 10.11/Redis integration |
| PHP tests only | Pint + MariaDB 10.11/Redis integration |
| Composer manifest/lock | Composer validation/audit/license + static/application integration affected by dependency changes |
| Docker CI/runtime | Docker Compose contract + MariaDB 10.11/Redis integration |
| operational/deployment entrypoints | shell/PHP operational syntax/entrypoint validation; separate High/Critical review/release gates still apply |
| unknown/unclassified | fail safe to every normal validation domain |

`.github/workflows/staging-readiness.yml` is the only current workflow intentionally classified as bounded read-only control plane. Its verifier requires manual dispatch, read-only token permissions, no secrets/protected environment, the current repository runner selector, an allowlisted action surface and no known runtime/host mutation commands. The verifier itself has adversarial tests. No wildcard `.github/workflows/*` downgrade exists.

Changes to the CI classifier/workflow are self-modifying control-plane changes: representative classifier cases and verifier-abuse cases run independently of the classifier result. A CI-policy change does not manufacture a MariaDB run when the effective diff cannot affect application/database behavior; conversely any application/schema/database-affecting diff still requires MariaDB 10.11.

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

Routine successful checks are GitHub evidence. Do not manufacture per-task evidence files or large success artifacts.

## Reproducible verification commands

These commands may run in a reviewed Actions checkout or an explicitly delegated Worker environment. They do not imply an Owner local checkout.

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
bash scripts/ci/verify-planning.sh
bash scripts/ci/verify-project-control.sh
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

`composer test:quick` is fast feedback only. MariaDB is required for migrations, constraints, triggers, locking, and concurrency acceptance.

## Test design rules

- Redis coordination loss must not violate durable correctness.
- Time, randomness, external I/O, and providers should be deterministic/injectable in tests.
- Critical paths cover success, validation, authorization, exact replay, conflicting replay, concurrency/database conflict, and failure/uncertainty boundaries as applicable.
- Monetary tests use integer IRR or fixed-precision decimal, never floating-point money.
- Fakes prove local orchestration semantics only; they do not prove live provider compatibility.

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

Do not suppress, weaken, quarantine, or repeatedly rerun a genuine deterministic failure until green. Financial, authorization, idempotency, schema, security, backup/restore, and release-integrity failures are blocking.
