#!/usr/bin/env bash

set -euo pipefail

paths_file=${1:-}

project_control=false
planning=false
control_plane=false
style=false
static_analysis=false
dependencies=false
integration=false
runtime=false
operations=false
unknown=false

mark_full() {
    project_control=true
    planning=true
    control_plane=true
    style=true
    static_analysis=true
    dependencies=true
    integration=true
    runtime=true
    operations=true
    unknown=true
}

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

            README.md|AGENTS.md|CONTRIBUTING.md|CHANGELOG.md|LICENSE|.gitignore|.gitattributes|.github/pull_request_template.md|.github/ISSUE_TEMPLATE/*|docs/README.md|docs/development/*|docs/adr/*|evidence/README.md)
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

            scripts/ci/classify-validation-plan.sh|scripts/ci/test-validation-plan.sh|scripts/ci/verify-project-control.sh|scripts/ci/verify-planning.sh|scripts/ci/verify-readonly-staging-workflow.sh)
                project_control=true
                control_plane=true
                ;;

            scripts/ci/forbidden-patterns.sh|scripts/ci/architecture.sh|scripts/ci/ArchitectureBoundaryChecker.php|scripts/ci/architecture-boundaries.php|scripts/ci/run-architecture-check.php)
                project_control=true
                control_plane=true
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

            scripts/ci/bootstrap-self-hosted-toolchain.sh)
                project_control=true
                control_plane=true
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
                style=true
                static_analysis=true
                integration=true
                ;;

            tests/*)
                style=true
                integration=true
                ;;

            composer.json|composer.lock)
                static_analysis=true
                dependencies=true
                integration=true
                ;;

            .env.example|phpunit.xml)
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

if [[ "$unknown" == 'true' ]]; then
    profile=FULL
elif [[ "$operations" == 'true' && ( "$style" == 'true' || "$static_analysis" == 'true' || "$dependencies" == 'true' || "$integration" == 'true' || "$runtime" == 'true' ) ]]; then
    profile=FULL
elif [[ "$operations" == 'true' ]]; then
    profile=OPERATIONS
elif [[ "$style" == 'true' || "$static_analysis" == 'true' || "$dependencies" == 'true' || "$integration" == 'true' || "$runtime" == 'true' ]]; then
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
style=$style
static_analysis=$static_analysis
dependencies=$dependencies
integration=$integration
runtime=$runtime
operations=$operations
EOF_PLAN
