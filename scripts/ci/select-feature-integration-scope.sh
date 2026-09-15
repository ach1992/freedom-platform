#!/usr/bin/env bash

set -euo pipefail

base=${1:-}
head=${2:-}

emit_full() {
    printf '%s\n' 'scope=FULL'
    exit 0
}

emit_targeted() {
    printf '%s\n' 'scope=TARGETED'
    local target
    for target in "$@"; do
        printf 'target=%s\n' "$target"
    done
    exit 0
}

[[ -n "$base" && -n "$head" ]] || emit_full
git cat-file -e "$base^{commit}" 2>/dev/null || emit_full
git cat-file -e "$head^{commit}" 2>/dev/null || emit_full

mapfile -t changes < <(git diff --name-status --find-renames "$base" "$head")
((${#changes[@]} > 0)) || emit_full

feature_targets=()
only_tests=true

for entry in "${changes[@]}"; do
    IFS=$'\t' read -r status path extra <<< "$entry"
    [[ -n "$status" && -n "$path" && -z "${extra:-}" ]] || emit_full

    case "$path" in
        tests/Feature/*.php)
            [[ "$status" == 'A' || "$status" == 'M' ]] || emit_full
            feature_targets+=("$path")
            ;;
        tests/Unit/*.php)
            [[ "$status" == 'A' || "$status" == 'M' ]] || emit_full
            ;;
        *)
            only_tests=false
            ;;
    esac
done

if [[ "$only_tests" == 'true' ]]; then
    ((${#feature_targets[@]} > 0)) || emit_full
    emit_targeted "${feature_targets[@]}"
fi

# The only product-code shape that is safe to narrow without a maintained
# semantic dependency graph is an entirely additive new module. Existing
# modules, modified migrations, global runtime/configuration and unknown paths
# deliberately fall back to the complete Feature suite.
declare -A modules=()
feature_targets=()
saw_product_change=false

for entry in "${changes[@]}"; do
    IFS=$'\t' read -r status path extra <<< "$entry"
    [[ -n "$status" && -n "$path" && -z "${extra:-}" ]] || emit_full

    case "$path" in
        app/Modules/*/*.php|app/Modules/*/*/*.php|app/Modules/*/*/*/*.php|app/Modules/*/*/*/*/*.php)
            [[ "$status" == 'A' ]] || emit_full
            module=${path#app/Modules/}
            module=${module%%/*}
            [[ -n "$module" ]] || emit_full
            modules["$module"]=1
            saw_product_change=true
            ;;
        database/migrations/*.php)
            [[ "$status" == 'A' ]] || emit_full
            saw_product_change=true
            ;;
        database/seeders/DatabaseSeeder.php)
            [[ "$status" == 'M' ]] || emit_full
            saw_product_change=true
            ;;
        database/seeders/*.php)
            [[ "$status" == 'A' ]] || emit_full
            saw_product_change=true
            ;;
        scripts/ci/architecture-boundaries.php)
            [[ "$status" == 'M' ]] || emit_full
            ;;
        tests/Feature/*.php)
            [[ "$status" == 'A' || "$status" == 'M' ]] || emit_full
            feature_targets+=("$path")
            ;;
        tests/Unit/*.php)
            [[ "$status" == 'A' || "$status" == 'M' ]] || emit_full
            ;;
        *)
            emit_full
            ;;
    esac
done

[[ "$saw_product_change" == 'true' ]] || emit_full
((${#modules[@]} == 1)) || emit_full
((${#feature_targets[@]} > 0)) || emit_full

module=${!modules[@]}

# A pre-existing module can have dependencies that are not inferable from file
# paths alone. Only a module absent from the base tree qualifies for this
# conservative additive selector.
if git cat-file -e "$base:app/Modules/$module" 2>/dev/null; then
    emit_full
fi

# DatabaseSeeder may only gain references belonging to the new module. Any
# deletion or unrelated addition makes the selection ambiguous and therefore
# FULL. Comments/blank lines are ignored.
if git diff --name-only "$base" "$head" -- database/seeders/DatabaseSeeder.php | grep -q .; then
    while IFS= read -r line; do
        case "$line" in
            '+++'*|'---'*|'@@'*) continue ;;
            -*) emit_full ;;
            +*)
                added=${line#+}
                [[ -z "${added//[[:space:]]/}" || "$added" == *"$module"* ]] || emit_full
                ;;
        esac
    done < <(git diff --unified=0 "$base" "$head" -- database/seeders/DatabaseSeeder.php)
fi

# Every selected Feature test must explicitly reference the new module. This is
# intentionally stricter than filename matching and fails closed when the
# relationship is not directly evidenced by the candidate itself.
module_reference="App\\\\Modules\\\\${module}\\\\"
for target in "${feature_targets[@]}"; do
    git show "$head:$target" | grep -F "$module_reference" >/dev/null || emit_full
done

emit_targeted "${feature_targets[@]}"
