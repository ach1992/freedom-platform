#!/usr/bin/env bash

set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
selector="$root/scripts/ci/select-feature-integration-scope.sh"
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT
repo="$tmpdir/repo"

fail() {
    echo "Feature integration scope test failed: $*" >&2
    exit 1
}

reset_repo() {
    git -C "$repo" reset --hard "$base" >/dev/null
    git -C "$repo" clean -fdx >/dev/null
}

commit_case() {
    local message=$1
    git -C "$repo" add -A
    git -C "$repo" commit -m "$message" >/dev/null
}

scope_for() {
    (cd "$repo" && bash "$selector" "$base" HEAD)
}

expect_full() {
    local name=$1
    local actual
    actual=$(scope_for)
    [[ "$actual" == 'scope=FULL' ]] || fail "$name expected FULL, got: $actual"
}

expect_targeted() {
    local name=$1
    shift
    local actual expected
    actual=$(scope_for)
    expected=$(printf 'scope=TARGETED\n'; printf 'target=%s\n' "$@")
    [[ "$actual" == "$expected" ]] || fail "$name expected targeted scope: $expected ; got: $actual"
}

mkdir -p \
    "$repo/app/Modules/Existing/Application" \
    "$repo/database/migrations" \
    "$repo/database/seeders" \
    "$repo/scripts/ci" \
    "$repo/tests/Feature" \
    "$repo/tests/Unit" \
    "$repo/config"

git -C "$repo" init -q
git -C "$repo" config user.name 'CI Scope Test'
git -C "$repo" config user.email 'ci-scope@example.invalid'

cat > "$repo/app/Modules/Existing/Application/ExistingService.php" <<'PHP'
<?php
namespace App\Modules\Existing\Application;
final class ExistingService {}
PHP
cat > "$repo/database/migrations/2026_01_01_000000_existing.php" <<'PHP'
<?php
return null;
PHP
cat > "$repo/database/seeders/DatabaseSeeder.php" <<'PHP'
<?php
final class DatabaseSeeder
{
    public function run(): void
    {
    }
}
PHP
printf '%s\n' '<?php return [];' > "$repo/scripts/ci/architecture-boundaries.php"
cat > "$repo/tests/Feature/ExistingFeatureTest.php" <<'PHP'
<?php
use App\Modules\Existing\Application\ExistingService;
PHP
printf '%s\n' '<?php return [];' > "$repo/config/app.php"

git -C "$repo" add -A
git -C "$repo" commit -m base >/dev/null
base=$(git -C "$repo" rev-parse HEAD)

# Test-only diffs have no product-code ambiguity: only the changed Feature file
# needs real-engine execution; Unit changes are handled independently.
printf '%s\n' '<?php' 'use App\Modules\Existing\Application\ExistingService;' '// changed' > "$repo/tests/Feature/ExistingFeatureTest.php"
printf '%s\n' '<?php // unit-only companion change' > "$repo/tests/Unit/CompanionTest.php"
commit_case feature-only
expect_targeted feature-only tests/Feature/ExistingFeatureTest.php

reset_repo

# Entirely additive new module + additive migration + explicit Feature evidence
# is the bounded product shape eligible for focused MariaDB/Redis acceptance.
mkdir -p "$repo/app/Modules/NewThing/Application"
cat > "$repo/app/Modules/NewThing/Application/NewThingService.php" <<'PHP'
<?php
namespace App\Modules\NewThing\Application;
final class NewThingService {}
PHP
cat > "$repo/database/migrations/2026_01_02_000000_new_thing.php" <<'PHP'
<?php
return null;
PHP
cat > "$repo/database/seeders/NewThingSeeder.php" <<'PHP'
<?php
final class NewThingSeeder {}
PHP
python3 - "$repo/database/seeders/DatabaseSeeder.php" <<'PY'
from pathlib import Path
import sys
p = Path(sys.argv[1])
s = p.read_text()
s = s.replace('    }\n}', '        NewThingSeeder::class;\n    }\n}')
p.write_text(s)
PY
printf '%s\n' '<?php return ["NewThing"];' > "$repo/scripts/ci/architecture-boundaries.php"
cat > "$repo/tests/Feature/NewThingFoundationTest.php" <<'PHP'
<?php
use App\Modules\NewThing\Application\NewThingService;
PHP
printf '%s\n' '<?php // NewThing unit' > "$repo/tests/Unit/NewThingUnitTest.php"
commit_case additive-new-module
expect_targeted additive-new-module tests/Feature/NewThingFoundationTest.php

reset_repo

# Existing modules can have non-local consumers/invariants: path proximity is
# insufficient evidence, so product changes in an existing module stay FULL.
printf '%s\n' '<?php' 'namespace App\Modules\Existing\Application;' 'final class ExistingService { public function changed(): bool { return true; } }' > "$repo/app/Modules/Existing/Application/ExistingService.php"
printf '%s\n' '<?php' 'use App\Modules\Existing\Application\ExistingService;' '// regression' > "$repo/tests/Feature/ExistingFeatureTest.php"
commit_case existing-module
expect_full existing-module

reset_repo

# Existing migration mutation is never narrowed by this selector.
printf '%s\n' '<?php' '// modified existing migration' 'return null;' > "$repo/database/migrations/2026_01_01_000000_existing.php"
printf '%s\n' '<?php' 'use App\Modules\Existing\Application\ExistingService;' '// migration regression' > "$repo/tests/Feature/ExistingFeatureTest.php"
commit_case modified-migration
expect_full modified-migration

reset_repo

# Cross-cutting/global surfaces are deliberately fail-safe FULL.
printf '%s\n' '<?php return ["changed" => true];' > "$repo/config/app.php"
printf '%s\n' '<?php' 'use App\Modules\Existing\Application\ExistingService;' '// config regression' > "$repo/tests/Feature/ExistingFeatureTest.php"
commit_case global-config
expect_full global-config

reset_repo

# Additive product work without explicit Feature evidence is not eligible for a
# focused acceptance shortcut.
mkdir -p "$repo/app/Modules/NoFeature/Application"
printf '%s\n' '<?php namespace App\Modules\NoFeature\Application; final class Service {}' > "$repo/app/Modules/NoFeature/Application/Service.php"
commit_case no-feature-evidence
expect_full no-feature-evidence

reset_repo

# The selected Feature file must directly reference the newly added module;
# otherwise the relationship is ambiguous and selection fails closed.
mkdir -p "$repo/app/Modules/Mismatch/Application"
printf '%s\n' '<?php namespace App\Modules\Mismatch\Application; final class Service {}' > "$repo/app/Modules/Mismatch/Application/Service.php"
printf '%s\n' '<?php' 'use App\Modules\Existing\Application\ExistingService;' > "$repo/tests/Feature/MismatchFoundationTest.php"
commit_case mismatched-feature
expect_full mismatched-feature

printf '%s\n' 'Feature integration scope selector tests passed.'
