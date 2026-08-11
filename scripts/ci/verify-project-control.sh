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
    PROJECT_STATUS.md
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
    .github/workflows/ci.yml
)

for path in "${required_files[@]}"; do
    test -s "$path" || fail "required canonical file is missing or empty: $path"
done

for entry in PROJECT_STATUS.md AGENTS.md CONTRIBUTING.md docs/README.md; do
    grep -F "$entry" README.md >/dev/null \
        || fail "README.md does not link required entry point: $entry"
done

grep -F 'GitHub is authoritative for live task' PROJECT_STATUS.md >/dev/null \
    || fail 'PROJECT_STATUS.md must establish GitHub as live task authority'
grep -F 'Only two branches are long-lived' AGENTS.md >/dev/null \
    || fail 'AGENTS.md must define the two long-lived branch policy'
grep -F 'Task-level implementation history is preserved by commits, PRs, Issues, reviews, and CI' AGENTS.md >/dev/null \
    || fail 'AGENTS.md must define task evidence authority'
grep -F 'does not track implementation status' docs/05-architecture-overview.md >/dev/null \
    || fail 'architecture document must reject mutable implementation status'
grep -F 'no implementation status' docs/01-authoritative-requirements.md >/dev/null \
    || fail 'requirement index must not contain mutable implementation status'
grep -F 'not a per-task archive' evidence/README.md >/dev/null \
    || fail 'evidence policy must reject per-task repository evidence'

# Duplicate machine/current status files are intentionally forbidden.
for forbidden in docs/project-status.json docs/development/project-status.schema.json; do
    test ! -e "$forbidden" || fail "obsolete duplicate project status remains: $forbidden"
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

# Task-level repository evidence is forbidden; release/RC evidence may be added directly under evidence/ later.
shopt -s nullglob
legacy_task_evidence=(evidence/0.*/*.md)
shopt -u nullglob
((${#legacy_task_evidence[@]} == 0)) \
    || fail "obsolete per-task evidence remains in active evidence tree: ${legacy_task_evidence[*]}"

# Canonical docs must not point back to retired coordination/status/evidence material.
if grep -RIE --include='*.md' \
    'docs/project-status\.json|current-traceability-overlay|continuation-handoff|phase-[0-9].*traceability|evidence/0\.[0-9]|docs/(00|02|10|11|12|13|14|15|16|17|18|19)-' \
    README.md AGENTS.md CONTRIBUTING.md PROJECT_STATUS.md docs/README.md docs/0[1-9]-*.md docs/development/repository-map.md \
    >/dev/null; then
    fail 'canonical documentation references a retired status/planning/traceability/evidence file'
fi

# All workflows use the canonical owner-controlled self-hosted runner.
shopt -s nullglob
workflow_files=(.github/workflows/*.yml .github/workflows/*.yaml)
shopt -u nullglob
((${#workflow_files[@]} > 0)) || fail 'repository contains no GitHub Actions workflows'

expected_runner_selector='runs-on: [self-hosted, Linux, X64, freedom-staging, php84]'
for workflow in "${workflow_files[@]}"; do
    found_runner=false
    while IFS= read -r runner_line; do
        trimmed="${runner_line#"${runner_line%%[![:space:]]*}"}"
        [[ "$trimmed" == "$expected_runner_selector" ]] \
            || fail "workflow must use canonical self-hosted runner selector: $workflow: $trimmed"
        found_runner=true
    done < <(grep -E '^[[:space:]]*runs-on:' "$workflow" || true)

    [[ "$found_runner" == true ]] || fail "workflow has no explicit self-hosted runs-on selector: $workflow"
done

# Generic CI validates same-repository Worker PRs targeting develop and stays secret-free.
ci=.github/workflows/ci.yml
grep -A5 -F 'pull_request:' "$ci" | grep -F 'develop/v1.0.0-completion' >/dev/null \
    || fail 'generic CI does not validate Worker PRs targeting develop/v1.0.0-completion'
grep -F 'github.event.pull_request.head.repo.full_name == github.repository' "$ci" >/dev/null \
    || fail 'generic CI lacks same-repository protection for the self-hosted runner'
if grep -Eq 'secrets\.(PASARGUARD|STAGING|TELEGRAM|NOWPAYMENTS|ZARINPAL|MELLI|KAVENEGAR)' "$ci"; then
    fail 'generic CI references protected provider/staging/runtime secrets'
fi

printf '%s\n' 'Project control verification passed.'
