# Test Strategy

Status: planning baseline

Applies to: `0.1.0` through `1.0.0`

Primary requirements: `QUA-001`, `SEC-001`, `OPS-003`, `BAK-001`, `BAK-002`, `INS-001`, `UPD-001`

## Purpose

Testing proves the product invariants; it is not a claim that defects cannot exist. A release is blocked whenever a mandatory test fails, required evidence is missing, or a Critical/High security finding remains unresolved.

The traceability matrix in `docs/02-requirement-traceability-matrix.md` is the authoritative mapping from requirement to design, implementation, test, command, result, and evidence. Test names must include the relevant requirement ID, for example `PAY_003_duplicate_callback_captures_once`.

## Test environments

Evidence paths have distinct purposes: `build/evidence/` is transient local/CI output and is uploaded as a GitHub Actions artifact; `evidence/<phase>/` contains reviewed repository manifests that point to immutable runs; `storage/app/private/operations/evidence/` retains redacted staging/production operational evidence outside the public web root.

| Environment | Purpose | Database/Redis | External systems | Evidence |
|---|---|---|---|---|
| Local | Fast unit and focused integration work | Disposable MariaDB and authenticated Redis from `docker-compose.ci.yml` | Fakes only | `build/evidence/local/<UTC timestamp>/` |
| GitHub Actions | Mandatory repeatable merge gates | Disposable MariaDB and authenticated Redis | Fakes and deterministic contract fixtures | Workflow artifact `ci-evidence-<run id>` |
| Staging | Target-like acceptance | Dedicated non-production MariaDB/Redis | Sandbox or controlled test accounts | `storage/app/private/operations/evidence/<release>/staging/` |
| Production | Deployment verification only | Production | Live providers | `storage/app/private/operations/evidence/<release>/production/`; redacted export |

Production data, tokens, customer identifiers, subscription links, receipts, and provider payloads must never be copied to CI fixtures. Test credentials are scoped and revoked before release.

## Determinism and test architecture

- PHP 8.4 and Laravel 13 are the baseline.
- CI integration tests run against real MariaDB and authenticated Redis; SQLite is not accepted as migration or financial-integrity evidence.
- Time, randomness, HTTP clients, Telegram, SMS, panels, payment providers, and storage destinations use injectable contracts.
- Fake adapters are deterministic and model success, definitive failure, retryable failure, timeout-before-effect, timeout-after-effect, duplicate events, and out-of-order events.
- Tests isolate state and are parallel-safe. A test requiring serialization states why.
- Money uses integer IRR or fixed-precision decimals; floating-point monetary tests are forbidden.
- Database uniqueness and transactions are tested as the final duplicate-effect barrier. Redis locks alone do not satisfy an idempotency test.
- Logs and generated evidence are passed through secret/PII redaction checks.

## Required suites

| Suite | Minimum scope | Runs |
|---|---|---|
| Unit | Money conversion/rounding, policies, state machines, pricing, permissions, matching, mappings, masks | PR and push |
| Database integration | Migrations, constraints, ledger balance, holds, reservations, Outbox, encryption casts | PR and push |
| Contract | Fake and Generic REST adapters; signed webhook fixtures; provider version notes | PR; live/sandbox on staging |
| Concurrency/property | Wallet, exact amount, discount/capacity reservations, callbacks, receipt decisions, redemption, provisioning | PR and nightly/staging stress |
| Telegram E2E | Onboarding, purchase, all payment paths, delivery, support, broadcast, Back/Cancel/replay | PR with fake API; staging with test bot |
| Security | Authentication, authorization, IDOR, CSRF, SSRF/DNS rebinding, upload, injection, replay, rate limit, redaction | PR; full before RC |
| Performance | Agreed workload, webhook bursts, purchases, ingestion, broadcasts, sync, reports, recovery | Staging before RC |
| Chaos | Redis restart, deadlock, killed worker, provider/panel timeout, disk pressure, interrupted backup/deploy | Staging before RC |
| Install/update/restore | Fresh install, installer lock, update, failed migration/smoke, symlink rollback, encrypted restore | Target-like staging |

The detailed cases mandated by Master Prompt sections 30.2–30.15 are copied into the traceability matrix as test IDs; omission is a release blocker.

## Non-negotiable invariant tests

These tests cannot be quarantined or waived for a production release:

1. No provisioning before authoritative capture, except typed trial/gift/admin-grant sources.
2. A provider transaction, gift-card redemption, wallet capture, refund, callback, Telegram update, or manual decision creates at most one financial effect.
3. One paid order item creates at most one active remote service identity, including timeout-after-create and worker-crash cases.
4. Every ledger transaction balances; available balance never becomes negative; reconciliation finds no unexplained mismatch.
5. Explicit administrator deny overrides role grants and every action re-authorizes server-side.
6. A backup is encrypted and checksummed, and a full restore rehearsal passes.
7. An update package is verified, activated atomically, and rollback compatibility is enforced.

## CI command contract

Once `composer.json` exists, the following Composer scripts are mandatory. CI fails if any is absent; a missing script is never treated as a skipped pass.

```bash
composer ci:static
composer ci:test
composer ci:forbidden-patterns
composer ci:licenses
```

Expected responsibilities:

- `ci:static`: `pint --test`, Larastan/PHPStan at the configured strict level, and architecture tests.
- `ci:test`: complete automated suite, JUnit at `build/evidence/tests/junit.xml`, and Clover at `build/evidence/coverage/clover.xml`.
- `ci:forbidden-patterns`: fail on disabled TLS verification, monetary floats, unsafe raw SQL concatenation, direct `env()` outside config, unlocalized presentation strings, and known secret patterns.
- `ci:licenses`: create `build/evidence/dependencies/licenses.json` and fail on abandoned, unknown, or policy-incompatible packages.

Exact CI commands are in `.github/workflows/ci.yml`. To reproduce locally from repository root:

```bash
docker compose -f docker-compose.ci.yml up -d --wait
composer validate --strict --no-check-publish
composer install --no-interaction --prefer-dist --no-progress
composer audit --locked
composer ci:static
composer ci:forbidden-patterns
composer ci:test
composer ci:licenses
docker compose -f docker-compose.ci.yml down --volumes
```

Expected result: every command exits `0`; MariaDB and Redis health checks report healthy; JUnit, coverage, dependency, and command logs exist under `build/evidence/`. Send the first failing command, exit code, and its redacted log when troubleshooting.

## Coverage policy

Coverage percentage is diagnostic, not a substitute for scenario coverage. The release gate checks:

- every requirement row has at least one automated test and current evidence;
- every financial/authorization/provisioning invariant has branch and failure-path coverage;
- changed code has meaningful tests;
- uncovered high-risk paths are release blockers regardless of aggregate percentage.

An initial numeric baseline is recorded at `0.2.0`. Raising or lowering a threshold requires an ADR and cannot remove an invariant test.

## Performance certification

Final throughput and latency targets require the owner's realistic volume before `0.9.0`. Until then, CI runs only a documented smoke baseline. Staging evidence must record workload model, dataset size, concurrency, duration, p50/p95/p99 latency, error rate, queue recovery time, database connections, and host specifications. Invented targets are prohibited.

## Evidence and defect handling

Each run records:

- Git SHA, release, UTC time, runner image/PHP/Composer/MariaDB/Redis versions;
- exact command and exit code;
- JUnit and coverage outputs;
- migration/schema and reconciliation results;
- dependency audit, license inventory, and secret scan;
- sanitized logs and environment manifest.

Evidence is immutable for the release candidate. Failed tests create a defect linked to requirement/test IDs. Flaky tests are defects: they remain blocking unless the test is proven irrelevant by documented specialist review. Financial, authorization, provisioning-idempotency, restore, and Critical/High security tests cannot be quarantined.

## Phase gates

| Phase | Exit evidence |
|---|---|
| `0.1.0` | Complete requirement mapping, architecture/test/security review, no unresolved Critical business ambiguity |
| `0.2.0` | Clean target-like install; migrations on MariaDB; authenticated Redis; static gates and foundation tests pass |
| `0.3.0` | Authorization matrix, OTP abuse, and identity privacy tests pass |
| `0.4.0` | Panel adapter contracts and remote idempotency tests pass |
| `0.5.0` | Payment, matching, redemption, wallet, concurrency, and no-provision-before-capture tests pass |
| `0.6.0` | Failure/uncertain-result matrix and duplicate-service prevention pass |
| `0.7.0` | Telegram E2E and broadcast idempotency pass |
| `0.8.0` | Restore, update/rollback, and operations failure rehearsals pass |
| `0.9.0` | Full regression/security/performance/chaos; no open Critical/High; signed RC evidence |
| `1.0.0` | Production package/checksum, final reports, deployment and post-deployment checks accepted |
