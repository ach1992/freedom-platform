#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

CLI=/www/server/php/84/bin/php
LSPHP=/usr/local/lsws/lsphp84/bin/lsphp
WORK=/root/freedom-bootstrap/php-functions
PROBE="$WORK/probe.php"
COMMON_FUNCTIONS=(putenv proc_open proc_close proc_get_status proc_terminate)
CLI_FUNCTIONS=("${COMMON_FUNCTIONS[@]}" symlink)
LSPHP_FUNCTIONS=("${COMMON_FUNCTIONS[@]}")

exec 9>/root/freedom-bootstrap/php-functions.lock
flock -w 300 9 || {
    echo 'PHP function configuration lock timeout.' >&2
    exit 75
}

test -x "$CLI"
test -x "$LSPHP"
install -d -m 700 "$WORK"

cat > "$PROBE" <<'PHP'
<?php

declare(strict_types=1);

$functions = array_slice($argv, 1);
$missing = array_values(array_filter(
    $functions,
    static fn (string $function): bool => ! function_exists($function),
));

echo 'ini=' . (php_ini_loaded_file() ?: 'none') . PHP_EOL;
echo 'version=' . PHP_VERSION . PHP_EOL;
echo 'sapi=' . PHP_SAPI . PHP_EOL;
echo 'missing=' . ($missing === [] ? 'none' : implode(',', $missing)) . PHP_EOL;

exit($missing === [] ? 0 : 2);
PHP
chmod 600 "$PROBE"

CLI_INI=$($CLI -r 'echo php_ini_loaded_file();')
LSPHP_OUTPUT=$($LSPHP "$PROBE" "${LSPHP_FUNCTIONS[@]}" 2>&1 || true)
LSPHP_INI=$(printf '%s\n' "$LSPHP_OUTPUT" | awk -F= '$1=="ini" {print $2}')

test -f "$CLI_INI"
test -f "$LSPHP_INI"

update_disable_functions() {
    local ini_path=$1
    shift

    python3 - "$ini_path" "$@" <<'PY'
from pathlib import Path
import os
import re
import shutil
import sys

path = Path(sys.argv[1])
required = set(sys.argv[2:])
backup = path.with_name(path.name + '.freedom-before-process-functions')

if not backup.exists():
    shutil.copy2(path, backup)

original = path.read_text()
output = []
changed = False

for line in original.splitlines():
    match = re.match(r'^(\s*disable_functions\s*=\s*)(.*)$', line, re.IGNORECASE)
    if not match:
        output.append(line)
        continue

    functions = [item.strip() for item in match.group(2).split(',') if item.strip()]
    filtered = [item for item in functions if item not in required]
    replacement = match.group(1) + ','.join(filtered)
    output.append(replacement)
    changed = changed or replacement != line

if changed:
    temporary = path.with_name(path.name + '.freedom-new')
    temporary.write_text('\n'.join(output) + '\n')
    os.chmod(temporary, path.stat().st_mode & 0o777)
    os.chown(temporary, path.stat().st_uid, path.stat().st_gid)
    temporary.replace(path)
PY
}

update_disable_functions "$CLI_INI" "${CLI_FUNCTIONS[@]}"
update_disable_functions "$LSPHP_INI" "${LSPHP_FUNCTIONS[@]}"

/usr/local/lsws/bin/lswsctrl restart >/dev/null
sleep 3

set +e
CLI_OUTPUT=$($CLI "$PROBE" "${CLI_FUNCTIONS[@]}" 2>&1)
CLI_STATUS=$?
LSPHP_OUTPUT=$($LSPHP "$PROBE" "${LSPHP_FUNCTIONS[@]}" 2>&1)
LSPHP_STATUS=$?
set -e

printf '%s\n' "$CLI_OUTPUT" | grep -qx 'missing=none'
printf '%s\n' "$LSPHP_OUTPUT" | grep -qx 'missing=none'
test "$CLI_STATUS" -eq 0
test "$LSPHP_STATUS" -eq 0

echo '# PHP function readiness evidence'
echo "utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf '%s\n' "$CLI_OUTPUT" | grep -E '^(version|sapi|missing)='
printf '%s\n' "$LSPHP_OUTPUT" | grep -E '^(version|sapi|missing)='
echo "cli_symlink=$($CLI -r 'echo function_exists("symlink") ? "present" : "absent";')"
echo "lsphp_symlink=$($LSPHP "$PROBE" symlink 2>/dev/null | awk -F= '$1=="missing" {print $2=="none" ? "present" : "absent"}')"
echo "ols_processes=$(pgrep -fc 'lshttpd|litespeed' || true)"

rm -f "$PROBE"
