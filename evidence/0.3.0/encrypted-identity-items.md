# Phase 0.3.0 evidence — encrypted identity items

Status: candidate implementation assembled; mandatory CI evidence pending.

Requirements: `USR-001`, `ACL-002`, `SEC-003`, `DAT-003`, `QUA-001`, `QUA-011`.

Candidate implementation SHA before verification trigger: `967daee6ff352fc5d3ed574377f4690054ed08ba`.

## Delivered boundary

- independent national-ID, bank-card and full-name identity items;
- canonical normalization and checksum validation for Iranian national IDs and bank cards;
- encrypted-at-rest canonical values with masked display values;
- keyed opaque lookup hashes with key-version metadata;
- global active uniqueness for national ID and bank card while allowing duplicate full names;
- explicit unverified, pending, verified and rejected lifecycle states;
- optional ownership-check pending, matched and mismatched states;
- ownership-required items cannot be verified before a successful match;
- authorized administrator verification/rejection with current permission checks;
- customer resubmission after rejection and versioned append-only histories;
- replay-safe request fingerprints and safe audit payloads that exclude canonical values and hashes;
- configurable required-item policy with aggregate customer identity status;
- masked My Account summary projection without sensitive value decryption;
- feature and unit tests for encryption, masking, uniqueness, validation, replay, ownership checks, authorization and aggregate status.

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
