# Phase 0.3.0 evidence — SMS HTTP provider adapters

Status: verified in mandatory self-hosted CI.

Requirements: `ONB-004`, `SEC-003`, `INT-002`, `QUA-001`, `QUA-011`.

## Delivered boundary

- localized OTP message rendering with explicit locale and code redaction;
- `MelliPayamakSmsProvider` over the official HTTPS REST endpoint;
- `KavenegarSmsProvider` over the official HTTPS REST endpoint;
- fixed endpoints and normal TLS verification with no configurable `verify=false` path;
- no automatic HTTP send retry;
- accepted / definitive-failure / uncertain normalization;
- fallback only after definitive failure;
- deterministic Kavenegar `localid` from the application idempotency key;
- real providers disabled by default while fake providers remain active;
- environment placeholders with no committed credentials;
- fake HTTP contract tests for successful, rejected, rate-limited, malformed, transport, and provider-unavailable outcomes;
- credential and sender redaction in configuration debug output.

## Verification

Implementation SHA: `5a9efad6932d2f26719f0f92d8e6c20995dbe5ce`  
GitHub Actions run: `30965519559`

Commands executed by the mandatory CI pipeline:

```bash
bash scripts/ci/verify-planning.sh
vendor/bin/pint --test --format=json
composer validate --strict --no-check-publish
vendor/bin/phpstan analyse --no-progress --error-format=table
composer ci:forbidden-patterns
composer ci:architecture
composer audit --locked --abandoned=fail --format=json
composer ci:licenses
docker compose -f docker-compose.ci.yml up -d --wait
php artisan config:clear --ansi
COLUMNS=240 php artisan test --display-warnings --fail-on-warning --log-junit build/evidence/tests/junit.xml --coverage-clover build/evidence/coverage/clover.xml
docker compose -f docker-compose.ci.yml down --volumes --remove-orphans
```

Results:

- repository preflight and requirement traceability: passed;
- Gitleaks secret scan: passed;
- Pint: passed;
- PHPStan/Larastan: passed;
- architecture and forbidden-pattern policies: passed;
- Composer validation, dependency audit and license policy: passed;
- MariaDB and authenticated Redis suite: **143 tests, 703 assertions, zero failures/errors/warnings**;
- SMS HTTP provider contract tests: **8 passed**;
- test artifact `8914572318`, SHA-256 `fe9aedeacd490f293bc17308aee2ca701a5b1082db07c22d8c11469d2a48edb4`;
- static artifact `8914587180`, SHA-256 `3a0d49efe9950eca5ab368d85eeafded589b9cae645120f041353ed844484ef4`;
- dependency artifact `8914576805`, SHA-256 `d0610f366125bbb0f6ff163189aac0f957e6256d9033ef4aa7771f827b17353a`;
- preflight artifact `8914562840`, SHA-256 `57d1f6ca3e0b7d589025f80dfc6419dc2d85f5c166eeffa9980aebf49a681e10`;
- Gitleaks SARIF artifact `8914559717`, SHA-256 `b5988e16878ff83afb55ec024ce42422097640de241a896165ad0145cf27a77b`.

## Remaining activation gate

Real Melli Payamak and Kavenegar credentials are not required for this package. A later controlled activation must securely supply test credentials, verify sender authorization and destination behavior, and record a redacted provider receipt without exposing the OTP or credentials.
