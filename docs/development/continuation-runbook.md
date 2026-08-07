# Continuation Runbook

Use this runbook when starting in a new chat, joining the project, recovering after an interruption, or handing work to another engineer.

## 1. Establish live truth

Do not begin from conversation memory or a copied SHA.

1. Fetch PR `#6` in `ach1992/freedom-platform`.
2. Verify:
   - state is open;
   - `draft=true`;
   - base is `main`;
   - head branch is `develop/v1.0.0-completion`;
   - repository head and PR head belong to the same repository.
3. Record the exact `head_sha` for the current inspection only.
4. Fetch Issue `#7` and its recent comments.
5. Read:
   - `AGENTS.md`;
   - `PROJECT_STATUS.md`;
   - `docs/project-status.json`;
   - the active handoff linked by the status files;
   - relevant requirement, architecture, risk, test, deployment, evidence, and phase traceability files.
6. Reconcile any disagreement before feature work. A stale document is a defect in the project control plane.

## 2. Inspect exact-SHA CI

Fetch workflow runs associated with the exact PR head SHA.

Mandatory `CI` jobs:

- Repository preflight;
- Secret scan;
- Dependency and license policy;
- PHP static quality;
- MariaDB and Redis tests.

For every job:

1. inspect status and conclusion;
2. inspect step summaries;
3. read executable logs for failures, cancellations, warnings, unexpected skips, or suspiciously small artifacts;
4. verify the checkout identifies the intended PR head, even when GitHub executes a synthetic PR merge ref;
5. distinguish a real code/policy failure from a runner or GitHub incident using logs, not assumptions.

A workflow is acceptable only when every mandatory job completed successfully. A queued, cancelled, stale, or partially executable run is not evidence.

### Missing workflow run

Connector/content commits may not immediately produce an Actions run. When no run exists on the exact head, the only owner action to request is:

`Actions → CI → Run workflow → develop/v1.0.0-completion`

Do not request a manual run when a run already exists or while a newer exact-head run is queued/in progress.

## 3. Diagnose without wasting time

Use bounded thresholds and inspect the current step.

- Toolchain bootstrap: normally under 2 minutes.
- Dependency installation with warm cache: normally under 10 minutes.
- Static analysis: bounded to 15 minutes.
- Disposable MariaDB/Redis startup: bounded to 5 minutes.
- Full suite with coverage: bounded to 30 minutes.

If a step remains unchanged beyond its bound:

1. read its log if available;
2. check whether the self-hosted runner is online and actually executing another job;
3. check branch-aware concurrency and superseded runs;
4. do not create repeated commits or workflow dispatches to “unstick” an occupied runner;
5. request one exact runner-service check only when connector inspection cannot resolve it.

Do not wait indefinitely on a job with no executable step/log. Treat it as an infrastructure blocker and preserve the evidence.

## 4. Choose the next bounded action

Apply this priority:

1. release-blocking security, authorization, financial, remote-idempotency, or data-integrity defect;
2. exact-head CI failure;
3. project-control drift that can misdirect the next engineer;
4. active-increment implementation gap;
5. evidence and traceability completion;
6. next planned increment.

Never enter a later phase merely because the current phase has a difficult integration or CI problem.

## 5. Implement safely

Before a write:

- re-fetch PR `#6` if the previous read may be stale;
- search for existing implementation and terminology;
- identify requirement IDs and affected invariants;
- identify rollback and compatibility impact;
- decide whether the change is implementation, infrastructure, evidence, or documentation.

For code changes:

- preserve `strict_types`, typed boundaries, translations, validation, authorization, redaction, transactions, replay safety, and fail-closed behavior;
- add tests before claiming behavior;
- use the smallest safe commit;
- do not edit core/vendor/third-party files;
- do not suppress a failing gate to produce green CI.

For infrastructure changes:

- record exact runner/runtime assumptions in `docs/development/ci-runner-contract.md`;
- use timeouts and fail-fast verification;
- keep production/staging mutation workflows explicit and guarded;
- never expose secret values.

## 6. Verify an implementation boundary

1. Commit the implementation only.
2. Fetch the new exact PR head.
3. obtain mandatory CI success on that exact SHA;
4. read test logs and extract exact test/assertion counts;
5. fetch the retained artifact and record name/ID;
6. download the artifact and calculate SHA-256 independently;
7. inspect artifact contents for completeness and secret safety;
8. create bounded evidence and traceability documents without overstating provider or production compatibility;
9. commit evidence/documents;
10. obtain mandatory CI success on the exact evidence-head SHA;
11. only then update Issue `#7` and PR `#6`.

If evidence changes implementation behavior, it is no longer an evidence-only head; create and verify a new implementation boundary.

## 7. Leave a durable handoff

Update before stopping:

- `PROJECT_STATUS.md`;
- `docs/project-status.json`;
- active phase handoff;
- affected traceability rows;
- affected risk entries;
- tests/evidence references;
- exact blockers and mandatory next steps.

The handoff must state:

- last independently verified boundary;
- active unverified scope;
- files and behavior added;
- invariants that must remain true;
- claims intentionally not made;
- exact CI state and whether executable logs existed;
- next actions in mandatory order;
- any one human-only action, with safe verification and rollback.

Never make a new engineer infer current status by reading hundreds of commits or old Issue comments.
