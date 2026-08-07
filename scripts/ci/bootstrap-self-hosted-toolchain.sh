#!/usr/bin/env bash

set -euo pipefail

mode="${1:-}"

case "$mode" in
    coverage)
        pcov_enabled=1
        ;;
    no-coverage)
        pcov_enabled=0
        ;;
    *)
        echo "Usage: $0 <coverage|no-coverage>" >&2
        exit 64
        ;;
esac

php_root="${PHP_ROOT:-/www/server/php/84}"
composer_bin="${COMPOSER_BIN:-/usr/local/bin/composer}"
php_bin="$php_root/bin/php"

: "${RUNNER_TEMP:?RUNNER_TEMP is required}"
: "${GITHUB_JOB:?GITHUB_JOB is required}"
: "${GITHUB_PATH:?GITHUB_PATH is required}"

test -x "$php_bin" || {
    echo "Required PHP CLI binary is unavailable: $php_bin" >&2
    exit 1
}

test -f "$composer_bin" || {
    echo "Required Composer binary is unavailable: $composer_bin" >&2
    exit 1
}

tool_bin="$RUNNER_TEMP/freedom-ci-toolchain-$GITHUB_JOB"
rm -rf "$tool_bin"
mkdir -p "$tool_bin"

cat > "$tool_bin/php" <<EOF
#!/usr/bin/env bash
exec "$php_bin" \
    -d opcache.jit=0 \
    -d opcache.jit_buffer_size=0 \
    -d pcov.enabled=$pcov_enabled \
    "\$@"
EOF

cat > "$tool_bin/composer" <<EOF
#!/usr/bin/env bash
exec "$tool_bin/php" "$composer_bin" "\$@"
EOF

chmod 0755 "$tool_bin/php" "$tool_bin/composer"
echo "$tool_bin" >> "$GITHUB_PATH"

startup_stderr="$RUNNER_TEMP/php-startup-$GITHUB_JOB.stderr"
rm -f "$startup_stderr"

required_extensions=(
    bcmath
    ctype
    curl
    dom
    fileinfo
    filter
    hash
    intl
    json
    libxml
    mbstring
    openssl
    pcntl
    pdo
    pdo_mysql
    redis
    session
    sodium
    tokenizer
    xml
    xmlwriter
)

if [[ "$mode" == "coverage" ]]; then
    required_extensions+=(pcov)
fi

required_csv="$(IFS=,; echo "${required_extensions[*]}")"

if ! php_summary="$(
    FREEDOM_REQUIRED_EXTENSIONS="$required_csv" \
    FREEDOM_EXPECTED_PCOV="$pcov_enabled" \
    "$tool_bin/php" -r '
        $required = array_values(array_filter(explode(",", (string) getenv("FREEDOM_REQUIRED_EXTENSIONS"))));

        if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) {
            fwrite(STDERR, "PHP 8.4 is required; found ".PHP_VERSION.PHP_EOL);
            exit(1);
        }

        $missing = array_values(array_filter(
            $required,
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));

        if ($missing !== []) {
            fwrite(STDERR, "Missing PHP extensions: ".implode(", ", $missing).PHP_EOL);
            exit(1);
        }

        if ((int) ini_get("opcache.jit_buffer_size") !== 0) {
            fwrite(STDERR, "PHP JIT must be disabled for the CI wrapper.".PHP_EOL);
            exit(1);
        }

        $expectedPcov = (int) getenv("FREEDOM_EXPECTED_PCOV");
        if (extension_loaded("pcov") && (int) ini_get("pcov.enabled") !== $expectedPcov) {
            fwrite(STDERR, "Unexpected pcov.enabled value.".PHP_EOL);
            exit(1);
        }

        printf(
            "php=%s\npcov_loaded=%s\npcov_enabled=%s\njit_buffer_size=%s\n",
            PHP_VERSION,
            extension_loaded("pcov") ? "yes" : "no",
            extension_loaded("pcov") ? (string) (int) ini_get("pcov.enabled") : "not-loaded",
            (string) ini_get("opcache.jit_buffer_size"),
        );
    ' 2> "$startup_stderr"
)"; then
    cat "$startup_stderr" >&2
    exit 1
fi

if [[ -s "$startup_stderr" ]]; then
    echo "PHP emitted unexpected startup diagnostics:" >&2
    cat "$startup_stderr" >&2
    exit 1
fi

printf '%s\n' "$php_summary"

composer_version="$("$tool_bin/composer" --version --no-ansi)"
printf '%s\n' "$composer_version"

grep -F 'Composer version 2.10.2 ' <<< "$composer_version" >/dev/null || {
    echo "Composer 2.10.2 is required." >&2
    exit 1
}
