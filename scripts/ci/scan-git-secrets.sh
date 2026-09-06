#!/usr/bin/env bash

set -euo pipefail

fail() {
    echo "Secret-scan range resolution failed: $*" >&2
    exit 1
}

gitleaks_bin=gitleaks
remote=origin
report_path=results.sarif

command -v "$gitleaks_bin" >/dev/null 2>&1 || fail "Gitleaks executable is unavailable: $gitleaks_bin"

run_gitleaks() {
    local mode=$1
    local log_opts=${2:-}
    local -a args=(
        detect
        --redact
        -v
        --exit-code=2
        --report-format=sarif
        --report-path="$report_path"
        --log-level=debug
    )

    case "$mode" in
        range)
            [[ "$log_opts" == --full-history\ --diff-merges=separate\ *..* ]] \
                || fail "Range mode received unexpected Git log options: ${log_opts:-missing}"
            args+=(--log-opts="$log_opts")
            ;;
        single)
            args+=(--log-opts=-1)
            ;;
        all)
            ;;
        *)
            fail "Unknown secret-scan mode: $mode"
            ;;
    esac

    "$gitleaks_bin" "${args[@]}"
}

case "${GITHUB_EVENT_NAME:-}" in
    pull_request)
        for value in PR_BASE_SHA PR_HEAD_SHA PR_BASE_REF PR_HEAD_REF; do
            [[ -n "${!value:-}" ]] || fail "Missing pull-request secret-scan input: $value"
        done

        base_remote="${PR_BASE_REPO_URL:-$remote}"
        head_remote="${PR_HEAD_REPO_URL:-$remote}"
        current_base=$(git ls-remote --refs "$base_remote" "refs/heads/$PR_BASE_REF" | awk 'NR == 1 {print $1}')
        current_head=$(git ls-remote --refs "$head_remote" "refs/heads/$PR_HEAD_REF" | awk 'NR == 1 {print $1}')
        [[ "$current_base" == "$PR_BASE_SHA" ]] \
            || fail "Secret-scan base drifted: expected $PR_BASE_SHA, got ${current_base:-missing}"
        [[ "$current_head" == "$PR_HEAD_SHA" ]] \
            || fail "Secret-scan head drifted: expected $PR_HEAD_SHA, got ${current_head:-missing}"

        git cat-file -e "${PR_BASE_SHA}^{commit}"
        git cat-file -e "${PR_HEAD_SHA}^{commit}"
        git merge-base --is-ancestor "$PR_BASE_SHA" "$PR_HEAD_SHA" \
            || fail 'Secret-scan head is not a descendant of the event base.'

        scan_range="$PR_BASE_SHA..$PR_HEAD_SHA"
        commit_count=$(git rev-list --count "$scan_range")
        ((commit_count > 0)) || fail 'Secret-scan range contains no candidate commits.'

        echo "Secret-scan base SHA: $PR_BASE_SHA"
        echo "Secret-scan head SHA: $PR_HEAD_SHA"
        echo "Secret-scan candidate commits: $commit_count"
        log_opts="--full-history --diff-merges=separate $scan_range"
        run_gitleaks range "$log_opts"
        ;;

    push)
        [[ -n "${GITHUB_SHA:-}" ]] || fail 'Missing GITHUB_SHA for push secret scan.'
        git cat-file -e "${GITHUB_SHA}^{commit}"

        if [[ -n "${PUSH_BEFORE_SHA:-}" && ! "$PUSH_BEFORE_SHA" =~ ^0+$ && "$PUSH_BEFORE_SHA" != "$GITHUB_SHA" ]]; then
            git cat-file -e "${PUSH_BEFORE_SHA}^{commit}"
            git merge-base --is-ancestor "$PUSH_BEFORE_SHA" "$GITHUB_SHA" \
                || fail 'Push secret-scan head is not a descendant of the before SHA.'
            scan_range="$PUSH_BEFORE_SHA..$GITHUB_SHA"
            commit_count=$(git rev-list --count "$scan_range")
            ((commit_count > 0)) || fail 'Push secret-scan range contains no commits.'
            echo "Secret-scan push commits: $commit_count"
            log_opts="--full-history --diff-merges=separate $scan_range"
            run_gitleaks range "$log_opts"
        else
            run_gitleaks single
        fi
        ;;

    workflow_dispatch)
        run_gitleaks all
        ;;

    *)
        fail "Unsupported secret-scan event: ${GITHUB_EVENT_NAME:-missing}"
        ;;
esac
