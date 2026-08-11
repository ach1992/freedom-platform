#!/usr/bin/env bash

set -euo pipefail

fail() {
    echo "Project control verification failed: $*" >&2
    exit 1
}

required_files=(
    README.md
    AGENTS.md
    CONTRIBUTING.md
    docs/README.md
    docs/specification/master-execution-prompt.md
    docs/01-authoritative-requirements.md
    docs/03-risk-register.md
    docs/04-domain-glossary.md
    docs/05-architecture-overview.md
    docs/06-test-strategy.md
    docs/07-security-threat-model.md
    docs/08-data-classification.md
    docs/09-deployment-runbook.md
    docs/development/repository-map.md
    evidence/README.md
    .github/CODEOWNERS
    .github/ISSUE_TEMPLATE/task.yml
    .github/pull_request_template.md
    .github/workflows/ci.yml
)

for path in "${required_files[@]}"; do
    test -s "$path" || fail "required canonical file is missing or empty: $path"
done

# Recovery must start from durable repository rules and live GitHub, not a mutable status snapshot.
for entry in AGENTS.md CONTRIBUTING.md docs/README.md; do
    grep -F "$entry" README.md >/dev/null \
        || fail "README.md does not link required entry point: $entry"
done
grep -F 'Program Issue #3' README.md >/dev/null \
    || fail 'README.md must route replacement maintainers to the live Version 1 program'
grep -F 'Draft integration PR #6' README.md >/dev/null \
    || fail 'README.md must route replacement maintainers to the live integration PR'
grep -F 'Chat history is optional context, never project state.' AGENTS.md >/dev/null \
    || fail 'AGENTS.md must make repository/GitHub state recoverable without Chat'
grep -F 'Only two branches are long-lived' AGENTS.md >/dev/null \
    || fail 'AGENTS.md must define the two long-lived branch policy'
grep -F 'MariaDB `10.11` is the mandatory normal integration target.' AGENTS.md >/dev/null \
    || fail 'AGENTS.md must define the primary MariaDB CI target'
grep -F 'does not track implementation status' docs/05-architecture-overview.md >/dev/null \
    || fail 'architecture document must reject mutable implementation status'
grep -F 'no implementation status' docs/01-authoritative-requirements.md >/dev/null \
    || fail 'requirement index must not contain mutable implementation status'
grep -F 'not a per-task archive' evidence/README.md >/dev/null \
    || fail 'evidence policy must reject per-task repository evidence'

# Task/PR templates enforce a useful minimum without requiring ceremonial N/A sections.
for label in 'Parent / requirements' 'Goal / outcome' 'Dependencies / base rule' 'Scope' 'Acceptance criteria' 'Validation strategy' 'Change risk' 'Initial state'; do
    grep -F "$label" .github/ISSUE_TEMPLATE/task.yml >/dev/null \
        || fail "task template lacks core contract field: $label"
done
grep -F 'Material constraints / impacts (optional)' .github/ISSUE_TEMPLATE/task.yml >/dev/null \
    || fail 'task template must keep risk-specific detail conditional'
for heading in '## Owning Issue' '## Summary' '## Risk / material impact' '## Verification' '## Review gates'; do
    grep -F "$heading" .github/pull_request_template.md >/dev/null \
        || fail "PR template lacks review-useful section: $heading"
done

# Sensitive ownership must exist, but the verifier must not hard-code one person's username.
for rule in \
    '^/app/Modules/Payments/[[:space:]]+@[^[:space:]]+' \
    '^/database/migrations/[[:space:]]+@[^[:space:]]+' \
    '^/.github/workflows/[[:space:]]+@[^[:space:]]+' \
    '^/deploy/[[:space:]]+@[^[:space:]]+'; do
    grep -Eq "$rule" .github/CODEOWNERS \
        || fail "CODEOWNERS lacks a durable owner/team for required sensitive path: $rule"
done

grep -F 'High/Critical work involving financial integrity' CONTRIBUTING.md >/dev/null \
    || fail 'contribution guide lacks risk/Owner review gate'
grep -F 'Merge style never substitutes for review or the applicable green CI tier.' CONTRIBUTING.md >/dev/null \
    || fail 'contribution guide lacks merge-method safety rule'

# Duplicate mutable project status and retired coordination files are forbidden.
for forbidden in PROJECT_STATUS.md docs/project-status.json docs/development/project-status.schema.json; do
    test ! -e "$forbidden" || fail "duplicate mutable project status remains: $forbidden"
done

# The active documentation tree is allowlisted. Historical task records belong in Git/GitHub history.
while IFS= read -r path; do
    case "$path" in
        docs/README.md|\
        docs/01-authoritative-requirements.md|\
        docs/03-risk-register.md|\
        docs/04-domain-glossary.md|\
        docs/05-architecture-overview.md|\
        docs/06-test-strategy.md|\
        docs/07-security-threat-model.md|\
        docs/08-data-classification.md|\
        docs/09-deployment-runbook.md|\
        docs/development/repository-map.md|\
        docs/specification/master-execution-prompt.md|\
        docs/adr/*.md)
            ;;
        *)
            fail "non-canonical documentation file remains in active tree: $path"
            ;;
    esac
done < <(find docs -type f -name '*.md' -print | sort)

# Task-level repository evidence is forbidden; release/RC evidence may be retained when needed.
shopt -s nullglob
legacy_task_evidence=(evidence/0.*/*.md)
shopt -u nullglob
((${#legacy_task_evidence[@]} == 0)) \
    || fail "obsolete per-task evidence remains in active evidence tree: ${legacy_task_evidence[*]}"

# Canonical navigation must not point back to retired status/traceability/evidence material.
if grep -RIE --include='*.md' \
    'PROJECT_STATUS\.md|docs/project-status\.json|current-traceability-overlay|continuation-handoff|phase-[0-9].*traceability|evidence/0\.[0-9]|docs/(00|02|10|11|12|13|14|15|16|17|18|19)-' \
    README.md AGENTS.md CONTRIBUTING.md docs/README.md docs/0[1-9]-*.md docs/development/repository-map.md \
    >/dev/null; then
    fail 'canonical documentation references retired status/planning/traceability/evidence material'
fi

# Every executing workflow is self-hosted-only. No GitHub-hosted fallback is accepted.
shopt -s nullglob
workflow_files=(.github/workflows/*.yml .github/workflows/*.yaml)
shopt -u nullglob
((${#workflow_files[@]} > 0)) || fail 'repository contains no GitHub Actions workflows'

expected_runner_selector='runs-on: [self-hosted, Linux, X64, freedom-staging, php84]'
for workflow in "${workflow_files[@]}"; do
    if grep -Eq 'Historical - Disabled|disabled/historical-workflow|docs/development/staging-workflow-inventory\.md' "$workflow"; then
        fail "historical disabled workflow stub remains in active tree: $workflow"
    fi

    found_runner=false
    while IFS= read -r runner_line; do
        trimmed="${runner_line#"${runner_line%%[![:space:]]*}"}"
        [[ "$trimmed" == "$expected_runner_selector" ]] \
            || fail "workflow must use canonical self-hosted runner selector: $workflow: $trimmed"
        found_runner=true
    done < <(grep -E '^[[:space:]]*runs-on:' "$workflow" || true)

    [[ "$found_runner" == true ]] || fail "workflow has no explicit self-hosted runs-on selector: $workflow"
done

# Generic CI protects the self-hosted runner, keeps Drafts quiet, defaults unknown changes to FULL,
# and uses MariaDB 10.11 as the single mandatory normal integration target.
ci=.github/workflows/ci.yml
grep -A16 -F 'pull_request:' "$ci" | grep -F 'develop/v1.0.0-completion' >/dev/null \
    || fail 'generic CI does not validate Worker PRs targeting the integration branch'
grep -A16 -F 'pull_request:' "$ci" | grep -F 'ready_for_review' >/dev/null \
    || fail 'generic CI does not trigger validation when a Draft becomes review-ready'
grep -A16 -F 'pull_request:' "$ci" | grep -F 'converted_to_draft' >/dev/null \
    || fail 'generic CI cannot cancel active validation when a PR returns to Draft'
grep -F 'github.event.pull_request.head.repo.full_name == github.repository' "$ci" >/dev/null \
    || fail 'generic CI lacks same-repository protection for the self-hosted runner'
grep -F 'github.event.pull_request.draft == false' "$ci" >/dev/null \
    || fail 'generic CI does not suppress automatic self-hosted jobs for Draft PRs'
grep -F 'full_ci=true' "$ci" >/dev/null \
    || fail 'generic CI must default changes to FULL validation'
grep -F "github.base_ref }}\" == 'develop/v1.0.0-completion'" "$ci" >/dev/null \
    || fail 'generic CI may not downgrade PRs unless they target the integration branch'
grep -F "MARIADB_VERSION: '10.11'" "$ci" >/dev/null \
    || fail 'normal FULL CI must use MariaDB 10.11'
if grep -Eq 'matrix:|mariadb_version:.*11\.4|MARIADB_VERSION:.*11\.4' "$ci"; then
    fail 'normal CI must not require a MariaDB compatibility matrix on every PR'
fi
grep -F '.github/ISSUE_TEMPLATE/*|docs/*|evidence/README.md)' "$ci" >/dev/null \
    || fail 'generic CI lacks the explicit control-only allowlist'
if grep -Eq 'secrets\.(PASARGUARD|STAGING|TELEGRAM|NOWPAYMENTS|ZARINPAL|MELLI|KAVENEGAR)' "$ci"; then
    fail 'generic CI references protected provider/staging/runtime secrets'
fi

printf '%s\n' 'Project control verification passed.'
