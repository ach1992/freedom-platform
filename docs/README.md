# Documentation

The repository intentionally keeps a small documentation set. Git history and GitHub PR/Issue/CI history are the archive; `docs/` is for durable knowledge needed by future development and operation.

## Product contract

- [`specification/master-execution-prompt.md`](specification/master-execution-prompt.md) — normative Version 1 specification.
- [`01-authoritative-requirements.md`](01-authoritative-requirements.md) — stable requirement-ID index used by Issues, code annotations, tests, and reviews.
- [`04-domain-glossary.md`](04-domain-glossary.md) — canonical domain and Persian terminology.

## Engineering reference

- [`05-architecture-overview.md`](05-architecture-overview.md) — durable architecture and correctness boundaries.
- [`06-test-strategy.md`](06-test-strategy.md) — mandatory testing, CI, and self-hosted runner contract.
- [`07-security-threat-model.md`](07-security-threat-model.md) — security boundaries and required controls.
- [`08-data-classification.md`](08-data-classification.md) — sensitive-data handling and retention constraints.
- [`03-risk-register.md`](03-risk-register.md) — durable cross-project risks and owner decisions that remain relevant beyond one task.
- [`development/repository-map.md`](development/repository-map.md) — source/module navigation.
- [`adr/`](adr/) — durable architecture decisions only.

## Operations

- [`09-deployment-runbook.md`](09-deployment-runbook.md) — deployment, backup/restore, update/rollback safety contract. Commands are executable only when the referenced implementation exists and the release task explicitly authorizes them.

## Current state

Current phase-level state is in [`../PROJECT_STATUS.md`](../PROJECT_STATUS.md). Live task, branch, PR, review, blocker, and CI state is in GitHub.

Do not add another current-state file.

## Evidence

Task-level evidence belongs in commits, PRs, Issues, reviews, and GitHub Actions. See [`../evidence/README.md`](../evidence/README.md).

Repository evidence files are reserved for release-candidate/release records whose essential metadata must outlive CI artifact retention.

## Documentation rules

1. One concept has one canonical document.
2. Do not create per-task handoff, overlay, traceability, risk, or evidence documents.
3. Do not copy mutable SHAs, CI runs, test counts, Worker state, or branch inventories into durable docs.
4. Update an existing canonical reference when a durable rule changes.
5. Delete obsolete coordination/history files from the active tree; Git already preserves them.
6. Target-state requirements must never be presented as implemented behavior.
7. New operational commands must be tied to actual source and verified before being described as executable.
8. A new engineer should recover the project from `README.md` -> `PROJECT_STATUS.md` -> `AGENTS.md` -> task Issue -> only the relevant canonical reference.