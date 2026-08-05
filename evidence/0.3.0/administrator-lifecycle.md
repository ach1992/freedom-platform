# Phase 0.3.0 evidence — administrator lifecycle

Status: verified by the mandatory CI quality gate.

Requirements: `ADM-001`, `ACL-002`, `SEC-002`, `QUA-001`, `QUA-011`.

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

## Mandatory CI verification

- Verified implementation SHA: `48d0b9e8a0abb87fc7cd3d2a6b5267e8c171cb1d`.
- CI run: `31034601238` (`CI` run number `795`).
- Result: success across repository preflight, PHP static quality, MariaDB/Redis integration tests, dependency/license policy and secret scan.
- Automated suite: `194` tests passed with `958` assertions.
- Test evidence artifact: `test-evidence-31034601238`.

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
