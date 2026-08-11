# Repository Operating Contract

This file defines the durable working rules for humans and AI agents.

## Authority

Use sources in this order:

1. `docs/specification/master-execution-prompt.md` — normative Version 1 scope.
2. Live GitHub — PRs, Issues, branches, reviews, and workflow runs.
3. `PROJECT_STATUS.md` — current phase-level state.
4. Canonical references linked from `docs/README.md`.
5. Git history for historical context.

If a lower-authority source conflicts with a higher one, correct or remove the lower source.

## Branch and PR model

Only two branches are long-lived:

- `main` — release branch;
- `develop/v1.0.0-completion` — Version 1 integration branch.

Draft PR `#6` integrates `develop/v1.0.0-completion` into `main` and must remain Draft until explicit final release acceptance.

Implementation/maintenance work uses temporary `agent/<issue-number>-<slug>` branches created from the current integration head. Worker PRs target `develop/v1.0.0-completion`. Delete temporary branches after merge, cancellation, or abandonment once GitHub preserves the record.

Never push product work directly to `main` or `develop/v1.0.0-completion`, rewrite shared history, force-push shared branches, self-merge a Worker PR, or enable auto-merge for high-risk work.

## Task and ownership contract

Every bounded task must have a GitHub Issue that states the parent/requirements, goal, dependencies/base rule, in/out scope, protected areas, risk, affected security/data/financial/remote/schema/runtime surfaces, required verification, merge prerequisites, and current blocker/handoff state. Use `.github/ISSUE_TEMPLATE/task.yml` for new work when available.

PRs use `.github/pull_request_template.md`. Sensitive paths are assigned in `.github/CODEOWNERS`; CODEOWNERS expresses intended ownership but does not prove that GitHub branch protection/rulesets require code-owner approval.

High/Critical financial, authorization, security, provider, schema, deployment/release, secret, or irreversible work requires independent review and explicit Owner approval before merge. The applicable CI tier must pass on the final tested PR revision; merge style never substitutes for review or validation.

## Scope and correctness

Work from a bounded GitHub Issue. Preserve accepted behavior outside the task scope.

Release-blocking invariants:

- no paid provisioning before authoritative payment capture;
- no duplicate financial or remote effect;
- uncertain external results require authoritative lookup/reconciliation before retry;
- idempotency-key conflicts fail closed and preserve the accepted result;
- MariaDB transactions, locks, constraints, and immutable history are final correctness barriers;
- execution-time authorization is mandatory; UI visibility is not authorization;
- browser/customer assertions never prove capture;
- TLS verification is never disabled;
- fake/fixture/source-contract evidence never proves live provider compatibility.

## Security

Never request, retrieve, print, commit, log, attach, or quote secrets. Production credentials, OTPs, payment instruments, private provider payloads, subscription URLs, identity data, and real customer data do not belong in Chat, Git, Issues, PR text, fixtures, screenshots, or CI evidence.

Use prepared ORM/query-builder paths, validation, output escaping, least privilege, fail-closed authorization, bounded network I/O, and explicit redaction.

## Change protocol

For every change:

1. read the task Issue and relevant canonical docs;
2. inspect current implementation/tests before adding a new concept;
3. make the smallest reliable change;
4. add focused success, validation, authorization, replay/conflict, concurrency, and failure tests as applicable;
5. run the appropriate local checks in `CONTRIBUTING.md`;
6. do not weaken checks to obtain green CI;
7. keep live progress in GitHub, not new handoff/status documents.

## CI

Every executing GitHub Actions job runs on the owner-controlled self-hosted runner with:

```yaml
runs-on: [self-hosted, Linux, X64, freedom-staging, php84]
```

GitHub-hosted runners are not a fallback. Exact runtime and quality requirements are documented in `docs/06-test-strategy.md`.

CI is risk-based, not ceremonial:

- **CONTROL CI** is allowed only for PRs to `develop/v1.0.0-completion` whose entire diff is inside the workflow's explicit documentation/governance-only allowlist. It runs repository/project-control validation and secret scanning.
- **FULL CI** is required for source, routes, bootstrap/config, schema/migrations, tests, dependencies, static/CI tooling, Docker/runtime/deployment/workflow changes, any unknown path, every PR targeting `main`, and intentional manual release validation.
- Unknown or unclassifiable changes default to FULL CI.
- Draft PRs do not consume self-hosted-runner jobs automatically. Marking a PR Ready for review triggers the applicable tier on the current revision.
- Draft integration PR #6 must not re-run the full suite merely because an already-green Worker PR was merged into `develop`; it receives FULL CI when it is intentionally moved to final review/release validation.

A green applicable tier may be reused when the tested revision/resulting tree has not changed. Do not rerun CI merely because the same reviewed content was merged without conflict-resolution edits into the unchanged intended base. If the base or resulting content changes, validate again.

On an unchanged revision, rerun only a clearly transient failed job (or failed jobs) when possible. Do not rerun successful jobs without a reason, and never rerun a deterministic failure hoping for green; fix the cause first.

## Documentation and evidence

Do not create per-task handoff, overlay, current-state, risk, traceability, or evidence documents. Durable rules belong in an existing canonical document. Dynamic state belongs in GitHub.

Task-level implementation history is preserved by commits, PRs, Issues, reviews, and CI. `evidence/` is reserved for release-candidate/release records that must remain after workflow-artifact retention.

## Human approval

Explicit owner approval is required before merging high/critical-risk changes involving financial integrity, authorization, security controls, provider semantics, schema, deployment/release behavior, secrets, or irreversible operations.
