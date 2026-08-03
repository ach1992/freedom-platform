#!/usr/bin/env bash

set -euo pipefail

mkdir -p build/evidence/static
report="build/evidence/static/architecture.txt"
: > "${report}"

status=0

scan_forbidden_import() {
    local label="$1"
    local pattern="$2"
    local path="$3"
    local rg_status

    set +e
    rg --line-number "${pattern}" "${path}" >> "${report}"
    rg_status=$?
    set -e

    if [[ "${rg_status}" -eq 0 ]]; then
        printf 'Architecture violation: %s\n' "${label}" >&2
        status=1
    elif [[ "${rg_status}" -gt 1 ]]; then
        printf 'Architecture scanner failed: %s\n' "${label}" >&2
        exit 2
    fi
}

if [[ -d app/Modules ]]; then
    while IFS= read -r domain_path; do
        scan_forbidden_import 'Domain imports Laravel/framework infrastructure' '^use (Illuminate|Symfony|Monolog)\\' "${domain_path}"
        scan_forbidden_import 'Domain imports Infrastructure layer' '^use App\\.*\\Infrastructure\\' "${domain_path}"
    done < <(find app/Modules -type d -name Domain -print | sort)
fi

if [[ -d app/Shared/Domain ]]; then
    scan_forbidden_import 'Shared Domain imports framework infrastructure' '^use (Illuminate|Symfony|Monolog)\\' app/Shared/Domain
fi

if [[ "${status}" -ne 0 ]]; then
    sed -n '1,240p' "${report}" >&2
    exit "${status}"
fi

printf 'No architecture dependency violations detected.\n' | tee "${report}"
