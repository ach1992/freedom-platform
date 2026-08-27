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

        foreach ($this->phpFiles('app/Modules') as $relativePath => $source) {
            $this->scanModuleFile($relativePath, $source, $violations, $edges);
            $this->scanPersistence($relativePath, $source, $violations);
            $this->scanOpaquePersistence($relativePath, $source, $violations);
        }

        foreach ($this->phpFiles('app/Shared') as $relativePath => $source) {
            $this->scanSharedFile($relativePath, $source, $violations);
            $this->scanPersistence($relativePath, $source, $violations);
            $this->scanOpaquePersistence($relativePath, $source, $violations);
        }

        foreach ($this->phpFiles('routes') as $relativePath => $source) {
            $this->scanPersistence($relativePath, $source, $violations);
            $this->scanOpaquePersistence($relativePath, $source, $violations);
        }

        $edges = array_values(array_unique($edges));
        sort($edges, SORT_STRING);
        array_push($violations, ...$this->cycleViolations($edges));
        array_push($violations, ...$this->persistenceExceptionViolations());
        array_push($violations, ...$this->migrationTriggerDdlHelperViolations());

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
        $this->scanDeferredTableMutations($relativePath, $source, $violations);
        $this->scanDynamicTableMutations($relativePath, $source, $violations);

        preg_match_all(
            '/(?:DB::|->)table\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',
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
        if ($owner === null || $sourceOwner === null || $owner === $sourceOwner) {
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
    private function scanDynamicTableMutations(string $relativePath, string $source, array &$violations): void
    {
        preg_match_all(
            '/(?:\bDB::|->)\s*table\(\s*(?![\'\"])([^)]*)\)/',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $offset = $match[0][1];
            $statement = substr($source, $offset, $this->statementLength($source, $offset));
            $mutation = $this->mutationMethod($statement);
            if ($mutation === null) {
                continue;
            }

            $tables = $this->boundedDynamicTables($source, $offset, trim($match[1][0]));
            if ($tables !== null && $this->boundedTablesAreAllowed($relativePath, $tables, $mutation)) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d dynamic table mutation is forbidden because durable-table ownership cannot be attributed; use a literal or statically bounded reviewed table boundary.',
                $relativePath,
                $this->lineNumber($source, $offset),
            );
        }
    }

    /** @return list<string>|null */
    private function boundedDynamicTables(string $source, int $offset, string $expression): ?array
    {
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $variableMatch) !== 1) {
            return null;
        }

        $variable = $variableMatch[1];
        $prefix = substr($source, 0, $offset);
        $pattern = '/foreach\s*\(\s*\[((?:\s*[\'\"][A-Za-z0-9_]+[\'\"]\s*,)*\s*[\'\"][A-Za-z0-9_]+[\'\"]\s*,?\s*)\]\s+as\s+\$'.preg_quote($variable, '/').'\s*\)/s';
        preg_match_all($pattern, $prefix, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($matches === []) {
            return null;
        }

        $match = $matches[array_key_last($matches)];
        $fullMatch = $match[0][0];
        $fullOffset = $match[0][1];
        $lastAs = strrpos($prefix, 'as $'.$variable);
        if ($lastAs === false || $lastAs < $fullOffset || $lastAs > $fullOffset + strlen($fullMatch)) {
            return null;
        }

        $between = substr($prefix, $fullOffset + strlen($fullMatch));
        if (preg_match('/\$'.preg_quote($variable, '/').'\s*=/', $between) === 1) {
            return null;
        }

        preg_match_all('/[\'\"]([A-Za-z0-9_]+)[\'\"]/', $match[1][0], $tableMatches);
        $tables = array_values(array_unique($tableMatches[1] ?? []));

        return $tables === [] ? null : $tables;
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
            '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*[^;]*?(?:DB::|->)table\(\s*[^)]*\)[^;]*;/',
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
                '/'.preg_quote($variable, '/').'\s*->\s*(insert|insertGetId|insertOrIgnore|update|delete|upsert|updateOrInsert|increment|decrement|truncate)\s*\(/',
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
            return preg_match('/;\s*\S/s', $sql) === 1 ? 'unknown' : 'session';
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

    private function mutationMethod(string $statement): ?string
    {
        if (preg_match(
            '/->\s*(insert|insertGetId|insertOrIgnore|update|delete|upsert|updateOrInsert|increment|decrement|truncate)\s*\(/',
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
