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
done < <(find "$feature_root" -type f -name '*.php' -print0) | sort -nr -k1,1 -k2,2 > "$tmp"

[[ -s "$tmp" ]] || fail 'no Feature tests were found'

declare -a weights
declare -a assignments
for ((i = 0; i < shard_count; i++)); do
    weights[$i]=0
    assignments[$i]=''
done

while IFS=$'\t' read -r padded_lines rel; do
    [[ -n "$padded_lines" && -n "$rel" ]] || fail 'invalid weighted Feature test record'
    lines=$((10#$padded_lines))
    best=0
    for ((i = 1; i < shard_count; i++)); do
        if ((weights[$i] < weights[$best])); then
            best=$i
        fi
    done
    weights[$best]=$((weights[$best] + lines))
    assignments[$best]+="$rel"$'\n'
done < "$tmp"

printf '%s' "${assignments[$shard_index]}" | sed '/^$/d' | sort
