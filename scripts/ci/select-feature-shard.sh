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
feature_root="$root/tests/Feature"
[[ -d "$feature_root" ]] || fail 'tests/Feature does not exist'

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT

while IFS= read -r -d '' path; do
    rel=${path#"$root/"}
    lines=$(wc -l < "$path")
    printf '%012d\t%s\n' "$lines" "$rel"
done < <(find "$feature_root" -type f -name '*Test.php' -print0) | sort -k2,2 > "$tmp"

[[ -s "$tmp" ]] || fail 'no Feature tests were found'

total=0
while IFS=$'\t' read -r padded_lines rel; do
    [[ -n "$padded_lines" && -n "$rel" ]] || fail 'invalid weighted Feature test record'
    total=$((total + 10#$padded_lines))
done < "$tmp"
((total > 0)) || fail 'Feature test static weight must be positive'

cumulative=0
selected=0
while IFS=$'\t' read -r padded_lines rel; do
    lines=$((10#$padded_lines))
    midpoint_twice=$((2 * cumulative + lines))
    assigned=$((midpoint_twice * shard_count / (2 * total)))
    ((assigned < shard_count)) || assigned=$((shard_count - 1))

    if ((assigned == shard_index)); then
        printf '%s\n' "$rel"
        selected=$((selected + 1))
    fi

    cumulative=$((cumulative + lines))
done < "$tmp"

((selected > 0)) || fail "Feature shard $shard_index/$shard_count is empty"
