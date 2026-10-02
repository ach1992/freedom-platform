#!/usr/bin/env bash

set -euo pipefail

if (($# == 0)); then
    set -- .github/workflows
fi

python3 - "$@" <<'PY_RETIRED_ACTIONS_SECRETS'
from pathlib import Path
import math
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
    """SafeLoader aligned with GitHub Actions' policy-relevant YAML behavior."""

    MAX_YAML_NODES = 50000

    def __init__(self, stream):
        super().__init__(stream)
        self._policy_yaml_nodes = 0

    def compose_node(self, parent, index):
        # Count every traversal, including aliases, before object construction.
        # GitHub Actions applies the same 50k node ceiling to bound anchor replay.
        self._policy_yaml_nodes += 1
        if self._policy_yaml_nodes > self.MAX_YAML_NODES:
            mark = self.peek_event().start_mark
            raise yaml.composer.ComposerError(
                "while composing a GitHub Actions workflow",
                mark,
                "maximum YAML nodes exceeded",
                mark,
            )
        return super().compose_node(parent, index)


# PyYAML's SafeLoader still carries YAML 1.1 implicit bool/int/float/timestamp
# behavior. GitHub Actions' YamlObjectReader instead applies YAML 1.2 core
# null/bool/number/string rules and does not implicitly create timestamps.
# Copy first so this policy cannot mutate the process-global SafeLoader table.
ActionsLoader.yaml_implicit_resolvers = {
    first: list(resolvers)
    for first, resolvers in yaml.SafeLoader.yaml_implicit_resolvers.items()
}
for first, resolvers in list(ActionsLoader.yaml_implicit_resolvers.items()):
    ActionsLoader.yaml_implicit_resolvers[first] = [
        (tag, pattern)
        for tag, pattern in resolvers
        if tag not in {
            "tag:yaml.org,2002:bool",
            "tag:yaml.org,2002:int",
            "tag:yaml.org,2002:float",
            "tag:yaml.org,2002:timestamp",
        }
    ]

ACTIONS_BOOL = re.compile(r"^(?:true|True|TRUE|false|False|FALSE)$")
ACTIONS_INT = re.compile(r"^(?:[-+]?[0-9]+|0x[0-9a-fA-F]+|0o[0-7]+)$")
ACTIONS_FLOAT = re.compile(
    r"^(?:"
    r"[-+]?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)(?:[eE][-+]?[0-9]+)?"
    r"|[-+]?\.(?:inf|Inf|INF)"
    r"|\.(?:nan|NaN|NAN)"
    r")$"
)

ActionsLoader.add_implicit_resolver(
    "tag:yaml.org,2002:bool",
    ACTIONS_BOOL,
    list("tTfF"),
)
ActionsLoader.add_implicit_resolver(
    "tag:yaml.org,2002:int",
    ACTIONS_INT,
    list("-+0123456789"),
)
ActionsLoader.add_implicit_resolver(
    "tag:yaml.org,2002:float",
    ACTIONS_FLOAT,
    list("-+0123456789."),
)


def construct_actions_bool(loader, node):
    scalar = loader.construct_scalar(node)
    if scalar in {"true", "True", "TRUE"}:
        return True
    if scalar in {"false", "False", "FALSE"}:
        return False
    raise yaml.constructor.ConstructorError(
        "while constructing a GitHub Actions YAML scalar",
        node.start_mark,
        f"invalid bool value {scalar!r}",
        node.start_mark,
    )


def construct_actions_null(loader, node):
    scalar = loader.construct_scalar(node)
    if scalar in {"", "~", "null", "Null", "NULL"}:
        return None
    raise yaml.constructor.ConstructorError(
        "while constructing a GitHub Actions YAML scalar",
        node.start_mark,
        f"invalid null value {scalar!r}",
        node.start_mark,
    )


def reject_actions_timestamp(loader, node):
    scalar = loader.construct_scalar(node)
    raise yaml.constructor.ConstructorError(
        "while constructing a GitHub Actions YAML scalar",
        node.start_mark,
        f"unsupported timestamp tag for value {scalar!r}",
        node.start_mark,
    )


def construct_actions_number(loader, node):
    scalar = loader.construct_scalar(node)

    if node.tag == "tag:yaml.org,2002:int":
        if re.fullmatch(r"0x[0-9a-fA-F]+", scalar):
            return float(int(scalar[2:], 16))
        if re.fullmatch(r"0o[0-7]+", scalar):
            return float(int(scalar[2:], 8))
        if re.fullmatch(r"[-+]?[0-9]+", scalar):
            return float(scalar)
    elif node.tag == "tag:yaml.org,2002:float":
        lowered = scalar.lower()
        if lowered in {".inf", "+.inf"}:
            return math.inf
        if lowered == "-.inf":
            return -math.inf
        if lowered == ".nan":
            return math.nan
        if ACTIONS_FLOAT.fullmatch(scalar):
            return float(scalar)

    raise yaml.constructor.ConstructorError(
        "while constructing a GitHub Actions YAML scalar",
        node.start_mark,
        f"invalid {node.tag.rsplit(':', 1)[-1]} value {scalar!r}",
        node.start_mark,
    )


ActionsLoader.add_constructor("tag:yaml.org,2002:bool", construct_actions_bool)
ActionsLoader.add_constructor("tag:yaml.org,2002:null", construct_actions_null)
ActionsLoader.add_constructor("tag:yaml.org,2002:int", construct_actions_number)
ActionsLoader.add_constructor("tag:yaml.org,2002:float", construct_actions_number)
ActionsLoader.add_constructor("tag:yaml.org,2002:timestamp", reject_actions_timestamp)


def github_scalar_to_string(value):
    """Mirror GitHub TemplateReader scalar-to-string coercion for mapping keys."""
    if isinstance(value, str):
        return value
    if value is None:
        return ""
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, (int, float)):
        number = float(value)
        if math.isnan(number):
            return "NaN"
        if math.isinf(number):
            return "Infinity" if number > 0 else "-Infinity"
        # GitHub NumberToken.ToString() uses G15 with invariant culture.
        return format(number, ".15g")
    return None


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
    normalized_keys = set()

    for key_node, value_node in node.value:
        key = loader.construct_object(key_node, deep=deep)
        normalized = github_scalar_to_string(key)
        if normalized is None:
            raise yaml.constructor.ConstructorError(
                "while constructing a mapping",
                node.start_mark,
                "found a non-scalar mapping key",
                key_node.start_mark,
            )

        folded = normalized.lower()
        if folded in normalized_keys:
            raise yaml.constructor.ConstructorError(
                "while constructing a mapping",
                node.start_mark,
                f"found duplicate mapping key {normalized!r}",
                key_node.start_mark,
            )

        normalized_keys.add(folded)
        mapping[normalized] = loader.construct_object(value_node, deep=deep)

    return mapping


ActionsLoader.construct_mapping = construct_mapping_without_collisions

keyword_token = re.compile(r"[A-Z_][A-Z0-9_-]*", re.IGNORECASE)
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

def expression_bodies(path: Path, scalar_text: str, location: str):
    """Yield GitHub expression bodies from one YAML-decoded scalar string."""
    cursor = 0
    while True:
        start = scalar_text.find("${{", cursor)
        if start < 0:
            return

        index = start + 3
        body_start = index
        in_string = False

        while index < len(scalar_text):
            char = scalar_text[index]

            if in_string:
                if char == "'":
                    # GitHub expression strings escape a single quote by doubling it.
                    if index + 1 < len(scalar_text) and scalar_text[index + 1] == "'":
                        index += 2
                        continue
                    in_string = False
                index += 1
                continue

            if char == "'":
                in_string = True
                index += 1
                continue

            if scalar_text.startswith("}}", index):
                yield scalar_text[body_start:index]
                cursor = index + 2
                break

            index += 1
        else:
            fail(path, f"unterminated GitHub expression at {location}")


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


def mapping_key_label(value) -> str:
    normalized = github_scalar_to_string(value)
    return normalized if normalized is not None else repr(value)


def decoded_scalar_strings(path: Path, value):
    """Walk decoded strings once per container with a GitHub-sized node budget."""
    seen_containers = set()
    nodes = 0

    def walk(current, location):
        nonlocal nodes
        nodes += 1
        if nodes > 50000:
            fail(path, "maximum decoded YAML nodes exceeded")

        if isinstance(current, str):
            yield location, current
            return

        if isinstance(current, dict):
            identity = id(current)
            if identity in seen_containers:
                return
            seen_containers.add(identity)

            for key, child in current.items():
                if isinstance(key, str):
                    yield f"{location}.<key>", key
                yield from walk(
                    child,
                    f"{location}[{mapping_key_label(key)!r}]",
                )
            return

        if isinstance(current, list):
            identity = id(current)
            if identity in seen_containers:
                return
            seen_containers.add(identity)

            for index, child in enumerate(current):
                yield from walk(child, f"{location}[{index}]")

    yield from walk(value, "$")


def parse_workflow(path: Path, source: str):
    try:
        document = yaml.load(source, Loader=ActionsLoader)
    except yaml.YAMLError as exc:
        print(f"Workflow YAML cannot be parsed safely: {path}: {exc}", file=sys.stderr)
        raise SystemExit(1)

    if document is None:
        return {}
    if not isinstance(document, dict):
        fail(path, "workflow root is not a mapping")
    return document


def validate_semantic_secret_forwarding(path: Path, document: dict) -> None:
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

        # GitHub's workflow schema declares these destination keys as
        # non-empty-string. TemplateReader coerces scalar keys to strings before
        # validating that contract, so mirror that behavior and apply only the
        # policy-specific retired-prefix restriction.
        normalized_ids = set()
        for secret_id in forwarded:
            normalized = github_scalar_to_string(secret_id)
            if normalized is None or normalized == "":
                fail(path, "empty or unsupported reusable-workflow secret identifier")

            folded = normalized.lower()
            if folded in normalized_ids:
                fail(path, "duplicate reusable-workflow secret identifier")
            normalized_ids.add(folded)

            if is_retired(normalized):
                fail(path, "retired reusable-workflow secret identifier")


def is_secrets_named_value(body: str, match) -> bool:
    if match.group(0).casefold() != "secrets":
        return False

    before = match.start() - 1
    while before >= 0 and body[before].isspace():
        before -= 1
    if before >= 0 and body[before] == ".":
        return False

    after = match.end()
    while after < len(body) and body[after].isspace():
        after += 1
    if after < len(body) and body[after] == "(":
        return False

    return True


def validate_expression_secret_access(path: Path, document: dict) -> None:
    # GitHub parses YAML first, then scans each decoded string scalar for
    # expressions. Inspect the same semantic layer so YAML quoting, block style,
    # escapes, comments, anchors, and aliases cannot change expression boundaries.
    for location, scalar_text in decoded_scalar_strings(path, document):
        for body in expression_bodies(path, scalar_text, location):
            inspectable = mask_expression_strings(body)
            for token in keyword_token.finditer(inspectable):
                if not is_secrets_named_value(body, token):
                    continue
                tail = body[token.end():]

                prop = property_access.match(tail)
                if prop is not None:
                    if is_retired(prop.group(1)):
                        fail(path, f"retired static secret reference at {location}")
                    continue

                literal = literal_index_access.match(tail)
                if literal is not None:
                    if is_retired(literal.group(2)):
                        fail(path, f"retired static secret reference at {location}")
                    continue

                # Any computed index or whole-context use is rejected because
                # static analysis cannot prove the resolved set excludes the
                # retired staging/provider interfaces.
                fail(path, f"dynamic or bulk secrets-context access at {location}")

for raw_target in sys.argv[1:]:
    for path in workflow_files(Path(raw_target)):
        try:
            source = path.read_text(encoding="utf-8")
        except UnicodeDecodeError as exc:
            print(f"Workflow YAML is not valid UTF-8: {path}: {exc}", file=sys.stderr)
            raise SystemExit(1)

        document = parse_workflow(path, source)
        validate_semantic_secret_forwarding(path, document)
        validate_expression_secret_access(path, document)
PY_RETIRED_ACTIONS_SECRETS
