# Phase 0.3.0 evidence — SMS HTTP provider adapters

Status: implementation prepared; mandatory CI evidence pending.

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

## Verification plan

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

No green-test claim is made until a recorded CI run completes.

## Remaining activation gate

Real Melli Payamak and Kavenegar credentials are not required for this package. A later controlled activation must securely supply test credentials, verify sender authorization and destination behavior, and record a redacted provider receipt without exposing the OTP or credentials.
