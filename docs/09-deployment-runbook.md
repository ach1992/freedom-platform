# Deployment, Backup, Update, and Rollback

Target environment: Ubuntu/aaPanel/OpenLiteSpeed, PHP 8.4, MariaDB, authenticated Redis.

This document is a **safety contract**, not proof that every described command/capability currently exists. Execute an operational step only when the release/task explicitly authorizes it and the referenced implementation is present on the exact release commit.

## Execution and access topology

The project separates coding, GitHub control, CI, and staging operations:

- **GitHub repository / ChatGPT GitHub integration** owns live repository state: Issues, PRs, refs, repository files, reviews and Actions evidence. It is not an interactive server shell and cannot reveal secret values.
- **Repository-linked OpenAI Codex cloud environment** is the normal broad coding workspace when a task needs a real working tree, shell commands, multi-file edits and commits. Publication to GitHub is through the Codex GitHub integration / `Create PR` flow. A Codex sandbox may show no raw `origin` remote; that alone is not a GitHub-access failure. The supported access check is whether Codex can publish its prepared branch/commit/PR to this repository.
- **GitHub Actions** runs authoritative repository validation on the owner-controlled self-hosted runner `freedom-staging-runner` selected by `[self-hosted, Linux, X64, freedom-staging, php84]`.
- **Staging/test host** is target-like infrastructure, not a mutable developer checkout. The known project root is `/www/acdomains/hell.hellpservice.ir`; `current` is a release symlink and must not be edited in place for development.

Use `CONTRIBUTING.md` for capability routing and `docs/06-test-strategy.md` for CI/runner execution details.

### Verified GitHub/Codex publication model

A repository-linked Codex task has been verified to create a commit and publish a temporary branch/PR back to this repository through the Codex UI integration. Therefore a future developer/agent should not create a manual patch-relay workflow merely because `git remote -v` inside the Codex sandbox is empty.

When Codex publishes work:

1. select repository `ach1992/freedom-platform` and the intended base branch;
2. let Codex prepare the task change/commit;
3. use Codex `Create PR`/GitHub publication flow;
4. inspect the resulting GitHub branch/PR directly;
5. keep high-risk cumulative PRs Draft until validation is intentionally requested;
6. delete temporary verification/task branches after cancellation/integration once GitHub preserves the history.

A different developer checkout that has a normal authenticated `origin` may use ordinary `git fetch`/`git push`; this is not required of the Codex cloud sandbox.

### GitHub repository Environment vs Codex environment

These are different systems:

- **GitHub repository Environment** is configured under repository `Settings -> Environments` and is consumed by workflow jobs with `environment:`.
- **Codex cloud environment** is configured in OpenAI Codex and supplies an agent coding workspace linked to the repository.

The guarded provider mutation workflow uses GitHub Environment `provider-live-acceptance`. The workflow itself permits the mutation job only from `develop/v1.0.0-completion` with its explicit confirmation input. Do not infer current GitHub Environment branch restrictions from this document; inspect GitHub Settings if that enforcement matters to a decision.

## Secret and credential interfaces

Secret **values** are write-only operational state. They are not documentation, must never be pasted into Chat, and are not expected to be readable by ChatGPT/Codex/GitHub connectors. A future agent should use an existing secret identifier through the owning workflow instead of asking the Owner to reveal its value.

### Currently consumed by repository workflows

Current provider workflows consume:

- `PASARGUARD_TEST_ORIGIN`
- `PASARGUARD_TEST_API_KEY`

`Provider Readiness - Read Only` uses those identifiers for the read-only PasarGuard probe. `Provider Live Acceptance - PasarGuard` uses the same identifiers in GitHub Environment `provider-live-acceptance` for the explicitly confirmed disposable live-acceptance path.

### Configured secret interfaces known to the project

The repository/Environment setup has been provisioned with these secret identifiers. Their presence does **not** mean every identifier has a current workflow consumer:

- provider test: `PASARGUARD_TEST_ORIGIN`, `PASARGUARD_TEST_API_KEY`, `PASARGUARD_TEST_USERNAME`, `PASARGUARD_TEST_PASSWORD`;
- staging target: `STAGING_DOMAIN`, `STAGING_HOST`, `STAGING_PORT`, `STAGING_USER`, `STAGING_PASSWORD`;
- staging SSH/host verification: `STAGING_SSH_PRIVATE_KEY`, `STAGING_KNOWN_HOSTS`;
- Telegram controlled test integration: `TELEGRAM_TEST_BOT_TOKEN`.

Before using a configured identifier, search the exact current workflow/source revision. If source does not reference it, treat it as **reserved/unconsumed**, not as authorization to invent a new remote execution path.

Do not duplicate these values into a Codex Environment secret store unless a specific task genuinely needs that capability in Codex and the Task Contract authorizes it. Normal coding does not require staging/provider secret values inside Codex.

### GitHub Actions permissions

Repository Actions settings are intentionally capable of write operations so controlled workflows can support repository automation when required. However each workflow must still declare its own narrow `permissions:` block. Current CI/provider/readiness workflows use read-scoped repository permissions unless a bounded future workflow explicitly needs write access.

Do not infer that a job has write access merely because the repository default allows it. The workflow YAML for the exact revision is the authority.

Prefer repository-scoped `GITHUB_TOKEN` for GitHub operations performed by Actions. Do not create a PAT merely to make normal CI work. A PAT, GitHub App token, deploy key or remote credential is justified only for a concrete capability that `GITHUB_TOKEN` cannot provide.

### Safe secret discovery rule

To determine whether an integration is configured:

1. inspect the owning workflow for the required secret **names**;
2. inspect GitHub Settings only for presence/ownership when needed;
3. run the narrow read-only readiness workflow when one exists;
4. never print/dump secret contexts, `.env`, SSH material or provider credentials for discovery.

A missing/empty secret should be reported by the workflow's preflight. Do not ask for the value in Chat; ask the Owner to create/rotate the named secret in GitHub Settings only when preflight proves it is missing or invalid.

## Existing operational workflows

### CI

`.github/workflows/ci.yml`

- runs on the self-hosted runner;
- Draft PRs intentionally do not consume CI;
- CONTROL tier handles allowlisted docs/governance-only diffs;
- FULL tier runs PHP/static/dependency/security gates and MariaDB 10.11 + authenticated Redis integration tests;
- manual `workflow_dispatch` is available for intentional full validation/coverage evidence.

### Staging Readiness

`.github/workflows/staging-readiness.yml`

- manual read-only host inspection;
- confirmation input: `READ_ONLY_STAGING_CHECK`;
- verifies `RUNNER_NAME=freedom-staging-runner`;
- reports sanitized PHP/Composer/Docker/service/release-symlink facts;
- does not require staging SSH secrets and must not mutate the staging target.

### Provider Readiness - Read Only

`.github/workflows/provider-readiness.yml`

- manual read-only PasarGuard readiness probe;
- run from `develop/v1.0.0-completion`;
- confirmation input: `READ_ONLY_PROVIDER_CHECK`;
- consumes `PASARGUARD_TEST_ORIGIN` and `PASARGUARD_TEST_API_KEY`;
- produces sanitized readiness evidence.

### Provider Live Acceptance - PasarGuard

`.github/workflows/provider-live-acceptance.yml`

- privileged/disposable provider mutation path; not normal CI;
- only valid from `develop/v1.0.0-completion`;
- uses GitHub Environment `provider-live-acceptance`;
- consumes `PASARGUARD_TEST_ORIGIN` and `PASARGUARD_TEST_API_KEY`;
- requires the explicit confirmation string encoded in the current workflow;
- must not be run merely to test whether credentials exist.

The workflow source is authoritative for the exact confirmation string, provider version, optional inputs and current safety checks. Do not copy those volatile details into another status document.

## Production layout

```text
<root>/
├── releases/<version>/       # immutable code
├── shared/
│   ├── .env                 # mode 0600
│   ├── storage/
│   ├── backups/
│   └── update-packages/
└── current -> releases/<version>
```

Only `current/public` is web-exposed. Do not expose repository root, `.env`, shared storage, backups, or provider certificates.

## Preflight

Before first install or release activation verify:

- CLI PHP and OpenLiteSpeed PHP are independently compatible PHP 8.4 runtimes;
- required extensions and Composer are available;
- MariaDB and authenticated Redis are reachable with least-privilege application credentials;
- filesystem owner/group/permissions are correct; never use `0777`;
- HTTPS is valid;
- only one Scheduler Cron entry exists;
- Supervisor workers use the reviewed queue/runtime configuration;
- no secret is passed through command arguments, Chat, Git, or screenshots.

## Release acceptance before deployment

Do not deploy unless the exact release candidate has:

- mandatory CI success;
- reviewed migration/schema compatibility;
- verified package manifest/checksum/signature policy;
- target-like install/update/rollback testing applicable to the release;
- current encrypted backup and restore confidence;
- no unresolved Critical/High release blocker;
- explicit owner release approval.

## Package and activation

A release package must contain source, lockfile, migrations, release metadata, compatibility metadata, and integrity material without secrets.

Verify package integrity against authenticated release metadata before extraction/activation. Reject path traversal, symlink escape, incompatible runtime/schema, and unverified content.

Activation must use the repository's guarded atomic release-switch implementation when available. Do not replace a guarded release primitive with ad-hoc `ln -sfn`, manual file copying over the live tree, or direct edits to an existing release.

After activation run the implemented health/readiness, queue/worker, Scheduler, webhook, and reconciliation checks required by that release. Do not invent commands that are not present in source.

## Scheduler and workers

- exactly one Cron invokes Laravel Scheduler;
- Supervisor manages workers;
- payment/provisioning-critical queues remain isolated from bulk/report/broadcast work as configured;
- worker timeouts remain below Redis queue retry-after bounds;
- worker/scheduler health must be observable without exposing secrets.

## Backups

Production backups must be:

- consistent for the database/private files required for recovery;
- authenticated-encrypted;
- checksummed/manifested;
- stored outside the public root;
- copied to an independent destination according to retention policy;
- periodically restored in an isolated target-like environment.

Never expose a database password in process arguments. Temporary plaintext, if unavoidable, must have restrictive permissions and be removed only after verified encrypted output exists.

A backup that has never been restored is not sufficient release evidence.

## Restore

Restore is a privileged, explicit operation. It must:

1. authenticate/authorize the operator;
2. validate backup manifest, checksum, encryption key, and compatibility;
3. preserve the current state/safety backup before destructive replacement;
4. restore into an isolated/staged boundary where possible;
5. run migrations/compatibility steps defined by the restore implementation;
6. smoke-test and reconcile financial/remote state before reopening.

Never edit ledger/payment history manually to make a restore appear consistent.

## Update

An updater must verify the package and compatibility before mutation, quiesce unsafe work, make a verified pre-update backup, stage code separately, apply reviewed migrations, run health/smoke checks, then atomically activate.

Database migrations should use expand/contract compatibility. A code rollback is forbidden when the current schema is not compatible with the previous release.

## Rollback

If activation fails:

- preserve logs/journal/evidence;
- stop new unsafe financial/provisioning effects if needed;
- use the guarded release rollback path only when schema compatibility is proven;
- otherwise restore the verified pre-update backup through the controlled restore process;
- re-run health, worker/Scheduler, webhook, and reconciliation checks before reopening.

Never run `migrate:rollback` blindly on production.

## Secrets and operational evidence

Operational evidence is stored in protected target storage. A repository release record contains only sanitized metadata such as release commit, command/result summary, artifact/checksum identifiers, and known limitations.

Never commit `.env`, credentials, raw provider responses, unrestricted screenshots, customer data, subscription URLs, or private backup contents.
