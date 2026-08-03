#!/usr/bin/env bash

set -euo pipefail

mkdir -p build/evidence/dependencies
composer licenses --format=json --no-interaction > build/evidence/dependencies/licenses.json

if jq -e '.dependencies[] | select((.license | length) == 0)' build/evidence/dependencies/licenses.json >/dev/null; then
    printf 'At least one dependency has no declared license.\n' >&2
    exit 1
fi

allowed='["MIT","BSD-2-Clause","BSD-3-Clause","Apache-2.0","ISC","CC0-1.0"]'

if jq -e --argjson allowed "${allowed}" '
    .dependencies
    | to_entries[]
    | select(any(.value.license[]; (. as $license | $allowed | index($license)) == null))
' build/evidence/dependencies/licenses.json >/dev/null; then
    printf 'At least one dependency uses a license outside the approved SPDX allowlist.\n' >&2
    exit 1
fi
