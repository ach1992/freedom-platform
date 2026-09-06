#!/usr/bin/env bash

set -euo pipefail

fail() {
    echo "Project control verification failed: $*" >&2
    exit 1
}

selector_has_label() {
    local selector=$1
    local required=$2
    local body label
    local -a labels

    body=${selector#runs-on: }
    body=${body#\[}
    body=${body%\]}
    IFS=',' read -r -a labels <<< "$body"

    for label in "${labels[@]}"; do
        label=${label#"${label%%[![:space:]]*}"}
        label=${label%"${label##*[![:space:]]}"}
        [[ "$label" == "$required" ]] && return 0
    done

    return 1
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
    docs/development/execution-infrastructure.md
    docs/development/repository-map.md
    evidence/README.md
    .github/CODEOWNERS
    .github/ISSUE_TEMPLATE/task.yml
    .github/pull_request_template.md
    .github/workflows/ci.yml
    scripts/ci/classify-validation-plan.sh
    scripts/ci/test-validation-plan.sh
    scripts/ci/verify-readonly-staging-workflow.sh
    scripts/ci/scan-git-secrets.sh
    scripts/ci/test-secret-scan.sh

)

for path in "${required_files[@]}"; do
    test -s "$path" || fail "required canonical file is missing or empty: $path"
done

# Recovery must start from durable repository rules and live GitHub, not mutable status/inventory snapshots.
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
grep -F 'MariaDB `10.11` is the mandatory normal integration target when' AGENTS.md >/dev/null \
    || fail 'AGENTS.md must define when the primary MariaDB integration target applies'
grep -F 'does not track implementation status' docs/05-architecture-overview.md >/dev/null \
    || fail 'architecture document must reject mutable implementation status'
grep -F 'no implementation status' docs/01-authoritative-requirements.md >/dev/null \
    || fail 'requirement index must not contain mutable implementation status'
grep -F 'not a per-task archive' evidence/README.md >/dev/null \
    || fail 'evidence policy must reject per-task repository evidence'

# Execution infrastructure has one canonical owner; surrounding docs must route to it instead of copying lifecycle rules.
for entry in README.md AGENTS.md CONTRIBUTING.md docs/06-test-strategy.md docs/09-deployment-runbook.md docs/README.md docs/development/repository-map.md; do
    grep -F 'execution-infrastructure.md' "$entry" >/dev/null \
        || fail "$entry does not route execution/tool/runner questions to the canonical execution-infrastructure document"
done
grep -F 'runner display names' docs/development/execution-infrastructure.md >/dev/null \
    || fail 'execution-infrastructure document must reject runner-name routing dependencies'
grep -F 'Add or replace a runner' docs/development/execution-infrastructure.md >/dev/null \
    || fail 'execution-infrastructure document must define runner onboarding/replacement'
grep -F 'Quarantine a runner' docs/development/execution-infrastructure.md >/dev/null \
    || fail 'execution-infrastructure document must define runner quarantine'
grep -F 'Remove a runner' docs/development/execution-infrastructure.md >/dev/null \
    || fail 'execution-infrastructure document must define runner removal'

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

# Duplicate mutable project status and retired coordination/one-time staging artifacts are forbidden.
for forbidden in PROJECT_STATUS.md docs/project-status.json docs/development/project-status.schema.json; do
    test ! -e "$forbidden" || fail "duplicate mutable project status remains: $forbidden"
done

for retired_staging_path in \
    deploy/staging/configure-aapanel-site.sh \
    deploy/staging/prepare-php-functions.sh \
    deploy/staging/prepare-runtime-paths.sh \
    deploy/staging/recover-operations-migration.sh; do
    test ! -e "$retired_staging_path" \
        || fail "retired one-time staging artifact returned to the active tree: $retired_staging_path"
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
        docs/development/execution-infrastructure.md|\
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
    README.md AGENTS.md CONTRIBUTING.md docs/README.md docs/0[1-9]-*.md docs/development/*.md \
    >/dev/null; then
    fail 'canonical documentation references retired status/planning/traceability/evidence material'
fi

# Generic CI is intentionally GitHub-hosted. Manual staging/provider workflows remain self-hosted
# operational contracts and are dormant unless an explicitly trusted runner is connected.
shopt -s nullglob
workflow_files=(.github/workflows/*.yml .github/workflows/*.yaml)
shopt -u nullglob
((${#workflow_files[@]} > 0)) || fail 'repository contains no GitHub Actions workflows'

for workflow in "${workflow_files[@]}"; do
    if grep -Eq 'Historical - Disabled|disabled/historical-workflow|docs/development/staging-workflow-inventory\.md' "$workflow"; then
        fail "historical disabled workflow stub remains in active tree: $workflow"
    fi

    runner_lines=$(grep -E '^[[:space:]]*runs-on:' "$workflow" || true)
    [[ -n "$runner_lines" ]] || fail "workflow has no explicit runs-on selector: $workflow"

    case "$workflow" in
        .github/workflows/ci.yml)
            while IFS= read -r runner_line; do
                trimmed="${runner_line#"${runner_line%%[![:space:]]*}"}"
                [[ "$trimmed" == 'runs-on: ubuntu-24.04' ]] \
                    || fail "generic CI must use the pinned standard GitHub-hosted Ubuntu runner: $workflow: $trimmed"
            done <<< "$runner_lines"
            if grep -F 'self-hosted' "$workflow" >/dev/null; then
                fail 'generic CI must not retain a self-hosted runner route while the repository is public-ready'
            fi
            ;;
        .github/workflows/staging-readiness.yml|.github/workflows/provider-readiness.yml|.github/workflows/provider-live-acceptance.yml)
            while IFS= read -r runner_line; do
                trimmed="${runner_line#"${runner_line%%[![:space:]]*}"}"
                [[ "$trimmed" == runs-on:\ \[*\] ]] \
                    || fail "operational workflow runner selector must remain an explicit label list: $workflow: $trimmed"
                for required_label in self-hosted Linux X64; do
                    selector_has_label "$trimmed" "$required_label" \
                        || fail "operational workflow runner selector must retain exact label $required_label: $workflow: $trimmed"
                done
            done <<< "$runner_lines"
            ;;
        *)
            fail "workflow runner policy is unclassified: $workflow"
            ;;
    esac
done

# Generic CI uses ephemeral GitHub-hosted runners, keeps Drafts quiet, validates same-repository and fork PRs,
# computes a fail-safe validation plan, cancels superseded PR work, and uses MariaDB 10.11 only when applicable.
ci=.github/workflows/ci.yml
grep -A16 -F 'pull_request:' "$ci" | grep -F 'develop/v1.0.0-completion' >/dev/null \
    || fail 'generic CI does not validate Worker PRs targeting the integration branch'
grep -A16 -F 'pull_request:' "$ci" | grep -F 'ready_for_review' >/dev/null \
    || fail 'generic CI does not trigger validation when a Draft becomes review-ready'
grep -A16 -F 'pull_request:' "$ci" | grep -F 'converted_to_draft' >/dev/null \
    || fail 'generic CI cannot cancel active validation when a PR returns to Draft'
if grep -F 'github.event.pull_request.head.repo.full_name == github.repository' "$ci" >/dev/null; then
    fail 'generic GitHub-hosted CI must not suppress fork pull requests with the legacy self-hosted guard'
fi
grep -F 'github.event.pull_request.draft == false' "$ci" >/dev/null \
    || fail 'generic CI does not suppress automatic jobs for Draft PRs'
grep -F 'scripts/ci/classify-validation-plan.sh' "$ci" >/dev/null \
    || fail 'generic CI must use the shared validation-plan classifier'
grep -F 'github.event.pull_request.number || github.ref' "$ci" >/dev/null \
    || fail 'generic CI concurrency must use pull-request identity for supersession'
grep -A3 -F 'concurrency:' "$ci" | grep -F 'cancel-in-progress: true' >/dev/null \
    || fail 'generic CI must cancel superseded runs'
grep -F 'EXPECTED_HEAD_SHA' "$ci" >/dev/null \
    || fail 'generic CI lacks exact PR head/base freshness validation'
grep -F 'HEAD_REPO_URL: ${{ github.event.pull_request.head.repo.clone_url }}' "$ci" >/dev/null \
    || fail 'generic CI does not bind PR freshness checks to the actual head repository'
grep -F 'PR_HEAD_REPO_URL: ${{ github.event.pull_request.head.repo.clone_url }}' "$ci" >/dev/null \
    || fail 'generic secret scan does not bind fork PR heads to the actual head repository'
grep -F 'fetch-depth: 0' "$ci" >/dev/null \
    || fail 'generic CI secret scan must fetch complete Git history'
grep -F 'bash scripts/ci/scan-git-secrets.sh' "$ci" >/dev/null \
    || fail 'generic CI secret scan must delegate range resolution and scanning to the shared executable helper'
grep -F 'PR_BASE_SHA: ${{ github.event.pull_request.base.sha }}' "$ci" >/dev/null \
    || fail 'generic CI secret scan must bind the helper to the exact PR base SHA from the event'
grep -F 'PR_HEAD_SHA: ${{ github.event.pull_request.head.sha }}' "$ci" >/dev/null \
    || fail 'generic CI secret scan must bind the helper to the exact PR head SHA from the event'
grep -F 'PR_BASE_REF: ${{ github.event.pull_request.base.ref }}' "$ci" >/dev/null \
    || fail 'generic CI secret scan must bind the helper to the exact PR base ref from the event'
grep -F 'PR_HEAD_REF: ${{ github.event.pull_request.head.ref }}' "$ci" >/dev/null \
    || fail 'generic CI secret scan must bind the helper to the exact PR head ref from the event'
grep -Eq "GITLEAKS_SHA256: '[0-9a-f]{64}'" "$ci" >/dev/null \
    || fail 'generic CI secret scan must checksum-pin the standalone Gitleaks binary'
grep -F 'sha256sum -c -' "$ci" >/dev/null \
    || fail 'generic CI secret scan must verify the pinned Gitleaks archive checksum'
grep -F 'scripts/ci/bootstrap-ci-toolchain.sh' "$ci" >/dev/null \
    || fail 'generic CI must validate the runner-neutral PHP/Composer toolchain contract'
if grep -F 'bootstrap-self-hosted-toolchain.sh' "$ci" >/dev/null; then
    fail 'generic CI retains the retired self-hosted-only toolchain entrypoint'
fi
setup_php_ref=$(grep -Eo 'shivammathur/setup-php@[0-9a-f]+' "$ci" | head -n 1 || true)
[[ "$setup_php_ref" =~ ^shivammathur/setup-php@[0-9a-f]{40}$ ]] \
    || fail 'generic CI must pin setup-php to an immutable full commit SHA'
if grep -F 'gitleaks/gitleaks-action@' "$ci" >/dev/null; then
    fail 'generic CI must not rely on the PR-commit-list pagination behavior of gitleaks-action'
fi
if grep -F 'SCAN_LOG_OPTS' "$ci" >/dev/null; then
    fail 'generic CI must not reintroduce a separately mutable Secret-scan log-options handoff'
fi
bash scripts/ci/test-secret-scan.sh >/dev/null \
    || fail 'generic CI Secret-scan helper failed semantic history regression tests'
grep -F 'needs.preflight.outputs.integration == ' "$ci" >/dev/null \
    || fail 'MariaDB/Redis integration must be gated by the computed validation plan'
grep -F 'needs.preflight.outputs.dependencies == ' "$ci" >/dev/null \
    || fail 'dependency/license policy must be independently gated by the validation plan'
grep -F 'needs.preflight.outputs.operations == ' "$ci" >/dev/null \
    || fail 'operational validation must be independently gated by the validation plan'
grep -F "MARIADB_VERSION: '10.11'" "$ci" >/dev/null \
    || fail 'applicable integration CI must use MariaDB 10.11'
if grep -Eq 'matrix:|mariadb_version:.*11\.4|MARIADB_VERSION:.*11\.4' "$ci"; then
    fail 'normal CI must not require a MariaDB compatibility matrix on every PR'
fi
if grep -Eq 'secrets\.(PASARGUARD|STAGING|TELEGRAM|NOWPAYMENTS|ZARINPAL|MELLI|KAVENEGAR)' "$ci"; then
    fail 'generic CI references protected provider/staging/runtime secrets'
fi

# Superseded read-only checks may be cancelled; a guarded provider mutation must not be interrupted mid-effect.
grep -A3 -F 'concurrency:' .github/workflows/staging-readiness.yml | grep -F 'cancel-in-progress: true' >/dev/null \
    || fail 'staging-readiness must cancel superseded manual runs'
grep -A3 -F 'concurrency:' .github/workflows/provider-readiness.yml | grep -F 'cancel-in-progress: true' >/dev/null \
    || fail 'provider-readiness must cancel superseded read-only runs'
grep -A3 -F 'concurrency:' .github/workflows/provider-live-acceptance.yml | grep -F 'cancel-in-progress: false' >/dev/null \
    || fail 'provider live mutation must not be cancelled mid-effect'

# Provider workflow safeguards remain explicit and fail closed.
provider_readonly=.github/workflows/provider-readiness.yml
grep -F 'workflow_dispatch:' "$provider_readonly" >/dev/null \
    || fail 'provider-readiness must remain manual-only'
grep -F 'READ_ONLY_PROVIDER_CHECK' "$provider_readonly" >/dev/null \
    || fail 'provider-readiness lost its explicit read-only confirmation sentinel'
grep -F 'contents: read' "$provider_readonly" >/dev/null \
    || fail 'provider-readiness must retain read-only repository permissions'
grep -F 'pasarguard-readonly-probe.php' "$provider_readonly" >/dev/null \
    || fail 'provider-readiness must use the bounded read-only probe'
grep -F 'secrets.PASARGUARD_TEST_ORIGIN' "$provider_readonly" >/dev/null \
    || fail 'provider-readiness lost the protected origin input'
grep -F 'secrets.PASARGUARD_TEST_API_KEY' "$provider_readonly" >/dev/null \
    || fail 'provider-readiness lost the protected API-key input'
if grep -Eq '^[[:space:]]*(push|pull_request|pull_request_target|schedule|workflow_run):' "$provider_readonly"; then
    fail 'provider-readiness may not gain an automatic trigger'
fi
if grep -Eq '^[[:space:]]*environment:' "$provider_readonly"; then
    fail 'provider-readiness must not bind the protected live-mutation environment'
fi
if grep -Eq '^[[:space:]]+(sudo|curl|wget|ssh|scp|rsync|git[[:space:]]+push|php[[:space:]]+artisan|composer[[:space:]]+(install|update)|docker[[:space:]]+compose[[:space:]]+(up|down|run|exec|start|stop|restart|pull|build))([[:space:]]|$)|pasarguard-live-acceptance\.php' "$provider_readonly"; then
    fail 'provider-readiness contains a command outside its bounded read-only provider contract'
fi

provider_live=.github/workflows/provider-live-acceptance.yml
grep -F 'workflow_dispatch:' "$provider_live" >/dev/null \
    || fail 'provider live acceptance must remain manual-only'
grep -F 'contents: read' "$provider_live" >/dev/null \
    || fail 'provider live acceptance must retain read-only repository permissions'
grep -F 'MUTATE_DISPOSABLE_PASARGUARD_V5_2_1' "$provider_live" >/dev/null \
    || fail 'provider live acceptance lost its explicit mutation sentinel'
grep -F 'name: provider-live-acceptance' "$provider_live" >/dev/null \
    || fail 'provider live acceptance must retain the protected environment gate'
grep -F 'pasarguard-live-acceptance.php' "$provider_live" >/dev/null \
    || fail 'provider live acceptance must retain the guarded acceptance entrypoint'
grep -F 'secrets.PASARGUARD_TEST_ORIGIN' "$provider_live" >/dev/null \
    || fail 'provider live acceptance lost the protected origin input'
grep -F 'secrets.PASARGUARD_TEST_API_KEY' "$provider_live" >/dev/null \
    || fail 'provider live acceptance lost the protected API-key input'

printf '%s\n' 'Project control verification passed.'
