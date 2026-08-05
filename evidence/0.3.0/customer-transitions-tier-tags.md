# Phase 0.3.0 evidence — customer transitions, tiers and tags

Status: implementation prepared; mandatory CI evidence pending.

Implementation head: `747905cbef18205fd023dd6254c17815ca932324`.

Requirements: `ONB-005`, `USR-002`, `USR-003`, `SEC-002`, `QUA-001`, `QUA-011`.

## Delivered boundary

- validated mutation context with stable request fingerprint, correlation ID, reason and actor identity;
- database-idempotent customer status transitions with row locking, append-only history and safe audit evidence;
- replay protection that prevents an old request from reverting a later customer state;
- canonical tier thresholds for `new`, `normal`, `loyal` and `vip` with total-spend criteria present but disabled by default;
- deterministic automatic tier calculator using integer metrics only;
- manual tier assignment, lock/unlock, append-only history and safe audit evidence;
- automatic promotion with locked-tier protection and automatic downgrade disabled by default;
- customer tag assignment, removal and reactivation without deleting the historical assignment row;
- append-only audit records for tag mutations;
- unique `(action, request_fingerprint)` database barrier for mutation idempotency;
- active-administrator checks and transaction rollback on authorization failure;
- unit and MariaDB integration tests for thresholds, status replay, tier lock/promotion/no-downgrade, tags and uniqueness.

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
