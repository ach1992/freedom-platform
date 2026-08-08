#!/usr/bin/env bash

set -euo pipefail

fail() {
    echo "Project control verification failed: $*" >&2
    exit 1
}

safe_repository_path() {
    local path="$1"

    case "$path" in
        ''|null|/*|*'..'*) return 1 ;;
    esac

    return 0
}

required_files=(
    AGENTS.md
    PROJECT_STATUS.md
    CONTRIBUTING.md
    README.md
    docs/project-status.json
    docs/development/project-status.schema.json
    docs/development/continuation-runbook.md
    docs/development/github-actions-runner-policy.md
    docs/development/ci-runner-contract.md
    docs/development/increment-lifecycle.md
    docs/development/repository-map.md
    docs/development/staging-workflow-inventory.md
    docs/development/operational-document-status.md
    docs/31-project-control-plane-audit.md
    docs/32-current-traceability-overlay.md
    docs/33-current-risk-overlay.md
    docs/specification/master-execution-prompt.md
    .github/workflows/staging-readiness.yml
)

for path in "${required_files[@]}"; do
    test -s "$path" || fail "required file is missing or empty: $path"
done

jq -e . docs/project-status.json >/dev/null || fail 'docs/project-status.json is not valid JSON'
jq -e . docs/development/project-status.schema.json >/dev/null || fail 'project status schema is not valid JSON'

jq -e '
    .schema_version == 1
    and .repository == "ach1992/freedom-platform"
    and .authoritative_pr == 6
    and .authoritative_issue == 7
    and .allowed_branch == "develop/v1.0.0-completion"
    and .base_branch == "main"
    and .required_pr_state == "draft"
    and .live_head_source == "github_pr_head_sha"
    and .active_phase.version == "0.4.0"
    and .active_phase.status == "active"
    and (.last_verified_boundary.name | type == "string" and length > 0)
    and (.last_verified_boundary.implementation_sha | test("^[a-f0-9]{40}$"))
    and (.last_verified_boundary.implementation_ci_run_id | type == "number" and . > 0)
    and (.last_verified_boundary.implementation_ci_run_number | type == "number" and . > 0)
    and (.last_verified_boundary.evidence_sha | test("^[a-f0-9]{40}$"))
    and (.last_verified_boundary.evidence_ci_run_id | type == "number" and . > 0)
    and (.last_verified_boundary.evidence_ci_run_number | type == "number" and . > 0)
    and (.last_verified_boundary.tests | type == "number" and . > 0)
    and (.last_verified_boundary.assertions | type == "number" and . > 0)
    and (.last_verified_boundary.artifact_name | type == "string" and length > 0)
    and (.last_verified_boundary.artifact_id | type == "number" and . > 0)
    and (.last_verified_boundary.artifact_sha256 | test("^[a-f0-9]{64}$"))
    and (.last_verified_boundary.evidence_path | type == "string" and length > 0)
    and (.last_verified_boundary.traceability_path | type == "string" and length > 0)
    and (.active_increment.name | type == "string" and length > 0)
    and (.active_increment.status | IN("planned", "active", "unverified", "blocked", "verified"))
    and (.active_increment.handoff_path | type == "string" and length > 0)
    and (.active_increment.requirements | type == "array" and length > 0)
    and ([.active_increment.requirements[] | test("^[A-Z]+-[0-9]{3}$")] | all)
    and .stabilization.status == "complete"
    and .stabilization.feature_development_paused == false
    and (.forbidden_actions | index("merge_pr") != null)
    and (.forbidden_actions | index("mark_ready_for_review") != null)
    and (.forbidden_actions | index("enable_auto_merge") != null)
    and (.forbidden_actions | index("rewrite_history") != null)
    and (.forbidden_actions | index("push_main") != null)
    and (.forbidden_actions | index("use_github_hosted_runner") != null)
    and (.forbidden_actions | index("create_temporary_branch") != null)
    and (.forbidden_actions | index("retrieve_or_print_secrets") != null)
    and (.forbidden_actions | index("claim_unverified_provider_compatibility") != null)
' docs/project-status.json >/dev/null || fail 'project status structure or fixed repository policy is inconsistent'

implementation_sha="$(jq -r '.last_verified_boundary.implementation_sha' docs/project-status.json)"
evidence_sha="$(jq -r '.last_verified_boundary.evidence_sha' docs/project-status.json)"
evidence_path="$(jq -r '.last_verified_boundary.evidence_path' docs/project-status.json)"
traceability_path="$(jq -r '.last_verified_boundary.traceability_path' docs/project-status.json)"
active_increment="$(jq -r '.active_increment.name' docs/project-status.json)"
handoff_path="$(jq -r '.active_increment.handoff_path' docs/project-status.json)"

for path in "$evidence_path" "$traceability_path" "$handoff_path"; do
    safe_repository_path "$path" || fail "project status contains an unsafe repository path: $path"
    test -s "$path" || fail "project status references a missing or empty file: $path"
done

status_requirements=(
    'PR `#6`'
    '`#7`'
    'develop/v1.0.0-completion'
    "$implementation_sha"
    "$evidence_sha"
    "$evidence_path"
    "$traceability_path"
    "$active_increment"
    "$handoff_path"
    'docs/32-current-traceability-overlay.md'
    'docs/33-current-risk-overlay.md'
)

for value in "${status_requirements[@]}"; do
    grep -F "$value" PROJECT_STATUS.md >/dev/null \
        || fail "PROJECT_STATUS.md is missing current project-status value: $value"
done

for entry in AGENTS.md PROJECT_STATUS.md CONTRIBUTING.md; do
    grep -F "$entry" README.md >/dev/null \
        || fail "README.md does not link the required entry point: $entry"
done

for historical in docs/20-current-state-audit.md docs/22-phase-boundary-reconciliation.md; do
    test -s "$historical" || fail "historical audit file is missing: $historical"
    head -n 12 "$historical" | grep -Ei 'superseded|historical' >/dev/null \
        || fail "$historical must be explicitly marked historical or superseded"
done

shopt -s nullglob
repair_workflows=(.github/workflows/ci-repair*.yml .github/workflows/ci-repair*.yaml)
repair_scripts=(scripts/ci/generate-ci-repair*.sh)
legacy_staging_workflows=(.github/workflows/staging-*.yml .github/workflows/staging-*.yaml)
workflow_files=(.github/workflows/*.yml .github/workflows/*.yaml)
shopt -u nullglob

((${#repair_workflows[@]} == 0)) || fail "temporary repair workflow remains: ${repair_workflows[*]}"
((${#repair_scripts[@]} == 0)) || fail "temporary repair script remains: ${repair_scripts[*]}"
((${#workflow_files[@]} > 0)) || fail 'repository contains no GitHub Actions workflows'

expected_runner_selector='runs-on: [self-hosted, Linux, X64, freedom-staging, php84]'
for workflow in "${workflow_files[@]}"; do
    found_runner=false
    while IFS= read -r runner_line; do
        trimmed="${runner_line#"${runner_line%%[![:space:]]*}"}"
        [[ "$trimmed" == "$expected_runner_selector" ]] \
            || fail "workflow must use the canonical self-hosted runner selector: $workflow: $trimmed"
        found_runner=true
    done < <(grep -E '^[[:space:]]*runs-on:' "$workflow" || true)

    [[ "$found_runner" == true ]] || fail "workflow has no explicit self-hosted runs-on selector: $workflow"
done

for workflow in "${legacy_staging_workflows[@]}"; do
    [[ "$workflow" == '.github/workflows/staging-readiness.yml' ]] && continue

    grep -F 'Historical - Disabled' "$workflow" >/dev/null \
        || fail "legacy staging workflow is not visibly disabled: $workflow"
    grep -F "disabled/historical-workflow" "$workflow" >/dev/null \
        || fail "legacy staging workflow does not have an impossible job condition: $workflow"

    if grep -Eq 'secrets\.|sudo|apt-get|systemctl[[:space:]]+(enable|start|restart|stop)|(^|[[:space:]])ssh([[:space:]\\]|$)|(^|[[:space:]])scp([[:space:]\\]|$)' "$workflow"; then
        fail "disabled staging workflow still contains remote secret or mutation logic: $workflow"
    fi
done

readiness=.github/workflows/staging-readiness.yml
grep -F 'READ_ONLY_STAGING_CHECK' "$readiness" >/dev/null \
    || fail 'staging readiness workflow lacks typed read-only confirmation'
grep -F 'freedom-staging-runner' "$readiness" >/dev/null \
    || fail 'staging readiness workflow lacks runner identity validation'

if grep -Eq 'secrets\.|sudo|apt-get|systemctl[[:space:]]+(enable|start|restart|stop)|(^|[[:space:]])ssh([[:space:]\\]|$)|(^|[[:space:]])scp([[:space:]\\]|$)' "$readiness"; then
    fail 'staging readiness workflow contains a secret, privilege, remote-shell, or mutation operation'
fi

for required_rule in \
    'PR must remain Draft' \
    'Never trust a SHA copied from a handoff' \
    'Every GitHub Actions job must run on the owner-controlled self-hosted runner' \
    'Never request, retrieve, print, commit, log, attach, or quote secrets.'; do
    grep -F "$required_rule" AGENTS.md >/dev/null \
        || fail "AGENTS.md is missing operating rule: $required_rule"
done

printf '%s\n' 'Project control verification passed.'
