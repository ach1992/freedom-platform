# Project Documentation Index

This file is the canonical index for durable project documentation. The root `README.md` is the user-facing project entry point for what the product is, how to get started, and other information a user or adopter needs; it is not a project-state, recovery, governance, architecture, or engineering-authority document.

Git history and GitHub PR/Issue/CI history are the implementation archive. `docs/` contains durable product, engineering, security, testing, execution, and operations knowledge that future development and operation actually need.

## Product contract

- [`specification/master-execution-prompt.md`](specification/master-execution-prompt.md) — normative Version 1 product/security/correctness specification; repository execution mechanics are governed by `AGENTS.md`, `CONTRIBUTING.md`, the canonical engineering references, and live GitHub.
- [`01-authoritative-requirements.md`](01-authoritative-requirements.md) — stable requirement-ID index used by Issues, code annotations, tests, and reviews.
- [`04-domain-glossary.md`](04-domain-glossary.md) — canonical domain and Persian terminology.

## Engineering reference

- [`05-architecture-overview.md`](05-architecture-overview.md) — durable architecture and correctness boundaries.
- [`06-test-strategy.md`](06-test-strategy.md) — required testing/CI semantics, compatibility, and evidence rules.
- [`07-security-threat-model.md`](07-security-threat-model.md) — security boundaries and required controls.
- [`08-data-classification.md`](08-data-classification.md) — sensitive-data handling and retention constraints.
- [`03-risk-register.md`](03-risk-register.md) — durable cross-project risks/decisions that remain relevant beyond one task.
- [`development/execution-infrastructure.md`](development/execution-infrastructure.md) — GitHub/integration/Actions execution boundaries, hosted-CI toolchain ownership, and optional self-hosted runner lifecycle/qualification.
- [`development/repository-map.md`](development/repository-map.md) — source/module/runtime navigation.
- [`adr/`](adr/) — durable architecture decisions only.

## Operations

- [`09-deployment-runbook.md`](09-deployment-runbook.md) — deployment, backup/restore, update/rollback safety contract. Commands are executable only when the referenced implementation exists and the release task explicitly authorizes them.

## Development state and recovery

There is intentionally no repository project-status or infrastructure-inventory snapshot.

A new maintainer or replacement Master recovers the development system from:

1. [`../AGENTS.md`](../AGENTS.md) for repository authority, safety, execution, review, and continuity rules;
2. [`../CONTRIBUTING.md`](../CONTRIBUTING.md) for the development workflow;
3. [Program Issue `#3`](https://github.com/ach1992/freedom-platform/issues/3) for the active Version 1 phase/dependency state;
4. the active Phase Issue and, when one exists, the current bounded task/PR;
5. exact-head workflow checks and only the canonical reference needed for the decision at hand.

Historical Issues and PRs are evidence, not standing pointers to current work. GitHub owns mutable priority, status, blockers, assignments, branches/SHAs, PR/review state, and CI state. Operational systems own their live runtime inventory.

## Evidence

Task-level verification belongs in commits, PRs, Issues, reviews, and GitHub Actions. See [`../evidence/README.md`](../evidence/README.md) for the release-evidence directory policy.

Repository evidence files are reserved for release-candidate/release records whose essential metadata must outlive workflow retention and have a real future consumer.

## Documentation rules

1. One kind of durable truth has one canonical owner; other documents link to it instead of copying it.
2. Keep `README.md` user-facing. Do not use it as project recovery, architecture, governance, task-state, CI-state, or engineering-policy authority.
3. Do not create per-task handoff, overlay, traceability, risk, current-state, infrastructure-inventory, or evidence documents.
4. Do not copy mutable SHAs, CI runs, test counts, Worker state, branch inventories, runner display names, host addresses, credentials, or live runner inventory into durable docs.
5. Update a canonical reference only when its durable contract changes; operational/live state stays in GitHub or the operating system/service that owns it.
6. Delete obsolete coordination/history files from the active tree when Git/GitHub already preserves the history.
7. Target-state requirements must never be presented as implemented behavior.
8. New operational commands must be tied to actual source and verified before being described as executable.
9. A new manager/engineer should recover from `AGENTS.md` -> `CONTRIBUTING.md` -> Program Issue `#3` / active Phase/task -> only the canonical reference needed for the current decision.
