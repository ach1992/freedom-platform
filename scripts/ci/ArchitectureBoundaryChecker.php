<?php

declare(strict_types=1);

namespace FreedomPlatform\CI;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ArchitectureBoundaryChecker
{
    /** @var array<string,true> */
    private array $usedPersistenceExceptions = [];

    /** @var array<string,true> */
    private array $usedMigrationTriggerDdlHelpers = [];

    /** @var array<string,true> */
    private array $usedTelegramPresentationSources = [];

    /** @var array<string,true> */
    private array $usedTelegramConfidentialPresentationSources = [];

    /** @var array<string,true> */
    private array $usedModuleDependencyReferenceExceptions = [];

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {}

    /** @return array{violations:list<string>,edges:list<string>} */
    public function check(): array
    {
        $violations = [];
        $edges = [];
        $this->usedPersistenceExceptions = [];
        $this->usedMigrationTriggerDdlHelpers = [];
        $this->usedTelegramPresentationSources = [];
        $this->usedTelegramConfidentialPresentationSources = [];
        $this->usedModuleDependencyReferenceExceptions = [];

        foreach ($this->phpFiles('app/Modules') as $relativePath => $source) {
            $this->scanModuleFile($relativePath, $source, $violations, $edges);
            $this->scanPersistence($relativePath, $source, $violations);
            $this->scanOpaquePersistence($relativePath, $source, $violations);
            $this->scanUnattributablePersistenceMechanisms($relativePath, $source, $violations);
            $this->scanTelegramGenericDeliveryBoundary($relativePath, $source, $violations);
            $this->scanTelegramConfidentialDeliveryBoundary($relativePath, $source, $violations);
        }

        foreach ($this->phpFiles('app/Shared') as $relativePath => $source) {
            $this->scanSharedFile($relativePath, $source, $violations);
            $this->scanPersistence($relativePath, $source, $violations);
            $this->scanOpaquePersistence($relativePath, $source, $violations);
            $this->scanUnattributablePersistenceMechanisms($relativePath, $source, $violations);
            $this->scanTelegramGenericDeliveryBoundary($relativePath, $source, $violations);
            $this->scanTelegramConfidentialDeliveryBoundary($relativePath, $source, $violations);
        }

        foreach ($this->phpFiles('routes') as $relativePath => $source) {
            $this->scanPersistence($relativePath, $source, $violations);
            $this->scanOpaquePersistence($relativePath, $source, $violations);
            $this->scanUnattributablePersistenceMechanisms($relativePath, $source, $violations);
            $this->scanTelegramGenericDeliveryBoundary($relativePath, $source, $violations);
            $this->scanTelegramConfidentialDeliveryBoundary($relativePath, $source, $violations);
        }

        $edges = array_values(array_unique($edges));
        sort($edges, SORT_STRING);
        array_push($violations, ...$this->cycleViolations($edges));
        array_push($violations, ...$this->moduleDependencyReferenceExceptionViolations());
        array_push($violations, ...$this->persistenceExceptionViolations());
        array_push($violations, ...$this->applicationPrivateTableViolations());
        array_push($violations, ...$this->migrationTriggerDdlHelperViolations());
        array_push($violations, ...$this->telegramPresentationSourceViolations());
        array_push($violations, ...$this->telegramConfidentialPresentationSourceViolations());

        $violations = array_values(array_unique($violations));
        sort($violations, SORT_STRING);

        return ['violations' => $violations, 'edges' => $edges];
    }

    /**
     * @param  list<string>  $violations
     * @param  list<string>  $edges
     */
    private function scanModuleFile(string $relativePath, string $source, array &$violations, array &$edges): void
    {
        if (preg_match('#^app/Modules/([^/]+)/([^/]+)/#', $relativePath, $pathParts) !== 1) {
            return;
        }

        $sourceModule = $pathParts[1];
        $sourceLayer = $pathParts[2];

        foreach ($this->qualifiedNames($source) as $reference) {
            $name = $reference['name'];
            $line = $reference['line'];

            if ($this->isEloquentReference($name)) {
                $violations[] = sprintf(
                    '%s:%d feature modules may not reference Eloquent persistence primitives because durable-table ownership cannot be attributed; use an attributable persistence boundary.',
                    $relativePath,
                    $line,
                );
            }

            if ($sourceLayer === 'Domain' && $this->isFrameworkReference($name)) {
                $violations[] = sprintf(
                    '%s:%d Domain may not reference framework infrastructure.',
                    $relativePath,
                    $line,
                );
            }

            $target = $this->moduleReference($name);
            if ($target === null) {
                continue;
            }

            [$targetModule, $targetLayer] = $target;

            if ($sourceLayer === 'Domain') {
                if ($targetModule === $sourceModule && $targetLayer === 'Domain') {
                    continue;
                }

                if ($targetModule !== $sourceModule) {
                    $edges[] = $sourceModule.'>'.$targetModule;
                }

                $exceptionKey = $relativePath.'|'.$targetModule.'\\'.$targetLayer;
                $exceptions = $this->config['domain_dependency_exceptions'] ?? [];
                if (is_array($exceptions) && in_array($exceptionKey, $exceptions, true)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d Domain may depend only on its own Domain and approved Shared Domain primitives; found %s\\%s.',
                    $relativePath,
                    $line,
                    $targetModule,
                    $targetLayer,
                );

                continue;
            }

            if ($targetModule === $sourceModule) {
                if (! $this->sameModuleLayerAllowed($sourceLayer, $targetLayer)) {
                    $violations[] = sprintf(
                        '%s:%d %s may not depend on its own %s layer; preserve dependency direction through Domain/Application boundaries.',
                        $relativePath,
                        $line,
                        $sourceLayer,
                        $targetLayer,
                    );
                }

                continue;
            }

            if ($this->consumeModuleDependencyReferenceException($relativePath, $name)) {
                continue;
            }

            if (in_array($targetLayer, ['Infrastructure', 'Presentation'], true)) {
                $violations[] = sprintf(
                    '%s:%d cross-module import of %s\\%s is forbidden; use a public Application/Domain boundary.',
                    $relativePath,
                    $line,
                    $targetModule,
                    $targetLayer,
                );

                continue;
            }

            $edge = $sourceModule.'>'.$targetModule;
            $edges[] = $edge;
            if (! $this->allowedDependency($sourceModule, $targetModule)) {
                $violations[] = sprintf(
                    '%s:%d undeclared module dependency %s -> %s; declare the reviewed public boundary in scripts/ci/architecture-boundaries.php.',
                    $relativePath,
                    $line,
                    $sourceModule,
                    $targetModule,
                );
            }
        }
    }

    /** @param list<string> $violations */
    private function scanSharedFile(string $relativePath, string $source, array &$violations): void
    {
        foreach ($this->qualifiedNames($source) as $reference) {
            if ($this->isEloquentReference($reference['name'])) {
                $violations[] = sprintf(
                    '%s:%d Shared code may not reference Eloquent persistence primitives because durable-table ownership cannot be attributed.',
                    $relativePath,
                    $reference['line'],
                );
            }

            if (str_starts_with($reference['name'], 'App\\Modules\\')) {
                $violations[] = sprintf(
                    '%s:%d Shared code must not depend on a feature module.',
                    $relativePath,
                    $reference['line'],
                );
            }

            if (str_starts_with($relativePath, 'app/Shared/Domain/') && $this->isFrameworkReference($reference['name'])) {
                $violations[] = sprintf(
                    '%s:%d Shared Domain may not reference framework infrastructure.',
                    $relativePath,
                    $reference['line'],
                );
            }
        }
    }

    /** @param list<string> $violations */
    private function scanPersistence(string $relativePath, string $source, array &$violations): void
    {
        $this->scanApplicationPrivateTableReferences($relativePath, $source, $violations);
        $this->scanDeferredTableMutations($relativePath, $source, $violations);
        $this->scanDynamicTableAccess($relativePath, $source, $violations);
        $this->scanUnsupportedQuerySources($relativePath, $source, $violations);

        preg_match_all(
            '/(?:DB::|->)(?:table|from)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $tableParts = preg_split('/\s+as\s+/i', trim($match[1][0]));
            $table = is_array($tableParts) && isset($tableParts[0]) ? $tableParts[0] : trim($match[1][0]);
            $offset = $match[0][1];
            $statement = substr($source, $offset, $this->statementLength($source, $offset));
            $mutation = $this->mutationMethod($statement);
            if ($mutation === null) {
                continue;
            }

            $this->recordTableMutationViolation($relativePath, $source, $offset, $table, $mutation, $violations);
        }
    }

    /** @param list<string> $violations */
    private function scanApplicationPrivateTableReferences(string $relativePath, string $source, array &$violations): void
    {
        $tables = $this->config['application_private_tables'] ?? [];
        if (! is_array($tables)) {
            return;
        }

        $sourceOwner = $this->sourcePersistenceOwner($relativePath);
        foreach ($tables as $table) {
            if (! is_string($table)) {
                continue;
            }

            $owner = $this->durableTableOwner($table);
            if ($owner !== null && $sourceOwner === $owner) {
                continue;
            }

            if (preg_match('/\b'.preg_quote($table, '/').'\b/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d durable table %s is private to the %s Application boundary; cross-module direct persistence access is forbidden.',
                $relativePath,
                $this->lineNumber($source, $match[0][1]),
                $table,
                $owner ?? 'declared',
            );
        }
    }

    /** @param list<string> $violations */
    private function recordTableMutationViolation(
        string $relativePath,
        string $source,
        int $offset,
        string $table,
        string $mutation,
        array &$violations,
    ): void {
        $line = $this->lineNumber($source, $offset);
        $sourceLayer = $this->sourceLayer($relativePath);

        if (str_starts_with($relativePath, 'routes/') || $sourceLayer === 'Presentation') {
            if ($this->consumePersistenceException($relativePath, $table)) {
                return;
            }

            $violations[] = sprintf(
                '%s:%d direct persistence mutation of %s from %s is forbidden; call an Application boundary.',
                $relativePath,
                $line,
                $table,
                str_starts_with($relativePath, 'routes/') ? 'routes' : 'Presentation',
            );

            return;
        }

        $owner = $this->durableTableOwner($table);
        if ($owner === 'SharedAppendOnly') {
            if (! in_array($mutation, ['insert', 'insertGetId', 'insertOrIgnore'], true)) {
                $violations[] = sprintf(
                    '%s:%d durable table %s is append-only; mutation %s is forbidden.',
                    $relativePath,
                    $line,
                    $table,
                    $mutation,
                );
            }

            return;
        }

        $sourceOwner = $this->sourcePersistenceOwner($relativePath);
        if ($owner === null) {
            $violations[] = sprintf(
                '%s:%d mutation of unmapped durable table candidate %s is forbidden because ownership cannot be attributed.',
                $relativePath,
                $line,
                $table,
            );

            return;
        }
        if ($sourceOwner === null || $owner === $sourceOwner) {
            return;
        }

        if ($this->consumePersistenceException($relativePath, $table)) {
            return;
        }

        $violations[] = sprintf(
            '%s:%d %s mutation of durable table %s owned by %s is forbidden.',
            $relativePath,
            $line,
            $sourceOwner,
            $table,
            $owner,
        );
    }

    /** @param list<string> $violations */
    private function scanDynamicTableAccess(string $relativePath, string $source, array &$violations): void
    {
        preg_match_all(
            '/(?:\bDB::|->)\s*(?:table|from)\(\s*([^)]*)\)/',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($this->isConsoleTableRenderer($relativePath, $source, $offset)) {
                continue;
            }

            $expression = trim($match[1][0]);
            if ($this->literalTableName($expression) !== null) {
                continue;
            }

            $statement = substr($source, $offset, $this->statementLength($source, $offset));
            $mutation = $this->mutationMethod($statement);
            $tables = $this->boundedDynamicTables($source, $offset, $expression);
            if ($tables === null) {
                $violations[] = sprintf(
                    '%s:%d dynamic table %s is forbidden because durable-table ownership cannot be statically attributed; use a literal or statically bounded reviewed table boundary.',
                    $relativePath,
                    $this->lineNumber($source, $offset),
                    $mutation === null ? 'read' : 'mutation',
                );

                continue;
            }

            $sourceOwner = $this->sourcePersistenceOwner($relativePath);
            $privateViolation = false;
            foreach ($tables as $table) {
                $owner = $this->applicationPrivateTableOwner($table);
                if ($owner === null || $owner === $sourceOwner) {
                    continue;
                }

                // The literal private-table scanner already owns contiguous source references.
                // This path closes dynamically constructed identities without double-reporting.
                if (preg_match('/\b'.preg_quote($table, '/').'\b/', $source) === 1) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d durable table %s is private to the %s Application boundary; cross-module direct persistence access is forbidden.',
                    $relativePath,
                    $this->lineNumber($source, $offset),
                    $table,
                    $owner,
                );
                $privateViolation = true;
            }

            if ($privateViolation || $mutation === null) {
                continue;
            }

            if (! $this->boundedTablesAreAllowed($relativePath, $tables, $mutation)) {
                $violations[] = sprintf(
                    '%s:%d dynamic table mutation is forbidden because durable-table ownership cannot be attributed; use a literal or statically bounded reviewed table boundary.',
                    $relativePath,
                    $this->lineNumber($source, $offset),
                );
            }
        }
    }

    private function literalTableName(string $expression): ?string
    {
        if (preg_match('/\A([\'\"])([A-Za-z0-9_.]+(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?)\1\z/i', $expression, $match) !== 1) {
            return null;
        }

        $parts = preg_split('/\s+as\s+/i', $match[2]);
        $table = is_array($parts) && isset($parts[0]) ? trim($parts[0]) : '';

        return preg_match('/\A[A-Za-z0-9_.]+\z/', $table) === 1 ? $table : null;
    }

    /** @return list<string>|null */
    private function boundedDynamicTables(string $source, int $offset, string $expression): ?array
    {
        $aliasBase = $this->dynamicAliasBaseTable($expression);
        if ($aliasBase !== null) {
            return [$aliasBase];
        }

        $tableConstant = $this->resolvedTableConstant($source, $expression);
        if ($tableConstant !== null) {
            return [$tableConstant];
        }

        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $variableMatch) !== 1) {
            return null;
        }

        $functionStart = $this->enclosingFunctionStart($source, $offset);
        if ($functionStart === null) {
            return null;
        }

        $variable = $variableMatch[1];
        $prefix = substr($source, $functionStart, $offset - $functionStart);

        $guardPattern = '/if\s*\(\s*!\s*in_array\s*\(\s*\$'.preg_quote($variable, '/').'\s*,\s*\[([^\]]+)\]\s*,\s*true\s*\)\s*\)\s*\{([^{}]*)\}/s';
        preg_match_all($guardPattern, $prefix, $guards, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($guards !== []) {
            $guard = $guards[array_key_last($guards)];
            $guardOffset = $functionStart + $guard[0][1];
            $guardEnd = $guard[0][1] + strlen($guard[0][0]);
            $guardBodyStart = $functionStart + $guard[2][1];
            $guardBodyEnd = $guardBodyStart + strlen($guard[2][0]);
            $afterGuard = substr($prefix, $guardEnd);
            if ($this->tokenIdAtOffset($source, $guardOffset) === T_IF
                && $this->braceDepthBetween($source, $functionStart, $guardOffset) === 1
                && $this->firstSignificantTokenIdInRange($source, $guardBodyStart, $guardBodyEnd) === T_THROW
                && ! $this->containsVariableCodeUse($afterGuard, $variable)
            ) {
                $tables = $this->literalTableList($guard[1][0]);
                if ($tables !== null) {
                    return $tables;
                }
            }
        }

        $foreachPattern = '/foreach\s*\(\s*\[((?:\s*[\'\"][A-Za-z0-9_.]+[\'\"]\s*,)*\s*[\'\"][A-Za-z0-9_.]+[\'\"]\s*,?\s*)\]\s+as\s+\$'.preg_quote($variable, '/').'\s*\)/s';
        preg_match_all($foreachPattern, $prefix, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($matches === []) {
            return null;
        }

        $match = $matches[array_key_last($matches)];
        $fullOffset = $match[0][1];
        $headerEnd = $fullOffset + strlen($match[0][0]);
        $absoluteHeaderStart = $functionStart + $fullOffset;
        $absoluteHeaderEnd = $functionStart + $headerEnd;
        if ($this->tokenIdAtOffset($source, $absoluteHeaderStart) !== T_FOREACH
            || ! $this->blockFromHeaderContainsOffset($source, $absoluteHeaderEnd, $offset)
        ) {
            return null;
        }

        $afterBinding = substr($prefix, $headerEnd);
        if ($this->containsVariableCodeUse($afterBinding, $variable)) {
            return null;
        }

        return $this->literalTableList($match[1][0]);
    }

    private function tokenIdAtOffset(string $source, int $targetOffset): ?int
    {
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $end = $offset + strlen($text);
            if ($targetOffset >= $offset && $targetOffset < $end) {
                return is_array($token) ? $token[0] : null;
            }
            $offset = $end;
        }

        return null;
    }

    private function firstSignificantTokenIdInRange(string $source, int $start, int $end): ?int
    {
        if ($end <= $start) {
            return null;
        }

        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $tokenEnd = $offset + strlen($text);
            if ($tokenEnd <= $start) {
                $offset = $tokenEnd;

                continue;
            }
            if ($offset >= $end) {
                break;
            }
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $offset = $tokenEnd;

                continue;
            }
            if (is_string($token) && trim($token) === '') {
                $offset = $tokenEnd;

                continue;
            }

            return is_array($token) ? $token[0] : null;
        }

        return null;
    }

    private function containsVariableCodeUse(string $source, string $variable): bool
    {
        $target = '$'.$variable;
        foreach (token_get_all('<?php '.$source) as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === $target) {
                return true;
            }
        }

        return false;
    }

    private function braceDepthBetween(string $source, int $start, int $end): int
    {
        if ($end <= $start) {
            return 0;
        }

        $tokens = token_get_all('<?php '.substr($source, $start, $end - $start));
        $depth = 0;
        foreach ($tokens as $token) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }
        }

        return $depth;
    }

    private function blockFromHeaderContainsOffset(string $source, int $headerEnd, int $offset): bool
    {
        if ($offset <= $headerEnd) {
            return false;
        }

        $tokens = token_get_all('<?php '.substr($source, $headerEnd, $offset - $headerEnd));
        $depth = 0;
        $seenOpen = false;
        foreach ($tokens as $token) {
            if ($token === '{') {
                $seenOpen = true;
                $depth++;
            } elseif ($token === '}' && $seenOpen) {
                $depth--;
                if ($depth === 0) {
                    return false;
                }
            }
        }

        return $seenOpen && $depth > 0;
    }

    private function isConsoleTableRenderer(string $relativePath, string $source, int $offset): bool
    {
        if (! str_contains($relativePath, '/Presentation/Console/')
            || preg_match('/\bextends\s+Command\b/', $source) !== 1
        ) {
            return false;
        }

        $prefix = substr($source, max(0, $offset - 24), min(24, $offset));

        return preg_match('/\$this\s*\z/', $prefix) === 1;
    }

    private function resolvedTableConstant(string $source, string $expression): ?string
    {
        if (preg_match('/\A(.+)::TABLE\z/', $expression, $match) !== 1
            || ! $this->isClassReference($match[1])
        ) {
            return null;
        }

        $class = $this->resolvedClassName($source, $match[1]);
        if ($class === null || ! str_starts_with($class, 'App\\')) {
            return null;
        }

        $relativePath = 'app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';
        $absolutePath = $this->root.'/'.$relativePath;
        if (! is_file($absolutePath)) {
            return null;
        }

        $classSource = file_get_contents($absolutePath);
        if (! is_string($classSource)
            || preg_match('/\b(?:public\s+)?const\s+TABLE\s*=\s*([\'\"])([A-Za-z0-9_.]+)\1\s*;/', $classSource, $constant) !== 1
        ) {
            return null;
        }

        return $constant[2];
    }

    private function isClassReference(string $reference): bool
    {
        $normalized = ltrim($reference, '\\');
        if ($normalized === '' || str_starts_with($normalized, '\\')) {
            return false;
        }

        foreach (explode('\\', $normalized) as $segment) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $segment) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function resolvedClassName(string $source, string $reference): ?string
    {
        if (str_starts_with($reference, '\\')) {
            return ltrim($reference, '\\');
        }

        preg_match_all(
            '/^use\s+(?!function\b|const\b)([^;]+);/mi',
            $source,
            $useStatements,
        );
        foreach ($useStatements[1] ?? [] as $statement) {
            $statement = trim($statement);
            if (preg_match(
                '/\A([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\z/',
                $statement,
                $import,
            ) !== 1) {
                continue;
            }

            $imported = $import[1];
            $alias = $import[2] ?? basename(str_replace('\\', '/', $imported));
            if ($reference === $alias) {
                return $imported;
            }
            if (str_starts_with($reference, $alias.'\\')) {
                return $imported.substr($reference, strlen($alias));
            }
        }

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1
            || ! $this->isClassReference(trim($namespace[1]))
        ) {
            return null;
        }

        return trim($namespace[1]).'\\'.$reference;
    }

    private function dynamicAliasBaseTable(string $expression): ?string
    {
        if (preg_match('/\A([\'\"])([A-Za-z0-9_.]+)\s+as\s+\1\s*\.\s*\$[A-Za-z_][A-Za-z0-9_]*\z/i', $expression, $match) !== 1) {
            return null;
        }

        return $match[2];
    }

    /** @return list<string>|null */
    private function literalTableList(string $source): ?array
    {
        if (preg_match('/\A\s*(?:[\'\"][A-Za-z0-9_.]+[\'\"]\s*,\s*)*[\'\"][A-Za-z0-9_.]+[\'\"]\s*,?\s*\z/', $source) !== 1) {
            return null;
        }

        preg_match_all('/[\'\"]([A-Za-z0-9_.]+)[\'\"]/', $source, $matches);
        $tables = array_values(array_unique($matches[1] ?? []));

        return $tables === [] ? null : $tables;
    }

    private function applicationPrivateTableOwner(string $table): ?string
    {
        $tables = $this->config['application_private_tables'] ?? [];
        if (! is_array($tables) || ! in_array($table, $tables, true)) {
            return null;
        }

        return $this->durableTableOwner($table);
    }

    /** @param list<string> $tables */
    private function boundedTablesAreAllowed(string $relativePath, array $tables, string $mutation): bool
    {
        $sourceLayer = $this->sourceLayer($relativePath);
        if (str_starts_with($relativePath, 'routes/') || $sourceLayer === 'Presentation') {
            return false;
        }

        $sourceOwner = $this->sourcePersistenceOwner($relativePath);
        if ($sourceOwner === null) {
            return false;
        }

        foreach ($tables as $table) {
            $owner = $this->durableTableOwner($table);
            if ($owner === 'SharedAppendOnly') {
                if (! in_array($mutation, ['insert', 'insertGetId', 'insertOrIgnore'], true)) {
                    return false;
                }

                continue;
            }

            if ($owner === $sourceOwner) {
                continue;
            }

            if ($owner !== null && $this->consumePersistenceException($relativePath, $table)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** @param list<string> $violations */
    private function scanDeferredTableMutations(string $relativePath, string $source, array &$violations): void
    {
        preg_match_all(
            '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*[^;]*?(?:DB::|->)(?:table|from|fromRaw|fromSub)\(\s*[^)]*\)[^;]*;/',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $variable = $match[1][0];
            $assignmentOffset = $match[0][1];
            $afterAssignment = $assignmentOffset + strlen($match[0][0]);
            $functionEnd = $this->enclosingFunctionEnd($source, $assignmentOffset) ?? strlen($source);
            if ($functionEnd <= $afterAssignment) {
                continue;
            }

            $segment = substr($source, $afterAssignment, $functionEnd - $afterAssignment);
            $reassignmentPattern = '/'.preg_quote($variable, '/').'\s*=/';
            if (preg_match($reassignmentPattern, $segment, $reassignment, PREG_OFFSET_CAPTURE) === 1) {
                $segment = substr($segment, 0, $reassignment[0][1]);
            }

            if (preg_match(
                '/'.preg_quote($variable, '/').'\s*->\s*(insert|insertGetId|insertOrIgnore|insertOrIgnoreReturning|insertUsing|insertOrIgnoreUsing|update|updateFrom|delete|upsert|updateOrInsert|increment|incrementEach|decrement|decrementEach|truncate)\s*\(/',
                $segment,
                $mutationMatch,
            ) !== 1) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d deferred table mutation through %s is forbidden because the table/owner boundary is detached from the mutation; keep the reviewed table chain in one attributable statement.',
                $relativePath,
                $this->lineNumber($source, $assignmentOffset),
                $variable,
            );
        }
    }

    /** @param list<string> $violations */
    private function scanUnsupportedQuerySources(string $relativePath, string $source, array &$violations): void
    {
        preg_match_all(
            '/->\s*(fromRaw|fromSub)\s*\(/',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($matches as $match) {
            $offset = $match[0][1];
            $violations[] = sprintf(
                '%s:%d query source through %s is forbidden because durable-table ownership cannot be statically attributed.',
                $relativePath,
                $this->lineNumber($source, $offset),
                $match[1][0],
            );
        }
    }

    /** @return list<array{start:int,end:int}> */
    private function functionRanges(string $source): array
    {
        $tokens = token_get_all($source);
        $ranges = [];
        $offset = 0;
        $depth = 0;
        $pendingFunction = false;
        $functions = [];

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (is_array($token) && $token[0] === T_FUNCTION) {
                $pendingFunction = true;
            } elseif ($token === '{') {
                $depth++;
                if ($pendingFunction) {
                    $functions[] = ['depth' => $depth, 'start' => $offset];
                    $pendingFunction = false;
                }
            } elseif ($token === '}') {
                $last = array_key_last($functions);
                if ($last !== null && $functions[$last]['depth'] === $depth) {
                    $function = array_pop($functions);
                    if (! is_array($function)) {
                        throw new RuntimeException('Architecture function range stack is inconsistent.');
                    }
                    $ranges[] = ['start' => $function['start'], 'end' => $offset];
                }
                $depth--;
            } elseif ($pendingFunction && $token === ';') {
                $pendingFunction = false;
            }

            $offset += strlen($text);
        }

        return $ranges;
    }

    private function enclosingFunctionEnd(string $source, int $offset): ?int
    {
        $bestStart = -1;
        $bestEnd = null;
        foreach ($this->functionRanges($source) as $range) {
            if ($range['start'] <= $offset && $offset <= $range['end'] && $range['start'] > $bestStart) {
                $bestStart = $range['start'];
                $bestEnd = $range['end'];
            }
        }

        return $bestEnd;
    }

    private function enclosingFunctionStart(string $source, int $offset): ?int
    {
        $bestStart = null;
        foreach ($this->functionRanges($source) as $range) {
            if ($range['start'] <= $offset && $offset <= $range['end']
                && ($bestStart === null || $range['start'] > $bestStart)
            ) {
                $bestStart = $range['start'];
            }
        }

        return $bestStart;
    }

    /** @param list<string> $violations */
    private function scanOpaquePersistence(string $relativePath, string $source, array &$violations): void
    {
        $presentation = str_starts_with($relativePath, 'routes/') || $this->sourceLayer($relativePath) === 'Presentation';

        $patterns = [
            '/\bDB::\s*(statement|unprepared|insert|update|delete|affectingStatement)\s*\(/',
            '/->\s*(statement|unprepared|affectingStatement)\s*\(/',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $method = $match[1][0] ?? 'raw';
                $offset = $match[0][1];
                $line = $this->lineNumber($source, $offset);

                if ($presentation) {
                    $violations[] = sprintf(
                        '%s:%d opaque persistence API %s from %s is forbidden; call an Application boundary.',
                        $relativePath,
                        $line,
                        $method,
                        str_starts_with($relativePath, 'routes/') ? 'routes' : 'Presentation',
                    );

                    continue;
                }

                if (in_array($method, ['insert', 'update', 'delete', 'affectingStatement'], true)) {
                    $violations[] = sprintf(
                        '%s:%d opaque raw mutation API %s is forbidden because durable-table ownership cannot be attributed.',
                        $relativePath,
                        $line,
                        $method,
                    );

                    continue;
                }

                $openParen = strpos($source, '(', $offset);
                if ($openParen === false) {
                    continue;
                }

                $sql = $this->literalRawSql($source, $openParen + 1);
                $classification = $sql === null ? 'unknown' : $this->classifyRawStatement($sql);
                if ($classification === 'session') {
                    continue;
                }

                if ($classification === 'trigger_ddl' && $this->consumeMigrationTriggerDdlHelper($relativePath)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d opaque raw SQL %s is forbidden because durable-table ownership cannot be attributed.',
                    $relativePath,
                    $line,
                    in_array($classification, ['mutation', 'ddl', 'trigger_ddl'], true)
                        ? 'mutation'
                        : 'with a non-literal/unsupported statement',
                );
            }
        }
    }

    /** @param list<string> $violations */
    private function scanUnattributablePersistenceMechanisms(string $relativePath, string $source, array &$violations): void
    {
        $dbFacade = preg_quote('Illuminate\\Support\\Facades\\DB', '/');
        preg_match_all(
            '/\buse\s+'.$dbFacade.'\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/',
            $source,
            $aliases,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($aliases as $alias) {
            $violations[] = sprintf(
                '%s:%d aliasing the DB facade as %s is forbidden because static persistence attribution must remain syntax-stable.',
                $relativePath,
                $this->lineNumber($source, $alias[0][1]),
                $alias[1][0],
            );
        }

        $this->scanConnectionRawSql($relativePath, $source, $violations);
        $this->scanRuntimeSchemaMutations($relativePath, $source, $violations);

        $pdoPatterns = [
            '/(?:->\s*(?:getPdo|getRawPdo)\s*\(|\bDB::\s*(?:getPdo|getRawPdo)\s*\()/',
            '/\bnew\s+(?:'.preg_quote('\\PDO', '/').'|PDO)\b/',
        ];
        foreach ($pdoPatterns as $pattern) {
            preg_match_all($pattern, $source, $pdoCalls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($pdoCalls as $call) {
                $violations[] = sprintf(
                    '%s:%d direct PDO access is forbidden in runtime source because durable persistence ownership cannot be statically attributed.',
                    $relativePath,
                    $this->lineNumber($source, $call[0][1]),
                );
            }
        }
    }

    /** @param list<string> $violations */
    private function scanConnectionRawSql(string $relativePath, string $source, array &$violations): void
    {
        $readMethods = '(?:select|selectOne|selectFromWriteConnection|selectResultSets|scalar|cursor)';
        $writeMethods = '(?:insert|update|delete)';
        $methods = '(?:'.$readMethods.'|'.$writeMethods.')';
        $memberOperator = '(?:\\?->|->)';

        $receiverPatterns = [
            '/\\bDB\\s*::/',
            '/\\bDB\\s*::\\s*connection\\s*\\([^)]*\\)\\s*'.$memberOperator.'/',
        ];

        foreach ([
            'Illuminate\\Database\\Connection',
            'Illuminate\\Database\\ConnectionInterface',
        ] as $connectionType) {
            foreach ($this->typedPersistenceReceivers($source, $connectionType) as $receiver) {
                $receiverPatterns[] = '/'.preg_quote($receiver, '/').'\\s*'.$memberOperator.'/';
            }
        }

        $databaseManagers = $this->typedPersistenceReceivers($source, 'Illuminate\\Database\\DatabaseManager');
        foreach ($databaseManagers as $receiver) {
            $receiverPatterns[] = '/'.preg_quote($receiver, '/').'\\s*'.$memberOperator.'/';
            $receiverPatterns[] = '/'.preg_quote($receiver, '/').'\\s*'.$memberOperator.'\\s*connection\\s*\\([^)]*\\)\\s*'.$memberOperator.'/';
        }

        foreach ($databaseManagers as $manager) {
            preg_match_all(
                '/(\\$[A-Za-z_][A-Za-z0-9_]*)\\s*=\\s*'.preg_quote($manager, '/').'\\s*'.$memberOperator.'\\s*connection\\s*\\([^)]*\\)\\s*;/',
                $source,
                $assignedConnections,
                PREG_SET_ORDER,
            );
            foreach ($assignedConnections as $assigned) {
                $receiverPatterns[] = '/'.preg_quote($assigned[1], '/').'\\s*'.$memberOperator.'/';
            }
        }
        preg_match_all(
            '/(\\$[A-Za-z_][A-Za-z0-9_]*)\\s*=\\s*DB\\s*::\\s*connection\\s*\\([^)]*\\)\\s*;/',
            $source,
            $assignedFacadeConnections,
            PREG_SET_ORDER,
        );
        foreach ($assignedFacadeConnections as $assigned) {
            $receiverPatterns[] = '/'.preg_quote($assigned[1], '/').'\\s*'.$memberOperator.'/';
        }

        $receiverPatterns = array_values(array_unique($receiverPatterns));
        foreach ($receiverPatterns as $receiverPattern) {
            $pattern = substr($receiverPattern, 0, -1).'\\s*('.$methods.')\\s*\\(/';
            preg_match_all($pattern, $source, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($calls as $call) {
                $method = $call[1][0];
                $offset = $call[0][1];
                if (preg_match('/^'.$writeMethods.'$/', $method) === 1) {
                    $violations[] = sprintf(
                        '%s:%d raw Connection mutation API %s is forbidden because durable-table ownership cannot be attributed.',
                        $relativePath,
                        $this->lineNumber($source, $offset),
                        $method,
                    );

                    continue;
                }

                $openParen = $offset + strlen($call[0][0]) - 1;
                $sql = $this->literalRawSql($source, $openParen + 1);
                if ($sql !== null
                    && ($this->isLiteralReadOnlySql($sql)
                        || $this->isReviewedMetadataIntrospection($relativePath, $sql))) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d raw Connection read API %s must receive one literal read-only SELECT statement or the exact reviewed metadata introspection; mutation/DDL/dynamic SQL is forbidden.',
                    $relativePath,
                    $this->lineNumber($source, $offset),
                    $method,
                );
            }
        }
    }

    /** @return list<string> */
    private function typedPersistenceReceivers(string $source, string $fqcn): array
    {
        $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
        $typeNames = [$short, '\\'.$fqcn];
        $importPattern = preg_quote($fqcn, '/');
        if (preg_match('/\\buse\\s+'.$importPattern.'\\s+as\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*;/', $source, $alias) === 1) {
            $typeNames[] = $alias[1];
        }

        $receivers = [];
        foreach (array_values(array_unique($typeNames)) as $typeName) {
            $typePattern = preg_quote($typeName, '/');
            $typeUsePattern = '(?:\\?'.$typePattern.'|'.$typePattern.'(?:\\s*\\|\\s*null)?|null\\s*\\|\\s*'.$typePattern.')';
            preg_match_all(
                '/(?<![A-Za-z0-9_\\\\])'.$typeUsePattern.'\\s+\\$([A-Za-z_][A-Za-z0-9_]*)/',
                $source,
                $variables,
                PREG_SET_ORDER,
            );
            foreach ($variables as $variable) {
                $receivers[] = '$'.$variable[1];
            }

            preg_match_all(
                '/\\b(?:public|protected|private)(?:\\s+readonly)?\\s+'.$typeUsePattern.'\\s+\\$([A-Za-z_][A-Za-z0-9_]*)/',
                $source,
                $properties,
                PREG_SET_ORDER,
            );
            foreach ($properties as $property) {
                $receivers[] = '$this->'.$property[1];
            }
        }

        return array_values(array_unique($receivers));
    }

    private function isLiteralReadOnlySql(string $sql): bool
    {
        $sql = trim($sql);
        if ($sql === '' || preg_match('/;\\s*\\S/s', $sql) === 1) {
            return false;
        }
        if (preg_match('/^SELECT\\b/is', $sql) !== 1) {
            return false;
        }

        return preg_match('/\\bINTO\\s+(?:OUTFILE|DUMPFILE)\\b/i', $sql) !== 1;
    }

    private function isReviewedMetadataIntrospection(string $relativePath, string $sql): bool
    {
        if (! in_array($relativePath, [
            'app/Modules/Telegram/Application/TelegramDeliveryForeignKeyMetadataAttestor.php',
            'app/Modules/Telegram/Application/TelegramDeliveryLifecycleDatabaseAuthority.php',
        ], true)) {
            return false;
        }

        return preg_match('/^SHOW\s+GRANTS\s+FOR\s+CURRENT_USER(?:\(\))?\s*;?\s*$/i', trim($sql)) === 1;
    }

    private function scanRuntimeSchemaMutations(string $relativePath, string $source, array &$violations): void
    {
        $mutations = '(?:createDatabase|dropDatabaseIfExists|table|create|drop|dropIfExists|dropColumns|dropAllTables|dropAllViews|dropAllTypes|rename|enableForeignKeyConstraints|disableForeignKeyConstraints|withoutForeignKeyConstraints|ensureVectorExtensionExists|ensureExtensionExists|whenTableHasColumn|whenTableDoesntHaveColumn|whenTableHasIndex|whenTableDoesntHaveIndex|getConnection|blueprintResolver)';
        $schemaFacade = preg_quote('Illuminate\\Support\\Facades\\Schema', '/');
        preg_match_all(
            '/\buse\s+'.$schemaFacade.'\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/',
            $source,
            $aliases,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($aliases as $alias) {
            $violations[] = sprintf(
                '%s:%d aliasing the Schema facade as %s is forbidden because runtime DDL attribution must remain syntax-stable.',
                $relativePath,
                $this->lineNumber($source, $alias[0][1]),
                $alias[1][0],
            );
        }

        $facadePatterns = [
            '/\bSchema\s*::\s*'.$mutations.'\s*\(/',
            '/'.preg_quote('\\Illuminate\\Support\\Facades\\Schema', '/').'\s*::\s*'.$mutations.'\s*\(/',
        ];
        foreach ($facadePatterns as $pattern) {
            preg_match_all($pattern, $source, $schemaCalls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($schemaCalls as $call) {
                $violations[] = sprintf(
                    '%s:%d runtime schema mutation or escape surface is forbidden because durable DDL ownership belongs to reviewed migrations.',
                    $relativePath,
                    $this->lineNumber($source, $call[0][1]),
                );
            }
        }

        preg_match_all(
            '/->\s*getSchemaBuilder\s*\(\s*\)\s*->\s*'.$mutations.'\s*\(/',
            $source,
            $directBuilders,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($directBuilders as $call) {
            $violations[] = sprintf(
                '%s:%d runtime schema mutation or escape surface is forbidden because durable DDL ownership belongs to reviewed migrations.',
                $relativePath,
                $this->lineNumber($source, $call[0][1]),
            );
        }

        preg_match_all(
            '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*[^;]*->\s*getSchemaBuilder\s*\(\s*\)\s*;/',
            $source,
            $builders,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($builders as $builder) {
            $variable = $builder[1][0];
            $assignmentOffset = $builder[0][1];
            $afterAssignment = $assignmentOffset + strlen($builder[0][0]);
            $functionEnd = $this->enclosingFunctionEnd($source, $assignmentOffset) ?? strlen($source);
            if ($functionEnd <= $afterAssignment) {
                continue;
            }
            $segment = substr($source, $afterAssignment, $functionEnd - $afterAssignment);
            $reassignmentPattern = '/'.preg_quote($variable, '/').'\s*=/';
            if (preg_match($reassignmentPattern, $segment, $reassignment, PREG_OFFSET_CAPTURE) === 1) {
                $segment = substr($segment, 0, $reassignment[0][1]);
            }
            if (preg_match('/'.preg_quote($variable, '/').'\s*->\s*'.$mutations.'\s*\(/', $segment) !== 1) {
                continue;
            }
            $violations[] = sprintf(
                '%s:%d deferred runtime schema mutation or escape through %s is forbidden because durable DDL ownership belongs to reviewed migrations.',
                $relativePath,
                $this->lineNumber($source, $assignmentOffset),
                $variable,
            );
        }
    }

    private function literalRawSql(string $source, int $offset): ?string
    {
        $tail = substr($source, $offset);
        if (preg_match(
            '/^\s*<<<\'([A-Za-z_][A-Za-z0-9_]*)\'\R(.*?)\R\1(?=\s*[,);])/s',
            $tail,
            $heredoc,
        ) === 1) {
            $sql = trim($heredoc[2]);

            return $sql === '' ? null : $sql;
        }

        $argument = $this->firstCallArgument($source, $offset);
        if (preg_match('/^([\'\"])(.*)\1$/s', $argument, $match) !== 1) {
            return null;
        }

        $sql = trim(stripcslashes($match[2]));

        return $sql === '' ? null : $sql;
    }

    private function firstCallArgument(string $source, int $offset): string
    {
        $length = strlen($source);
        $quote = null;
        $escaped = false;
        $depth = 0;
        $result = '';

        for ($index = $offset; $index < $length; $index++) {
            $char = $source[$index];
            if ($quote !== null) {
                $result .= $char;
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                $result .= $char;

                continue;
            }
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                $result .= $char;

                continue;
            }
            if ($char === ')' || $char === ']' || $char === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
                $result .= $char;

                continue;
            }
            if ($char === ',' && $depth === 0) {
                break;
            }
            $result .= $char;
        }

        return trim($result);
    }

    private function classifyRawStatement(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            return 'unknown';
        }

        if (preg_match('/^SET\b/i', $sql) === 1) {
            if (preg_match('/;\s*\S/s', $sql) === 1) {
                return 'unknown';
            }
            if (preg_match('/^SET\s+@(?!@)/i', $sql) !== 1
                || preg_match('/(?:@@\s*GLOBAL\.|\bGLOBAL\b|\bPERSIST\b)/i', $sql) === 1
            ) {
                return 'unknown';
            }

            return 'session';
        }

        if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|WITH|CALL|LOAD)\b/i', $sql) === 1) {
            return 'mutation';
        }

        if (preg_match('/^DROP\s+TRIGGER\s+(?:IF\s+EXISTS\s+)?[A-Za-z0-9_]+\s*;?\s*$/i', $sql) === 1) {
            return 'trigger_ddl';
        }

        if (preg_match('/^CREATE\s+TRIGGER\b/is', $sql) === 1) {
            return preg_match('/\bEND\s*;?\s*$/is', $sql) === 1 ? 'trigger_ddl' : 'ddl';
        }

        if (preg_match('/^(CREATE|ALTER|DROP|RENAME)\b/i', $sql) === 1) {
            return 'ddl';
        }

        return 'unknown';
    }

    private function consumeMigrationTriggerDdlHelper(string $relativePath): bool
    {
        $helpers = $this->config['migration_trigger_ddl_helpers'] ?? [];
        if (! is_array($helpers) || ! in_array($relativePath, $helpers, true)) {
            return false;
        }

        $this->usedMigrationTriggerDdlHelpers[$relativePath] = true;

        return true;
    }

    /** @return list<string> */
    private function migrationTriggerDdlHelperViolations(): array
    {
        $helpers = $this->config['migration_trigger_ddl_helpers'] ?? [];
        if (! is_array($helpers)) {
            return ['migration_trigger_ddl_helpers must be an exact list of migration-only Infrastructure helper paths.'];
        }

        $violations = [];
        $seen = [];
        foreach ($helpers as $helper) {
            if (! is_string($helper)
                || preg_match('#^app/Modules/[A-Za-z0-9_]+/Infrastructure/.+\.php$#', $helper) !== 1
            ) {
                $violations[] = 'migration_trigger_ddl_helpers contains an invalid non-exact helper path.';

                continue;
            }

            if (isset($seen[$helper])) {
                $violations[] = 'migration_trigger_ddl_helpers contains duplicate helper '.$helper.'.';

                continue;
            }
            $seen[$helper] = true;

            if (! isset($this->usedMigrationTriggerDdlHelpers[$helper])) {
                $violations[] = 'migration_trigger_ddl_helpers contains stale/unused helper '.$helper.'.';

                continue;
            }

            $class = pathinfo($helper, PATHINFO_FILENAME);
            $migrationReferenceFound = false;
            foreach ($this->phpFiles('database/migrations') as $migrationSource) {
                if (preg_match('/\b'.preg_quote($class, '/').'\b/', $migrationSource) === 1) {
                    $migrationReferenceFound = true;
                    break;
                }
            }
            if (! $migrationReferenceFound) {
                $violations[] = sprintf(
                    'migration-only trigger DDL helper %s is not referenced by a migration.',
                    $helper,
                );
            }

            foreach (['app/Modules', 'app/Shared', 'routes'] as $directory) {
                foreach ($this->phpFiles($directory) as $runtimePath => $source) {
                    if ($runtimePath === $helper) {
                        continue;
                    }

                    if (preg_match('/\b'.preg_quote($class, '/').'\b/', $source) === 1) {
                        $violations[] = sprintf(
                            'migration-only trigger DDL helper %s may not be referenced from runtime source %s.',
                            $helper,
                            $runtimePath,
                        );
                    }
                }
            }
        }

        return $violations;
    }

    private function sourceLayer(string $relativePath): ?string
    {
        if (preg_match('#^app/Modules/[^/]+/([^/]+)/#', $relativePath, $pathParts) === 1) {
            return $pathParts[1];
        }

        return null;
    }

    private function sameModuleLayerAllowed(string $sourceLayer, string $targetLayer): bool
    {
        return match ($sourceLayer) {
            'Domain' => $targetLayer === 'Domain',
            'Application' => in_array($targetLayer, ['Domain', 'Application'], true),
            'Infrastructure' => in_array($targetLayer, ['Domain', 'Application', 'Infrastructure'], true),
            'Presentation' => in_array($targetLayer, ['Domain', 'Application', 'Presentation'], true),
            default => true,
        };
    }

    private function isFrameworkReference(string $name): bool
    {
        return str_starts_with($name, 'Illuminate\\')
            || str_starts_with($name, 'Symfony\\')
            || str_starts_with($name, 'Monolog\\');
    }

    private function isEloquentReference(string $name): bool
    {
        return str_starts_with($name, 'Illuminate\\Database\\Eloquent\\');
    }

    /** @return array{string,string}|null */
    private function moduleReference(string $name): ?array
    {
        $parts = explode('\\', $name);
        if (count($parts) < 4 || $parts[0] !== 'App' || $parts[1] !== 'Modules') {
            return null;
        }

        $layer = $parts[3];
        if (! in_array($layer, ['Domain', 'Application', 'Infrastructure', 'Presentation'], true)) {
            return null;
        }

        return [$parts[2], $layer];
    }

    /** @return list<array{name:string,line:int}> */
    private function qualifiedNames(string $source): array
    {
        $names = [];
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;
            if (! in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }

            $name = ltrim($text, '\\');
            if (str_starts_with($name, 'namespace\\')) {
                $name = substr($name, strlen('namespace\\'));
            }

            $names[] = ['name' => $name, 'line' => $line];
        }

        return $names;
    }

    /** @param list<string> $violations */
    private function scanTelegramGenericDeliveryBoundary(string $relativePath, string $source, array &$violations): void
    {
        $restrictedMethods = [
            'fromReviewedSource' => [
                'app/Modules/Telegram/Application/NonRestrictedTelegramPresentation.php',
                'app/Modules/Telegram/Application/NonRestrictedTelegramPresentationFactory.php',
            ],
            'restorePersisted' => [
                'app/Modules/Telegram/Application/NonRestrictedTelegramPresentation.php',
                'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
            ],
        ];
        foreach ($restrictedMethods as $method => $allowedPaths) {
            if ($this->containsCodeTokenText($source, $method) && ! in_array($relativePath, $allowedPaths, true)) {
                $violations[] = sprintf(
                    '%s may not call or dynamically reference internal Telegram presentation method %s; use the reviewed source/factory boundary instead.',
                    $relativePath,
                    $method,
                );
            }
        }

        $symbols = [
            'NonRestrictedTelegramPresentation',
            'NonRestrictedTelegramPresentationFactory',
            'NonRestrictedTelegramPresentationSource',
            'TelegramDeliveryQueueService',
            'TelegramPresentationProvenanceGuard',
        ];
        $usesGenericBoundary = false;
        foreach ($symbols as $symbol) {
            if ($this->containsCodeTokenText($source, $symbol)) {
                $usesGenericBoundary = true;

                break;
            }
        }
        if (! $usesGenericBoundary) {
            return;
        }

        $internalPaths = [
            'app/Modules/Telegram/Application/NonRestrictedTelegramPresentation.php',
            'app/Modules/Telegram/Application/NonRestrictedTelegramPresentationFactory.php',
            'app/Modules/Telegram/Application/NonRestrictedTelegramPresentationSource.php',
            'app/Modules/Telegram/Application/TelegramDeliveryDatabaseCapability.php',
            'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
            'app/Modules/Telegram/Application/TelegramDeliveryRequestFingerprint.php',
            'app/Modules/Telegram/Application/TelegramDeliveryOutboxHandler.php',
            'app/Modules/Telegram/Infrastructure/HttpTelegramMutationTransport.php',
            'app/Modules/Telegram/Application/TelegramDeliveryQueueService.php',
            'app/Modules/Telegram/Application/TelegramConfidentialDeliveryQueue.php',
            'app/Modules/Telegram/Application/TelegramMutationRequest.php',
            'app/Modules/Telegram/Application/TelegramPresentationProvenanceGuard.php',
            'app/Modules/Telegram/Application/ConfidentialTelegramPresentation.php',
            'app/Modules/Telegram/Application/TelegramConfidentialPresentationHasher.php',
            'app/Modules/Telegram/Application/ConfidentialTelegramPresentationFactory.php',
            'app/Modules/Telegram/Application/ConfidentialTelegramPresentationSource.php',
            'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
            'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationDatabaseCapability.php',
            'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1.php',
            'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
            'app/Modules/Telegram/Application/TelegramResolvedConfidentialPresentation.php',
            'app/Modules/Telegram/Application/TelegramConfidentialDeliveryOutboxHandler.php',
            'app/Modules/Telegram/Infrastructure/TelegramServiceProvider.php',
        ];
        if (in_array($relativePath, $internalPaths, true)) {
            return;
        }

        if (! str_starts_with($relativePath, 'app/Modules/Telegram/')) {
            $violations[] = sprintf(
                '%s generic non-restricted Telegram delivery is Telegram-owned and cannot be consumed by another module, Shared code, or routes; use the owning protected/reference delivery authority for RESTRICTED data.',
                $relativePath,
            );

            return;
        }

        $sources = $this->config['telegram_non_restricted_presentation_sources'] ?? [];
        if (! is_array($sources) || ! in_array($relativePath, $sources, true)) {
            $violations[] = sprintf(
                '%s generic non-restricted Telegram delivery source is not explicitly reviewed; add the exact Telegram Application/Presentation source path only after data-classification review.',
                $relativePath,
            );

            return;
        }

        $this->usedTelegramPresentationSources[$relativePath] = true;
    }

    /** @param list<string> $violations */
    private function scanTelegramConfidentialDeliveryBoundary(string $relativePath, string $source, array &$violations): void
    {
        $restrictedMethods = [
            'fromReviewedConfidentialSource' => [
                'app/Modules/Telegram/Application/ConfidentialTelegramPresentation.php',
                'app/Modules/Telegram/Application/ConfidentialTelegramPresentationFactory.php',
            ],
            'keyedHash' => [
                'app/Modules/Telegram/Application/ConfidentialTelegramPresentation.php',
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationHasher.php',
            ],
            'revealConfidentialText' => [
                'app/Modules/Telegram/Application/ConfidentialTelegramPresentation.php',
                'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
                'app/Modules/Telegram/Infrastructure/HttpTelegramMutationTransport.php',
            ],
            'restoreDecrypted' => [
                'app/Modules/Telegram/Application/ConfidentialTelegramPresentation.php',
                'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
            ],
        ];
        foreach ($restrictedMethods as $method => $allowedPaths) {
            if ($this->containsCodeTokenText($source, $method) && ! in_array($relativePath, $allowedPaths, true)) {
                $violations[] = sprintf(
                    '%s may not call or dynamically reference internal confidential Telegram presentation method %s.',
                    $relativePath,
                    $method,
                );
            }
        }

        $restrictedInternalSymbols = [
            'TelegramConfidentialPresentationHasher' => [
                'app/Modules/Telegram/Application/ConfidentialTelegramPresentation.php',
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationHasher.php',
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
                'app/Modules/Telegram/Infrastructure/TelegramServiceProvider.php',
            ],
            'TelegramDeliveryConfidentialPresentationService' => [
                'app/Modules/Telegram/Application/ConfidentialTelegramPresentation.php',
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationDatabaseCapability.php',
                'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
                'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
                'app/Modules/Telegram/Application/TelegramDeliveryQueueService.php',
            ],
            'TelegramDeliveryConfidentialPresentationDatabaseCapability' => [
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationDatabaseCapability.php',
                'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
            ],
            'TelegramResolvedConfidentialPresentation' => [
                'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
                'app/Modules/Telegram/Application/TelegramResolvedConfidentialPresentation.php',
            ],
            'TelegramMutationTransport' => [
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Application/Contracts/TelegramMutationTransport.php',
                'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
                'app/Modules/Telegram/Infrastructure/HttpTelegramMutationTransport.php',
                'app/Modules/Telegram/Infrastructure/TelegramServiceProvider.php',
            ],
            'HttpTelegramMutationTransport' => [
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Infrastructure/HttpTelegramMutationTransport.php',
                'app/Modules/Telegram/Infrastructure/TelegramServiceProvider.php',
            ],
            'TelegramMutationRequest' => [
                'app/Modules/Telegram/Application/Contracts/TelegramMutationTransport.php',
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Application/TelegramDeliveryDatabaseCapability.php',
                'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
                'app/Modules/Telegram/Application/TelegramDeliveryQueueService.php',
                'app/Modules/Telegram/Application/TelegramDeliveryRequestFingerprint.php',
                'app/Modules/Telegram/Application/TelegramMutationRequest.php',
                'app/Modules/Telegram/Application/TelegramPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Infrastructure/HttpTelegramMutationTransport.php',
            ],
            'TelegramDeliveryOperationExecutor' => [
                'app/Modules/Telegram/Application/NonRestrictedTelegramPresentation.php',
                'app/Modules/Telegram/Application/TelegramConfidentialDeliveryOutboxHandler.php',
                'app/Modules/Telegram/Infrastructure/TelegramServiceProvider.php',
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
                'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
                'app/Modules/Telegram/Application/TelegramDeliveryOutboxHandler.php',
                'app/Modules/Telegram/Application/TelegramInteractiveDeliveryOutboxHandler.php',
                'app/Modules/Telegram/Application/TelegramPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Infrastructure/TelegramServiceProvider.php',
            ],
            'TelegramConfidentialDeliveryOutboxHandler' => [
                'app/Modules/Telegram/Application/TelegramConfidentialDeliveryOutboxHandler.php',
                'app/Modules/Telegram/Infrastructure/TelegramServiceProvider.php',
                'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
                'app/Modules/Telegram/Infrastructure/TelegramServiceProvider.php',
            ],
        ];
        foreach ($restrictedInternalSymbols as $symbol => $allowedPaths) {
            if ($this->containsCodeTokenText($source, $symbol) && ! in_array($relativePath, $allowedPaths, true)) {
                $violations[] = sprintf(
                    '%s may not reference internal confidential Telegram authority symbol %s.',
                    $relativePath,
                    $symbol,
                );
            }
        }

        $symbols = [
            'ConfidentialTelegramPresentation',
            'ConfidentialTelegramPresentationFactory',
            'ConfidentialTelegramPresentationSource',
            'TelegramConfidentialPresentationProvenanceGuard',
            'TelegramConfidentialPresentationHasher',
            'queueConfidential',
        ];
        $usesBoundary = false;
        foreach ($symbols as $symbol) {
            if ($this->containsCodeTokenText($source, $symbol)) {
                $usesBoundary = true;
                break;
            }
        }
        if (! $usesBoundary) {
            return;
        }

        $internalPaths = [
            'app/Modules/Telegram/Application/ConfidentialTelegramPresentation.php',
            'app/Modules/Telegram/Application/TelegramConfidentialPresentationHasher.php',
            'app/Modules/Telegram/Application/ConfidentialTelegramPresentationFactory.php',
            'app/Modules/Telegram/Application/ConfidentialTelegramPresentationSource.php',
            'app/Modules/Telegram/Application/TelegramConfidentialPresentationProvenanceGuard.php',
            'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationDatabaseCapability.php',
            'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1.php',
            'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationService.php',
            'app/Modules/Telegram/Application/TelegramDeliveryDatabaseCapability.php',
            'app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php',
            'app/Modules/Telegram/Application/TelegramDeliveryRequestFingerprint.php',
            'app/Modules/Telegram/Application/TelegramDeliveryQueueService.php',
            'app/Modules/Telegram/Application/TelegramConfidentialDeliveryQueue.php',
            'app/Modules/Telegram/Infrastructure/HttpTelegramMutationTransport.php',
            'app/Modules/Telegram/Application/TelegramMutationRequest.php',
            'app/Modules/Telegram/Application/TelegramResolvedConfidentialPresentation.php',
            'app/Modules/Telegram/Application/TelegramConfidentialDeliveryOutboxHandler.php',
            'app/Modules/Telegram/Infrastructure/TelegramServiceProvider.php',
        ];
        if (in_array($relativePath, $internalPaths, true)) {
            return;
        }

        if (! str_starts_with($relativePath, 'app/Modules/Telegram/')) {
            $violations[] = sprintf(
                '%s confidential Telegram delivery is Telegram-owned and cannot be consumed by another module, Shared code, or routes.',
                $relativePath,
            );

            return;
        }

        $sources = $this->config['telegram_confidential_presentation_sources'] ?? [];
        if (! is_array($sources) || ! in_array($relativePath, $sources, true)) {
            $violations[] = sprintf(
                '%s confidential Telegram delivery source is not explicitly reviewed; add the exact Telegram Application/Presentation source path only after data-classification review.',
                $relativePath,
            );

            return;
        }

        $this->usedTelegramConfidentialPresentationSources[$relativePath] = true;
    }

    private function containsCodeTokenText(string $source, string $needle): bool
    {
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                continue;
            }

            [$id, $text] = $token;
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function telegramPresentationSourceViolations(): array
    {
        $sources = $this->config['telegram_non_restricted_presentation_sources'] ?? [];
        if (! is_array($sources)) {
            return ['telegram_non_restricted_presentation_sources must be an exact list of reviewed Telegram source paths.'];
        }

        $violations = [];
        $seen = [];
        foreach ($sources as $source) {
            if (! is_string($source)
                || preg_match('#^app/Modules/Telegram/(?:Application|Presentation)/.+\.php$#', $source) !== 1
            ) {
                $violations[] = 'telegram_non_restricted_presentation_sources contains an invalid or non-Telegram source path.';

                continue;
            }

            if (isset($seen[$source])) {
                $violations[] = 'telegram_non_restricted_presentation_sources contains duplicate entry '.$source.'.';

                continue;
            }
            $seen[$source] = true;

            if (! isset($this->usedTelegramPresentationSources[$source])) {
                $violations[] = 'telegram_non_restricted_presentation_sources contains stale/unused entry '.$source.'.';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function telegramConfidentialPresentationSourceViolations(): array
    {
        $sources = $this->config['telegram_confidential_presentation_sources'] ?? [];
        if (! is_array($sources)) {
            return ['telegram_confidential_presentation_sources must be an exact list of reviewed Telegram source paths.'];
        }

        $violations = [];
        $seen = [];
        foreach ($sources as $source) {
            if (! is_string($source)
                || preg_match('#^app/Modules/Telegram/(?:Application|Presentation)/.+\.php$#', $source) !== 1
            ) {
                $violations[] = 'telegram_confidential_presentation_sources contains an invalid or non-Telegram source path.';

                continue;
            }
            if (isset($seen[$source])) {
                $violations[] = 'telegram_confidential_presentation_sources contains duplicate entry '.$source.'.';

                continue;
            }
            $seen[$source] = true;
            if (! isset($this->usedTelegramConfidentialPresentationSources[$source])) {
                $violations[] = 'telegram_confidential_presentation_sources contains stale/unused entry '.$source.'.';
            }
        }

        return $violations;
    }

    private function consumeModuleDependencyReferenceException(string $relativePath, string $reference): bool
    {
        $key = $relativePath.'|'.$reference;
        $exceptions = $this->config['module_dependency_reference_exceptions'] ?? [];
        if (! is_array($exceptions) || ! in_array($key, $exceptions, true)) {
            return false;
        }

        $this->usedModuleDependencyReferenceExceptions[$key] = true;

        return true;
    }

    /** @return list<string> */
    private function moduleDependencyReferenceExceptionViolations(): array
    {
        $exceptions = $this->config['module_dependency_reference_exceptions'] ?? [];
        if (! is_array($exceptions)) {
            return ['module_dependency_reference_exceptions must be an exact list of source-path|Application-symbol entries.'];
        }

        $violations = [];
        $seen = [];
        foreach ($exceptions as $exception) {
            if (! is_string($exception)
                || preg_match('#^app/Modules/[A-Za-z0-9]+/Application/.+\.php\|App\\\\Modules\\\\[A-Za-z0-9]+\\\\Application\\\\.+$#', $exception) !== 1
            ) {
                $violations[] = 'module_dependency_reference_exceptions contains an invalid non-exact Application boundary entry.';

                continue;
            }
            if (isset($seen[$exception])) {
                $violations[] = 'module_dependency_reference_exceptions contains duplicate entry '.$exception.'.';

                continue;
            }
            $seen[$exception] = true;
            if (! isset($this->usedModuleDependencyReferenceExceptions[$exception])) {
                $violations[] = 'module_dependency_reference_exceptions contains stale/unused entry '.$exception.'.';
            }
        }

        return $violations;
    }

    private function allowedDependency(string $sourceModule, string $targetModule): bool
    {
        $dependencyMap = $this->config['allowed_module_dependencies'] ?? [];
        if (! is_array($dependencyMap)) {
            return false;
        }
        $allowed = $dependencyMap[$sourceModule] ?? [];

        return is_array($allowed) && in_array($targetModule, $allowed, true);
    }

    private function durableTableOwner(string $table): ?string
    {
        $owners = $this->config['durable_table_owners'] ?? [];
        if (! is_array($owners)) {
            return null;
        }

        $owner = $owners[$table] ?? null;

        return is_string($owner) && trim($owner) !== '' ? $owner : null;
    }

    private function sourcePersistenceOwner(string $relativePath): ?string
    {
        if (preg_match('#^app/Modules/([^/]+)/#', $relativePath, $pathParts) === 1) {
            return $pathParts[1];
        }

        if (str_starts_with($relativePath, 'app/Shared/')) {
            return 'Shared';
        }

        return null;
    }

    private function consumePersistenceException(string $relativePath, string $table): bool
    {
        $key = $relativePath.'|'.$table;
        $exceptions = $this->config['persistence_exceptions'] ?? [];
        if (! is_array($exceptions) || ! in_array($key, $exceptions, true)) {
            return false;
        }

        $this->usedPersistenceExceptions[$key] = true;

        return true;
    }

    /** @return list<string> */
    private function persistenceExceptionViolations(): array
    {
        $exceptions = $this->config['persistence_exceptions'] ?? [];
        if (! is_array($exceptions)) {
            return ['persistence_exceptions must be an exact list of source-path|table entries.'];
        }

        $violations = [];
        $seen = [];
        foreach ($exceptions as $exception) {
            if (! is_string($exception)
                || preg_match('#^(?:app/(?:Modules|Shared)/|routes/).+\.php\|[A-Za-z0-9_]+$#', $exception) !== 1
            ) {
                $violations[] = 'persistence_exceptions contains an invalid non-exact entry.';

                continue;
            }

            if (isset($seen[$exception])) {
                $violations[] = 'persistence_exceptions contains duplicate entry '.$exception.'.';

                continue;
            }
            $seen[$exception] = true;

            if (! isset($this->usedPersistenceExceptions[$exception])) {
                $violations[] = 'persistence_exceptions contains stale/unused entry '.$exception.'.';
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function applicationPrivateTableViolations(): array
    {
        $tables = $this->config['application_private_tables'] ?? [];
        if (! is_array($tables)) {
            return ['application_private_tables must be a list of exact durable table names.'];
        }

        $violations = [];
        $seen = [];
        foreach ($tables as $table) {
            if (! is_string($table) || preg_match('/\A[A-Za-z0-9_]+\z/', $table) !== 1) {
                $violations[] = 'application_private_tables contains an invalid table name.';

                continue;
            }
            if (isset($seen[$table])) {
                $violations[] = 'application_private_tables contains duplicate entry '.$table.'.';

                continue;
            }
            $seen[$table] = true;

            $owner = $this->durableTableOwner($table);
            if ($owner === null || in_array($owner, ['Framework', 'Shared', 'SharedAppendOnly'], true)) {
                $violations[] = 'application_private_tables entry '.$table.' must map to one feature-module durable owner.';
            }
        }

        return $violations;
    }

    private function mutationMethod(string $statement): ?string
    {
        if (preg_match(
            '/->\s*(insert|insertGetId|insertOrIgnore|insertOrIgnoreReturning|insertUsing|insertOrIgnoreUsing|update|updateFrom|delete|upsert|updateOrInsert|increment|incrementEach|decrement|decrementEach|truncate)\s*\(/',
            $statement,
            $match,
        ) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * @param  list<string>  $edges
     * @return list<string>
     */
    private function cycleViolations(array $edges): array
    {
        $graph = [];
        foreach ($edges as $edge) {
            [$source, $target] = explode('>', $edge, 2);
            $graph[$source][] = $target;
            $graph[$target] ??= [];
        }

        $index = 0;
        $stack = [];
        $onStack = [];
        $indices = [];
        $low = [];
        $components = [];

        $visit = function (string $node) use (&$visit, &$graph, &$index, &$stack, &$onStack, &$indices, &$low, &$components): void {
            $indices[$node] = $index;
            $low[$node] = $index;
            $index++;
            $stack[] = $node;
            $onStack[$node] = true;

            foreach (array_values(array_unique($graph[$node] ?? [])) as $next) {
                if (! array_key_exists($next, $indices)) {
                    $visit($next);
                    $low[$node] = min($low[$node], $low[$next]);
                } elseif ($onStack[$next] ?? false) {
                    $low[$node] = min($low[$node], $indices[$next]);
                }
            }

            if ($low[$node] !== $indices[$node]) {
                return;
            }

            $component = [];
            do {
                $member = array_pop($stack);
                if (! is_string($member)) {
                    throw new RuntimeException('Architecture cycle stack is inconsistent.');
                }
                $onStack[$member] = false;
                $component[] = $member;
            } while ($member !== $node);

            sort($component, SORT_STRING);
            $components[] = $component;
        };

        foreach (array_keys($graph) as $node) {
            if (! array_key_exists($node, $indices)) {
                $visit($node);
            }
        }

        $exceptions = $this->config['cycle_exceptions'] ?? [];
        $violations = [];
        foreach ($components as $component) {
            if (count($component) < 2) {
                continue;
            }
            $key = implode('|', $component);
            if (is_array($exceptions) && in_array($key, $exceptions, true)) {
                continue;
            }
            $violations[] = 'Module dependency cycle detected: '.implode(' <-> ', $component).'.';
        }

        return $violations;
    }

    /** @return array<string,string> */
    private function phpFiles(string $relativeDirectory): array
    {
        $directory = $this->root.'/'.$relativeDirectory;
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $absolutePath = $file->getPathname();
            $relativePath = ltrim(str_replace($this->root, '', $absolutePath), DIRECTORY_SEPARATOR);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $source = file_get_contents($absolutePath);
            if (! is_string($source)) {
                throw new RuntimeException('Unable to read architecture source: '.$relativePath);
            }
            $files[$relativePath] = $source;
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function statementLength(string $source, int $offset): int
    {
        $semicolon = strpos($source, ';', $offset);
        if ($semicolon === false) {
            return min(5000, strlen($source) - $offset);
        }

        return min(5000, $semicolon - $offset + 1);
    }

    private function lineNumber(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }
}
