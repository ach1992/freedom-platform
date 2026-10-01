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
(("${#feature_paths[@]}" >= shard_count)) || fail 'shard_count cannot exceed the number of Feature tests'

weights="$tmpdir/weights"
assignments="$tmpdir/assignments"

bash "$weigher" "${feature_paths[@]}" | sort -k2,2 > "$weights"
[[ -s "$weights" ]] || fail 'no weighted Feature tests were produced'

padded_weights=()
paths=()
total=0
while IFS=$'\t' read -r padded_weight rel; do
    [[ -n "$padded_weight" && -n "$rel" ]] || fail 'invalid weighted Feature test record'
    weight=$((10#$padded_weight))
    ((weight > 0)) || fail "Feature test static weight must be positive: $rel"
    padded_weights+=("$padded_weight")
    paths+=("$rel")
    total=$((total + weight))
done < "$weights"

count=${#paths[@]}
((count > 0 && total > 0)) || fail 'Feature test static weight must be positive'

abs_diff() {
    local left=$1
    local right=$2
    if ((left >= right)); then
        printf '%d\n' "$((left - right))"
    else
        printf '%d\n' "$((right - left))"
    fi
}

: > "$assignments"
current_shard=0
current_load=0
consumed=0

for ((i = 0; i < count; i++)); do
    weight=$((10#${padded_weights[i]}))
    remaining_items=$((count - i))
    remaining_shards=$((shard_count - current_shard))

    if ((remaining_shards > 1 && current_load > 0)); then
        if ((remaining_items == remaining_shards)); then
            current_shard=$((current_shard + 1))
            current_load=0
            remaining_shards=$((remaining_shards - 1))
        else
            remaining_weight=$((total - consumed))
            partition_budget=$((current_load + remaining_weight))
            target=$(((partition_budget + remaining_shards - 1) / remaining_shards))
            without_current=$(abs_diff "$current_load" "$target")
            with_current=$(abs_diff "$((current_load + weight))" "$target")

            if ((without_current < with_current)); then
                current_shard=$((current_shard + 1))
                current_load=0
            fi
        fi
    fi

    printf '%d\t%s\n' "$current_shard" "${paths[i]}" >> "$assignments"
    current_load=$((current_load + weight))
    consumed=$((consumed + weight))
done

((current_shard == shard_count - 1)) || fail 'Feature shard planner did not populate every requested shard'

awk -F $'\t' -v shard="$shard_index" '$1 == shard {print $2}' "$assignments"
