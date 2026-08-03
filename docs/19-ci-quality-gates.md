# CI and Quality Gates

## Gate model

`.github/workflows/ci.yml` has two modes:

1. **Planning mode**: when `composer.json` is absent, CI records that application gates are `not_applicable`; it validates only repository/planning policy. This is not application-test evidence.
2. **Application mode**: once `composer.json` exists, `composer.lock`, `artisan`, and all required Composer CI scripts become mandatory. Missing tools or scripts fail the workflow.

This transition is automatic and irreversible in practice: removing the application manifest from an application branch is a review-blocking scope regression.

## Mandatory checks

| Check | Blocking condition | Evidence |
|---|---|---|
| Repository preflight | Invalid planning/application state; `composer.json` without lock or `artisan` | `build/evidence/preflight/summary.md` |
| Composer validation | Invalid manifest/lock | `build/evidence/static/composer-validate.log` |
| Static quality | Pint, Larastan/PHPStan, or architecture check fails | `build/evidence/static/` |
| Forbidden patterns | TLS bypass, monetary float, unsafe query/env/localization or other policy grep fails | `build/evidence/static/forbidden-patterns.log` |
| Tests | Any test/migration/integration/concurrency suite fails | `build/evidence/tests/` |
| Dependency audit | High/Critical known vulnerability lacks approved mitigation | `build/evidence/dependencies/audit.json` |
| License policy | Unknown, abandoned, or incompatible dependency | `build/evidence/dependencies/licenses.json` |
| Secret scan | Credential-like material exists in Git history/change | GitHub job log and scanner result |
| Evidence contract | Expected artifacts absent or empty | Artifact upload/check step |

Application jobs use PHP 8.4, MariaDB and authenticated Redis from `docker-compose.ci.yml`. CI-only passwords are fixed public test values and must never be reused outside disposable test environments.

## Composer script contract

The Foundation phase must add these scripts to `composer.json`:

```json
{
  "scripts": {
    "ci:static": "...",
    "ci:test": "...",
    "ci:forbidden-patterns": "...",
    "ci:licenses": "..."
  }
}
```

Ellipses above document the names only; they are not valid implementation. The actual commands must be explicit, version-controlled, and produce the paths listed in `docs/06-test-strategy.md`. CI checks script presence before execution.

## Branch protection

For `main`, require:

- pull request review from someone other than the author;
- all `CI` jobs required after the Foundation phase;
- branch up to date before merge;
- no force push or branch deletion;
- conversation resolution;
- signed commits/tags where the selected repository policy supports them.

Financial, authorization, installer/updater, backup/restore, and provider changes require specialist review. Do not configure a path filter that allows application changes to bypass CI.

## Vulnerability decisions

`composer audit --locked --format=json` is mandatory. Critical/High findings block release. A temporary mitigation requires a risk-register entry containing package/advisory, reachability, compensating control, owner, expiry, and removal plan; it cannot waive a reachable Critical issue. Dependency allowlists live in version control and cannot be hidden in workflow UI.

## Evidence retention

The workflow uploads sanitized evidence even on failure. Retain CI artifacts for at least 30 days during development and preserve the successful release-candidate run with the release record. Release evidence must name Git SHA and must not be mutable after sign-off.

## Local verification

Run from repository root:

```bash
docker compose -f docker-compose.ci.yml config --quiet
docker compose -f docker-compose.ci.yml up -d --wait
composer validate --strict --no-check-publish
composer install --no-interaction --prefer-dist --no-progress
composer audit --locked
composer ci:static
composer ci:forbidden-patterns
composer ci:test
composer ci:licenses
docker compose -f docker-compose.ci.yml down --volumes
```

Expected result: all commands exit `0`, services are healthy, and evidence files exist. The exact first failing command plus its sanitized evidence file is the only useful troubleshooting input.

## Release gate escalation

The Lead may stop a merge or release for a missing mapping/evidence even when CI is green. CI success does not replace staging acceptance, provider contract evidence, a restore rehearsal, update/rollback rehearsal, or independent security review.

