# Historical Delivery Ledger

**Document role:** historical navigation only. This file is not a project-status source, task board, handoff, branch inventory, or next-work plan.

The normative Version 1 contract is `docs/specification/master-execution-prompt.md` with stable requirement IDs in `docs/01-authoritative-requirements.md`.

For current project state use only:

1. `PROJECT_STATUS.md`;
2. `docs/project-status.json`;
3. live GitHub PRs, Issues, branches, workflow runs, and artifacts.

## Purpose

This ledger explains where accepted delivery history is retained without copying mutable state into a long-lived planning document.

Accepted implementation history is preserved by:

- Git commits and merged Worker PRs;
- bounded traceability documents under `docs/`;
- retained verification material under `evidence/`;
- GitHub Actions run/artifact metadata recorded by the applicable evidence document.

A historical record proves only its exact recorded boundary. It does not prove the current integration head, active task, provider compatibility, deployment state, or release readiness.

## Delivery history index

| Delivery area | Durable historical source |
|---|---|
| Phase `0.2.0` foundation/runtime/Telegram ingress | `evidence/0.2.0/` plus the associated phase traceability documents and merged history |
| Phase `0.3.0` identity/customers/agents/access control | `evidence/0.3.0/` plus the associated phase traceability documents and merged history |
| Phase `0.4.0` catalog/panels/offerings implementation | `evidence/0.4.0/` plus the associated phase traceability documents and merged history |
| Phase `0.5.0` financial/pricing/promotion/payment increments | `evidence/0.5.0/` plus the associated bounded traceability documents and merged history |
| Later phases | Evidence is added only after an implementation boundary satisfies the repository acceptance lifecycle |

Deployment-specific Marzban/PasarGuard compatibility is not inferred from Phase `0.4.0` source, fake, contract, or harness history. Real deployed-provider acceptance remains a release acceptance concern and must be proven against the exact supported deployment boundary.

## Historical evidence contract

A bounded accepted increment should make the following discoverable without relying on Chat history:

- requirement IDs;
- exact implementation/evidence commit boundary;
- exact verification command or CI run;
- test/result summary;
- retained artifact identity/digest when applicable;
- relevant traceability/evidence path;
- explicit limitations and deferred behavior.

The exact fields required for acceptance are governed by `docs/development/increment-lifecycle.md` and current repository CI policy.

## Anti-drift rules

This file must never contain:

- the active phase or active task;
- a current branch/Worker inventory;
- a copied live PR head SHA;
- current CI status;
- a "next task" recommendation;
- temporary bootstrap/safety/repair branch instructions;
- coordination handoffs or overlays.

If historical detail is useful only for audit, preserve it in Git history, the merged PR/Issue record, or bounded evidence instead of expanding this file.
