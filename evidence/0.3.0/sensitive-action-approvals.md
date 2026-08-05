# Phase 0.3.0 evidence — sensitive action approvals

Status: verified by the mandatory CI quality gate.

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

## Mandatory CI verification

- Verified implementation SHA: `5e6ef5a240b2ab82873e88d757191a56b6eded32`.
- CI run: `31029222468` (`CI` run number `780`).
- Result: success across repository preflight, PHP static quality, MariaDB/Redis integration tests, dependency/license policy and secret scan.
- Automated suite: `167` tests passed with `802` assertions.
- Test evidence artifact: `test-evidence-31029222468`.

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
