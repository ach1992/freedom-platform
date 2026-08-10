# Self-Hosted CI Runner Contract

This document defines the runtime contract for mandatory CI. It is not a production deployment guide.

## 0. Repository-wide execution rule

All GitHub Actions jobs in this repository must execute on the owner-controlled self-hosted runner. The canonical job selector is:

```yaml
runs-on: [self-hosted, Linux, X64, freedom-staging, php84]
```

This applies to CI, provider/readiness/live workflows, staging diagnostics, bootstrap workflows targeting `main`, and disabled/historical workflows. GitHub-hosted `ubuntu-*`, `windows-*`, and `macos-*` runner labels are not an allowed fallback. If the self-hosted runner is unavailable, the workflow must remain queued/blocked until the runner/service/labels are corrected.

The repository-wide policy and rationale are authoritative in `docs/development/github-actions-runner-policy.md`.

## 1. Runner identity

Expected runner:

- name: `freedom-staging-runner`;
- labels: `self-hosted`, `Linux`, `X64`, `freedom-staging`, `php84`;
- operating user: `github-runner`;
- repository: same-repository PRs only;
- current capacity: one runner, so jobs that appear parallel in the workflow may execute serially.

Do not assume the runner is disposable. CI must clean repository worktrees, temporary wrappers, Docker resources, and generated evidence without altering production application state.

## 2. Required host tools

The runner host must provide:

- PHP CLI: `/www/server/php/84/bin/php`;
- PHP CLI configuration: `/www/server/php/84/etc/php-cli.ini`;
- Composer: `/usr/local/bin/composer`;
- Docker and `docker compose`;
- Git, Bash, `jq`, `curl`, `sha256sum`, and standard POSIX tools.

Current accepted tool versions:

- PHP `8.4.x`;
- Composer `2.10.2`;
- PCOV `1.0.12` or a reviewed compatible version;
- GitHub Actions runner `2.336.0` or a reviewed later version.

The workflow verifies the effective versions; labels alone are not proof.

## 3. PHP extensions

All PHP jobs require:

- `bcmath`;
- `ctype`;
- `curl`;
- `dom`;
- `fileinfo`;
- `filter`;
- `hash`;
- `intl`;
- `json`;
- `libxml`;
- `mbstring`;
- `openssl`;
- `pcntl`;
- `PDO` and `pdo_mysql`;
- `redis`;
- `session`;
- `sodium`;
- `tokenizer`;
- `xml` and `xmlwriter`.

The integration/coverage job additionally requires PCOV.

## 4. Deterministic PHP wrapper

Every PHP job must run:

```bash
bash scripts/ci/bootstrap-self-hosted-toolchain.sh <coverage|no-coverage>
```

The script:

- selects the exact PHP binary and `php-cli.ini` rather than relying on the runner service's ambient environment;
- creates temporary `php` and `composer` wrappers under `$RUNNER_TEMP`;
- appends only that directory to `$GITHUB_PATH`;
- disables JIT for CI to avoid PCOV/JIT startup diagnostics and non-deterministic subprocess output;
- enables PCOV only for coverage jobs;
- validates PHP 8.4, required extensions, PCOV mode, JIT state, and Composer 2.10.2;
- fails within two minutes when the host contract is not met.

Do not reintroduce `shivammathur/setup-php` on this runner. It attempted privileged package operations through interactive `sudo`, blocked the job, and produced no executable test evidence.

## 5. Coverage mode

Coverage mode must report all of the following before dependencies or services start:

```text
ini=/www/server/php/84/etc/php-cli.ini
pcov_loaded=yes
pcov_enabled=1
jit_buffer_size=0
```

A test artifact without a non-empty Clover file is invalid. A small cleanup-only artifact produced after bootstrap failure is not test evidence.

Non-coverage mode may load PCOV disabled or omit it, depending on extension startup behavior, but it must report `pcov_enabled=0` when loaded and must keep JIT disabled.

## 6. Dependency installation

CI installs the exact lockfile:

```bash
composer install --no-interaction --prefer-dist --no-progress --no-scripts
php artisan package:discover --ansi
```

The split is intentional:

- dependency acquisition remains deterministic and does not execute arbitrary package scripts implicitly;
- Laravel package discovery is explicit and visible in logs;
- `composer.lock` is mandatory;
- dependency updates occur only in a focused, reviewed maintenance change and are followed by audit, license, static, and full-suite gates.

## 7. Mandatory jobs and bounds

| Job | Required outcome | Bound |
|---|---|---:|
| Repository preflight | repository contract and planning traceability pass | 10 min |
| Secret scan | no committed secret finding | 15 min |
| Dependency and license policy | audit, abandoned-package, and license gates pass | 20 min |
| PHP static quality | Pint, Composer validation, PHPStan, forbidden-pattern, and architecture gates pass | 30 min |
| MariaDB 10.11/11.4 and Redis tests | disposable services, full suite, JUnit and Clover artifacts pass for each target line | 45 min per matrix entry |

Individual expensive steps also have smaller workflow timeouts. Do not wait beyond the bound without inspecting current job/runner state.

## 8. MariaDB and Redis isolation

Integration tests use `docker-compose.ci.yml` and a serialized matrix of `MARIADB_VERSION=10.11` and `MARIADB_VERSION=11.4`. The Compose image resolves as `mariadb:${MARIADB_VERSION:-11.4}`; each matrix entry has its own per-run Compose project name, so the fixed loopback ports remain isolated on the single-capacity runner. The job must:

1. run `docker compose ... up -d --wait`;
2. confirm service state;
3. execute the suite with process environment selecting the matrix MariaDB version and Redis;
4. capture sanitized service logs on success or failure;
5. always run `docker compose ... down --volumes --remove-orphans`;
6. upload JUnit, Clover, and service evidence with an artifact name containing the MariaDB matrix identity.

`phpunit.xml` contains safe local defaults for developer execution. Mandatory CI environment variables override those defaults. Isolation-sensitive database tests must run against MariaDB, not SQLite.

## 9. Protected PasarGuard live acceptance

The PasarGuard live-mutation workflow declares the `provider-live-acceptance` GitHub Environment. It retains the integration-branch and typed-confirmation restrictions; absent provider configuration stops validation before any provider mutation.

**Human-only setup (once):** Create the `provider-live-acceptance` Environment in GitHub, restrict deployment branches to `develop/v1.0.0-completion`, require reviewer approval, and move the PasarGuard provider secrets into that Environment.

## 10. Concurrency and queue behavior

CI groups runs by workflow and PR head branch with `cancel-in-progress: true`.

- A new head supersedes older head runs.
- Do not repeatedly commit or dispatch workflows while the single runner is occupied.
- A superseded job that remains `in_progress` with no steps/logs can block all newer jobs; inspect the runner service rather than creating more runs.
- Connector commits may not always trigger Actions immediately; use a manual workflow dispatch only when no exact-head run exists.
- A queued job caused by an offline, busy, or label-mismatched self-hosted runner must not be moved to GitHub-hosted capacity.

## 11. Host changes

Installing or updating PHP, extensions, Docker, the Actions runner, permissions, or service configuration is a host operation, not an application commit.

For every host change:

- record the exact reason and command;
- back up the modified configuration;
- verify as user `github-runner`;
- avoid granting unrestricted passwordless `sudo`;
- state whether a service restart is required;
- retain a rollback command;
- update this contract when the accepted runtime changes.

Current PCOV installation affects CLI configuration only. It must not be treated as proof of the separate LSPHP production runtime.

## 12. Evidence rules

Accepted CI evidence records:

- exact PR head SHA;
- workflow run ID and number;
- job names and conclusions;
- exact test/assertion counts from executable test logs;
- artifact name and ID;
- independent artifact SHA-256;
- artifact content inspection result;
- relevant environment facts without secrets.

Runner labels, a green summary without logs, a GitHub-hosted execution, or an uploaded artifact created only by `if: always()` cleanup do not prove implementation behavior.
