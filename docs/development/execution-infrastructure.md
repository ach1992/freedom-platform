# Development Execution Infrastructure

This document is the canonical repository reference for **how development/runtime work is executed** and for the lifecycle of the tools that provide that execution capacity. It is not a live inventory or status page.

GitHub remains the project source of truth. Machine state, runner availability, current connection state, temporary credentials, CI run IDs, and host-specific diagnostics remain operational/live state and must not be copied here.

## Responsibility map

| Capability | Canonical owner | What it owns |
|---|---|---|
| Project/source state | GitHub repository, Issues, PRs, refs, checks | code, current work, integration/review/CI truth |
| Agent behavior and repository safety | [`../../AGENTS.md`](../../AGENTS.md) | durable rules for humans/AI agents |
| Development workflow | [`../../CONTRIBUTING.md`](../../CONTRIBUTING.md) | branches, PRs, validation workflow, review/merge conventions |
| Execution infrastructure | **this document** | execution tools, hosted-CI boundary, access boundaries, self-hosted runner lifecycle/qualification |
| Test/CI semantics | [`../06-test-strategy.md`](../06-test-strategy.md) | which checks are required and what evidence they prove |
| Deployment/runtime operations | [`../09-deployment-runbook.md`](../09-deployment-runbook.md) | deployment, backup/restore, update/rollback and privileged runtime operations |
| Exact PHP/Composer runner contract | [`../../scripts/ci/bootstrap-ci-toolchain.sh`](../../scripts/ci/bootstrap-ci-toolchain.sh) | runner-neutral executable PHP version/extensions/PCOV/Composer/JIT checks |
| Exact runner routing | the `runs-on` selector in each workflow revision | labels required for that job at that revision |

When information of one kind appears in more than one place, keep the rule only in its canonical owner and replace the duplicate with a link.

## Execution boundaries

### Connected GitHub integration

Use the connected GitHub integration as the normal project-control path when its current capabilities can perform the operation. It is appropriate for repository reads/writes, Issues, PRs, refs, reviews, and Actions evidence/actions exposed by the integration.

It does not make Chat state authoritative, does not expose secret values, and does not replace runtime validation that requires an actual PHP/Docker/MariaDB/Redis environment.

### Dedicated AI Server workspace

When `AI_Server_Agent` is connected, reuse the repository-scoped workspace before asking the Owner to recreate shell access:

- workspace: `/srv/ai-workspace/freedom-platform`;
- normal user: `aiworker`.

The workspace is persistent execution/cache state, not project authority. Durable changes must return to GitHub. Never read or expose its SSH private key or other credentials.

Use the repository's currently configured Git remote after verifying it. Remote names, SSH host aliases, and credential wiring are workspace-operational state and are not a durable project contract.

Ordinary unprivileged Git/edit/diagnostic commands may use this workspace. Host-wide package/service/firewall/user changes, production/deployment mutation, credential changes, or other privileged operations retain their normal authorization and safety gates.

### GitHub-hosted generic CI

GitHub Actions is the authoritative reviewed runtime/CI path for repository jobs that need real PHP/Composer/Docker/MariaDB/Redis execution. Ordinary repository CI uses the exact standard GitHub-hosted Linux image pinned by `.github/workflows/ci.yml`. Each generic job must provision only the toolchain it needs, and `scripts/ci/bootstrap-ci-toolchain.sh` verifies the effective PHP/Composer/PCOV/JIT contract after provisioning. Host-specific aaPanel paths are compatibility inputs for a future private self-hosted route, not assumptions of generic CI.

Public fork pull requests may execute generic CI only on GitHub-hosted runners. Their head revision/freshness and secret-scan range must be resolved against the actual PR head repository; generic CI must not depend on a same-repository-only guard that existed solely to protect self-hosted hosts.

### Optional self-hosted runners

Self-hosted runners remain a supported capability for a future private repository or for a separately trusted private operational boundary. Self-hosted runner display names are inventory only, never a workflow contract. Jobs route by labels. The exact `runs-on` selector in the workflow revision is authoritative; do not copy custom label strings into multiple documents or use display names as routing dependencies.

A label is a capability claim, not proof. A runner receives a workload capability label only after qualification for that workload. A runner does not need to support every workload. When this source repository is public, repository-level self-hosted runners must remain disconnected; retaining host installations for later private re-registration is allowed, but a dormant installation is not an active workflow capability.

### External coding/review workers

External Workers are optional capacity. Use them only when isolation, safe parallelism, specialist expertise, independent review, or a missing execution capability materially justifies delegation. They never become project authority and durable results must return to GitHub for Master verification.

The repository has no dependency on a particular external Worker product.

### Staging/deployment targets

Deployment/staging hosts are runtime targets, not developer checkouts or project-recovery sources. Privileged/live operations are governed by the owning task/release and [`../09-deployment-runbook.md`](../09-deployment-runbook.md), not by the existence of shell access.

## Toolchain ownership

Avoid maintaining a handwritten copy of executable tool requirements when the repository already verifies them. A runner needs only the tools/runtime required by the workload capabilities it claims; do not install a database daemon, Docker daemon, PHP runtime, or other privileged capability merely to make all runners identical.

Common generic-CI expectations are provided by the pinned GitHub-hosted Ubuntu image plus the setup steps in the workflow. For any future self-hosted job, minimum expectations remain supported 64-bit Linux/x64, Git, Bash, `jq`, outbound access required by pinned Actions/dependency sources, and sufficient filesystem capacity for the intended workload.

Additional requirements are capability-scoped:

- **Repository-control / secret-scan work:** use the exact current workflow as authority. Current repository preflight can validate a changed Docker Compose contract with `docker compose ... config`, so a compatible Docker CLI/Compose parser is required when that path can route to the host; that check alone does not require access to a Docker daemon or permission to start containers. Pinned Actions may also bring their own executable tooling.
- **PHP/static/dependency/operational work:** requires the PHP/Composer environment accepted by `scripts/ci/bootstrap-ci-toolchain.sh` plus only the commands used by the current job definitions.
- **Database integration work:** requires the accepted PHP/Composer contract plus Docker Engine/daemon access and Compose sufficient to create and remove the repository's disposable MariaDB/Redis dependencies safely.
- **Staging-runtime / provider-acceptance work:** the exact workflow revision owns any additional host topology, network, trust, secret, approval, or tool requirements. Do not infer those capabilities from ordinary CI eligibility.

The bootstrap script is authoritative for exact PHP 8.4 extensions, PCOV mode, Composer version, JIT state, and runtime paths. If those executable requirements change, update the script and tests first; documentation should describe the boundary rather than duplicate every checked value.

## Runner security boundary

A self-hosted runner executes repository workflow code on the host and is not an ephemeral clean VM. Treat runner eligibility as a security and operations boundary. In particular, do not keep a repository-level self-hosted runner connected while this repository is public; public pull requests must use GitHub-hosted generic CI. For a future private-repository self-hosted route:

- use a dedicated unprivileged runner service account;
- do not store production/customer secrets or private backups in the runner workspace;
- do not disable TLS verification;
- do not expose a general-purpose root shell through a workflow;
- do not make untrusted/fork PR code automatically execute on an owner-controlled runner;
- use only the workflow permissions/secrets/environments needed by that exact job;
- grant Docker/daemon, host mount, network, secret, or privileged access only when the claimed workload explicitly requires it and the host trust boundary permits it;
- do not treat a registration token, remove token, PAT, SSH key, `.env`, or other credential as documentation/evidence.

## Runner capability qualification

Hardware eligibility is workload-based, not provider-, hostname-, CPU-model-, or vCPU-count-based. The pool does not require identical hardware and every registered runner does not need to qualify for every job.

For the current database-integration workload:

- **Memory:** maintain enough physical-memory headroom to complete the representative suite without sustained swap or memory pressure. About 2 GiB is the current practical lower bound observed for this workload, while 4 GiB or more is preferred when unrelated services share the host; these values are diagnostic guidance, not independent proof of capability.
- **CPU:** per-core performance matters because material parts of the suite are single-threaded. Nominal vCPU count alone is not qualification evidence.
- **Storage:** stable synchronous-write latency matters more than headline sequential throughput.
- **Safety margin:** the complete MariaDB 10.11 + authenticated Redis integration suite must pass under normal host load with at least one-third of the existing workflow timeout remaining.
- **Stability:** success must not depend on stale CI containers, accumulated workspace state, sustained swap pressure, abnormal CPU steal, or competing workload spikes.

The unchanged representative workload is the definitive integration-qualification gate. Synthetic benchmarks and nominal hardware thresholds are diagnostic evidence only. A machine that does not meet integration qualification may still receive lighter workload capability labels if it separately satisfies those jobs.

Do not weaken tests, durability, or timeouts to make a host appear qualified.

## Public/private visibility boundary

Before changing this repository from private to public, verify that generic CI is already GitHub-hosted, full public refs/history pass secret policy, retained Actions logs/artifacts have no unacceptable sensitive exposure, and every repository-level self-hosted runner is disconnected. Public visibility is not a confidentiality-reversible operation: switching back to private later does not erase clones or already-public forks.

If the repository becomes private again, the Owner may re-register retained self-hosted hosts and restore explicit self-hosted `runs-on` selectors through the normal reviewed CI-control change. Re-registration must requalify current toolchain/runtime/security assumptions; old labels or prior success are not proof after a long disconnected period.

## Add or replace a runner

1. **Determine intended capability.** Inspect current workflow `runs-on` selectors and decide which workload classes the host should support. Do not copy labels from an old host by habit.
2. **Prepare only the required boundary.** Create/use a dedicated unprivileged service account and install the base/tool requirements needed by the intended workload. Do not grant Docker/privileged/runtime access merely because another capability uses it.
3. **Register using current GitHub instructions.** Open repository **Settings -> Actions -> Runners -> New self-hosted runner**, choose the actual OS/architecture, and run the download/configuration commands GitHub displays. The runner release and registration token are live/time-sensitive values and must not be persisted in docs, Chat, Issues, or PRs.
4. **Choose an operational display name.** Use a unique human-readable name only for inventory. Workflows must not depend on it.
5. **Install the runner as a service.** On systemd Linux use the runner package's `svc.sh` interface from its installation directory and verify it runs as the intended unprivileged account and returns after reboot.
6. **Qualify before normal routing.** Verify the exact toolchain, daemon/runtime access (only when that workload requires it), resource headroom, trust/network boundary, and representative validation for the intended capability. Keep the host out of stronger-capability routing until it passes.
7. **Assign only proven custom capability labels.** Preserve truthful default OS/architecture labels and add only the exact current custom labels required by workflows the host has qualified to execute.
8. **Verify routing and completion.** Run the narrowest safe repository validation that proves the intended job can land on and complete on the host with the required safety margin.

A replacement runner is qualified like a new runner. Custom capability labels do not become trusted merely because another machine previously used the same labels.

## Quarantine a runner

Quarantine is preferred over removal when the host may be repaired or requalified.

1. Confirm no GitHub Actions job is active on the runner.
2. Remove the workload capability label that makes it eligible for the affected route, or stop the runner service when the entire host must be offline.
3. Verify in repository **Settings -> Actions -> Runners** that it cannot receive the affected jobs.
4. Diagnose/repair the host without changing product correctness requirements or weakening CI.
5. Re-run qualification before restoring the capability label/service eligibility.

Do not hard-pin a workflow to another runner display name as a workaround.

## Remove a runner

1. Confirm no job is active and that required workload classes retain sufficient healthy capacity.
2. Quarantine the runner from normal routing first.
3. In repository **Settings -> Actions -> Runners**, select the runner and choose **Remove**. Use the current removal command/token GitHub displays on the host; do not persist that token.
4. If the host is permanently unavailable, use GitHub's supported force-removal path rather than fabricating local cleanup evidence.
5. Verify the registration is absent from GitHub before deleting the runner installation or repurposing the host.
6. Remove host files/services only after their ownership is known. Broad `docker system prune`, runner `_work` deletion, or filesystem cleanup is not a substitute for registration removal.

## Requalification and health failures

Requalify after a material VM resize/migration, storage-class change, OS/toolchain rebuild, repeated timeout, trust/network change relevant to the claimed capability, or other evidence that may invalidate the runner's capability claim.

When the same exact repository revision behaves materially differently across runners, investigate infrastructure before changing product code. Compare only decision-relevant signals such as CPU performance/steal, memory/swap pressure, synchronous storage latency, Docker/stale CI resources when applicable, and competing workloads.

Clean only resources proven to belong to finished CI work and only while no job is active. A repeated timeout is not a reason to increase timeout, skip tests, or blindly rerun unchanged work.

## What belongs outside this document

Keep these as live operational/GitHub state instead of durable documentation:

- current runner count, display names, hostnames, IP addresses, cloud providers/plans, online/offline/busy state;
- current custom-label assignments and which physical host has them;
- registration/remove tokens, runner package download URLs, PATs, SSH keys and secret values;
- local Git remote names, SSH host aliases, credential wiring and other replaceable workspace configuration;
- transient performance measurements, current CI run IDs/test counts/failures and diagnostic snapshots;
- temporary repair/quarantine status.

Persist a new tool/execution path here only when it becomes a durable supported project capability with a clear owner and boundary. Remove its section when the capability is intentionally retired; Git/GitHub history is sufficient historical record.
