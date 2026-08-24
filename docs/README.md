# Documentation

The repository intentionally keeps a small documentation set. Git history and GitHub PR/Issue/CI history are the archive; `docs/` is for durable knowledge needed by future development and operation.

## Product contract

- [`specification/master-execution-prompt.md`](specification/master-execution-prompt.md) — normative Version 1 product/security/correctness specification; repository execution mechanics are governed by `AGENTS.md` and `CONTRIBUTING.md`.
- [`01-authoritative-requirements.md`](01-authoritative-requirements.md) — stable requirement-ID index used by Issues, code annotations, tests, and reviews.
- [`04-domain-glossary.md`](04-domain-glossary.md) — canonical domain and Persian terminology.

## Engineering reference

- [`05-architecture-overview.md`](05-architecture-overview.md) — durable architecture and correctness boundaries.
- [`06-test-strategy.md`](06-test-strategy.md) — required testing/CI semantics, compatibility and evidence rules.
- [`07-security-threat-model.md`](07-security-threat-model.md) — security boundaries and required controls.
- [`08-data-classification.md`](08-data-classification.md) — sensitive-data handling and retention constraints.
- [`03-risk-register.md`](03-risk-register.md) — durable cross-project risks/decisions that remain relevant beyond one task.
- [`development/execution-infrastructure.md`](development/execution-infrastructure.md) — GitHub/MCP/Actions execution boundaries, toolchain ownership, and self-hosted runner lifecycle/qualification.
- [`development/repository-map.md`](development/repository-map.md) — source/module navigation.
- [`adr/`](adr/) — durable architecture decisions only.

## Operations

- [`09-deployment-runbook.md`](09-deployment-runbook.md) — deployment, backup/restore, update/rollback safety contract. Commands are executable only when the referenced implementation exists and the release task explicitly authorizes them.

## Current state and recovery

There is intentionally no repository project-status or infrastructure-inventory snapshot.

Recover current delivery state from live GitHub:

1. Program Issue `#3` for Version 1 phase/backlog/dependencies;
2. Draft integration PR `#6` for the current integration line toward `main`;
3. the active phase Issue and specific task/PR;
4. exact-head workflow checks for validation/review state.

`README.md` and `AGENTS.md` provide stable navigation and rules. GitHub owns mutable priority, status, blockers, assignments, SHAs, PR/review state, CI state, and live runner inventory.

## Evidence

Task-level verification belongs in commits, PRs, Issues, reviews, and GitHub Actions. See [`../evidence/README.md`](../evidence/README.md).

Repository evidence files are reserved for release-candidate/release records whose essential metadata must outlive workflow retention and have a real future consumer.

## Documentation rules

1. One kind of durable truth has one canonical owner; other documents link to it instead of copying it.
2. Do not create per-task handoff, overlay, traceability, risk, current-state, infrastructure-inventory, or evidence documents.
3. Do not copy mutable SHAs, CI runs, test counts, Worker state, branch inventories, runner display names, host addresses, credentials, or live runner inventory into durable docs.
4. Update a canonical reference only when its durable contract changes; operational/live state stays in GitHub or the operating system/service that owns it.
5. Delete obsolete coordination/history files from the active tree when Git/GitHub already preserves the history.
6. Target-state requirements must never be presented as implemented behavior.
7. New operational commands must be tied to actual source and verified before being described as executable.
8. A new manager/engineer should recover the project from `README.md` -> `AGENTS.md` -> Program Issue `#3` / active task -> only the canonical reference for the decision at hand.
