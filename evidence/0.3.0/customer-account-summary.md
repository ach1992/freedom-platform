# Phase 0.3.0 evidence — customer My Account summary

Status: candidate implementation assembled; mandatory CI evidence pending.

Requirements: `USR-001`, `USR-002`, `USR-003`, `SEC-003`, `QUA-001`, `QUA-011`.

Candidate implementation SHA before verification trigger: `d81a770402459ba119e8b868c5b79aaa5f557a7a`.

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
