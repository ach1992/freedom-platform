# Test Strategy

**Status:** active release strategy, reviewed 2026-08-07  
**Applies to:** `0.1.0` through `1.0.0`  
**Primary requirements:** `QUA-001`, `QUA-002`, `QUA-004`, `QUA-012`, `QUA-013`, `SEC-001`, `OPS-003`, `BAK-001`, `BAK-002`, `INS-001`, `UPD-001`

## Purpose

Testing proves product invariants and bounded acceptance claims; it is not a claim that defects cannot exist. A release or increment is blocked whenever a mandatory test fails, executable evidence is missing, an exact-head gate is incomplete, or a Critical/High security/integrity risk remains unresolved.

Requirement wording comes from `docs/01-authoritative-requirements.md`. Current implementation status comes from `docs/32-current-traceability-overlay.md` until the full baseline matrix is regenerated. Exact working state always comes from PR `#6`.

## Evidence lifecycle

Evidence paths have distinct purposes:

- `build/evidence/` — transient local/CI output;
- GitHub Actions artifacts — retained immutable output for a workflow run;
- `evidence/<phase>/` — reviewed repository evidence that references accepted exact-SHA runs;
- protected target storage — staging/production operational evidence outside the public web root.

An accepted bounded increment requires:

1. implementation SHA;
2. mandatory CI success on that exact SHA;
3. executable test/assertion counts;
4. retained artifact name/ID;
5. independently calculated artifact SHA-256 and content inspection;
6. evidence/traceability commit;
7. mandatory CI success on the exact evidence-head SHA.

A cancelled, queued, skipped, stale, cleanup-only, or synthetic-merge-only run is not implementation evidence.

## Test environments

| Environment | Purpose | Database/Redis | External systems | Evidence |
|---|---|---|---|---|
| Local | unit and focused integration work | disposable MariaDB/authenticated Redis where semantics matter | fakes only | developer logs; not acceptance evidence |
| GitHub Actions | mandatory exact-head gates | disposable MariaDB and authenticated Redis | fakes and deterministic contract fixtures | separate preflight/static/test/dependency/secret artifacts |
| Staging | target-like acceptance | dedicated non-production MariaDB/Redis | sandbox or controlled test accounts | protected target evidence and sanitized retained summary |
| Production | deployment verification only | production | live providers | protected operational evidence; minimal redacted export |

Production data, tokens, customer identifiers, subscription links, receipts, private files, and raw provider payloads must never be copied to CI fixtures.

## Self-hosted runner contract

Mandatory CI runs on `freedom-staging-runner` with labels:

- `self-hosted`;
- `Linux`;
- `X64`;
- `freedom-staging`;
- `php84`.

The workflow uses `scripts/ci/bootstrap-self-hosted-toolchain.sh`, not a privileged runtime installer. It explicitly selects `/www/server/php/84/bin/php` and `/www/server/php/84/etc/php-cli.ini`, verifies Composer `2.10.2`, validates extensions, disables JIT, and enables PCOV only for coverage.

See `docs/development/ci-runner-contract.md`. Runner labels are not evidence that the effective toolchain is correct; executable bootstrap output is required.

## Determinism and test architecture

- PHP 8.4 and Laravel 13 are the baseline.
- CI integration tests run against real MariaDB and authenticated Redis; SQLite is not migration, locking, trigger, concurrency, or integrity evidence.
- Time, randomness, HTTP clients, Telegram, SMS, panels, payment providers, and storage destinations use injectable contracts.
- Fake adapters are deterministic and model success, definitive failure, retryable failure, timeout-before-effect, timeout-after-effect, duplicate events, and out-of-order events where applicable.
- Tests isolate state and are parallel-safe. A test requiring serialization states why.
- Money uses integer IRR or fixed-precision decimals; monetary floating point is forbidden.
- Database uniqueness and transactions are the final duplicate-effect barrier. Redis locks alone never satisfy an idempotency proof.
- Logs and evidence pass secret/PII redaction checks.
- Composer package scripts are not run implicitly during dependency installation; Laravel package discovery is explicit.

## Required suites

| Suite | Minimum scope | Mandatory run |
|---|---|---|
| Unit | value objects, policies, state machines, calculations, permissions, mappings, masks, adapter DTOs | every implementation/evidence head |
| Database integration | migrations, constraints/triggers, histories, reservations, replay, outbox, encryption behavior | every implementation/evidence head on MariaDB |
| Contract | Fake/Generic/provider adapters, signed fixtures, exact version notes | fake/fixture in CI; real/sandbox only at controlled staging gate |
| Concurrency/property | capacity, wallet, exact amount, callbacks, reviews, redemption, provisioning | affected increment; broader stress before closure/RC |
| Telegram E2E | onboarding, navigation, payment/service/support/broadcast, Back/Cancel/replay | fake API in owning phase; test bot in staging |
| Security | authorization, IDOR, CSRF, SSRF/DNS rebinding, upload, injection, replay, rate limit, redaction | affected increment; full review before RC |
| Performance | agreed workloads, bursts, broadcasts, sync, reports, recovery | staging before RC |
| Chaos | Redis restart, deadlock, killed worker, provider timeout, disk pressure, interrupted deploy/backup | staging before RC |
| Install/update/restore | fresh install, lock, failed update/smoke, symlink rollback, encrypted restore | target-like staging in owning phases |

A fake proves orchestration semantics only. It does not prove a real provider version, endpoint, rate limit, authentication method, or error behavior.

## Non-negotiable invariant tests

These tests cannot be waived for their owning phase/release:

1. no paid provisioning before authoritative capture, except typed trial/gift/admin-grant sources;
2. duplicate transaction, callback, webhook, Telegram update, retry, review decision, or admin click creates no duplicate effect;
3. one paid order item creates at most one active remote identity, including timeout-after-create and worker crash;
4. uncertain remote create is discovered/adopted or sent to conflict/manual review before any subsequent create;
5. every ledger transaction balances and no available balance becomes negative when Wallet is implemented;
6. explicit administrator deny overrides role grants and every action re-authorizes server-side;
7. secrets, credentials, OTPs, full PII, provider payloads, and subscription material do not appear in logs/evidence/serialization;
8. backup is encrypted/checksummed and restore rehearsal passes in the owning phase;
9. update package is verified, activated atomically, and rollback compatibility is enforced.

## Mandatory CI jobs

| Job | Required content |
|---|---|
| Repository preflight | repository contract, planning traceability, project-control consistency, runner facts, evidence upload |
| Secret scan | complete Git history scan under the configured policy |
| Dependency and license policy | locked install, advisory/abandoned-package gate, license inventory |
| PHP static quality | Pint, Composer validation, PHPStan/Larastan, forbidden patterns, architecture checks |
| MariaDB and Redis tests | disposable services, full suite, JUnit, Clover, sanitized service logs |

Every job must complete successfully. An `if: always()` artifact upload after a failed bootstrap does not make the job acceptable.

## Command contract

CI installs the exact lockfile:

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
```

Static and policy commands:

```bash
php vendor/bin/pint --test
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G
composer validate --strict --no-check-publish
bash scripts/ci/forbidden-patterns.sh
bash scripts/ci/architecture.sh
bash scripts/ci/verify-project-control.sh
```

Dependency commands:

```bash
mkdir -p build/evidence/dependencies
composer audit --locked --abandoned=fail --format=json > build/evidence/dependencies/audit.json
bash scripts/ci/licenses.sh
```

Integration commands:

```bash
docker compose -f docker-compose.ci.yml up -d --wait
php artisan config:clear --ansi
COLUMNS=240 php artisan test \
  --display-warnings \
  --fail-on-warning \
  --log-junit build/evidence/tests/junit.xml \
  --coverage-clover build/evidence/coverage/clover.xml
docker compose -f docker-compose.ci.yml down --volumes
```

The workflow is the source of truth for exact environment variables, cleanup, timeout, and artifact behavior.

## Forbidden-pattern and architecture scope

`forbidden-patterns.sh` and `architecture.sh` are defense-in-depth, not a complete semantic proof. Their current results must not be described more broadly than the checks they execute.

Current gaps tracked by the project-control audit include:

- complete module graph/cycle enforcement;
- table ownership and direct cross-module writes;
- full localization detection;
- every possible monetary float or unsafe dynamic query;
- target-versus-implemented module consistency.

New checks should first report/classify current exceptions and then become blocking through a focused change. Do not introduce a broad rule and “fix” verified code blindly.

## Coverage policy

Clover output must exist and be non-empty for accepted implementation/evidence runs. Aggregate percentage remains diagnostic; scenario coverage is the release criterion.

For every changed critical path, evidence maps tests for:

- success/lifecycle;
- validation;
- authorization;
- exact replay;
- conflicting replay;
- database conflict/concurrency;
- failure before side effect;
- uncertainty after possible side effect;
- redaction/serialization;
- migration/temporal compatibility.

Before Phase 0.4 closure, record the stable aggregate/changed-line baseline and identify uncovered critical branches. A numeric threshold may be added only through an ADR with exclusions and rationale. It may never replace invariant mapping.

## Flakiness and time bounds

A flaky test is a defect. Retrying a complete idempotent database transaction or a job after an explicit infrastructure incident is different from rerunning a failing test until green.

Self-hosted jobs have bounded steps. If a job remains unchanged beyond its documented bound:

1. inspect steps/logs;
2. inspect branch concurrency and older runs;
3. inspect runner service state;
4. do not create a commit/dispatch storm;
5. preserve the incident evidence.

## Performance certification

Final throughput/latency targets require realistic owner volume before `0.9.0`. Until then, only a documented smoke baseline is valid. Staging evidence records workload model, data size, concurrency, duration, p50/p95/p99, error rate, queue recovery, connections, and host facts. Invented targets are prohibited.

## Evidence and defect handling

Each accepted run records:

- exact PR head SHA and whether GitHub checked out a PR merge ref;
- run ID/number and every mandatory job conclusion;
- PHP/Composer/MariaDB/Redis relevant versions;
- exact command and exit result;
- test/assertion counts from executable logs;
- JUnit/Clover and relevant policy outputs;
- artifact name/ID and independent digest;
- sanitized environment/runner facts;
- unsupported claims and remaining risks.

Failed tests create a defect linked to requirement/test IDs. Financial, authorization, remote-idempotency, restore, and Critical/High security tests cannot be quarantined.

## Phase gates

| Phase | Exit evidence |
|---|---|
| `0.1.0` | complete requirement mapping, architecture/test/security review, no unresolved Critical business ambiguity |
| `0.2.0` | target-like install; MariaDB/authenticated Redis; installer/runtime/ingress gates and evidence |
| `0.3.0` | identity/privacy, agent, authorization, sensitive approval and Owner lifecycle evidence |
| `0.4.0` | Catalog/Offering/Capacity/Trial and Panel adapter contracts, remote idempotency, complete traceability and closure audit |
| `0.5.0` | payment, matching, redemption, wallet, concurrency and no-provision-before-capture evidence |
| `0.6.0` | provisioning failure/uncertainty matrix and duplicate-service prevention evidence |
| `0.7.0` | complete Persian Telegram E2E, content/support/broadcast idempotency evidence |
| `0.8.0` | reporting/operations plus restore and update/rollback rehearsals |
| `0.9.0` | full regression/security/performance/chaos; no open Critical/High; signed RC evidence |
| `1.0.0` | production package/checksum, final reports, deployment and post-deployment acceptance |
