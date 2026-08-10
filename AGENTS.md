# Repository Operating Contract

This file is the mandatory operating entry point for AI agents and human engineers.

## 1. Authority order

Use sources in this order:

1. `docs/specification/master-execution-prompt.md` — normative Version 1 product, engineering, security and acceptance scope.
2. Live GitHub state — PRs, Issues, branches, workflow runs and artifacts.
3. `PROJECT_STATUS.md` and `docs/project-status.json` — current verified boundary and active phase.
4. Stable architecture, requirement, risk, test, deployment and development references under `docs/`.
5. Accepted historical evidence under `evidence/` and Git history.

When sources disagree, higher authority wins and the stale lower source must be corrected or removed. Dynamic task state does not belong in general documentation.

## 2. Mandatory start sequence

Before changing code or project-control files:

1. fetch PR `#6`;
2. confirm it is open, Draft, base `main`, head `develop/v1.0.0-completion`;
3. use its exact live `head_sha` as the current integration head;
4. read this file, `PROJECT_STATUS.md`, `docs/project-status.json`, and `docs/README.md`;
5. inspect exact-head mandatory CI;
6. inspect the authoritative active-phase Issue and only the requirement/evidence references relevant to the task;
7. search existing source and GitHub history before adding a new concept, service, table, workflow, document or Issue.

Never trust a SHA copied from a handoff, old Issue body, document, or Chat as live authority.

## 3. Branch and PR policy

The only long-lived branches are:

- `main`;
- `develop/v1.0.0-completion`.

PR `#6` must remain Draft until final Version 1 release acceptance. Do not merge it, enable auto-merge, mark it Ready, rewrite its history, force-push it, or push product work directly to `main`.

Worker branches are explicitly allowed only for an active contracted task using `agent/<issue-number>-<short-slug>`. Every Worker branch is temporary: create it from the live integration head, target `develop/v1.0.0-completion`, and delete the branch after merge, cancellation, or abandonment once GitHub PR/Issue history preserves the task record.

Do not create standing `ops/*`, `safety/*`, repair, bootstrap, or recovery branches as project infrastructure. If an exceptional temporary branch is genuinely required, its cleanup condition must be explicit and it must be removed immediately after that condition is satisfied.

Every GitHub Actions job must run on the owner-controlled self-hosted runner using `runs-on: [self-hosted, Linux, X64, freedom-staging, php84]`. GitHub-hosted runners are not a fallback.

## 4. Scope and correctness

Work only inside the current authoritative phase and a bounded requirement/task boundary. Do not pull Order/provisioning/Service behavior into an earlier phase merely to unblock another feature.

The following are release-blocking invariants:

- no paid provisioning before authoritative payment capture;
- no duplicate financial, provisioning, Telegram, or remote-provider effect;
- uncertain external mutations enter lookup/discovery/reconciliation before retry;
- conflicting idempotency-key reuse never overwrites the accepted result;
- database transactions, locking, uniqueness and immutable history remain final correctness barriers;
- browser return/customer assertion never proves capture;
- TLS verification is never disabled;
- source/fake/harness evidence never proves deployed-provider compatibility by itself.

A class, migration, fake, shell, workflow, document or test name is not a completion claim. Accepted capability requires the repository's exact-head verification/evidence lifecycle.

## 5. Secrets and sensitive data

Never request, retrieve, print, commit, log, attach, or quote secrets.

Protected runtime values, credentials, OTPs, card/payment data, gift codes, private provider payloads, subscription URLs and identity data must not appear in Issues, PR text, repository docs, fixtures, screenshots, retained evidence or Chat. Verify presence/behavior without revealing values.

## 6. Change protocol

For each bounded change:

1. identify requirement IDs and dependencies;
2. inspect current code, tests, migrations and accepted evidence first;
3. make the smallest reliable change that preserves accepted behavior;
4. add/update tests for normal, replay, conflict, authorization, validation, concurrency and failure behavior as applicable;
5. run mandatory repository checks;
6. do not weaken checks or suppress failures to make CI green;
7. keep product implementation and evidence/documentation boundaries reviewable;
8. update GitHub task state and `PROJECT_STATUS.md` only when current state materially changes.

## 7. Verification lifecycle

An accepted implementation boundary requires the applicable exact implementation/merge-candidate CI, executable test evidence, retained artifact metadata, bounded evidence/traceability, independent review where required, history-preserving integration, and green post-merge integration CI.

Cancelled, stale, superseded, skipped, queued or non-executable runs are not acceptance evidence.

## 8. Multi-agent work

Use `docs/development/multi-agent-orchestration.md` only when parallel Workers are actually needed. Stable repository rules stay here; dynamic Worker state stays in GitHub Issues/PRs/CI, never in handoff documents or Chat memory.

Workers never push directly to `develop/v1.0.0-completion` or `main`, never self-merge, and never modify another Worker's branch. High/Critical financial, authorization, security, provider or release changes require independent review and explicit owner approval unless that exact risk was pre-authorized.

## 9. Human-only blockers

Ask the owner only when the immediate safe step needs a real secret/account, root/target-server action, financially or legally material policy decision, irreversible production action, or real provider behavior that conflicts with the accepted contract.

## 10. Recovery standard

A new engineer with no Chat history must be able to recover the project from:

`README.md` -> `AGENTS.md` -> `PROJECT_STATUS.md` -> `docs/README.md` -> live PR `#6` / active Issue / exact-head CI.

Do not create new `current-*`, overlay, numbered handoff, transition checkpoint, or duplicate status files. Preserve accepted evidence and GitHub history; remove obsolete coordination artifacts instead of accumulating them.
