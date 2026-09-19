#!/usr/bin/env bash

set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
selector="$root/scripts/ci/select-feature-shard.sh"
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

fail() {
    echo "Feature sharding test failed: $*" >&2
    exit 1
}

all="$tmpdir/all"
find "$root/tests/Feature" -type f -name '*Test.php' -printf '%P\n' | sed 's#^#tests/Feature/#' | sort > "$all"
[[ -s "$all" ]] || fail 'repository has no Feature tests'
if grep -Ev 'Test\.php$' "$all" >/dev/null; then
    fail 'sharding input must contain PHPUnit test entry files only'
fi

one="$tmpdir/one"
bash "$selector" 1 0 > "$one"
cmp -s "$all" "$one" || fail '1/1 shard must contain the complete Feature suite'

weight_for() {
    local list=$1
    local total=0
    local path
    while IFS= read -r path; do
        [[ -n "$path" ]] || continue
        [[ -f "$root/$path" ]] || fail "selector returned missing file: $path"
        total=$((total + $(wc -l < "$root/$path")))
    done < "$list"
    printf '%s\n' "$total"
}

verify_shards() {
    local count=$1
    local ordered="$tmpdir/ordered-$count"
    : > "$ordered"

    local min_weight=0
    local max_weight=0
    local weights=()
    local index
    for ((index = 0; index < count; index++)); do
        local shard="$tmpdir/shard-$count-$index"
        bash "$selector" "$count" "$index" > "$shard"
        [[ -s "$shard" ]] || fail "$count-way shard $index must be non-empty"
        cat "$shard" >> "$ordered"

        local weight
        weight=$(weight_for "$shard")
        weights+=("$weight")
        if ((index == 0 || weight < min_weight)); then
            min_weight=$weight
        fi
        if ((weight > max_weight)); then
            max_weight=$weight
        fi
    done

    cmp -s "$all" "$ordered" \
        || fail "$count-way shards must cover every Feature test exactly once in contiguous suite order"
    ((min_weight > 0)) || fail 'shard static weight must be positive'
    ((max_weight * 100 <= min_weight * 115)) \
        || fail "$count-way shard static weights are imbalanced: ${weights[*]}"

    printf 'Feature $s-way sharding invariants passed: weights %s\n' "$count" "${weights[*]}"
}

verify_shards 2
verify_shards 4

if bash "$selector" 0 0 >/dev/null 2>&1; then
    fail 'zero shard count must be rejected'
fi
if bash "$selector" 4 4 >/dev/null 2>&1; then
    fail 'out-of-range shard index must be rejected'
fi
