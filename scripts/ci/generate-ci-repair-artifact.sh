#!/usr/bin/env bash

set -euo pipefail

required_files=(
    composer.json
    composer.lock
    app/Modules/Panels/Application/Contracts/PanelCreateServiceRequest.php
    app/Modules/Panels/Application/Contracts/PanelCapabilities.php
    app/Modules/Panels/Application/Contracts/SensitiveDeliveryArtifacts.php
    app/Modules/Panels/Infrastructure/FakePanelAdapter.php
    database/migrations/2026_08_06_002200_create_trial_policy_foundation.php
)

for path in "${required_files[@]}"; do
    test -f "$path" || {
        echo "Required repair input is missing: $path" >&2
        exit 1
    }
done

composer update league/commonmark \
    --with-dependencies \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --no-scripts

php artisan package:discover --ansi

commonmark_version="$(php -r '
    $lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
    foreach ($lock["packages"] as $package) {
        if (($package["name"] ?? null) === "league/commonmark") {
            echo ltrim((string) $package["version"], "v");
            exit(0);
        }
    }
    fwrite(STDERR, "league/commonmark is absent from composer.lock".PHP_EOL);
    exit(1);
')"

php -r '
    if (version_compare($argv[1], "2.9.0", "<")) {
        fwrite(STDERR, "league/commonmark 2.9.0 or later is required; found {$argv[1]}".PHP_EOL);
        exit(1);
    }
' "$commonmark_version"

formatted_files=(
    app/Modules/Panels/Application/Contracts/PanelCreateServiceRequest.php
    app/Modules/Panels/Application/Contracts/PanelCapabilities.php
    app/Modules/Panels/Application/Contracts/SensitiveDeliveryArtifacts.php
    app/Modules/Panels/Infrastructure/FakePanelAdapter.php
    database/migrations/2026_08_06_002200_create_trial_policy_foundation.php
)

php vendor/bin/pint "${formatted_files[@]}"
php vendor/bin/pint --test "${formatted_files[@]}"
php -d memory_limit=1G vendor/bin/phpstan analyse \
    --no-progress \
    --error-format=table \
    --memory-limit=1G
composer validate --strict --no-check-publish
bash scripts/ci/forbidden-patterns.sh
bash scripts/ci/architecture.sh

mkdir -p build/evidence/dependencies
composer audit --locked --abandoned=fail --format=json > build/evidence/dependencies/audit.json
bash scripts/ci/licenses.sh

artifact_root=build/maintenance/repository
rm -rf build/maintenance
mkdir -p "$artifact_root"

cp --parents composer.lock "${formatted_files[@]}" "$artifact_root"

(
    cd "$artifact_root"
    find . -type f -print0 \
        | sort -z \
        | xargs -0 sha256sum > ../SHA256SUMS
)

cat > build/maintenance/manifest.json <<EOF
{
  "schema_version": 1,
  "purpose": "bounded-ci-repair",
  "source_sha": "${GITHUB_SHA:-unknown}",
  "league_commonmark_version": "$commonmark_version",
  "generated_at_utc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF

printf 'Prepared bounded repair artifact with league/commonmark %s.\n' "$commonmark_version"
