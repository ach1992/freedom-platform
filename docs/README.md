# Documentation Guide

Do not read every numbered file in sequence to discover current project state. Documentation is split by purpose.

## Current state

Use only these sources for decisions about what is happening now:

1. [`../AGENTS.md`](../AGENTS.md) — repository operating contract.
2. [`../PROJECT_STATUS.md`](../PROJECT_STATUS.md) — current human-readable phase/boundary.
3. [`project-status.json`](project-status.json) — machine-readable status.
4. Live GitHub — Draft PR `#6`, active phase/task Issues, temporary Worker PRs/branches and exact-head CI.

Dynamic SHAs, task assignments, blockers and branch state belong here or in GitHub, not in additional status/handoff files.

## Normative Version 1 scope

- [`specification/master-execution-prompt.md`](specification/master-execution-prompt.md) — normative product/delivery specification.
- [`01-authoritative-requirements.md`](01-authoritative-requirements.md) — stable requirement IDs and acceptance outcomes.
- [`04-domain-glossary.md`](04-domain-glossary.md) — canonical terminology.
- [`05-architecture-overview.md`](05-architecture-overview.md) — target architecture/correctness boundaries.
- [`06-test-strategy.md`](06-test-strategy.md) — testing strategy.
- [`07-security-threat-model.md`](07-security-threat-model.md) and [`08-data-classification.md`](08-data-classification.md) — security/data rules.
- [`adr/`](adr/) — durable architecture decisions.

A cancelled implementation attempt does not silently cancel a normative requirement. Deliberate scope changes must be recorded explicitly.

## Developer workflow

- [`development/repository-map.md`](development/repository-map.md) — current source/module navigation.
- [`development/continuation-runbook.md`](development/continuation-runbook.md) — recovery/start procedure.
- [`development/increment-lifecycle.md`](development/increment-lifecycle.md) — implementation/evidence acceptance lifecycle.
- [`development/ci-runner-contract.md`](development/ci-runner-contract.md) and [`development/github-actions-runner-policy.md`](development/github-actions-runner-policy.md) — CI/runtime policy.
- [`development/multi-agent-orchestration.md`](development/multi-agent-orchestration.md) — only when parallel Workers are used.
- [`development/operational-document-status.md`](development/operational-document-status.md) — operational command/document safety classification.

## Durable planning and traceability

These are reference ledgers, not live task boards:

- `00-execution-ledger.md` — historical accepted delivery ledger;
- `02-requirement-traceability-matrix.md` — global traceability baseline; status cells can lag newer bounded evidence;
- `03-risk-register.md` — durable risk definitions/history;
- numbered phase traceability/contract documents — accepted bounded technical history where still relevant.

Accepted proof belongs under [`../evidence/`](../evidence/). Evidence proves an exact historical boundary; it does not prove current external-provider or deployment state.

## Documentation rules

1. Current state exists only in `PROJECT_STATUS.md`, `project-status.json`, and GitHub.
2. Do not create `current-*`, overlay, transition checkpoint, or numbered handoff documents.
3. Do not add a new document when an existing stable reference can be updated.
4. Preserve accepted evidence, ADRs and requirement definitions.
5. Remove obsolete coordination/audit snapshots from the current tree once Git history preserves them.
6. Keep target-state operational instructions clearly separated from currently executable operations.
7. A new engineer should be able to start from `README.md` -> `AGENTS.md` -> `PROJECT_STATUS.md` -> this guide without reading historical documents.
