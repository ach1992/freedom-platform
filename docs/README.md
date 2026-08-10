# Documentation Guide

This directory contains several kinds of documents with different authority and freshness. Do not read every numbered file in sequence to discover the current project state.

## Current state

Use these sources for decisions about what is happening now:

1. [`../AGENTS.md`](../AGENTS.md) — authority, safety, branching, and verification rules.
2. [`../PROJECT_STATUS.md`](../PROJECT_STATUS.md) — current human-readable status and active blocker/work boundary.
3. [`project-status.json`](project-status.json) — machine-readable status.
4. Live GitHub state — Draft PR `#6`, open phase Issues, active Worker Issues/PRs, branches, and exact-head CI.

Dynamic task state, current SHAs, CI run IDs, active Worker assignments, and temporary blockers should live in GitHub or `PROJECT_STATUS.md`, not be copied into general documentation.

## Normative product scope

These define what Version `1.0.0` is supposed to become:

- [`specification/master-execution-prompt.md`](specification/master-execution-prompt.md) — normative product and delivery specification.
- [`01-authoritative-requirements.md`](01-authoritative-requirements.md) — stable requirement IDs and acceptance outcomes.
- [`04-domain-glossary.md`](04-domain-glossary.md) — canonical terminology.
- [`05-architecture-overview.md`](05-architecture-overview.md) — target architecture and correctness boundaries.
- [`06-test-strategy.md`](06-test-strategy.md) — test strategy.
- [`07-security-threat-model.md`](07-security-threat-model.md) and [`08-data-classification.md`](08-data-classification.md) — security and data-handling requirements.
- [`adr/`](adr/) — durable architecture decisions.

A temporary task cancellation or implementation shortcut does not silently change normative Version 1 scope. Scope changes must be deliberate and reconciled against these sources.

## Developer workflow

Use these when implementing or handing off work:

- [`development/repository-map.md`](development/repository-map.md) — current source/module navigation.
- [`development/continuation-runbook.md`](development/continuation-runbook.md) — recovery and handoff procedure.
- [`development/increment-lifecycle.md`](development/increment-lifecycle.md) — implementation/evidence acceptance lifecycle.
- [`development/ci-runner-contract.md`](development/ci-runner-contract.md) — CI runtime contract.
- [`development/github-actions-runner-policy.md`](development/github-actions-runner-policy.md) — runner policy.
- [`development/multi-agent-orchestration.md`](development/multi-agent-orchestration.md) — MASTER/Worker workflow when parallel agents are actually used.
- [`development/operational-document-status.md`](development/operational-document-status.md) — whether operational instructions are executable, target-state, historical, or disabled.

Read only the documents relevant to the task after establishing current state.

## Traceability and delivery history

The repository intentionally preserves detailed audit history, but these files are not the preferred source for current task status:

- `00-execution-ledger.md` — historical delivery ledger; reconcile periodically, do not use it instead of live status.
- `02-requirement-traceability-matrix.md` — global traceability baseline; individual status cells may lag accepted bounded evidence until regeneration.
- `03-risk-register.md` — durable risk definitions/history.
- numbered phase traceability, handoff, reconciliation, and audit documents — bounded records for the exact increment they describe.
- `32-current-traceability-overlay.md`, `33-current-risk-overlay.md`, and numbered `*-handoff.md` files — transition-era overlays/handoffs that must not override newer GitHub/`PROJECT_STATUS.md` state.

Accepted proof belongs under [`../evidence/`](../evidence/) and should be preserved. Evidence proves an exact historical boundary; it does not prove that an external service, deployment, or current branch head is unchanged today.

## Documentation rules

When adding or changing documentation:

1. Put dynamic state in GitHub or `PROJECT_STATUS.md` instead of duplicating it.
2. Prefer updating an existing stable reference over creating another `current-*`, `handoff-*`, or overlay document.
3. Preserve accepted evidence and ADRs; do not rewrite history merely to make the tree look smaller.
4. Mark operational documents with their execution status and verify commands against the exact current implementation before running them.
5. If a document becomes historical, keep it for audit only when it carries unique evidence or decisions; otherwise consolidate its durable content and remove the duplicate from the active navigation path.
6. A new engineer should be able to start from `README.md` -> `AGENTS.md` -> `PROJECT_STATUS.md` -> this guide without reading the whole documentation archive.
