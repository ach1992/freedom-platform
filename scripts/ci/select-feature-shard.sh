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
weigher="$root/scripts/ci/feature-test-weight.sh"
[[ -d "$feature_root" ]] || fail 'tests/Feature does not exist'
[[ -s "$weigher" ]] || fail 'Feature test weight helper does not exist'

tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

feature_paths=()
mapfile -d '' -t feature_paths < <(find "$feature_root" -type f -name '*Test.php' -print0)
(("${#feature_paths[@]}" > 0)) || fail 'no Feature tests were found'

weights="$tmpdir/weights"
ranked="$tmpdir/ranked"
assignments="$tmpdir/assignments"
selected="$tmpdir/selected"

bash "$weigher" "${feature_paths[@]}" > "$weights"
[[ -s "$weights" ]] || fail 'no weighted Feature tests were produced'

sort -t $'\t' -k1,1nr -k2,2 "$weights" > "$ranked"

loads=()
for ((index = 0; index < shard_count; index++)); do
    loads[index]=0
done

: > "$assignments"
while IFS=$'\t' read -r padded_weight rel; do
    [[ -n "$padded_weight" && -n "$rel" ]] || fail 'invalid weighted Feature test record'
    weight=$((10#$padded_weight))

    target=0
    for ((index = 1; index < shard_count; index++)); do
        if ((loads[index] < loads[target])); then
            target=$index
        fi
    done

    printf '%d\t%s\n' "$target" "$rel" >> "$assignments"
    loads[target]=$((loads[target] + weight))
done < "$ranked"

awk -F $'\t' -v shard="$shard_index" '$1 == shard {print $2}' "$assignments" | sort > "$selected"
[[ -s "$selected" ]] || fail "Feature shard $shard_index/$shard_count is empty"
cat "$selected"
