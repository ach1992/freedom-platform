#!/usr/bin/env bash

set -euo pipefail

if [[ -e app/Modules/README.md ]]; then
    echo 'Duplicate module architecture authority is not allowed: app/Modules/README.md; use docs/05-architecture-overview.md.' >&2
    exit 1
fi

php scripts/ci/run-architecture-check.php
