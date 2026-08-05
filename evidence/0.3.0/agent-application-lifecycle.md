# Phase 0.3.0 evidence — agent application and profile lifecycle

Status: verified by the mandatory CI quality gate.

Requirements: `AGT-001`, `AGT-002`, `ACL-001`, `ACL-002`, `SEC-002`, `QUA-001`, `QUA-011`.

## Delivered boundary

- idempotent cooperation application submission with one active application per customer;
- explicit `submitted`, `under_review`, `approved`, `rejected` and `withdrawn` states;
- row-locked review claim and release;
- atomic approval creating the agent profile, assigning a pricing profile and changing account type;
- rejection with mandatory reason, configurable cooldown and explicit administrator release;
- customer withdrawal and safe resubmission;
- active, limited and suspended profile states with append-only status history;
- owner-aware, role-based permission authorizer with explicit-deny precedence;
- append-only transition history and sanitized audit evidence;
- request-fingerprint replay protection and unique business constraints;
- database tests for application uniqueness, replay, approval, rejection/reapplication, permission denial and profile transitions.

## Mandatory CI verification

- Verified implementation SHA: `2df392850b08a3ceb2560a4d8967bcf4c2626ca4`.
- CI run: `31013386688` (`CI` run number `772`).
- Result: success across repository preflight, PHP static quality, MariaDB/Redis integration tests, dependency/license policy and secret scan.
- Automated suite: `155` tests passed with `750` assertions.
- Test evidence artifact: `test-evidence-31013386688`.

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
