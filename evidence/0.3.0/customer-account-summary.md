# Phase 0.3.0 evidence — customer My Account summary

Status: verified by the mandatory CI quality gate.

Requirements: `USR-001`, `USR-002`, `USR-003`, `SEC-003`, `QUA-001`, `QUA-011`.

## Delivered boundary

- self-only account-summary authorization;
- stable public account identity, commercial account type/status and locale;
- join and last-seen timestamps;
- current customer tier and manual-lock state;
- phone verification method/status/time without phone decryption;
- aggregate identity status and masked per-item lifecycle summary;
- active customer tags only;
- agent status, approval time and latest cooperation-application state;
- administrator status and Owner flag kept separate from commercial account classification;
- safe defaults when optional customer, phone, identity, agent or administrator records do not exist;
- deleted accounts fail closed;
- output contract excludes encrypted values, lookup hashes and canonical identifiers;
- feature tests for complete projection, privacy redaction, defaults, self-only access and deleted-account behavior.

## Mandatory CI verification

- Verified implementation SHA: `922fbbcedde55136d8a901446a94e3cc2cbf8549`.
- CI run: `31033653827` (`CI` run number `793`).
- Result: success across repository preflight, PHP static quality, MariaDB/Redis integration tests, dependency/license policy and secret scan.
- Automated suite: `189` tests passed with `917` assertions.
- Test evidence artifact: `test-evidence-31033653827`.

## Verification commands

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

The recorded CI run is the authoritative verification for this increment.
