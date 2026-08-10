# Documentation Guide

Documentation is organized by authority and purpose. Do not read numbered files sequentially to infer current project state.

## 1. Current state

Use only these sources for decisions about what is happening now:

1. [`../AGENTS.md`](../AGENTS.md) — repository operating contract and authority order.
2. [`../PROJECT_STATUS.md`](../PROJECT_STATUS.md) — current human-readable phase/boundary.
3. [`project-status.json`](project-status.json) — machine-readable current status.
4. Live GitHub — Draft PR `#6`, active Issues, temporary Worker PRs/branches, exact-head CI, and artifacts.

Dynamic SHAs, assignments, blockers, current branch inventory, CI results, and next-task decisions do not belong in any other document.

## 2. Normative Version 1 scope

These define what the product must become:

- [`specification/master-execution-prompt.md`](specification/master-execution-prompt.md) — normative product/delivery specification.
- [`01-authoritative-requirements.md`](01-authoritative-requirements.md) — stable requirement IDs and acceptance outcomes.
- [`04-domain-glossary.md`](04-domain-glossary.md) — canonical terminology.
- [`05-architecture-overview.md`](05-architecture-overview.md) — target architecture/correctness boundaries.
- [`06-test-strategy.md`](06-test-strategy.md) — verification strategy.
- [`07-security-threat-model.md`](07-security-threat-model.md) and [`08-data-classification.md`](08-data-classification.md) — security/data rules.
- [`adr/`](adr/) — durable architecture decisions.

A cancelled implementation attempt does not cancel a normative requirement. Any deliberate scope change must be explicit and reconciled against the normative specification and requirement catalogue.

## 3. Developer workflow and governance

- [`development/repository-map.md`](development/repository-map.md) — source/module navigation.
- [`development/continuation-runbook.md`](development/continuation-runbook.md) — recovery/start procedure.
- [`development/increment-lifecycle.md`](development/increment-lifecycle.md) — implementation/evidence acceptance lifecycle.
- [`development/ci-runner-contract.md`](development/ci-runner-contract.md) and [`development/github-actions-runner-policy.md`](development/github-actions-runner-policy.md) — CI/runtime policy.
- [`development/multi-agent-orchestration.md`](development/multi-agent-orchestration.md) — only when parallel Workers are actually used.
- [`development/operational-document-status.md`](development/operational-document-status.md) — executable vs target/historical operational classification.

These documents define stable process. They must not become live task boards.

## 4. Historical, traceability, risk, and evidence records

- [`00-execution-ledger.md`](00-execution-ledger.md) — historical delivery/evidence navigation only; never current state.
- [`02-requirement-traceability-matrix.md`](02-requirement-traceability-matrix.md) — stable traceability contract and canonical requirement coverage index; intentionally no mutable implementation-status cells.
- [`03-risk-register.md`](03-risk-register.md) — durable risk definitions, lifecycle, controls, and decision gates; not task progress.
- numbered phase traceability/contract documents — accepted bounded technical history where still relevant.
- [`../evidence/`](../evidence/) — retained proof for exact historical implementation/evidence boundaries.

Evidence proves only the exact boundary it records. It does not prove the current integration head, current external-provider behavior, deployment state, or release readiness.

## 5. Operational documents

Deployment, installer, backup, restore, update, staging, and provider documents can describe future or bounded behavior that is not currently executable.

Before executing any operational instruction, read [`development/operational-document-status.md`](development/operational-document-status.md) and verify the exact current tool/workflow at the live head.

Target-state documentation is an acceptance/design contract, not execution authorization.

## 6. Documentation rules

1. Current state exists only in `PROJECT_STATUS.md`, `project-status.json`, and live GitHub.
2. Do not create `current-*`, overlay, transition-checkpoint, or numbered handoff documents.
3. Do not add a document when an existing stable authority can be updated.
4. Do not duplicate mutable implementation status in traceability/history/architecture/reference documents.
5. Preserve accepted evidence, ADRs, normative requirements, and useful bounded traceability.
6. Remove obsolete coordination/audit snapshots from the current tree once Git history preserves them.
7. Keep target-state operational instructions separate from currently approved executable operations.
8. Historical documents may explain past decisions but must clearly identify themselves as historical and must not issue current instructions.
9. Every new document must have one clear role: normative, current-state, stable reference/governance, or historical/evidence. Mixing roles is a defect.
10. A new engineer must be able to recover from `README.md` -> `AGENTS.md` -> `PROJECT_STATUS.md` -> this guide -> live GitHub without Chat history.

Project-control CI enforces the highest-value anti-drift rules; reviewers must reject semantic duplication that cannot be reliably detected by automation.
