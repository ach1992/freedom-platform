# Phase 0.3.0 evidence — administrator lifecycle

Status: candidate implementation assembled; mandatory CI evidence pending.

Requirements: `ADM-001`, `ACL-002`, `SEC-002`, `QUA-001`, `QUA-011`.

Candidate implementation SHA before verification trigger: `a06b252af31087128d55542e0ec04f6032943152`.

## Delivered boundary

- explicit active, suspended and terminal revoked administrator states;
- Owner-authorized lifecycle mutations with current permission revalidation;
- non-Owner self-management denial;
- Owner target protection outside the ownership-transfer flow;
- immediate permission-version increments and authentication-session invalidation;
- suspension preserves role assignments and overrides while effective authorization fails closed;
- reactivation restores effective role/override authorization without restoring a prior authenticated session;
- revocation atomically terminates active role assignments and removes direct overrides;
- terminal revoked accounts cannot be reactivated;
- append-only status histories with actor, reason, correlation, permission version and revoked-access counts;
- replay-safe request fingerprints and sanitized audit evidence;
- feature tests for suspend/reactivate, immediate authorization invalidation, terminal revocation, access cleanup, Owner/self protection, unauthorized actors and fingerprint conflicts.

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
