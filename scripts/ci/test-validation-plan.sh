#!/usr/bin/env bash

set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
classifier="$root/scripts/ci/classify-validation-plan.sh"
retired_secret_guard="$root/scripts/ci/reject-retired-actions-secrets.sh"
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

fail() {
    echo "Validation-plan classifier test failed: $*" >&2
    exit 1
}

assert_plan() {
    local name=$1
    local expected_profile=$2
    local expected_project_control=$3
    local expected_planning=$4
    local expected_control_plane=$5
    local expected_unit=$6
    local expected_style=$7
    local expected_static=$8
    local expected_dependencies=$9
    local expected_integration=${10}
    local expected_runtime=${11}
    local expected_operations=${12}
    shift 12

    local paths_file="$tmpdir/$name.paths"
    : > "$paths_file"
    if (($# > 0)); then
        printf '%s\n' "$@" > "$paths_file"
    fi

    local profile project_control planning control_plane unit style static_analysis dependencies integration runtime operations
    while IFS='=' read -r key value; do
        case "$key" in
            profile|project_control|planning|control_plane|unit|style|static_analysis|dependencies|integration|runtime|operations)
                printf -v "$key" '%s' "$value"
                ;;
            *) fail "$name returned unexpected key: $key" ;;
        esac
    done < <(GITHUB_EVENT_NAME= GITHUB_EVENT_PATH= GITHUB_SHA= bash "$classifier" "$paths_file")

    [[ "$profile" == "$expected_profile" ]] || fail "$name profile expected $expected_profile, got $profile"
    [[ "$project_control" == "$expected_project_control" ]] || fail "$name project_control expected $expected_project_control, got $project_control"
    [[ "$planning" == "$expected_planning" ]] || fail "$name planning expected $expected_planning, got $planning"
    [[ "$control_plane" == "$expected_control_plane" ]] || fail "$name control_plane expected $expected_control_plane, got $control_plane"
    [[ "$unit" == "$expected_unit" ]] || fail "$name unit expected $expected_unit, got $unit"
    [[ "$style" == "$expected_style" ]] || fail "$name style expected $expected_style, got $style"
    [[ "$static_analysis" == "$expected_static" ]] || fail "$name static_analysis expected $expected_static, got $static_analysis"
    [[ "$dependencies" == "$expected_dependencies" ]] || fail "$name dependencies expected $expected_dependencies, got $dependencies"
    [[ "$integration" == "$expected_integration" ]] || fail "$name integration expected $expected_integration, got $integration"
    [[ "$runtime" == "$expected_runtime" ]] || fail "$name runtime expected $expected_runtime, got $runtime"
    [[ "$operations" == "$expected_operations" ]] || fail "$name operations expected $expected_operations, got $operations"
}

# Required representative classes. These assertions are deliberately independent of the workflow conditions that consume the plan.
assert_plan docs_only CONTROL true true false false false false false false false false docs/06-test-strategy.md
assert_plan release_docs CONTROL true false false false false false false false false false docs/operations-guide.md docs/provider-extension-guide.md docs/reference/data-dictionary.md docs/reference/permission-catalog.md
assert_plan release_reference_generator APPLICATION true false false false true false false false false false scripts/docs/generate-release-references.php
assert_plan governance_only CONTROL true false false false false false false false false false AGENTS.md .github/ISSUE_TEMPLATE/task.yml
assert_plan provider_readiness_scripts OPERATIONS false false false false false false false false false true scripts/ci/pasarguard-readonly-probe.php scripts/ci/MarzbanReadinessProbe.php scripts/ci/marzban-readonly-probe.php
assert_plan provider_live_harnesses FULL false false false true true false false false false true scripts/ci/PasarGuardLiveAcceptance.php scripts/ci/MarzbanLiveAcceptance.php
assert_plan provider_live_entrypoints OPERATIONS false false false false false false false false false true scripts/ci/pasarguard-live-acceptance.php scripts/ci/marzban-live-acceptance.php
assert_plan application_source APPLICATION false false false true true true false true false false app/Modules/Orders/Application/OrderService.php
assert_plan localization_catalog APPLICATION false false false true true false false false false false resources/lang/fa/telegram.php
assert_plan feature_tests APPLICATION false false false false true false false true false false tests/Feature/OrderTest.php
assert_plan unit_tests APPLICATION false false false true true false false false false false tests/Unit/OrderTest.php
assert_plan dependency_lockfile APPLICATION false false false true false true true true false false composer.lock
assert_plan schema_migration APPLICATION false false false true true true false true false false database/migrations/2026_08_20_000001_example.php
assert_plan docker_runtime APPLICATION false false false false false false false true true false docker-compose.ci.yml
assert_plan docker_mariadb_init APPLICATION false false false false false false false true true false docker/mariadb/ci-init.sql
assert_plan global_pint_policy APPLICATION false false false false true false false false false false pint.json
assert_plan editor_style_policy APPLICATION false false false false true false false false false false .editorconfig
assert_plan deployment_mutation_workflow FULL true true true true true true true true true true .github/workflows/deploy-production.yml
assert_plan security_sensitive APPLICATION false false false true true true false true false false app/Modules/AccessControl/Application/AuthorizationService.php
assert_plan ci_policy CONTROL_PLANE true false true false false false false false false false .github/workflows/ci.yml scripts/ci/classify-validation-plan.sh
assert_plan feature_sharding_control CONTROL_PLANE true false true false false false false false false false scripts/ci/select-feature-shard.sh scripts/ci/test-feature-sharding.sh
assert_plan secret_scan_control CONTROL_PLANE true false true false false false false false false false scripts/ci/scan-git-secrets.sh scripts/ci/test-secret-scan.sh
assert_plan retired_secret_guard CONTROL_PLANE true false true false false false false false false false scripts/ci/reject-retired-actions-secrets.sh
assert_plan operations_only OPERATIONS false false false false false false false false false true deploy/bin/queue-worker-with-heartbeat.sh
assert_plan unknown_view FULL true true true true true true true true true true resources/views/home.blade.php
assert_plan unknown_public_asset FULL true true true true true true true true true true public/example.txt
assert_plan unknown_path FULL true true true true true true true true true true mystery/unclassified.file
assert_plan mixed_application APPLICATION true true false true true true false true false false docs/06-test-strategy.md app/Modules/Orders/Application/OrderService.php
assert_plan empty_diff FULL true true true true true true true true true true

printf '%s\n' 'Validation-plan classifier tests passed.'

# A filtered workflow_dispatch is diagnostic-only. It needs the real runtime +
# integration path, but must not manufacture unrelated merge-acceptance domains.
diagnostic_event="$tmpdir/diagnostic-event.json"
diagnostic_paths="$tmpdir/diagnostic.paths"
diagnostic_plan="$tmpdir/diagnostic.plan"
printf '{"inputs":{"phpunit_filter":"PaymentAuthorizationTest"}}\n' > "$diagnostic_event"
: > "$diagnostic_paths"
GITHUB_EVENT_NAME=workflow_dispatch \
GITHUB_EVENT_PATH="$diagnostic_event" \
GITHUB_SHA= \
bash "$classifier" "$diagnostic_paths" > "$diagnostic_plan"
[[ ! -s "$diagnostic_paths" ]] || fail 'filtered diagnostic dispatch must not invent a changed-path set'
grep -Fx 'profile=DIAGNOSTIC' "$diagnostic_plan" >/dev/null || fail 'filtered diagnostic dispatch did not use DIAGNOSTIC profile'
for expected in \
    'project_control=false' \
    'planning=false' \
    'control_plane=false' \
    'unit=false' \
    'style=false' \
    'static_analysis=false' \
    'dependencies=false' \
    'integration=true' \
    'runtime=true' \
    'operations=false'; do
    grep -Fx "$expected" "$diagnostic_plan" >/dev/null || fail "filtered diagnostic dispatch missing expected plan value: $expected"
done

printf '{"inputs":{"phpunit_filter":""}}\n' > "$diagnostic_event"
: > "$diagnostic_paths"
GITHUB_EVENT_NAME=workflow_dispatch \
GITHUB_EVENT_PATH="$diagnostic_event" \
GITHUB_SHA= \
bash "$classifier" "$diagnostic_paths" > "$diagnostic_plan"
grep -Fx 'profile=FULL' "$diagnostic_plan" >/dev/null || fail 'empty diagnostic filter must retain the intentional FULL manual plan'

printf '{"inputs":{"phpunit_filter":" "}}\n' > "$diagnostic_event"
: > "$diagnostic_paths"
GITHUB_EVENT_NAME=workflow_dispatch \
GITHUB_EVENT_PATH="$diagnostic_event" \
GITHUB_SHA= \
bash "$classifier" "$diagnostic_paths" > "$diagnostic_plan"
grep -Fx 'profile=DIAGNOSTIC' "$diagnostic_plan" >/dev/null || fail 'non-empty filter semantics must match workflow diagnostic conditions'

grep -F 'failOnEmptyTestSuite="true"' "$root/phpunit.xml" >/dev/null \
    || fail 'PHPUnit must fail when a diagnostic filter selects zero tests'
grep -F 'failOnWarning="true"' "$root/phpunit.xml" >/dev/null \
    || fail 'PHPUnit warning policy must remain fail closed'
grep -F '"@php vendor/bin/phpunit --configuration phpunit.xml --testsuite Unit --display-warnings --fail-on-warning"' "$root/composer.json" >/dev/null \
    || fail 'canonical fast Unit runner must use direct PHPUnit with fail-on-warning'
if grep -F '"@php artisan test --testsuite=Unit' "$root/composer.json" >/dev/null; then
    fail 'canonical fast Unit runner must not use the warning-producing Artisan test wrapper'
fi

printf '%s\n' 'Filtered diagnostic, zero-test, and Unit warning-policy tests passed.'


# Retired staging/provider Actions secret interfaces must remain rejected across
# static/dynamic expression forms. Reusable-workflow forwarding is validated from
# resolved YAML semantics so anchors, aliases, tags, scalar styles, and merge keys
# cannot disguise bulk inheritance.
retired_fixture_dir="$tmpdir/retired-secret-fixtures"
mkdir -p "$retired_fixture_dir"

cat > "$retired_fixture_dir/uppercase-property.yml" <<'YAML'
env:
  PROBE: ${{ secrets.STAGING_HOST }}
YAML

cat > "$retired_fixture_dir/lowercase-property.yml" <<'YAML'
env:
  PROBE: ${{ secrets.pasarguard_test_api_key }}
YAML

cat > "$retired_fixture_dir/single-index.yml" <<'YAML'
env:
  PROBE: ${{ secrets['MARZBAN_TEST_PASSWORD'] }}
YAML

cat > "$retired_fixture_dir/double-index.yml" <<'YAML'
env:
  PROBE: ${{ secrets["staging_known_hosts"] }}
YAML

cat > "$retired_fixture_dir/multiline-index.yml" <<'YAML'
env:
  PROBE: ${{ secrets[
    'STAGING_SSH_PRIVATE_KEY'
  ] }}
YAML

cat > "$retired_fixture_dir/dynamic-format.yml" <<'YAML'
env:
  PROBE: ${{ secrets[format('STAGING_%s', matrix.env)] }}
YAML

cat > "$retired_fixture_dir/dynamic-variable.yml" <<'YAML'
env:
  PROBE: ${{ secrets[matrix.secret_name] }}
YAML

cat > "$retired_fixture_dir/bulk-context.yml" <<'YAML'
env:
  PROBE: ${{ toJSON(secrets) }}
YAML

cat > "$retired_fixture_dir/bulk-inherit.yml" <<'YAML'
jobs:
  relay:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets: inherit
YAML

cat > "$retired_fixture_dir/bulk-inherit-anchor.yml" <<'YAML'
jobs:
  relay:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets: &all inherit
YAML

cat > "$retired_fixture_dir/bulk-inherit-alias.yml" <<'YAML'
defaults: &all inherit
jobs:
  relay:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets: *all
YAML

cat > "$retired_fixture_dir/bulk-inherit-quoted.yml" <<'YAML'
jobs:
  relay:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets: "inherit"
YAML

cat > "$retired_fixture_dir/bulk-inherit-tagged.yml" <<'YAML'
jobs:
  relay:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets: !!str inherit
YAML

cat > "$retired_fixture_dir/bulk-inherit-folded.yml" <<'YAML'
jobs:
  relay:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets: >-
      inherit
YAML

cat > "$retired_fixture_dir/bulk-inherit-merge.yml" <<'YAML'
defaults: &defaults
  secrets: inherit
jobs:
  relay:
    <<: *defaults
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
YAML

cat > "$retired_fixture_dir/bulk-inherit-anchor-key.yml" <<'YAML'
jobs:
  relay:
    &secret_key secrets: inherit
YAML

cat > "$retired_fixture_dir/bulk-inherit-alias-key.yml" <<'YAML'
secret_key: &secret_key secrets
jobs:
  relay:
    *secret_key: inherit
YAML

cat > "$retired_fixture_dir/bulk-inherit-plain-multiline.yml" <<'YAML'
jobs:
  relay:
    secrets:
      inherit
YAML

cat > "$retired_fixture_dir/bulk-inherit-single-quoted.yml" <<'YAML'
jobs:
  relay:
    secrets: 'inherit'
YAML

cat > "$retired_fixture_dir/bulk-inherit-yaml12-job-keys.yml" <<'YAML'
jobs:
  on:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets: inherit
  yes:
    runs-on: ubuntu-latest
    steps:
      - run: echo benign
YAML

cat > "$retired_fixture_dir/bulk-inherit-duplicate-job-key.yml" <<'YAML'
jobs:
  relay:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets: inherit
  relay:
    runs-on: ubuntu-latest
    steps:
      - run: echo benign
YAML

cat > "$retired_fixture_dir/safe-yaml12-job-keys.yml" <<'YAML'
jobs:
  on:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets:
      telegram_token: ${{ secrets.TELEGRAM_TEST_BOT_TOKEN }}
  yes:
    runs-on: ubuntu-latest
    steps:
      - run: echo yes
  off:
    runs-on: ubuntu-latest
    steps:
      - run: echo off
  no:
    runs-on: ubuntu-latest
    steps:
      - run: echo no
YAML

cat > "$retired_fixture_dir/safe.yml" <<'YAML'
env:
  STAGING_HOST: 127.0.0.1
  PASARGUARD_TEST_ORIGIN: https://example.invalid
  TELEGRAM_TOKEN: ${{ secrets.TELEGRAM_TEST_BOT_TOKEN }}
  TELEGRAM_TOKEN_INDEX: ${{ secrets['TELEGRAM_TEST_BOT_TOKEN'] }}
secret_map: &secret_map
  telegram_token: ${{ secrets.TELEGRAM_TEST_BOT_TOKEN }}
jobs:
  relay:
    uses: example/example/.github/workflows/reusable.yml@0123456789abcdef0123456789abcdef01234567
    secrets: *secret_map
YAML

for fixture in \
    uppercase-property.yml \
    lowercase-property.yml \
    single-index.yml \
    double-index.yml \
    multiline-index.yml \
    dynamic-format.yml \
    dynamic-variable.yml \
    bulk-context.yml \
    bulk-inherit.yml \
    bulk-inherit-anchor.yml \
    bulk-inherit-alias.yml \
    bulk-inherit-quoted.yml \
    bulk-inherit-tagged.yml \
    bulk-inherit-folded.yml \
    bulk-inherit-merge.yml \
    bulk-inherit-anchor-key.yml \
    bulk-inherit-alias-key.yml \
    bulk-inherit-plain-multiline.yml \
    bulk-inherit-single-quoted.yml \
    bulk-inherit-yaml12-job-keys.yml \
    bulk-inherit-duplicate-job-key.yml; do
    if bash "$retired_secret_guard" "$retired_fixture_dir/$fixture" >/dev/null 2>&1; then
        fail "retired Actions secret guard accepted fixture: $fixture"
    fi
done

bash "$retired_secret_guard" "$retired_fixture_dir/safe.yml" >/dev/null \
    || fail 'retired Actions secret guard rejected a bounded safe fixture'
bash "$retired_secret_guard" "$retired_fixture_dir/safe-yaml12-job-keys.yml" >/dev/null \
    || fail 'retired Actions secret guard rejected distinct YAML 1.2 boolean-like job IDs'
bash "$retired_secret_guard" "$root/.github/workflows/ci.yml" >/dev/null \
    || fail 'current secret-free CI workflow violates retired Actions secret policy'

printf '%s\n' 'Retired Actions secret-reference guard tests passed.'

# The required GitHub status context must be the final aggregate gate, not the early planning job.
ci_workflow="$root/.github/workflows/ci.yml"
python3 - "$ci_workflow" <<'PY_CI_GATE'
from pathlib import Path
import sys

path = Path(sys.argv[1])
text = path.read_text()

def require(fragment: str, message: str) -> None:
    if fragment not in text:
        raise SystemExit(message)

if text.count('    name: Repository preflight\n') != 1:
    raise SystemExit('CI must expose exactly one Repository preflight status context')

try:
    triggers = text.split('on:\n', 1)[1].split('\npermissions:', 1)[0]
except IndexError as exc:
    raise SystemExit('CI trigger block is malformed or missing') from exc
if '\n  push:' in '\n' + triggers:
    raise SystemExit('generic CI must not rerun an already accepted integration on main push')
for trigger in ('  pull_request:\n', '  workflow_dispatch:\n'):
    if trigger not in triggers:
        raise SystemExit(f'generic CI lost required trigger: {trigger.strip()}')
if 'PUSH_BEFORE_SHA' in text:
    raise SystemExit('generic CI retains retired push secret-scan input')

require('  preflight:\n    name: Validation plan and repository control\n', 'early preflight job must not satisfy the required final status context')
require('  integration_shards:\n', 'parallel real-engine shard job is missing')
require('        shard_index: [0, 1, 2, 3, 4, 5, 6, 7]\n', 'normal FULL integration must retain the eight-shard matrix')
require('  integration:\n    name: MariaDB 10.11 and Redis tests\n', 'stable aggregate integration gate is missing')
require('          SHARD_RESULT: ${{ needs.integration_shards.result }}\n', 'aggregate integration gate must consume every shard result')
require('  required:\n    name: Repository preflight\n', 'final aggregate required gate is missing')
required = text.split('\n  required:\n', 1)[1]
for dependency in ('preflight', 'quality', 'dependencies', 'integration', 'operations', 'secrets'):
    require(f'      - {dependency}\n', f'final required gate does not depend on {dependency}')
for command in (
    "require_success 'Validation plan and repository control'",
    "require_success 'Secret scan'",
    "require_planned 'PHP quality and unit tests'",
    "require_planned 'Dependency and license policy'",
    "require_planned 'MariaDB 10.11 and Redis tests'",
    "require_planned 'Operational syntax and entrypoints'",
):
    if command not in required:
        raise SystemExit(f'final required gate does not enforce: {command}')
if "UNIT_REQUIRED: ${{ needs.preflight.outputs.unit }}" not in required:
    raise SystemExit('final required gate must consume the Unit validation plan')
if "needs.preflight.outputs.unit == 'true'" not in text:
    raise SystemExit('quality job must run when Unit validation is planned')
if '      - name: Unit tests\n' not in text or '        run: composer test:quick\n' not in text:
    raise SystemExit('quality job must execute the canonical fast Unit suite')
if '            phpunit_args+=(--testsuite Feature)\n' not in text:
    raise SystemExit('manual/unsharded fallback must retain the Feature suite entrypoint')
if 'bash scripts/ci/select-feature-shard.sh "$shard_count" "$shard_index"' not in text:
    raise SystemExit('normal FULL real-engine CI must partition the complete Feature suite through the shard selector')
if "if [[ \"$GITHUB_EVENT_NAME\" != 'workflow_dispatch' ]]; then" not in text:
    raise SystemExit('Feature-only CI selection must preserve intentional manual full-suite validation')
if text.count('--diff=HEAD^1') < 2:
    raise SystemExit('normal PR Pint validation must use changed-file diff scope in both style execution paths')
if text.count("VALIDATION_PROFILE: ${{ needs.preflight.outputs.profile }}") < 2:
    raise SystemExit('Pint diff scope must consume the fail-safe validation profile in both style execution paths')
if "grep -Eq '^(\\.editorconfig|pint\\.json)$'" not in text:
    raise SystemExit('global style-policy changes must force full-repository Pint validation')
if "if: ${{ always()" not in required:
    raise SystemExit('final required gate must evaluate after failed/skipped dependencies')
if 'Pull request revision drifted after validation; a fresh CI run is required.' not in required:
    raise SystemExit('final required gate must revalidate PR head/base freshness after dependent jobs')
if '      - name: Summarize slow tests\n' not in text:
    raise SystemExit('integration CI must retain low-overhead successful-run timing visibility')
if '            [[ -e "$changed_path" ]] || continue\n' not in text:
    raise SystemExit('repository preflight must ignore deleted changed paths before syntax validation')
PY_CI_GATE

printf '%s\n' 'Required CI gate contract tests passed.'

