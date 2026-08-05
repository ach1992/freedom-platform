# Phase 0.3.0 evidence — protected Owner transfer

Status: verified by the mandatory CI quality gate.

Requirements: `ACL-003`, `ADM-002`, `SEC-002`, `QUA-001`, `QUA-011`.

## Delivered boundary

- database-enforced singleton Owner invariant;
- separate two-step request and receiving-owner acceptance flow;
- current Owner authorization and recent reauthentication at request time;
- receiving administrator identity binding and recent reauthentication at acceptance time;
- short-lived request expiry and explicit cancellation;
- signed intent binding transfer ID, old/new Owner IDs, request fingerprint, correlation ID, permission versions and expiry;
- stale permission-version and tampered-intent rejection;
- row-locked atomic demotion/promotion with permission-version invalidation;
- one active request per current Owner and receiving administrator;
- replay-safe request, acceptance and cancellation;
- append-only safe audit evidence containing old/new Owner identifiers and state only;
- feature tests for exact-once acceptance, singleton enforcement, reauthentication, receiving-owner binding, stale/tampered intent, expiry, cancellation and fingerprint conflict.

## Mandatory CI verification

- Verified implementation SHA: `64bdda369868ceb0c43557cc84701bdeed279e68`.
- CI run: `31030935014` (`CI` run number `786`).
- Result: success across repository preflight, PHP static quality, MariaDB/Redis integration tests, dependency/license policy and secret scan.
- Automated suite: `173` tests passed with `844` assertions.
- Test evidence artifact: `test-evidence-31030935014`.

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
