# Phase 0.3.0 evidence — administrator access management

Status: implementation prepared; mandatory CI evidence pending.

Requirements: `ACL-001`, `ACL-002`, `SEC-002`, `QUA-001`, `QUA-011`.

## Delivered boundary

- reusable authorization provider shared by access control and dependent modules;
- multi-role assignment and revocation with one durable assignment row per administrator/role;
- seeded role grants for the current identity, customer and agent permission boundary;
- per-administrator `inherit`, `allow` and `deny` overrides with explicit-deny precedence;
- immediate `permission_version` increments for effective role and override mutations;
- current-actor reauthorization inside the transaction;
- protected Owner target behavior outside the ownership-transfer flow;
- non-Owner self-management prevention and delegation ceilings;
- mandatory actor/reason/correlation metadata and append-only safe audit records;
- request-fingerprint replay protection and conflict detection;
- MariaDB feature tests for role union, deny/allow/inherit, replay, revocation/reactivation, unauthorized access, delegation ceilings and Owner protection.

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
