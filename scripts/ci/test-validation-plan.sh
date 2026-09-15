#!/usr/bin/env bash

set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
classifier="$root/scripts/ci/classify-validation-plan.sh"
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
    local expected_style=$6
    local expected_static=$7
    local expected_dependencies=$8
    local expected_integration=$9
    local expected_runtime=${10}
    local expected_operations=${11}
    shift 11

    local paths_file="$tmpdir/$name.paths"
    : > "$paths_file"
    if (($# > 0)); then
        printf '%s\n' "$@" > "$paths_file"
    fi

    local profile project_control planning control_plane style static_analysis dependencies integration runtime operations
    while IFS='=' read -r key value; do
        case "$key" in
            profile|project_control|planning|control_plane|style|static_analysis|dependencies|integration|runtime|operations)
                printf -v "$key" '%s' "$value"
                ;;
            *) fail "$name returned unexpected key: $key" ;;
        esac
    done < <(bash "$classifier" "$paths_file")

    [[ "$profile" == "$expected_profile" ]] || fail "$name profile expected $expected_profile, got $profile"
    [[ "$project_control" == "$expected_project_control" ]] || fail "$name project_control expected $expected_project_control, got $project_control"
    [[ "$planning" == "$expected_planning" ]] || fail "$name planning expected $expected_planning, got $planning"
    [[ "$control_plane" == "$expected_control_plane" ]] || fail "$name control_plane expected $expected_control_plane, got $control_plane"
    [[ "$style" == "$expected_style" ]] || fail "$name style expected $expected_style, got $style"
    [[ "$static_analysis" == "$expected_static" ]] || fail "$name static_analysis expected $expected_static, got $static_analysis"
    [[ "$dependencies" == "$expected_dependencies" ]] || fail "$name dependencies expected $expected_dependencies, got $dependencies"
    [[ "$integration" == "$expected_integration" ]] || fail "$name integration expected $expected_integration, got $integration"
    [[ "$runtime" == "$expected_runtime" ]] || fail "$name runtime expected $expected_runtime, got $runtime"
    [[ "$operations" == "$expected_operations" ]] || fail "$name operations expected $expected_operations, got $operations"
}

# Required representative classes. These assertions are deliberately independent of the workflow conditions that consume the plan.
assert_plan docs_only CONTROL true true false false false false false false false docs/06-test-strategy.md
assert_plan governance_only CONTROL true false false false false false false false false AGENTS.md .github/ISSUE_TEMPLATE/task.yml
assert_plan read_only_workflow CONTROL_PLANE true false true false false false false false false .github/workflows/staging-readiness.yml
assert_plan application_source APPLICATION false false false true true false true false false app/Modules/Orders/Application/OrderService.php
assert_plan application_tests APPLICATION false false false true false false true false false tests/Feature/OrderTest.php
assert_plan dependency_lockfile APPLICATION false false false false true true true false false composer.lock
assert_plan schema_migration APPLICATION false false false true true false true false false database/migrations/2026_08_20_000001_example.php
assert_plan docker_runtime APPLICATION false false false false false false true true false docker-compose.ci.yml
assert_plan deployment_mutation_workflow FULL true true true true true true true true true .github/workflows/deploy-production.yml
assert_plan provider_mutation_workflow FULL true true true true true true true true true .github/workflows/provider-live-acceptance.yml
assert_plan security_sensitive APPLICATION false false false true true false true false false app/Modules/AccessControl/Application/AuthorizationService.php
assert_plan ci_policy CONTROL_PLANE true false true false false false false false false .github/workflows/ci.yml scripts/ci/classify-validation-plan.sh
assert_plan secret_scan_control CONTROL_PLANE true false true false false false false false false scripts/ci/scan-git-secrets.sh scripts/ci/test-secret-scan.sh
assert_plan operations_only OPERATIONS false false false false false false false false true deploy/bin/queue-worker-with-heartbeat.sh
assert_plan unknown_path FULL true true true true true true true true true mystery/unclassified.file
assert_plan mixed_application APPLICATION true true false true true false true false false docs/06-test-strategy.md app/Modules/Orders/Application/OrderService.php
assert_plan empty_diff FULL true true true true true true true true true

printf '%s\n' 'Validation-plan classifier tests passed.'

# Normal push events must classify the exact before..after tree change instead of
# treating the initially empty workflow path file as FULL. Unsafe/ambiguous push
# baselines deliberately retain the empty-file FULL fallback.
push_repo="$tmpdir/push-repo"
mkdir -p "$push_repo"
git -C "$push_repo" init -q
git -C "$push_repo" config user.name 'CI Test'
git -C "$push_repo" config user.email 'ci@example.invalid'
printf '%s\n' 'before' > "$push_repo/README.md"
git -C "$push_repo" add README.md
git -C "$push_repo" commit -qm 'before'
push_before=$(git -C "$push_repo" rev-parse HEAD)
printf '%s\n' 'after' > "$push_repo/README.md"
git -C "$push_repo" add README.md
git -C "$push_repo" commit -qm 'after'
push_after=$(git -C "$push_repo" rev-parse HEAD)
push_event="$tmpdir/push-event.json"
push_paths="$tmpdir/push.paths"
push_plan="$tmpdir/push.plan"

run_push_plan() {
    local before=$1
    local after=$2
    local forced=$3
    local github_sha=${4:-$after}

    printf '{"before":"%s","after":"%s","forced":%s}\n' "$before" "$after" "$forced" > "$push_event"
    : > "$push_paths"
    (
        cd "$push_repo"
        GITHUB_EVENT_NAME=push \
        GITHUB_EVENT_PATH="$push_event" \
        GITHUB_SHA="$github_sha" \
        bash "$classifier" "$push_paths"
    ) > "$push_plan"
}

run_push_plan "$push_before" "$push_after" false
grep -Fx 'README.md' "$push_paths" >/dev/null || fail 'normal push did not hydrate its exact changed path'
grep -Fx 'profile=CONTROL' "$push_plan" >/dev/null || fail 'normal documentation push did not classify as CONTROL'
grep -Fx 'integration=false' "$push_plan" >/dev/null || fail 'normal documentation push incorrectly required integration'

run_push_plan "$push_before" "$push_after" true
[[ ! -s "$push_paths" ]] || fail 'forced push must not trust a targeted changed-path plan'
grep -Fx 'profile=FULL' "$push_plan" >/dev/null || fail 'forced push must fail safe to FULL'

zero_sha=0000000000000000000000000000000000000000
run_push_plan "$zero_sha" "$push_after" false
[[ ! -s "$push_paths" ]] || fail 'zero-baseline push must not trust a targeted changed-path plan'
grep -Fx 'profile=FULL' "$push_plan" >/dev/null || fail 'zero-baseline push must fail safe to FULL'

run_push_plan "$push_before" "$push_after" false "$push_before"
[[ ! -s "$push_paths" ]] || fail 'event/head mismatch must not trust a targeted changed-path plan'
grep -Fx 'profile=FULL' "$push_plan" >/dev/null || fail 'event/head mismatch must fail safe to FULL'

printf '%s\n' 'Push validation-path hydration tests passed.'

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

require('  preflight:\n    name: Validation plan and repository control\n', 'early preflight job must not satisfy the required final status context')
require('  required:\n    name: Repository preflight\n', 'final aggregate required gate is missing')
required = text.split('\n  required:\n', 1)[1]
for dependency in ('preflight', 'quality', 'dependencies', 'integration', 'operations', 'secrets'):
    require(f'      - {dependency}\n', f'final required gate does not depend on {dependency}')
for command in (
    "require_success 'Validation plan and repository control'",
    "require_success 'Secret scan'",
    "require_planned 'PHP style and static architecture'",
    "require_planned 'Dependency and license policy'",
    "require_planned 'MariaDB 10.11 and Redis tests'",
    "require_planned 'Operational syntax and entrypoints'",
):
    if command not in required:
        raise SystemExit(f'final required gate does not enforce: {command}')
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

readonly_verifier="$root/scripts/ci/verify-readonly-staging-workflow.sh"
readonly_source="$root/.github/workflows/staging-readiness.yml"

expect_readonly_reject() {
    local name=$1
    local mutator=$2
    local candidate="$tmpdir/readonly-$name.yml"
    cp "$readonly_source" "$candidate"
    python3 - "$candidate" "$mutator" <<'PY_MUTATION'
from pathlib import Path
import sys

path = Path(sys.argv[1])
mutation = sys.argv[2]
text = path.read_text()

mutations = {
    'automatic_trigger': lambda s: s.replace('on:\n  workflow_dispatch:', 'on:\n  push:\n  workflow_dispatch:', 1),
    'write_permission': lambda s: s.replace('contents: read', 'contents: write', 1),
    'secret': lambda s: s.replace('set -euo pipefail', "set -euo pipefail\n          echo '${{ secrets.RUNTIME_ROOT }}'", 1),
    'environment': lambda s: s.replace('    runs-on:', '    environment: production\n    runs-on:', 1),
    'checkout_action': lambda s: s.replace('steps:\n      - name: Validate read-only contract', 'steps:\n      - uses: actions/checkout@deadbeef\n      - name: Validate read-only contract', 1),
    'sudo': lambda s: s.replace('set -euo pipefail', 'set -euo pipefail\n          sudo true', 1),
    'docker_mutation': lambda s: s.replace('set -euo pipefail', 'set -euo pipefail\n          docker compose up -d', 1),
    'systemctl_mutation': lambda s: s.replace('state=$(systemctl is-active "$service" 2>/dev/null || true)', 'state=$(systemctl restart "$service" 2>/dev/null || true)', 1),
}

try:
    changed = mutations[mutation](text)
except KeyError as exc:
    raise SystemExit(f'unknown mutation: {mutation}') from exc
if changed == text:
    raise SystemExit(f'mutation did not alter workflow: {mutation}')
path.write_text(changed)
PY_MUTATION

    if bash "$readonly_verifier" "$candidate" >/dev/null 2>&1; then
        fail "read-only verifier accepted mutation: $name"
    fi
}

bash "$readonly_verifier" "$readonly_source" >/dev/null
expect_readonly_reject automatic_trigger automatic_trigger
expect_readonly_reject write_permission write_permission
expect_readonly_reject secret secret
expect_readonly_reject environment environment
expect_readonly_reject checkout_action checkout_action
expect_readonly_reject sudo sudo
expect_readonly_reject docker_mutation docker_mutation
expect_readonly_reject systemctl_mutation systemctl_mutation

printf '%s\n' 'Read-only staging workflow verifier tests passed.'
