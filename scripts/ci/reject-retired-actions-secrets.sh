#!/usr/bin/env bash

set -euo pipefail

if (($# == 0)); then
    set -- .github/workflows
fi

python3 - "$@" <<'PY_RETIRED_ACTIONS_SECRETS'
from pathlib import Path
import re
import sys

try:
    import yaml
except ImportError:
    print(
        "PyYAML is required for semantic GitHub Actions secret-policy validation.",
        file=sys.stderr,
    )
    raise SystemExit(2)

RETIRED_PREFIXES = ("STAGING_", "PASARGUARD_TEST_", "MARZBAN_TEST_")
secret_token = re.compile(r"\bsecrets\b", re.IGNORECASE)
property_access = re.compile(r"\s*\.\s*([A-Z_][A-Z0-9_]*)", re.IGNORECASE)
literal_index_access = re.compile(
    r"""\s*\[\s*(['"])\s*([A-Z_][A-Z0-9_]*)\s*\1\s*\]""",
    re.IGNORECASE,
)
expression = re.compile(r"\$\{\{(.*?)\}\}", re.DOTALL)

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

def validate_semantic_secret_forwarding(path: Path, source: str) -> None:
    try:
        document = yaml.safe_load(source)
    except yaml.YAMLError as exc:
        print(f"Workflow YAML cannot be parsed safely: {path}: {exc}", file=sys.stderr)
        raise SystemExit(1)

    if document is None:
        return
    if not isinstance(document, dict):
        fail(path, "workflow root is not a mapping")

    jobs = document.get("jobs")
    if jobs is None:
        return
    if not isinstance(jobs, dict):
        fail(path, "jobs is not a mapping")

    for job_id, job in jobs.items():
        if not isinstance(job, dict):
            fail(path, f"job {job_id!r} is not a mapping")
        if "secrets" not in job:
            continue

        forwarded = job["secrets"]

        # GitHub's reusable-workflow bulk form resolves to a scalar "inherit".
        # YAML anchors, aliases, tags, quoted scalars, folded scalars, and merge
        # keys are intentionally handled by safe_load before this check, so
        # alternate YAML spellings cannot bypass the policy.
        if isinstance(forwarded, str) and forwarded.strip().lower() == "inherit":
            fail(path, "bulk reusable-workflow secret inheritance")

        # A standing workflow may forward an explicit mapping of individually
        # named values. Any other resolved shape is opaque/unsupported here and
        # fails closed rather than guessing whether retired secrets are excluded.
        if not isinstance(forwarded, dict):
            fail(path, "opaque reusable-workflow secrets forwarding")

for raw_target in sys.argv[1:]:
    for path in workflow_files(Path(raw_target)):
        source = path.read_text(encoding="utf-8", errors="replace")

        # First resolve YAML semantics for jobs.*.secrets. This closes anchor,
        # alias, tag, quoted/folded scalar, and merge-key representations of
        # reusable-workflow bulk inheritance.
        validate_semantic_secret_forwarding(path, source)

        # Then fail closed on ambiguous GitHub expression access to the secrets
        # context, while allowing explicitly named non-retired static secrets.
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
