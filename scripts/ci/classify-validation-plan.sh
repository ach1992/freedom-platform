#!/usr/bin/env bash

set -euo pipefail

paths_file=${1:-}

project_control=false
planning=false
control_plane=false
unit=false
style=false
static_analysis=false
dependencies=false
integration=false
runtime=false
operations=false
unknown=false
diagnostic=false

mark_full() {
    project_control=true
    planning=true
    control_plane=true
    unit=true
    style=true
    static_analysis=true
    dependencies=true
    integration=true
    runtime=true
    operations=true
    unknown=true
}

detect_filtered_diagnostic() {
    local event_path=${GITHUB_EVENT_PATH:-}
    local filter

    [[ "${GITHUB_EVENT_NAME:-}" == 'workflow_dispatch' ]] || return 0
    [[ -n "$event_path" && -f "$event_path" ]] || return 0
    command -v jq >/dev/null 2>&1 || return 0

    filter=$(jq -r '.inputs.phpunit_filter // empty' "$event_path" 2>/dev/null || true)
    [[ "$filter" =~ [^[:space:]] ]] || return 0

    diagnostic=true
    integration=true
    runtime=true
}

hydrate_push_paths() {
    local target=$1
    local event_path=${GITHUB_EVENT_PATH:-}
    local current_sha=${GITHUB_SHA:-}
    local before after forced candidate
    local zero_sha=0000000000000000000000000000000000000000

    [[ "${GITHUB_EVENT_NAME:-}" == 'push' ]] || return 0
    [[ -n "$event_path" && -f "$event_path" ]] || return 0
    command -v jq >/dev/null 2>&1 || return 0

    before=$(jq -r '.before // empty' "$event_path" 2>/dev/null || true)
    after=$(jq -r '.after // empty' "$event_path" 2>/dev/null || true)
    forced=$(jq -r 'if has("forced") then .forced else empty end' "$event_path" 2>/dev/null || true)

    [[ "$forced" == 'false' ]] || return 0
    [[ "$before" =~ ^[0-9a-f]{40}$ && "$before" != "$zero_sha" ]] || return 0
    [[ "$after" =~ ^[0-9a-f]{40}$ && "$after" != "$zero_sha" ]] || return 0
    [[ "$current_sha" == "$after" ]] || return 0
    git cat-file -e "${after}^{commit}" 2>/dev/null || return 0

    if ! git cat-file -e "${before}^{commit}" 2>/dev/null; then
        git fetch --no-tags --depth=1 origin "$before" >/dev/null 2>&1 || return 0
    fi

    candidate="${target}.push.$$"
    if git diff --name-only "$before" "$after" 2>/dev/null | sort -u > "$candidate" && [[ -s "$candidate" ]]; then
        mv "$candidate" "$target"
        return 0
    fi

    rm -f "$candidate"
}

# A filtered workflow dispatch is an explicit troubleshooting route, not merge
# acceptance. It needs the real integration/runtime surface only. If the event
# payload or filter is unavailable/blank, normal empty-input fail-safe FULL wins.
detect_filtered_diagnostic

if [[ "$diagnostic" != 'true' ]]; then
    # Pull-request CI already writes its exact merge-candidate diff into this file.
    # A normal push to main instead arrives with an initially empty file; hydrate it
    # from the authoritative push event. Ambiguous/forced/created/unavailable push
    # baselines deliberately remain empty so the existing fail-safe FULL plan wins.
    if [[ -n "$paths_file" && -f "$paths_file" && ! -s "$paths_file" ]]; then
        hydrate_push_paths "$paths_file"
    fi

    if [[ -z "$paths_file" || ! -f "$paths_file" || ! -s "$paths_file" ]]; then
        mark_full
    else
        while IFS= read -r path; do
            [[ -n "$path" ]] || continue

            case "$path" in
                docs/specification/master-execution-prompt.md|docs/01-authoritative-requirements.md|docs/03-risk-register.md|docs/04-domain-glossary.md|docs/05-architecture-overview.md|docs/06-test-strategy.md|docs/07-security-threat-model.md|docs/08-data-classification.md|docs/09-deployment-runbook.md)
                    project_control=true
                    planning=true
                    ;;

                README.md|AGENTS.md|CONTRIBUTING.md|CHANGELOG.md|LICENSE|.gitignore|.gitattributes|.github/pull_request_template.md|.github/ISSUE_TEMPLATE/*|docs/index.md|docs/development/*|docs/adr/*|evidence/README.md)
                    project_control=true
                    ;;

                .github/CODEOWNERS|.github/dependabot.yml|.gitleaksignore)
                    project_control=true
                    control_plane=true
                    ;;

                .github/workflows/ci.yml|.github/workflows/staging-readiness.yml|.github/workflows/provider-readiness.yml)
                    project_control=true
                    control_plane=true
                    ;;

                .github/workflows/provider-live-acceptance.yml)
                    mark_full
                    break
                    ;;

                scripts/ci/classify-validation-plan.sh|scripts/ci/test-validation-plan.sh|scripts/ci/scan-git-secrets.sh|scripts/ci/test-secret-scan.sh|scripts/ci/verify-project-control.sh|scripts/ci/verify-planning.sh|scripts/ci/verify-readonly-staging-workflow.sh)
                    project_control=true
                    control_plane=true
                    ;;

                scripts/ci/forbidden-patterns.sh|scripts/ci/architecture.sh|scripts/ci/ArchitectureBoundaryChecker.php|scripts/ci/architecture-boundaries.php|scripts/ci/run-architecture-check.php)
                    project_control=true
                    control_plane=true
                    unit=true
                    static_analysis=true
                    ;;

                scripts/ci/licenses.sh)
                    project_control=true
                    control_plane=true
                    dependencies=true
                    ;;

                scripts/ci/test-integration.sh)
                    project_control=true
                    control_plane=true
                    integration=true
                    runtime=true
                    ;;

                scripts/ci/bootstrap-ci-toolchain.sh)
                    project_control=true
                    control_plane=true
                    unit=true
                    style=true
                    static_analysis=true
                    dependencies=true
                    integration=true
                    runtime=true
                    ;;

                scripts/ci/PasarGuardLiveAcceptance.php|scripts/ci/pasarguard-live-acceptance.php|scripts/ci/pasarguard-readonly-probe.php)
                    operations=true
                    ;;

                app/*|bootstrap/*|config/*|database/*|routes/*)
                    unit=true
                    style=true
                    static_analysis=true
                    integration=true
                    ;;

                tests/Unit/*)
                    unit=true
                    style=true
                    ;;

                tests/Feature/*)
                    style=true
                    integration=true
                    ;;

                tests/*)
                    unit=true
                    style=true
                    integration=true
                    ;;

                composer.json|composer.lock)
                    unit=true
                    static_analysis=true
                    dependencies=true
                    integration=true
                    ;;

                phpunit.xml)
                    unit=true
                    integration=true
                    ;;

                .env.example)
                    integration=true
                    ;;

                phpstan.neon)
                    static_analysis=true
                    ;;

                docker-compose.ci.yml)
                    runtime=true
                    integration=true
                    ;;

                deploy/*)
                    operations=true
                    ;;

                .editorconfig)
                    style=true
                    ;;

                artisan)
                    unit=true
                    style=true
                    integration=true
                    ;;

                *)
                    mark_full
                    break
                    ;;
            esac
        done < "$paths_file"
    fi
fi

if [[ "$diagnostic" == 'true' ]]; then
    profile=DIAGNOSTIC
elif [[ "$unknown" == 'true' ]]; then
    profile=FULL
elif [[ "$operations" == 'true' && ( "$unit" == 'true' || "$style" == 'true' || "$static_analysis" == 'true' || "$dependencies" == 'true' || "$integration" == 'true' || "$runtime" == 'true' ) ]]; then
    profile=FULL
elif [[ "$operations" == 'true' ]]; then
    profile=OPERATIONS
elif [[ "$unit" == 'true' || "$style" == 'true' || "$static_analysis" == 'true' || "$dependencies" == 'true' || "$integration" == 'true' || "$runtime" == 'true' ]]; then
    profile=APPLICATION
elif [[ "$control_plane" == 'true' ]]; then
    profile=CONTROL_PLANE
else
    profile=CONTROL
fi

cat <<EOF_PLAN
profile=$profile
project_control=$project_control
planning=$planning
control_plane=$control_plane
unit=$unit
style=$style
static_analysis=$static_analysis
dependencies=$dependencies
integration=$integration
runtime=$runtime
operations=$operations
EOF_PLAN
