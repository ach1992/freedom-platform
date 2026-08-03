# Release Checklist

Release: `<VERSION>`

Git SHA: `<SHA>`

Candidate package: `<PACKAGE>`

Release owner: `<NAME>`

UTC decision time: `<YYYY-MM-DDTHH:MM:SSZ>`

Every item requires a link to immutable evidence or an explicit `N/A` approved by the Lead with rationale. `N/A` is forbidden for financial, authorization, provisioning-idempotency, restore, update/rollback, and Critical/High security gates.

## Scope and traceability

- [ ] Semantic version and release boundary are approved.
- [ ] Requirement ledger and bidirectional traceability matrix are current.
- [ ] Every in-scope requirement maps to design, code, automated tests, command, result, and evidence.
- [ ] Domain glossary, ADRs, schema/data dictionary, state machines, permission catalog, and risk register are current.
- [ ] No unapproved feature, silent omission, or unresolved Critical business ambiguity exists.
- [ ] Changelog and release notes explain migrations, configuration, operator action, known risk, and compatibility.

## Source and supply chain

- [ ] Release commit is reviewed and immutable; no author solely approved a sensitive change.
- [ ] `composer.lock` is committed; `composer validate --strict --no-check-publish` passes.
- [ ] Laravel 13/PHP 8.4 and every external integration contract were re-verified against dated official references.
- [ ] `composer audit --locked` has no unmitigated High/Critical finding.
- [ ] License inventory contains no unknown/incompatible/abandoned dependency.
- [ ] Secret scan passes across Git history and package contents.
- [ ] Release archive contains no `.env`, credentials, test secrets, private media, logs, caches, or development artifacts.
- [ ] Manifest, SHA-256 checksums, trusted signature mechanism, Git SHA, schema compatibility, and rollback metadata verify.

## Code quality and automated tests

- [ ] Pint, Larastan/PHPStan, architecture tests, and forbidden-pattern gates pass.
- [ ] Unit, MariaDB integration, authenticated Redis, contract, E2E, concurrency/property, and security suites pass.
- [ ] Common payment matrix passes for wallet, card-to-card, gift card, USDT, Zarinpal, and NOWPayments.
- [ ] No provisioning-before-authoritative-capture test passes only through a mock that bypasses domain rules.
- [ ] Duplicate update/callback/webhook/review/job/refund and uncertain remote-result tests prove one effect.
- [ ] Ledger balances, no negative balance, exact-amount uniqueness, redemption uniqueness, and reconciliation pass.
- [ ] Explicit deny precedence and server-side authorization matrix pass.
- [ ] Coverage report is reviewed for high-risk paths; missing scenario coverage is resolved.
- [ ] Flaky, skipped, quarantined, or expected-failure tests are reviewed; none conceal a mandatory gate.

## Security and privacy

- [ ] Independent reviewer signs off threat model and sensitive diffs.
- [ ] No unresolved Critical or High security finding exists.
- [ ] Telegram/provider callbacks authenticate, reject replay, limit body size, and fail without state change.
- [ ] SSRF/DNS rebinding, TLS rejection, CSRF, IDOR, injection, markup, upload, traversal, rate-limit, and session tests pass.
- [ ] Secrets and sensitive fields are encrypted/masked; logs/evidence/alerts pass redaction tests.
- [ ] Production has `APP_DEBUG=false`, HTTPS, secure cookies/headers, least privilege, and no `verify=false` equivalent.
- [ ] Test credentials are revoked; production secret collection/rotation uses a protected channel.

## Database and operations

- [ ] Fresh migrations and seeders pass on target MariaDB; schema constraints/indexes are reviewed.
- [ ] Migration is expand/contract compatible or has explicit backup, downtime, rollback/restore, and approval.
- [ ] Queue priority isolates payments/provisioning from broadcasts; retry/dead-letter policies are verified.
- [ ] Transactional Outbox, distributed locks, Scheduler and worker heartbeats pass.
- [ ] Exactly one Scheduler Cron entry exists; Supervisor processes are healthy.
- [ ] Structured logs, correlation IDs, audit append-only behavior, alert persistence/dedup/retry, and Operations Center checks pass.
- [ ] Disk, DB, Redis, queue backlog, provider/panel/SMS health, and capacity thresholds are acceptable.

## Target-like rehearsals

- [ ] Clean aaPanel/OpenLiteSpeed install checks both PHP runtimes and permanently locks installer.
- [ ] OpenLiteSpeed document root is exactly `/www/acdomains/hell.hellpservice.ir/current/public`; project/shared roots are not exposed.
- [ ] Marzban/PasarGuard contract and remote idempotency tests pass against installed test versions.
- [ ] Payment/SMS/rate adapters have current dated contract notes and Fake plus sandbox/controlled evidence.
- [ ] Telegram onboarding, purchase, payment, provisioning, delivery, renewal/add-on, support, broadcast, and admin journeys pass.
- [ ] Agreed performance baseline and queue recovery pass on documented hardware/workload.
- [ ] Chaos tests include provider/panel/Telegram timeout, Redis restart, deadlock, worker crash, disk pressure, and interrupted deployment.

## Backup, update, and rollback

- [ ] 10-minute DB, daily full, pre-update, retention, encryption, checksum, and optional <=45 MB Telegram part behavior pass.
- [ ] A full restore from this release's generated encrypted backup passes in target-like staging, including financial reconciliation.
- [ ] Update rehearsal verifies package, backup, staging, migrations, smoke, atomic switch, workers/webhook, and report.
- [ ] Code rollback, failed migration/smoke, incompatible rollback block, and backup-restore path pass.
- [ ] Recovery time and demonstrated data-loss window are recorded and accepted.

## Production readiness

- [ ] Owner inputs needed for this phase were collected without secrets in chat/repository/logs.
- [ ] Maintenance, customer communication, approvers, monitoring owner, and incident contacts are assigned.
- [ ] Deployment, restore, rollback, incident, secret rotation, and troubleshooting runbooks were dry-run by an operator who did not author them.
- [ ] Current production backup and previous compatible release are verified.
- [ ] No unsafe active financial/provisioning operation exists at activation time.
- [ ] Post-deployment smoke and reconciliation commands are prepared.

## Final decision

- [ ] **GO**: all mandatory items pass; no known Critical/High defect or unaccepted risk.
- [ ] **NO-GO**: any mandatory item fails or evidence is missing.

Decision: `<GO|NO-GO>`

Lead signature/reference: `<REFERENCE>`

Security signature/reference: `<REFERENCE>`

Operations signature/reference: `<REFERENCE>`

Accepted residual risks: `<RISK-IDS or NONE>`

Post-deployment evidence must record exact command, environment, exit code/result, UTC time, and sanitized path. A green workflow without restore/update/security evidence is not sufficient for `1.0.0`.
