#!/usr/bin/env bash

set -euo pipefail

shard_count=${1:-}
shard_index=${2:-}

fail() {
    echo "Feature shard selection failed: $*" >&2
    exit 1
}

[[ "$shard_count" =~ ^[0-9]+$ ]] || fail 'shard_count must be a positive integer'
[[ "$shard_index" =~ ^[0-9]+$ ]] || fail 'shard_index must be a non-negative integer'
((shard_count >= 1)) || fail 'shard_count must be at least 1'
((shard_index >= 0 && shard_index < shard_count)) || fail 'shard_index must be smaller than shard_count'

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
planner="$root/scripts/ci/feature-test-shard-plan.sh"
[[ -s "$planner" ]] || fail 'Feature shard planner does not exist'

selected=0
while IFS=$'\t' read -r assigned rel; do
    [[ "$assigned" =~ ^[0-9]+$ && -n "$rel" ]] || fail 'invalid Feature shard plan record'
    if ((assigned == shard_index)); then
        printf '%s\n' "$rel"
        selected=$((selected + 1))
    fi
done < <(bash "$planner" "$shard_count")

((selected > 0)) || fail "Feature shard $shard_index/$shard_count is empty"
