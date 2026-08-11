#!/usr/bin/env bash

set -euo pipefail

required=(
  docs/specification/master-execution-prompt.md
  docs/01-authoritative-requirements.md
  docs/03-risk-register.md
  docs/04-domain-glossary.md
  docs/05-architecture-overview.md
  docs/06-test-strategy.md
  docs/07-security-threat-model.md
  docs/08-data-classification.md
  docs/09-deployment-runbook.md
)

for path in "${required[@]}"; do
  test -s "${path}" || { echo "Missing or empty canonical planning artifact: ${path}" >&2; exit 1; }

  fence_count="$(grep -c '^```' "${path}" || true)"
  if (( fence_count % 2 != 0 )); then
    echo "Unbalanced Markdown code fences: ${path}" >&2
    exit 1
  fi
done

temporary="$(mktemp -d)"
trap 'rm -rf -- "${temporary}"' EXIT

sed -n '/# 36\. Functional Requirement/,/# 37\. Definition of Done/p' \
  docs/specification/master-execution-prompt.md \
  | grep -oE '`[A-Z][A-Z0-9]+-[0-9]{3}`' \
  | tr -d '`' \
  | sort -u > "${temporary}/canonical"

grep -oE '`[A-Z][A-Z0-9]+-[0-9]{3}`' docs/01-authoritative-requirements.md \
  | tr -d '`' \
  | sort -u > "${temporary}/candidate"

comm -23 "${temporary}/canonical" "${temporary}/candidate" > "${temporary}/missing"
if [[ -s "${temporary}/missing" ]]; then
  echo 'Canonical functional requirement IDs are missing from docs/01-authoritative-requirements.md.' >&2
  cat "${temporary}/missing" >&2
  exit 1
fi

mkdir -p build/evidence/planning
{
  echo '# Planning verification'
  echo
  echo "- Canonical functional requirement IDs: $(wc -l < "${temporary}/canonical")"
  echo "- Canonical planning documents: ${#required[@]}"
  echo '- Missing canonical IDs: 0'
  echo '- Unbalanced code fences: 0'
} > build/evidence/planning/summary.md

cat build/evidence/planning/summary.md
