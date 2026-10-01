#!/usr/bin/env bash

set -euo pipefail

workflow=${1:-.github/workflows/staging-readiness-runtime.yml}

fail() {
    echo "Read-only staging workflow verification failed: $*" >&2
    exit 1
}

test -s "$workflow" || fail "workflow is missing or empty: $workflow"

grep -F 'repository_dispatch:' "$workflow" >/dev/null     || fail 'workflow must be sourced from the default branch through repository_dispatch'
grep -F 'types: [staging_readiness]' "$workflow" >/dev/null     || fail 'workflow must accept only the staging_readiness repository-dispatch event type'
if grep -F 'workflow_dispatch:' "$workflow" >/dev/null; then
    fail 'workflow must not expose a branch-selectable workflow_dispatch trigger'
fi
grep -F 'READ_ONLY_STAGING_CHECK' "$workflow" >/dev/null     || fail 'workflow must retain the explicit read-only confirmation sentinel'
grep -A4 -F 'permissions:' "$workflow" | grep -F 'contents: read' >/dev/null     || fail 'workflow must retain explicit read-only repository permissions'

trusted_ref_guard="    if: \${{ github.ref == 'refs/heads/main' && github.event.client_payload.confirmation == 'READ_ONLY_STAGING_CHECK' }}"
grep -Fx "$trusted_ref_guard" "$workflow" >/dev/null     || fail 'staging job must require the exact trusted main ref before scheduling'

grep -Fx '    runs-on: ubuntu-24.04' "$workflow" >/dev/null     || fail 'read-only staging discovery must use the pinned GitHub-hosted Ubuntu runner contract'
if grep -F 'self-hosted' "$workflow" >/dev/null; then
    fail 'read-only staging discovery must not depend on an absent self-hosted runner'
fi

if grep -Eq '^[[:space:]]*(push|pull_request|pull_request_target|schedule|workflow_run):' "$workflow"; then
    fail 'workflow may not gain an automatic execution trigger while classified as read-only control-plane'
fi

if grep -Eq '^[[:space:]]+[A-Za-z0-9_-]+:[[:space:]]+write([[:space:]]|$)' "$workflow"; then
    fail 'workflow may not request write token permissions while classified as read-only control-plane'
fi

if grep -Eq '^[[:space:]]*environment:' "$workflow"; then
    fail 'read-only staging discovery may not bind a privileged deployment environment'
fi

mapfile -t consumed_secrets < <(grep -oE 'secrets\.[A-Z0-9_]+' "$workflow" | sed 's/^secrets\.//' | sort -u)
expected_secrets=(
    STAGING_DOMAIN
    STAGING_HOST
    STAGING_KNOWN_HOSTS
    STAGING_PORT
    STAGING_SSH_PRIVATE_KEY
    STAGING_USER
)
[[ "${consumed_secrets[*]}" == "${expected_secrets[*]}" ]]     || fail "workflow secret allowlist drifted: ${consumed_secrets[*]}"

while IFS= read -r uses_line; do
    trimmed=${uses_line#"${uses_line%%[![:space:]]*}"}
    trimmed=${trimmed#- }
    case "$trimmed" in
        uses:\ actions/upload-artifact@*) ;;
        *) fail "workflow action is outside the read-only allowlist: $trimmed" ;;
    esac
done < <(grep -E '^[[:space:]]*(-[[:space:]]+)?uses:' "$workflow" || true)

grep -F 'StrictHostKeyChecking=yes' "$workflow" >/dev/null     || fail 'SSH must retain strict host-key verification'
grep -F 'UserKnownHostsFile="$HOME/.ssh/known_hosts"' "$workflow" >/dev/null     || fail 'SSH must use the pinned known-hosts file'
grep -F 'BatchMode=yes' "$workflow" >/dev/null     || fail 'SSH must remain non-interactive'
grep -F 'IdentitiesOnly=yes' "$workflow" >/dev/null     || fail 'SSH must use only the staged private key'
grep -F "\"DOMAIN='\$STAGING_DOMAIN' bash -s\"" "$workflow" >/dev/null || fail 'remote execution must remain the fixed heredoc probe with only the validated staging domain input'
grep -F "<<'REMOTE'" "$workflow" >/dev/null || fail 'remote probe must remain a literal heredoc rather than caller-provided shell'

if grep -Eq 'github\.event\.client_payload\.(command|script|host|user|port|domain)' "$workflow"; then
    fail 'repository-dispatch payload may not inject remote target or command material'
fi

if grep -Eq '(^|[[:space:]])(sudo|scp|rsync|shutdown|reboot|mount|umount|kill|pkill|git[[:space:]]+push|composer[[:space:]]+(install|update)|apt(-get)?|dnf|yum)([[:space:]]|$)' "$workflow"; then
    fail 'workflow contains a mutating command outside the bounded read-only readiness contract'
fi

if grep -Eq 'docker[[:space:]]+compose[[:space:]]+(up|down|run|exec|start|stop|restart|pull|build)([[:space:]]|$)' "$workflow"; then
    fail 'workflow may not mutate Docker/runtime state while classified as read-only control-plane'
fi

if grep -Eq 'artisan[[:space:]]+(migrate|down|up|queue:restart|operations:(backup|restore|update)|telegram:webhook:configure)([[:space:]]|$)' "$workflow"; then
    fail 'workflow may not invoke a mutating Artisan command'
fi

grep -F 'artisan health:check --critical --json --redact' "$workflow" >/dev/null     || fail 'workflow must use the existing redacted critical health contract when an installed release is present'

while IFS= read -r systemctl_line; do
    [[ "$systemctl_line" == *'systemctl is-active '* ]]         || fail "workflow may only use systemctl is-active: $systemctl_line"
done < <(grep -F 'systemctl ' "$workflow" || true)

while IFS= read -r supervisor_line; do
    [[ "$supervisor_line" == *"supervisorctl status 'freedom-platform-workers:*'"* ]]         || fail "workflow may only use the bounded Supervisor status query: $supervisor_line"
done < <(grep -F 'supervisorctl ' "$workflow" || true)

grep -F 'rm -rf "$HOME/.ssh"' "$workflow" >/dev/null     || fail 'workflow must erase local SSH key material in an always() cleanup step'
grep -F 'if: always()' "$workflow" >/dev/null     || fail 'workflow must retain unconditional cleanup/evidence finalization'

if grep -F 'hostname=' "$workflow" >/dev/null; then
    fail 'workflow must not emit the raw remote hostname'
fi
grep -F 'hostname_sha256=' "$workflow" >/dev/null     || fail 'workflow should retain a non-secret stable host fingerprint for readiness comparisons'

printf '%s\n' 'Read-only staging workflow verification passed.'
