# Phase 0.3.0 evidence — sensitive action approvals

Status: implementation prepared; mandatory CI evidence pending.

Requirements: `ACL-003`, `SEC-002`, `QUA-001`, `QUA-011`.

## Delivered boundary

- short-lived approval requests bound to permission, action, optional target and requester;
- permissions must be explicitly marked as requiring approval;
- requester authorization and permission version captured at request time;
- independent approval rejects requester self-approval;
- approver authorization and permission version revalidated at decision time;
- explicit approved, rejected, expired and cancelled terminal transitions;
- one-time execution fingerprint and requester-bound consumption;
- current requester permission revalidated immediately before consumption;
- replay-safe request, decision, cancellation and consumption paths;
- append-only sanitized audit evidence with reason and correlation metadata;
- feature tests for independent approval, one-time consumption, permission revocation, approver revocation, terminal states, target binding and fingerprint conflicts.

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
