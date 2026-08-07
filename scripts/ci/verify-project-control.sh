#!/usr/bin/env bash

set -euo pipefail

fail() {
    echo "Project control verification failed: $*" >&2
    exit 1
}

required_files=(
    AGENTS.md
    PROJECT_STATUS.md
    CONTRIBUTING.md
    README.md
    docs/project-status.json
    docs/development/project-status.schema.json
    docs/development/continuation-runbook.md
    docs/development/ci-runner-contract.md
    docs/development/increment-lifecycle.md
    docs/development/repository-map.md
    docs/31-project-control-plane-audit.md
    docs/specification/master-execution-prompt.md
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
    and .last_verified_boundary.implementation_sha == "8e62867277acdd39cd1471ed3d454ef25520bef8"
    and .last_verified_boundary.evidence_sha == "0d34af0aa4f9f227fdf3cae74b4fd4717f199ddf"
    and .last_verified_boundary.artifact_sha256 == "5ec6b6dd94e1305c17312650abdc94a5253521834911c26eb48f8decbd105cb9"
    and .active_increment.status == "unverified"
    and (.active_increment.requirements | index("CAT-006") != null)
    and (.active_increment.requirements | index("PRV-001") != null)
    and (.forbidden_actions | index("merge_pr") != null)
    and (.forbidden_actions | index("push_main") != null)
' docs/project-status.json >/dev/null || fail 'project status constants or current boundary are inconsistent'

handoff_path="$(jq -r '.active_increment.handoff_path' docs/project-status.json)"
case "$handoff_path" in
    ''|null|/*|*'..'*) fail 'active handoff path is unsafe or empty' ;;
esac

test -s "$handoff_path" || fail "active handoff is missing or empty: $handoff_path"

status_requirements=(
    'PR `#6`'
    'Issue `#7`'
    'develop/v1.0.0-completion'
    '0d34af0aa4f9f227fdf3cae74b4fd4717f199ddf'
    'docs/30-phase-0.4-trial-panel-handoff.md'
)

for value in "${status_requirements[@]}"; do
    grep -F "$value" PROJECT_STATUS.md >/dev/null \
        || fail "PROJECT_STATUS.md is missing required value: $value"
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
shopt -u nullglob

((${#repair_workflows[@]} == 0)) || fail "temporary repair workflow remains: ${repair_workflows[*]}"
((${#repair_scripts[@]} == 0)) || fail "temporary repair script remains: ${repair_scripts[*]}"

for forbidden in \
    'PR must remain Draft' \
    'Never trust a SHA copied from a handoff' \
    'Never place credentials'; do
    grep -F "$forbidden" AGENTS.md >/dev/null \
        || fail "AGENTS.md is missing operating rule: $forbidden"
done

printf '%s\n' 'Project control verification passed.'
