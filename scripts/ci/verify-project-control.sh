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
    docs/development/staging-workflow-inventory.md
    docs/development/operational-document-status.md
    docs/31-project-control-plane-audit.md
    docs/32-current-traceability-overlay.md
    docs/33-current-risk-overlay.md
    docs/35-phase-0.4-panel-provider-source-contracts.md
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
    and .last_verified_boundary.implementation_sha == "b146c2c6aa902b4ed252d200121d63e422cd87f2"
    and .last_verified_boundary.evidence_sha == "31a1854a2804bb0b2cf466c96887de7c5813b343"
    and .last_verified_boundary.artifact_sha256 == "65b46242099caeef40e8bfeb7717b915ec306b449078570bd680420811bbde09"
    and .last_verified_boundary.tests == 298
    and .last_verified_boundary.assertions == 1471
    and .active_increment.name == "Pinned Marzban and PasarGuard Source-Contract Adapters"
    and .active_increment.status == "active"
    and .active_increment.handoff_path == "docs/35-phase-0.4-panel-provider-source-contracts.md"
    and (.active_increment.requirements | index("PRV-001") != null)
    and (.active_increment.requirements | index("PRV-002") != null)
    and (.active_increment.requirements | index("PRV-003") != null)
    and .stabilization.status == "complete"
    and .stabilization.feature_development_paused == false
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
    '31a1854a2804bb0b2cf466c96887de7c5813b343'
    'docs/35-phase-0.4-panel-provider-source-contracts.md'
    'Marzban `v0.8.4`'
    'PasarGuard `v5.2.1`'
    'docs/32-current-traceability-overlay.md'
    'docs/33-current-risk-overlay.md'
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
legacy_staging_workflows=(.github/workflows/staging-*.yml .github/workflows/staging-*.yaml)
shopt -u nullglob

((${#repair_workflows[@]} == 0)) || fail "temporary repair workflow remains: ${repair_workflows[*]}"
((${#repair_scripts[@]} == 0)) || fail "temporary repair script remains: ${repair_scripts[*]}"

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
    'Never request, retrieve, print, commit, log, attach, or quote secrets.'; do
    grep -F "$required_rule" AGENTS.md >/dev/null \
        || fail "AGENTS.md is missing operating rule: $required_rule"
done

printf '%s\n' 'Project control verification passed.'
