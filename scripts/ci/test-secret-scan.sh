#!/usr/bin/env bash

set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
helper="$root/scripts/ci/scan-git-secrets.sh"
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

fail() {
    echo "Secret-scan semantic test failed: $*" >&2
    exit 1
}

remote="$tmpdir/remote.git"
repo="$tmpdir/repo"
fake_gitleaks="$tmpdir/gitleaks"
late_secret="github_${late_secret_suffix:-pat_11TESTLATESECRET000000000000000000000000000000000000000000000000000000000}"
merge_secret="github_${merge_secret_suffix:-pat_11TESTMERGERESOLUTION000000000000000000000000000000000000000000000000000000}"

git init --bare -q "$remote"
git init -q "$repo"
cd "$repo"
git config user.name 'Secret Scan Test'
git config user.email 'secret-scan-test@example.invalid'
git remote add origin "$remote"
git switch -q -c base
printf '%s\n' 'base' > history.txt
git add history.txt
git commit -q -m 'base'
git push -q origin base
base_sha=$(git rev-parse HEAD)

git switch -q -c feature
for i in $(seq 1 26); do
    printf 'candidate-%02d\n' "$i" >> history.txt
    git add history.txt
    git commit -q -m "candidate $i"
done

git switch -q -c merge-side
printf '%s\n' 'side-parent' > merge.txt
git add merge.txt
git commit -q -m 'side parent'

git switch -q feature
printf '%s\n' 'feature-parent' > merge.txt
git add merge.txt
git commit -q -m 'feature parent'
if git merge --no-ff merge-side -m 'merge side' >/dev/null 2>&1; then
    fail 'synthetic merge unexpectedly avoided the intended conflict'
fi
printf '%s\n' 'feature-parent' 'side-parent' "$merge_secret" > merge.txt
git add merge.txt
git commit -q -m 'merge with resolution-only secret'
merge_commit=$(git rev-parse HEAD)
for parent in 1 2; do
    if git show "$merge_commit^$parent:merge.txt" | grep -F "$merge_secret" >/dev/null; then
        fail "synthetic merge parent unexpectedly contains the resolution-only secret: parent $parent"
    fi
done
git show "$merge_commit:merge.txt" | grep -F "$merge_secret" >/dev/null \
    || fail 'synthetic merge commit lost its resolution-only secret'
printf '%s\n' "$late_secret" > late-secret.txt
git add late-secret.txt
git commit -q -m 'introduce late secret'
git rm -q late-secret.txt
git commit -q -m 'remove late secret'
git push -q origin feature
head_sha=$(git rev-parse HEAD)
expected_count=$(git rev-list --count "$base_sha..$head_sha")
((expected_count > 25)) || fail "synthetic candidate must exceed 25 commits, got $expected_count"
expected_log_opts="--full-history --diff-merges=separate $base_sha..$head_sha"
if git log -p -U0 "$base_sha..$head_sha" | grep -F "$merge_secret" >/dev/null; then
    fail 'synthetic merge secret is not merge-resolution-only'
fi

cat > "$fake_gitleaks" <<'EOF_FAKE'
#!/usr/bin/env bash
set -euo pipefail
log_opts=''
if [[ -n "${FAKE_GITLEAKS_CALL_FILE:-}" ]]; then
    : > "$FAKE_GITLEAKS_CALL_FILE"
fi
for arg in "$@"; do
    case "$arg" in
        --log-opts=*) log_opts=${arg#--log-opts=} ;;
    esac
done

if [[ -n "${EXPECTED_LOG_OPTS:-}" ]]; then
    [[ "$log_opts" == "$EXPECTED_LOG_OPTS" ]] || {
        echo "unexpected log opts: ${log_opts:-missing}" >&2
        exit 91
    }
    read -r -a parsed <<< "$log_opts"
    history=$(git log -p -U0 "${parsed[@]}")
    if [[ -n "${EXPECTED_LATE_SECRET:-}" ]]; then
        grep -F "$EXPECTED_LATE_SECRET" <<< "$history" >/dev/null || exit 92
    fi
    if [[ -n "${EXPECTED_MERGE_SECRET:-}" ]]; then
        grep -F "$EXPECTED_MERGE_SECRET" <<< "$history" >/dev/null || exit 93
    fi
elif [[ -n "$log_opts" && "${EXPECTED_SCAN_MODE:-}" == 'all' ]]; then
    echo "all-history scan unexpectedly received log opts: $log_opts" >&2
    exit 94
fi

exit "${FAKE_GITLEAKS_EXIT:-0}"
EOF_FAKE
chmod 755 "$fake_gitleaks"

run_pr_case() {
    local candidate_helper=$1
    local output=$2
    local call_file="$tmpdir/$(basename "$candidate_helper").called"
    local status=0
    rm -f "$call_file"
    env \
        GITHUB_EVENT_NAME=pull_request \
        PR_BASE_SHA="$base_sha" \
        PR_HEAD_SHA="$head_sha" \
        PR_BASE_REF=base \
        PR_HEAD_REF=feature \
        PATH="$tmpdir:$PATH" \
        EXPECTED_LOG_OPTS="$expected_log_opts" \
        EXPECTED_LATE_SECRET="$late_secret" \
        EXPECTED_MERGE_SECRET="$merge_secret" \
        FAKE_GITLEAKS_CALL_FILE="$call_file" \
        bash "$candidate_helper" >"$output" 2>&1 || status=$?
    ((status == 0)) || return "$status"
    test -e "$call_file" || return 95
}

output="$tmpdir/pr.out"
run_pr_case "$helper" "$output" || {
    cat "$output" >&2
    fail 'canonical helper rejected the complete synthetic PR history'
}
grep -F "Secret-scan base SHA: $base_sha" "$output" >/dev/null \
    || fail 'canonical helper did not report the exact base SHA'
grep -F "Secret-scan head SHA: $head_sha" "$output" >/dev/null \
    || fail 'canonical helper did not report the exact head SHA'
grep -F "Secret-scan candidate commits: $expected_count" "$output" >/dev/null \
    || fail 'canonical helper did not report the complete candidate commit count'

if env \
    GITHUB_EVENT_NAME=pull_request \
    PR_BASE_SHA="$base_sha" \
    PR_HEAD_SHA="$(git rev-parse HEAD~1)" \
    PR_BASE_REF=base \
    PR_HEAD_REF=feature \
    PATH="$tmpdir:$PATH" \
    bash "$helper" >/dev/null 2>&1; then
    fail 'canonical helper accepted a stale event head SHA'
fi

push_before=$(git rev-parse HEAD~2)
push_opts="--full-history --diff-merges=separate $push_before..$head_sha"
env \
    GITHUB_EVENT_NAME=push \
    GITHUB_SHA="$head_sha" \
    PUSH_BEFORE_SHA="$push_before" \
    PATH="$tmpdir:$PATH" \
    EXPECTED_LOG_OPTS="$push_opts" \
    bash "$helper" >/dev/null

env \
    GITHUB_EVENT_NAME=workflow_dispatch \
    PATH="$tmpdir:$PATH" \
    EXPECTED_SCAN_MODE=all \
    bash "$helper" >/dev/null

set +e
env \
    GITHUB_EVENT_NAME=pull_request \
    PR_BASE_SHA="$base_sha" \
    PR_HEAD_SHA="$head_sha" \
    PR_BASE_REF=base \
    PR_HEAD_REF=feature \
    PATH="$tmpdir:$PATH" \
    EXPECTED_LOG_OPTS="$expected_log_opts" \
    EXPECTED_LATE_SECRET="$late_secret" \
    EXPECTED_MERGE_SECRET="$merge_secret" \
    FAKE_GITLEAKS_EXIT=2 \
    bash "$helper" >/dev/null 2>&1
finding_status=$?
set -e
[[ "$finding_status" -eq 2 ]] || fail "canonical helper did not propagate the scanner finding exit code: $finding_status"

expect_mutation_rejected() {
    local name=$1
    local mutated="$tmpdir/$name.sh"
    cp "$helper" "$mutated"
    case "$name" in
        truncated-consumer)
            sed -i 's|args+=(--log-opts="$log_opts")|args+=(--log-opts=-25)|' "$mutated"
            ;;
        reassigned-range)
            sed -i 's|scan_range="$PR_BASE_SHA..$PR_HEAD_SHA"|scan_range="$(git rev-parse "$PR_HEAD_SHA~24")..$PR_HEAD_SHA"|' "$mutated"
            ;;
        *) fail "unknown helper mutation: $name" ;;
    esac
    chmod 755 "$mutated"
    if cmp -s "$helper" "$mutated"; then
        fail "mutation did not alter helper: $name"
    fi
    if run_pr_case "$mutated" "$tmpdir/$name.out"; then
        fail "semantic harness accepted helper mutation: $name"
    fi
}

expect_mutation_rejected truncated-consumer
expect_mutation_rejected reassigned-range

printf '%s\n' "Secret-scan semantic tests passed ($expected_count candidate commits)."
