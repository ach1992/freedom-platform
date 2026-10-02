#!/usr/bin/env bash

set -euo pipefail

if (($# == 0)); then
    set -- .github/workflows
fi

python3 - "$@" <<'PY_RETIRED_ACTIONS_SECRETS'
from pathlib import Path
import re
import sys

RETIRED_PREFIXES = ("STAGING_", "PASARGUARD_TEST_", "MARZBAN_TEST_")
secret_token = re.compile(r"\bsecrets\b", re.IGNORECASE)
property_access = re.compile(r"\s*\.\s*([A-Z_][A-Z0-9_]*)", re.IGNORECASE)
literal_index_access = re.compile(
    r"""\s*\[\s*(['"])\s*([A-Z_][A-Z0-9_]*)\s*\1\s*\]""",
    re.IGNORECASE,
)
expression = re.compile(r"\$\{\{(.*?)\}\}", re.DOTALL)
inherit_secrets = re.compile(r"(?mi)^\s*secrets\s*:\s*inherit\s*(?:#.*)?$")

def workflow_files(target: Path):
    if target.is_file():
        yield target
        return
    if not target.exists():
        return
    for path in sorted(target.rglob("*")):
        if path.is_file() and path.suffix.lower() in {".yml", ".yaml"}:
            yield path

def is_retired(name: str) -> bool:
    normalized = name.upper()
    return any(normalized.startswith(prefix) for prefix in RETIRED_PREFIXES)

def fail(path: Path, reason: str) -> None:
    print(f"Unsafe GitHub Actions secrets access found: {path}: {reason}", file=sys.stderr)
    raise SystemExit(1)

for raw_target in sys.argv[1:]:
    for path in workflow_files(Path(raw_target)):
        source = path.read_text(encoding="utf-8", errors="replace")

        # Reusable-workflow bulk forwarding cannot prove that retired interfaces
        # are excluded, so standing workflows must fail closed on it.
        if inherit_secrets.search(source):
            fail(path, "bulk secrets inheritance")

        for match in expression.finditer(source):
            body = match.group(1)
            for token in secret_token.finditer(body):
                tail = body[token.end():]

                prop = property_access.match(tail)
                if prop is not None:
                    if is_retired(prop.group(1)):
                        fail(path, "retired static secret reference")
                    continue

                literal = literal_index_access.match(tail)
                if literal is not None:
                    if is_retired(literal.group(2)):
                        fail(path, "retired static secret reference")
                    continue

                # Any computed index or whole-context use is rejected because
                # static analysis cannot prove the resolved set excludes the
                # retired staging/provider interfaces.
                fail(path, "dynamic or bulk secrets-context access")
PY_RETIRED_ACTIONS_SECRETS
