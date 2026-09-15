#!/usr/bin/env bash

set -euo pipefail

workflow=${1:-.github/workflows/staging-readiness.yml}

fail() {
    echo "Read-only staging workflow verification failed: $*" >&2
    exit 1
}

selector_has_label() {
    local selector=$1
    local required=$2
    local body label
    local -a labels

    body=${selector#runs-on: }
    body=${body#\[}
    body=${body%\]}
    IFS=',' read -r -a labels <<< "$body"

    for label in "${labels[@]}"; do
        label=${label#"${label%%[![:space:]]*}"}
        label=${label%"${label##*[![:space:]]}"}
        [[ "$label" == "$required" ]] && return 0
    done

    return 1
}

test -s "$workflow" || fail "workflow is missing or empty: $workflow"

grep -F 'workflow_dispatch:' "$workflow" >/dev/null \
    || fail 'workflow must remain manually dispatched'
grep -F 'READ_ONLY_STAGING_CHECK' "$workflow" >/dev/null \
    || fail 'workflow must retain the explicit read-only confirmation sentinel'
grep -A4 -F 'permissions:' "$workflow" | grep -F 'contents: read' >/dev/null \
    || fail 'workflow must retain explicit read-only repository permissions'

runner_lines=$(grep -E '^[[:space:]]*runs-on:' "$workflow" || true)
[[ -n "$runner_lines" ]] || fail 'workflow must retain an explicit runner selector'
while IFS= read -r runner_line; do
    trimmed=${runner_line#"${runner_line%%[![:space:]]*}"}
    [[ "$trimmed" == runs-on:\ \[*\] ]] \
        || fail "workflow runner selector must remain an explicit label list: $trimmed"
    for required_label in self-hosted Linux X64; do
        selector_has_label "$trimmed" "$required_label" \
            || fail "workflow runner selector must retain exact label $required_label: $trimmed"
    done
done <<< "$runner_lines"

if grep -Eq '^[[:space:]]*(push|pull_request|pull_request_target|schedule|workflow_run):' "$workflow"; then
    fail 'workflow may not gain an automatic execution trigger while classified as read-only control-plane'
fi

if grep -Eq '^[[:space:]]+[A-Za-z0-9_-]+:[[:space:]]+write([[:space:]]|$)' "$workflow"; then
    fail 'workflow may not request write token permissions while classified as read-only control-plane'
fi

if grep -Eq '(^|[^A-Za-z0-9_])secrets\.' "$workflow"; then
    fail 'workflow may not consume GitHub secrets while classified as read-only control-plane'
fi

if grep -Eq '^[[:space:]]*environment:' "$workflow"; then
    fail 'workflow may not bind a protected deployment environment while classified as read-only control-plane'
fi

while IFS= read -r uses_line; do
    trimmed=${uses_line#"${uses_line%%[![:space:]]*}"}
    trimmed=${trimmed#- }
    case "$trimmed" in
        uses:\ actions/upload-artifact@*) ;;
        *) fail "workflow action is outside the read-only allowlist: $trimmed" ;;
    esac
done < <(grep -E '^[[:space:]]*(-[[:space:]]+)?uses:' "$workflow" || true)

if grep -Eq '(^|[[:space:]])(sudo|curl|wget|ssh|scp|rsync|shutdown|reboot|mount|umount|kill|pkill|git[[:space:]]+push|php[[:space:]]+artisan|composer[[:space:]]+(install|update)|apt(-get)?|dnf|yum)([[:space:]]|$)' "$workflow"; then
    fail 'workflow contains a command outside the bounded read-only host-readiness contract'
fi

if grep -Eq 'docker[[:space:]]+compose[[:space:]]+(up|down|run|exec|start|stop|restart|pull|build)([[:space:]]|$)' "$workflow"; then
    fail 'workflow may not mutate Docker/runtime state while classified as read-only control-plane'
fi

while IFS= read -r systemctl_line; do
    [[ "$systemctl_line" == *'systemctl is-active '* ]] \
        || fail "workflow may only use systemctl is-active: $systemctl_line"
done < <(grep -F 'systemctl ' "$workflow" || true)

printf '%s\n' 'Read-only staging workflow verification passed.'
