#!/usr/bin/env bash

set -euo pipefail

mkdir -p build/evidence/static
report="build/evidence/static/forbidden-patterns.txt"
: > "${report}"

fail_if_found() {
    local label="$1"
    local pattern="$2"
    shift 2

    set +e
    rg --line-number --hidden \
        --glob '!vendor/**' \
        --glob '!build/**' \
        --glob '!docs/specification/**' \
        --glob '!scripts/ci/forbidden-patterns.sh' \
        "${pattern}" "$@" >> "${report}"
    local rg_status=$?
    set -e

    if [[ "${rg_status}" -eq 0 ]]; then
        printf 'Forbidden pattern detected: %s\n' "${label}" >&2
        return 1
    fi

    if [[ "${rg_status}" -gt 1 ]]; then
        printf 'Pattern scanner failed while checking: %s\n' "${label}" >&2
        return 2
    fi

    return 0
}

status=0
fail_if_found 'disabled TLS verification' "['\"]verify['\"]\s*=>\s*false|Http::withoutVerifying\s*\(|CURLOPT_SSL_VERIFYPEER\s*,\s*(false|0)|CURLOPT_SSL_VERIFYHOST\s*,\s*0" . || status=1
fail_if_found 'direct env access outside config' '\benv\s*\(' app bootstrap database routes tests || status=1
fail_if_found 'debug dump' '\b(dd|dump|var_dump)\s*\(' app bootstrap database routes || status=1
fail_if_found 'monetary float declaration or cast' '(float\s+\$.*(amount|price|balance|money|total|fee|rate|discount)|\$(amount|price|balance|money|total|fee|rate|discount)\s*:\s*float|\b(floatval|doubleval)\s*\(|\(float\)\s*\$)' app database || status=1
fail_if_found 'shell execution in application code' '\b(exec|shell_exec|passthru|system|proc_open)\s*\(' app || status=1
fail_if_found 'raw SQL string concatenation' '(whereRaw|selectRaw|statement|unprepared)\s*\([^\n]*(\.\s*\$|\$[^,)]*\.)' app database || status=1

if [[ "${status}" -ne 0 ]]; then
    sed -n '1,240p' "${report}" >&2
    exit "${status}"
fi

printf 'No forbidden patterns detected.\n' | tee "${report}"
