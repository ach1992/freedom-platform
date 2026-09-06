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

: "${RUNNER_TEMP:?RUNNER_TEMP is required}"
: "${GITHUB_JOB:?GITHUB_JOB is required}"
: "${GITHUB_PATH:?GITHUB_PATH is required}"

php_bin="${PHP_BIN:-}"
php_config="${PHP_CONFIG:-}"
php_ini="${PHP_CLI_INI:-}"
composer_bin="${COMPOSER_BIN:-}"

if [[ -n "${PHP_ROOT:-}" ]]; then
    [[ -n "$php_bin" ]] || php_bin="$PHP_ROOT/bin/php"
    [[ -n "$php_config" ]] || php_config="$PHP_ROOT/bin/php-config"
    [[ -n "$php_ini" ]] || php_ini="$PHP_ROOT/etc/php-cli.ini"
fi

if [[ -z "$php_bin" ]]; then
    php_bin="$(command -v php || true)"
fi
if [[ -z "$php_config" ]]; then
    php_config="$(command -v php-config || true)"
fi
if [[ -z "$composer_bin" ]]; then
    composer_bin="$(command -v composer || true)"
fi

# Preserve the existing aaPanel-compatible fallback for a future private/self-hosted route.
if [[ -z "$php_bin" && -x /www/server/php/84/bin/php ]]; then
    php_bin=/www/server/php/84/bin/php
    php_config=/www/server/php/84/bin/php-config
    [[ -n "$php_ini" ]] || php_ini=/www/server/php/84/etc/php-cli.ini
fi
if [[ -z "$composer_bin" && -f /usr/local/bin/composer ]]; then
    composer_bin=/usr/local/bin/composer
fi

test -n "$php_bin" && test -x "$php_bin" || {
    echo "Required PHP CLI binary is unavailable: ${php_bin:-not found}" >&2
    exit 1
}
test -n "$composer_bin" && test -f "$composer_bin" || {
    echo "Required Composer binary is unavailable: ${composer_bin:-not found}" >&2
    exit 1
}
if [[ -n "$php_ini" ]]; then
    test -f "$php_ini" || {
        echo "Configured PHP CLI configuration is unavailable: $php_ini" >&2
        exit 1
    }
fi

base_php_args=("$php_bin")
if [[ -n "$php_ini" ]]; then
    base_php_args+=(-c "$php_ini")
fi
base_php_args+=(
    -d opcache.jit=0
    -d opcache.jit_buffer_size=0
)

pcov_source=not-loaded
pcov_module=''

if "${base_php_args[@]}" -r 'exit(extension_loaded("pcov") ? 0 : 1);'; then
    pcov_source=ini
elif [[ "$mode" == 'coverage' ]]; then
    if [[ -n "$php_config" && -x "$php_config" ]]; then
        extension_dir="$($php_config --extension-dir)"
    else
        extension_dir="$("${base_php_args[@]}" -r 'echo ini_get("extension_dir");')"
    fi

    extension_dir="${extension_dir%/}"
    test -n "$extension_dir" || {
        echo 'Unable to resolve the PHP extension directory.' >&2
        exit 1
    }

    pcov_module="$extension_dir/pcov.so"
    test -r "$pcov_module" || {
        echo "Required PCOV module is unavailable: $pcov_module" >&2
        exit 1
    }

    pcov_source=explicit
fi

tool_bin="$RUNNER_TEMP/freedom-ci-toolchain-$GITHUB_JOB"
rm -rf "$tool_bin"
mkdir -p "$tool_bin"

{
    echo '#!/usr/bin/env bash'
    echo 'set -euo pipefail'
    echo 'php_args=('
    printf '    %q\n' "$php_bin"
    if [[ -n "$php_ini" ]]; then
        printf '    -c\n    %q\n' "$php_ini"
    fi
    printf '    -d\n    %q\n' 'opcache.jit=0'
    printf '    -d\n    %q\n' 'opcache.jit_buffer_size=0'
    if [[ -n "$pcov_module" ]]; then
        printf '    -d\n    %q\n' "extension=$pcov_module"
    fi
    printf '    -d\n    %q\n' "pcov.enabled=$pcov_enabled"
    echo ')'
    echo 'exec "${php_args[@]}" "$@"'
} > "$tool_bin/php"

{
    echo '#!/usr/bin/env bash'
    echo 'set -euo pipefail'
    printf 'exec %q %q "$@"\n' "$tool_bin/php" "$composer_bin"
} > "$tool_bin/composer"

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

if [[ "$mode" == 'coverage' ]]; then
    required_extensions+=(pcov)
fi

required_csv="$(IFS=,; echo "${required_extensions[*]}")"

if ! php_summary="$(
    FREEDOM_REQUIRED_EXTENSIONS="$required_csv" \
    FREEDOM_EXPECTED_PCOV="$pcov_enabled" \
    FREEDOM_PCOV_SOURCE="$pcov_source" \
    FREEDOM_PCOV_MODULE="$pcov_module" \
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

        $source = (string) getenv("FREEDOM_PCOV_SOURCE");
        if ($expectedPcov === 1 && ! in_array($source, ["ini", "explicit"], true)) {
            fwrite(STDERR, "Coverage mode requires a known PCOV loading source.".PHP_EOL);
            exit(1);
        }

        printf(
            "php=%s\nini=%s\npcov_loaded=%s\npcov_enabled=%s\npcov_source=%s\npcov_module=%s\njit_buffer_size=%s\n",
            PHP_VERSION,
            php_ini_loaded_file() ?: "none",
            extension_loaded("pcov") ? "yes" : "no",
            extension_loaded("pcov") ? (string) (int) ini_get("pcov.enabled") : "not-loaded",
            $source,
            (string) getenv("FREEDOM_PCOV_MODULE") ?: "not-explicit",
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
