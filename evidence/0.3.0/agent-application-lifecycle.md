# Phase 0.3.0 evidence — agent application and profile lifecycle

Status: implementation prepared; mandatory CI evidence pending.

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
