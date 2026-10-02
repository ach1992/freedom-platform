#!/usr/bin/env bash

set -euo pipefail

if (($# == 0)); then
    set -- .github/workflows
fi

python3 - "$@" <<'PY_RETIRED_ACTIONS_SECRETS'
from pathlib import Path
import re
import sys

property_reference = re.compile(
    r"secrets\s*\.\s*(?:STAGING_|PASARGUARD_TEST_|MARZBAN_TEST_)[A-Z0-9_]*",
    re.IGNORECASE,
)
index_reference = re.compile(
    r"""secrets\s*\[\s*(['"])\s*(?:STAGING_|PASARGUARD_TEST_|MARZBAN_TEST_)[A-Z0-9_]*\s*\1\s*\]""",
    re.IGNORECASE,
)

def workflow_files(target: Path):
    if target.is_file():
        yield target
        return
    if not target.exists():
        return
    for path in sorted(target.rglob("*")):
        if path.is_file() and path.suffix.lower() in {".yml", ".yaml"}:
            yield path

for raw_target in sys.argv[1:]:
    for path in workflow_files(Path(raw_target)):
        source = path.read_text(encoding="utf-8", errors="replace")
        if property_reference.search(source) or index_reference.search(source):
            print(
                f"Retired staging/provider Actions secret reference found: {path}",
                file=sys.stderr,
            )
            raise SystemExit(1)
PY_RETIRED_ACTIONS_SECRETS
