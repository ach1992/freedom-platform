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


class ActionsLoader(yaml.SafeLoader):
    """SafeLoader adjusted to GitHub Actions' YAML 1.2 boolean semantics."""


# PyYAML's SafeLoader uses YAML 1.1 booleans, where plain yes/no/on/off are
# booleans. GitHub Actions uses YAML 1.2 core behavior, where only true/false
# variants are booleans. Copy before editing so this policy does not mutate the
# process-global SafeLoader resolver table.
ActionsLoader.yaml_implicit_resolvers = {
    first: list(resolvers)
    for first, resolvers in yaml.SafeLoader.yaml_implicit_resolvers.items()
}
for first, resolvers in list(ActionsLoader.yaml_implicit_resolvers.items()):
    ActionsLoader.yaml_implicit_resolvers[first] = [
        (tag, pattern)
        for tag, pattern in resolvers
        if tag != "tag:yaml.org,2002:bool"
    ]
ActionsLoader.add_implicit_resolver(
    "tag:yaml.org,2002:bool",
    re.compile(r"^(?:true|True|TRUE|false|False|FALSE)$"),
    list("tTfF"),
)


def construct_mapping_without_collisions(loader, node, deep=False):
    if not isinstance(node, yaml.nodes.MappingNode):
        raise yaml.constructor.ConstructorError(
            None,
            None,
            f"expected a mapping node, but found {node.id}",
            node.start_mark,
        )

    # Resolve YAML merge keys before constructing the mapping so aliases and
    # merges are inspected in the same semantic object. Fail closed instead of
    # allowing Python dict assignment to erase an earlier duplicate/colliding
    # key that GitHub Actions may still treat as a distinct workflow key.
    loader.flatten_mapping(node)
    mapping = {}
    semantic_keys = set()

    for key_node, value_node in node.value:
        key = loader.construct_object(key_node, deep=deep)
        try:
            hash(key)
        except TypeError as exc:
            raise yaml.constructor.ConstructorError(
                "while constructing a mapping",
                node.start_mark,
                "found an unhashable mapping key",
                key_node.start_mark,
            ) from exc

        semantic_key = (type(key), key)
        if semantic_key in semantic_keys:
            raise yaml.constructor.ConstructorError(
                "while constructing a mapping",
                node.start_mark,
                f"found duplicate mapping key {key!r}",
                key_node.start_mark,
            )
        if key in mapping:
            raise yaml.constructor.ConstructorError(
                "while constructing a mapping",
                node.start_mark,
                f"found colliding mapping key {key!r}",
                key_node.start_mark,
            )

        semantic_keys.add(semantic_key)
        mapping[key] = loader.construct_object(value_node, deep=deep)

    return mapping


ActionsLoader.construct_mapping = construct_mapping_without_collisions

secret_token = re.compile(r"\bsecrets\b", re.IGNORECASE)
secret_identifier = re.compile(r"^[A-Z_][A-Z0-9_]*$", re.IGNORECASE)
property_access = re.compile(r"\s*\.\s*([A-Z_][A-Z0-9_]*)", re.IGNORECASE)
literal_index_access = re.compile(
    r"""\s*\[\s*(['"])\s*([A-Z_][A-Z0-9_]*)\s*\1\s*\]""",
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

def is_retired(name: str) -> bool:
    normalized = name.upper()
    return any(normalized.startswith(prefix) for prefix in RETIRED_PREFIXES)

def fail(path: Path, reason: str) -> None:
    print(f"Unsafe GitHub Actions secrets access found: {path}: {reason}", file=sys.stderr)
    raise SystemExit(1)

def expression_bodies(path: Path, source: str):
    """Yield GitHub expression bodies without closing on }} inside string literals."""
    cursor = 0
    while True:
        start = source.find("${{", cursor)
        if start < 0:
            return

        index = start + 3
        body_start = index
        in_string = False

        while index < len(source):
            char = source[index]

            if in_string:
                if char == "'":
                    # GitHub expression strings escape a single quote by doubling it.
                    if index + 1 < len(source) and source[index + 1] == "'":
                        index += 2
                        continue
                    in_string = False
                index += 1
                continue

            if char == "'":
                in_string = True
                index += 1
                continue

            if source.startswith("}}", index):
                yield source[body_start:index]
                cursor = index + 2
                break

            index += 1
        else:
            fail(path, "unterminated GitHub expression")

def mask_expression_strings(body: str) -> str:
    """Mask single-quoted expression literals while preserving character offsets."""
    masked = list(body)
    index = 0
    in_string = False

    while index < len(body):
        char = body[index]

        if not in_string:
            if char == "'":
                in_string = True
                masked[index] = " "
            index += 1
            continue

        masked[index] = " "
        if char == "'":
            if index + 1 < len(body) and body[index + 1] == "'":
                masked[index + 1] = " "
                index += 2
                continue
            in_string = False
        index += 1

    return "".join(masked)

def validate_semantic_secret_forwarding(path: Path, source: str) -> None:
    try:
        document = yaml.load(source, Loader=ActionsLoader)
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
        # keys are intentionally resolved by ActionsLoader before this check, so
        # alternate YAML spellings cannot bypass the policy.
        if isinstance(forwarded, str) and forwarded.strip().lower() == "inherit":
            fail(path, "bulk reusable-workflow secret inheritance")

        # A standing workflow may forward an explicit mapping of individually
        # named values. Any other resolved shape is opaque/unsupported here and
        # fails closed rather than guessing whether retired secrets are excluded.
        if not isinstance(forwarded, dict):
            fail(path, "opaque reusable-workflow secrets forwarding")

        # Explicit reusable-workflow forwarding still defines destination secret
        # identifiers. Retired interfaces must not be recreated under an allowed
        # backing repository secret, and unsupported/non-string identifiers fail
        # closed rather than being interpreted differently by another parser.
        for secret_id in forwarded:
            if not isinstance(secret_id, str) or secret_identifier.fullmatch(secret_id) is None:
                fail(path, "unsupported reusable-workflow secret identifier")
            if is_retired(secret_id):
                fail(path, "retired reusable-workflow secret identifier")

for raw_target in sys.argv[1:]:
    for path in workflow_files(Path(raw_target)):
        source = path.read_text(encoding="utf-8", errors="replace")

        # First resolve YAML semantics for jobs.*.secrets. This closes anchor,
        # alias, tag, quoted/folded scalar, and merge-key representations of
        # reusable-workflow bulk inheritance.
        validate_semantic_secret_forwarding(path, source)

        # Then fail closed on ambiguous GitHub expression access to the secrets
        # context, while allowing explicitly named non-retired static secrets.
        # Expression boundaries and secret tokens are parsed with GitHub's
        # single-quoted string semantics so }} and secret-like text inside a
        # string literal cannot truncate or confuse policy inspection.
        for body in expression_bodies(path, source):
            inspectable = mask_expression_strings(body)
            for token in secret_token.finditer(inspectable):
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
