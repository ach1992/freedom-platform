#!/usr/bin/env bash

set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
selector="$root/scripts/ci/select-feature-shard.sh"
weigher="$root/scripts/ci/feature-test-weight.sh"
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

fail() {
    echo "Feature sharding test failed: $*" >&2
    exit 1
}

[[ -s "$selector" ]] || fail 'Feature shard selector is missing'
[[ -s "$weigher" ]] || fail 'Feature test weight helper is missing'

all="$tmpdir/all"
find "$root/tests/Feature" -type f -name '*Test.php' -printf '%P\n' | sed 's#^#tests/Feature/#' | sort > "$all"
[[ -s "$all" ]] || fail 'repository has no Feature tests'
if grep -Ev 'Test\.php$' "$all" >/dev/null; then
    fail 'sharding input must contain PHPUnit test entry files only'
fi

composed="tests/Feature/AgentPricingQuoteIntegrationTest.php"
[[ -f "$root/$composed" ]] || fail 'trait-composed Feature regression fixture is missing'
entry_lines=$(wc -l < "$root/$composed")
expanded_record=$(bash "$weigher" "$composed")
expanded_weight=${expanded_record%%$'\t'*}
expanded_weight=$((10#$expanded_weight))
if ((expanded_weight <= entry_lines)); then
    fail "trait-composed Feature weight must exceed entry-file LOC: entry=$entry_lines expanded=$expanded_weight"
fi

one="$tmpdir/one"
bash "$selector" 1 0 > "$one"
cmp -s "$all" "$one" || fail '1/1 shard must contain the complete Feature suite'

weight_for() {
    local list=$1
    local paths=()
    local path

    while IFS= read -r path; do
        [[ -n "$path" ]] || continue
        [[ -f "$root/$path" ]] || fail "selector returned missing file: $path"
        paths+=("$root/$path")
    done < "$list"

    (("${#paths[@]}" > 0)) || fail 'cannot weigh an empty Feature shard'

    local records=()
    mapfile -t records < <(bash "$weigher" "${paths[@]}")

    local total=0
    local record
    for record in "${records[@]}"; do
        local padded_weight=${record%%$'\t'*}
        total=$((total + 10#$padded_weight))
    done

    printf '%s\n' "$total"
}

verify_shards() {
    local count=$1
    local combined="$tmpdir/combined-$count"
    : > "$combined"

    local min_weight=0
    local max_weight=0
    local weights=()
    local index

    for ((index = 0; index < count; index++)); do
        local shard="$tmpdir/shard-$count-$index"
        local repeat="$tmpdir/shard-$count-$index-repeat"

        bash "$selector" "$count" "$index" > "$shard"
        bash "$selector" "$count" "$index" > "$repeat"
        cmp -s "$shard" "$repeat" || fail "$count-way shard $index assignment must be deterministic"
        [[ -s "$shard" ]] || fail "$count-way shard $index must be non-empty"

        cat "$shard" >> "$combined"

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

    cmp -s "$all" "$combined" || fail "$count-way shards must preserve global Feature order and cover every test exactly once"

    local combined_count
    local unique_count
    combined_count=$(wc -l < "$combined")
    unique_count=$(sort -u "$combined" | wc -l)
    ((combined_count == unique_count)) || fail "$count-way shards must not duplicate Feature test entry files"

    ((min_weight > 0)) || fail 'shard static weight must be positive'
    if ((max_weight * 100 > min_weight * 115)); then
        fail "$count-way shard static weights are imbalanced: ${weights[*]}"
    fi

    printf 'Feature %s-way sharding invariants passed: weights %s\n' "$count" "${weights[*]}"
}

verify_shards 2
verify_shards 4
verify_shards 8

if bash "$selector" 0 0 >/dev/null 2>&1; then
    fail 'zero shard count must be rejected'
fi
if bash "$selector" 8 8 >/dev/null 2>&1; then
    fail 'out-of-range shard index must be rejected'
fi
