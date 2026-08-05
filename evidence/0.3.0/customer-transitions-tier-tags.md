# Phase 0.3.0 evidence — customer transitions, tiers and tags

Status: verified in mandatory self-hosted CI.

Verified head: `733d10d11cbe6a56dbd041b11a435fb18542fc8d`.

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

## Verification

GitHub Actions run: `30966783453`

Commands executed by the mandatory CI pipeline:

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

Results:

- repository preflight and traceability: passed;
- Gitleaks secret scan: passed;
- Pint: passed;
- PHPStan/Larastan: passed;
- architecture and forbidden-pattern policies: passed;
- Composer validation, dependency audit and license policy: passed;
- MariaDB and authenticated Redis suite: **150 tests, 730 assertions, zero failures/errors/warnings**;
- test artifact `8915032432`, SHA-256 `8105512d817140a2f03223f6ba2c91cdf62d2d261bb11de560e5767bdd18d399`;
- static artifact `8915044753`, SHA-256 `2b89f60df4f4fe67e4ed1349d92b017d0b683fbd3b6add85a417167bbc84d5ae`;
- dependency artifact `8915023135`, SHA-256 `6672525bc4c7244b181c4f16107cfb3a124cdf9e3f28188f21afd02e3e04d825`;
- preflight artifact `8915012258`, SHA-256 `5e0851a9aeba94c0c3740cd4a37e2c762f2fc788f1b0331f4d2634b8937b18c1`;
- Gitleaks SARIF artifact `8915017818`, SHA-256 `6842f09aea89640cdc5b40480571ea8c0f6c5f5ed28fe60224237b0ac81a819d`.

## Gate conclusion

The customer state/tier/tag increment has no known Critical/High finding. Customer transitions are transactional and idempotent, tier policies preserve manual locks and the no-downgrade default, tag history is retained, and every mutation records append-only safe audit evidence. Phase `0.3.0` remains open for the agent lifecycle and authorization-management packages.
