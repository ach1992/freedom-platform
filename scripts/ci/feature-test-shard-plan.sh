#!/usr/bin/env bash

set -euo pipefail
export LC_ALL=C

shard_count=${1:-}

fail() {
    echo "Feature shard planning failed: $*" >&2
    exit 1
}

[[ "$shard_count" =~ ^[0-9]+$ ]] || fail 'shard_count must be a positive integer'
((shard_count >= 1)) || fail 'shard_count must be at least 1'

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
bash "$weigher" "${feature_paths[@]}" | sort -t $'\t' -k2,2 > "$weights"
[[ -s "$weights" ]] || fail 'no weighted Feature tests were produced'

if ! awk -F '\t' -v shard_count="$shard_count" '
function key(shard, item) {
    return shard SUBSEP item
}
function abs_value(value) {
    return value < 0 ? -value : value
}
{
    numeric_weight = $1 + 0
    if (numeric_weight <= 0 || $2 == "") {
        exit 3
    }

    weight[NR] = numeric_weight
    path[NR] = $2
    prefix[NR] = prefix[NR - 1] + numeric_weight
}
END {
    item_count = NR
    if (item_count < shard_count || item_count == 0) {
        exit 4
    }

    target = prefix[item_count] / shard_count

    for (item = 1; item <= item_count; item++) {
        dp[key(1, item)] = prefix[item]
        deviation[key(1, item)] = abs_value(prefix[item] - target)
        cut[key(1, item)] = 0
    }

    for (shard = 2; shard <= shard_count; shard++) {
        for (item = shard; item <= item_count; item++) {
            best_cost = -1
            best_deviation = -1
            best_cut = -1

            for (candidate = shard - 1; candidate <= item - 1; candidate++) {
                left_cost = dp[key(shard - 1, candidate)]
                right_weight = prefix[item] - prefix[candidate]
                candidate_cost = left_cost > right_weight ? left_cost : right_weight
                candidate_deviation = deviation[key(shard - 1, candidate)] + abs_value(right_weight - target)

                if (best_cost < 0
                    || candidate_cost < best_cost
                    || (candidate_cost == best_cost && candidate_deviation < best_deviation)
                    || (candidate_cost == best_cost && candidate_deviation == best_deviation && candidate < best_cut)) {
                    best_cost = candidate_cost
                    best_deviation = candidate_deviation
                    best_cut = candidate
                }
            }

            dp[key(shard, item)] = best_cost
            deviation[key(shard, item)] = best_deviation
            cut[key(shard, item)] = best_cut
        }
    }

    current_end = item_count
    for (shard = shard_count; shard >= 2; shard--) {
        boundary = cut[key(shard, current_end)]
        if (boundary < shard - 1 || boundary >= current_end) {
            exit 5
        }

        segment_start[shard] = boundary + 1
        segment_end[shard] = current_end
        current_end = boundary
    }

    segment_start[1] = 1
    segment_end[1] = current_end

    for (shard = 1; shard <= shard_count; shard++) {
        if (segment_start[shard] > segment_end[shard]) {
            exit 6
        }

        for (item = segment_start[shard]; item <= segment_end[shard]; item++) {
            printf "%d\t%s\n", shard - 1, path[item]
        }
    }
}
' "$weights"; then
    fail 'unable to compute deterministic contiguous Feature shard plan'
fi
