#!/usr/bin/env bash

set -euo pipefail

fail() {
    echo "Feature test weight failed: $*" >&2
    exit 1
}

(($# > 0)) || fail 'at least one Feature test path is required'

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
tests_root="$root/tests"
[[ -d "$tests_root" ]] || fail 'tests directory does not exist'

declare -A trait_files=()

while IFS= read -r -d '' path; do
    while IFS= read -r trait; do
        [[ -n "$trait" ]] || continue
        if [[ -n "${trait_files[$trait]+x}" && "${trait_files[$trait]}" != "$path" ]]; then
            fail "local test trait name is ambiguous: $trait"
        fi
        trait_files[$trait]="$path"
    done < <(sed -n -E 's/^[[:space:]]*trait[[:space:]]+([A-Za-z_][A-Za-z0-9_]*).*/\1/p' "$path")
done < <(find "$tests_root" -type f -name '*.php' -print0)

weight_for_path() {
    local target=$1
    local target_path

    if [[ "$target" = /* ]]; then
        target_path=$target
    else
        target_path="$root/$target"
    fi

    [[ -f "$target_path" ]] || fail "Feature test path does not exist: $target"
    target_path=$(realpath "$target_path")
    [[ "$target_path" == "$root/"* ]] || fail "Feature test path escapes repository root: $target"

    local rel=${target_path#"$root/"}
    [[ "$rel" == tests/Feature/*Test.php ]] || fail "weight target must be a Feature test entry file: $rel"

    declare -A seen=()
    local queue=("$target_path")
    local total=0

    while (("${#queue[@]}" > 0)); do
        local current=${queue[0]}
        queue=("${queue[@]:1}")

        if [[ -n "${seen[$current]+x}" ]]; then
            continue
        fi
        seen[$current]=1

        total=$((total + $(wc -l < "$current")))

        while IFS= read -r declaration; do
            local names=()
            IFS=',' read -r -a names <<< "$declaration"

            local trait
            for trait in "${names[@]}"; do
                trait="${trait#"${trait%%[![:space:]]*}"}"
                trait="${trait%"${trait##*[![:space:]]}"}"
                [[ -n "$trait" ]] || continue

                local trait_path=${trait_files[$trait]-}
                [[ -n "$trait_path" ]] || continue
                if [[ -z "${seen[$trait_path]+x}" ]]; then
                    queue+=("$trait_path")
                fi
            done
        done < <(sed -n -E 's/^[[:space:]]*use[[:space:]]+([A-Za-z_][A-Za-z0-9_]*([[:space:]]*,[[:space:]]*[A-Za-z_][A-Za-z0-9_]*)*)[[:space:]]*;.*/\1/p' "$current")
    done

    ((total > 0)) || fail "Feature test static weight must be positive: $rel"
    printf '%012d\t%s\n' "$total" "$rel"
}

for target in "$@"; do
    weight_for_path "$target"
done
