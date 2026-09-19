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
one="$tmpdir/one"
zero="$tmpdir/zero"
two="$tmpdir/two"
union="$tmpdir/union"
ordered_union="$tmpdir/ordered-union"
overlap="$tmpdir/overlap"

find "$root/tests/Feature" -type f -name '*Test.php' -printf '%P\n' | sed 's#^#tests/Feature/#' | sort > "$all"
[[ -s "$all" ]] || fail 'repository has no Feature tests'
if grep -Ev 'Test\.php$' "$all" >/dev/null; then
    fail 'sharding input must contain PHPUnit test entry files only'
fi

bash "$selector" 1 0 > "$one"
cmp -s "$all" "$one" || fail '1/1 shard must contain the complete Feature suite'

bash "$selector" 2 0 > "$zero"
bash "$selector" 2 1 > "$two"
[[ -s "$zero" && -s "$two" ]] || fail 'both 2-way shards must be non-empty'

cat "$zero" "$two" | sort > "$union"
cmp -s "$all" "$union" || fail '2-way shard union must contain every Feature file exactly once'

cat "$zero" "$two" > "$ordered_union"
cmp -s "$all" "$ordered_union" || fail '2-way shards must preserve contiguous Feature-suite file order'

comm -12 "$zero" "$two" > "$overlap"
[[ ! -s "$overlap" ]] || fail '2-way shards overlap'

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

weight_zero=$(weight_for "$zero")
weight_two=$(weight_for "$two")
min_weight=$weight_zero
max_weight=$weight_two
if ((weight_zero > weight_two)); then
    min_weight=$weight_two
    max_weight=$weight_zero
fi
((min_weight > 0)) || fail 'shard static weight must be positive'
# Greedy line-count balancing is only a static proxy for runtime, but a gross
# imbalance would make the parallel route predictably ineffective.
((max_weight * 100 <= min_weight * 115)) \
    || fail "2-way shard static weights are imbalanced: $weight_zero vs $weight_two"

if bash "$selector" 0 0 >/dev/null 2>&1; then
    fail 'zero shard count must be rejected'
fi
if bash "$selector" 2 2 >/dev/null 2>&1; then
    fail 'out-of-range shard index must be rejected'
fi

printf 'Feature sharding invariants passed: shard weights %s / %s lines.\n' "$weight_zero" "$weight_two"
