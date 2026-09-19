<?php

declare(strict_types=1);

namespace FreedomPlatform\CI;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ArchitectureBoundaryChecker
{
    private const PERSISTENCE_MUTATION_METHODS = [
        'insert',
        'insertGetId',
        'insertOrIgnore',
        'insertOrIgnoreReturning',
        'insertUsing',
        'insertOrIgnoreUsing',
        'update',
        'updateFrom',
        'delete',
        'upsert',
        'updateOrInsert',
        'increment',
        'incrementEach',
        'decrement',
        'decrementEach',
        'truncate',
    ];

    private const RAW_SQL_METHODS = [
        'affectingStatement',
        'cursor',
        'delete',
        'insert',
        'scalar',
        'select',
        'selectFromWriteConnection',
        'selectOne',
        'selectResultSets',
        'statement',
        'unprepared',
        'update',
    ];

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

        foreach ($this->persistenceMethodInvocations($source, ['table', 'from']) as $call) {
            $table = $this->literalTableName($call['argument']);
            if ($table === null) {
                continue;
            }

            $offset = $call['offset'];
            $mutation = $this->chainedMutationMethod($source, $call['end_offset']);
            if ($mutation === null) {
                continue;
            }

            $this->recordTableMutationViolation($relativePath, $source, $offset, $table, $mutation, $violations);
        }
    }

    /** @param list<string> $violations */
    private function scanApplicationPrivateTableReferences(string $relativePath, string $source, array &$violations): void
    {
        $sourceOwner = $this->sourcePersistenceOwner($relativePath);

        foreach ($this->persistenceMethodInvocations($source, ['table', 'from']) as $call) {
            if ($this->isConsoleTableRenderer($relativePath, $source, $call['receiver'])) {
                continue;
            }

            $tables = $this->attributedTablesForInvocation($source, $call);
            if ($tables === null) {
                continue;
            }

            foreach ($tables as $table) {
                $this->recordApplicationPrivateTableReference(
                    $relativePath,
                    $source,
                    $call['offset'],
                    $sourceOwner,
                    $table,
                    $violations,
                );
            }
        }

        foreach ($this->rawSqlPersistenceInvocations($source) as $call) {
            $sql = $this->literalRawSqlArgument($call['argument']);
            if ($sql === null) {
                continue;
            }

            foreach ($this->applicationPrivateTables() as $table) {
                if (! $this->rawSqlReferencesTable($sql, $table)) {
                    continue;
                }

                $this->recordApplicationPrivateTableReference(
                    $relativePath,
                    $source,
                    $call['offset'],
                    $sourceOwner,
                    $table,
                    $violations,
                );
            }
        }
    }

    /**
     * @param  array{method:string,offset:int,end_offset:int,argument:string,receiver:?string}  $call
     * @return list<string>|null
     */
    private function attributedTablesForInvocation(string $source, array $call): ?array
    {
        $expression = trim($call['argument']);
        $literal = $this->literalTableName($expression);
        if ($literal !== null) {
            return [$literal];
        }

        $bounded = $this->boundedDynamicTables($source, $call['offset'], $expression);
        if ($bounded !== null) {
            return $bounded;
        }

        $assigned = $this->simpleAssignedLiteralTable($source, $call['offset'], $expression);

        return $assigned === null ? null : [$assigned];
    }

    /** @param list<string> $violations */
    private function recordApplicationPrivateTableReference(
        string $relativePath,
        string $source,
        int $offset,
        ?string $sourceOwner,
        string $table,
        array &$violations,
    ): void {
        $owner = $this->applicationPrivateTableOwner($table);
        if ($owner === null || $owner === $sourceOwner) {
            return;
        }

        $violations[] = sprintf(
            '%s:%d durable table %s is private to the %s Application boundary; cross-module direct persistence access is forbidden.',
            $relativePath,
            $this->lineNumber($source, $offset),
            $table,
            $owner,
        );
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
        foreach ($this->persistenceMethodInvocations($source, ['table', 'from']) as $call) {
            $offset = $call['offset'];
            if ($this->isConsoleTableRenderer($relativePath, $source, $call['receiver'])) {
                continue;
            }

            $expression = trim($call['argument']);
            if ($this->literalTableName($expression) !== null) {
                continue;
            }

            $mutation = $this->chainedMutationMethod($source, $call['end_offset']);
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

            if ($mutation === null) {
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
        $expression = $this->phpExpressionWithoutTrivia($expression);
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
        $expression = $this->phpExpressionWithoutTrivia($expression);
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

    /**
     * @param  list<string>  $methods
     * @return list<array{method:string,offset:int,end_offset:int,argument:string,receiver:?string}>
     */
    private function persistenceMethodInvocations(string $source, array $methods, bool $allowDbStatic = true): array
    {
        $methodMap = [];
        foreach ($methods as $method) {
            $methodMap[strtolower($method)] = $method;
        }

        $tokens = $this->sourceTokens($source);
        $imports = $this->classImportMap($source);
        $calls = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index]['token'];
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $methodKey = strtolower($token[1]);
            if (! array_key_exists($methodKey, $methodMap)) {
                continue;
            }

            $operatorIndex = $this->previousSignificantTokenIndex($tokens, $index - 1);
            if ($operatorIndex === null) {
                continue;
            }
            $operator = $tokens[$operatorIndex]['token'];
            $isObjectCall = is_array($operator)
                && in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
            $isStaticCall = is_array($operator) && $operator[0] === T_DOUBLE_COLON;
            if (! $isObjectCall && ! $isStaticCall) {
                continue;
            }

            $receiver = $this->receiverExpressionAtOperator($tokens, $operatorIndex);
            if ($isStaticCall) {
                if (! $allowDbStatic || $receiver === null) {
                    continue;
                }
                $staticReceiver = ltrim($receiver, '\\');
                $resolvedReceiver = $this->resolveImportedClassReference($staticReceiver, $imports);
                $segments = explode('\\', $staticReceiver);
                if (strcasecmp($resolvedReceiver ?? '', 'Illuminate\\Support\\Facades\\DB') !== 0
                    && strtoupper((string) end($segments)) !== 'DB'
                ) {
                    continue;
                }
                $receiver = 'DB';
            }

            $openParenIndex = $this->nextSignificantTokenIndex($tokens, $index + 1);
            if ($openParenIndex === null || $tokens[$openParenIndex]['token'] !== '(') {
                continue;
            }
            $closeParenIndex = $this->matchingCloseParenIndex($tokens, $openParenIndex);
            if ($closeParenIndex === null) {
                continue;
            }

            $calls[] = [
                'method' => $methodMap[$methodKey],
                'offset' => $tokens[$operatorIndex]['offset'],
                'end_offset' => $tokens[$closeParenIndex]['offset'] + 1,
                'argument' => $this->firstCallArgumentBetween($source, $tokens, $openParenIndex, $closeParenIndex),
                'receiver' => $receiver,
            ];
        }

        return $calls;
    }

    /**
     * @param  list<array{token:array|string,offset:int}>  $tokens
     */
    private function receiverExpressionAtOperator(array $tokens, int $operatorIndex): ?string
    {
        $receiverIndex = $this->previousSignificantTokenIndex($tokens, $operatorIndex - 1);

        return $receiverIndex === null ? null : $this->receiverExpressionEndingAt($tokens, $receiverIndex);
    }

    /**
     * @param  list<array{token:array|string,offset:int}>  $tokens
     */
    private function receiverExpressionEndingAt(array $tokens, int $index): ?string
    {
        $token = $tokens[$index]['token'];
        if (is_array($token)) {
            if ($token[0] === T_VARIABLE) {
                return $token[1];
            }

            if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                return null;
            }

            $name = $token[1];
            $operatorIndex = $this->previousSignificantTokenIndex($tokens, $index - 1);
            if ($operatorIndex === null) {
                return $name;
            }
            $operator = $tokens[$operatorIndex]['token'];
            if (! is_array($operator)
                || ! in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            ) {
                return $name;
            }

            $baseIndex = $this->previousSignificantTokenIndex($tokens, $operatorIndex - 1);
            if ($baseIndex === null) {
                return null;
            }
            $base = $this->receiverExpressionEndingAt($tokens, $baseIndex);
            if ($base === null) {
                return null;
            }

            return $base.($operator[0] === T_DOUBLE_COLON ? '::' : '->').$name;
        }

        if ($token !== ')') {
            return null;
        }

        $openParenIndex = $this->matchingOpenParenIndex($tokens, $index);
        if ($openParenIndex === null) {
            return null;
        }
        if ($this->isTransparentGroupingClose($tokens, $index)) {
            $innerIndex = $this->previousSignificantTokenIndex($tokens, $index - 1);
            if ($innerIndex === null || $innerIndex <= $openParenIndex) {
                return null;
            }

            return $this->receiverExpressionEndingAt($tokens, $innerIndex);
        }

        $methodIndex = $this->previousSignificantTokenIndex($tokens, $openParenIndex - 1);
        if ($methodIndex === null) {
            return null;
        }
        $methodToken = $tokens[$methodIndex]['token'];
        if (! is_array($methodToken) || $methodToken[0] !== T_STRING) {
            return null;
        }
        $operatorIndex = $this->previousSignificantTokenIndex($tokens, $methodIndex - 1);
        if ($operatorIndex === null) {
            return null;
        }
        $operator = $tokens[$operatorIndex]['token'];
        if (! is_array($operator)
            || ! in_array($operator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
        ) {
            return null;
        }
        $baseIndex = $this->previousSignificantTokenIndex($tokens, $operatorIndex - 1);
        if ($baseIndex === null) {
            return null;
        }
        $base = $this->receiverExpressionEndingAt($tokens, $baseIndex);
        if ($base === null) {
            return null;
        }

        return $base.($operator[0] === T_DOUBLE_COLON ? '::' : '->').$methodToken[1].'()';
    }

    /**
     * @param  list<array{token:array|string,offset:int}>  $tokens
     */
    private function matchingOpenParenIndex(array $tokens, int $closeParenIndex): ?int
    {
        $depth = 0;
        for ($index = $closeParenIndex; $index >= 0; $index--) {
            $token = $tokens[$index]['token'];
            if ($token === ')') {
                $depth++;
            } elseif ($token === '(') {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{token:array|string,offset:int}>  $tokens
     */
    private function matchingCloseParenIndex(array $tokens, int $openParenIndex): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($index = $openParenIndex; $index < $count; $index++) {
            $token = $tokens[$index]['token'];
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{token:array|string,offset:int}>  $tokens
     */
    private function firstCallArgumentBetween(string $source, array $tokens, int $openParenIndex, int $closeParenIndex): string
    {
        $start = $tokens[$openParenIndex]['offset'] + 1;
        $end = $tokens[$closeParenIndex]['offset'];
        $depth = 0;

        for ($index = $openParenIndex + 1; $index < $closeParenIndex; $index++) {
            $token = $tokens[$index]['token'];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                if ($depth > 0) {
                    $depth--;
                }
            } elseif ($token === ',' && $depth === 0) {
                $end = $tokens[$index]['offset'];
                break;
            }
        }

        return trim(substr($source, $start, $end - $start));
    }

    private function chainedMutationMethod(string $source, int $offset): ?string
    {
        $methodMap = [];
        foreach (self::PERSISTENCE_MUTATION_METHODS as $method) {
            $methodMap[strtolower($method)] = $method;
        }

        $tokens = $this->sourceTokens($source);
        $index = $this->tokenIndexAtOrAfterOffset($tokens, $offset);
        if ($index === null) {
            return null;
        }

        while (true) {
            $operatorIndex = $this->nextFluentOperatorIndex($tokens, $index);
            if ($operatorIndex === null) {
                return null;
            }

            $methodIndex = $this->nextSignificantTokenIndex($tokens, $operatorIndex + 1);
            if ($methodIndex === null) {
                return null;
            }
            $methodToken = $tokens[$methodIndex]['token'];
            if (! is_array($methodToken) || $methodToken[0] !== T_STRING) {
                return null;
            }

            $openParenIndex = $this->nextSignificantTokenIndex($tokens, $methodIndex + 1);
            if ($openParenIndex === null || $tokens[$openParenIndex]['token'] !== '(') {
                return null;
            }
            $closeParenIndex = $this->matchingCloseParenIndex($tokens, $openParenIndex);
            if ($closeParenIndex === null) {
                return null;
            }

            $methodKey = strtolower($methodToken[1]);
            if (array_key_exists($methodKey, $methodMap)) {
                return $methodMap[$methodKey];
            }

            $index = $closeParenIndex + 1;
        }
    }

    /** @param list<array{token:array|string,offset:int}> $tokens */
    private function nextFluentOperatorIndex(array $tokens, int $index): ?int
    {
        while (true) {
            $nextIndex = $this->nextSignificantTokenIndex($tokens, $index);
            if ($nextIndex === null) {
                return null;
            }

            $token = $tokens[$nextIndex]['token'];
            if (is_array($token)
                && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            ) {
                return $nextIndex;
            }

            if ($token !== ')' || ! $this->isTransparentGroupingClose($tokens, $nextIndex)) {
                return null;
            }

            $index = $nextIndex + 1;
        }
    }

    /** @param list<array{token:array|string,offset:int}> $tokens */
    private function isTransparentGroupingClose(array $tokens, int $closeParenIndex): bool
    {
        $openParenIndex = $this->matchingOpenParenIndex($tokens, $closeParenIndex);
        if ($openParenIndex === null) {
            return false;
        }

        $beforeOpenIndex = $this->previousSignificantTokenIndex($tokens, $openParenIndex - 1);
        if ($beforeOpenIndex === null) {
            return true;
        }

        return ! $this->tokenCanEndCallableExpression($tokens[$beforeOpenIndex]['token']);
    }

    private function tokenCanEndCallableExpression(array|string $token): bool
    {
        if (! is_array($token)) {
            return in_array($token, [')', ']'], true);
        }

        return in_array($token[0], [
            T_STRING,
            T_VARIABLE,
            T_NAME_QUALIFIED,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE,
        ], true);
    }

    /**
     * @param  list<array{token:array|string,offset:int}>  $tokens
     */
    private function tokenIndexAtOrAfterOffset(array $tokens, int $offset): ?int
    {
        foreach ($tokens as $index => $token) {
            $text = is_array($token['token']) ? $token['token'][1] : $token['token'];
            if ($offset < $token['offset'] + strlen($text)) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<array{token:array|string,offset:int}> */
    private function sourceTokens(string $source): array
    {
        $tokenSource = $source;
        $offsetAdjustment = 0;
        if (preg_match('/\A\s*<\?php\b/i', $source) !== 1) {
            $prefix = '<?php ';
            $tokenSource = $prefix.$source;
            $offsetAdjustment = strlen($prefix);
        }

        $tokens = [];
        $offset = 0;
        foreach (token_get_all($tokenSource) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $tokens[] = [
                'token' => $token,
                'offset' => $offset - $offsetAdjustment,
            ];
            $offset += strlen($text);
        }

        return $tokens;
    }

    /**
     * @param  list<array{token:array|string,offset:int}>  $tokens
     */
    private function previousSignificantTokenIndex(array $tokens, int $index): ?int
    {
        for (; $index >= 0; $index--) {
            if (! $this->isTriviaToken($tokens[$index]['token'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array{token:array|string,offset:int}>  $tokens
     */
    private function nextSignificantTokenIndex(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        for (; $index < $count; $index++) {
            if (! $this->isTriviaToken($tokens[$index]['token'])) {
                return $index;
            }
        }

        return null;
    }

    private function isTriviaToken(array|string $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private function phpExpressionWithoutTrivia(string $source): string
    {
        $result = '';
        foreach ($this->sourceTokens($source) as $entry) {
            if ($entry['offset'] < 0 || $this->isTriviaToken($entry['token'])) {
                continue;
            }
            $result .= is_array($entry['token']) ? $entry['token'][1] : $entry['token'];
        }

        return trim($result);
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

    private function isConsoleTableRenderer(string $relativePath, string $source, ?string $receiver): bool
    {
        return $receiver === '$this'
            && str_contains($relativePath, '/Presentation/Console/')
            && preg_match('/\bextends\s+Command\b/', $source) === 1;
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

        $imported = $this->resolveImportedClassReference($reference, $this->classImportMap($source));
        if ($imported !== null) {
            return $imported;
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

    /** @return list<string> */
    private function applicationPrivateTables(): array
    {
        $tables = $this->config['application_private_tables'] ?? [];
        if (! is_array($tables)) {
            return [];
        }

        return array_values(array_filter($tables, static fn (mixed $table): bool => is_string($table)));
    }

    private function simpleAssignedLiteralTable(string $source, int $offset, string $expression): ?string
    {
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', trim($expression), $match) !== 1) {
            return null;
        }

        $functionStart = $this->enclosingFunctionStart($source, $offset);
        if ($functionStart === null || $functionStart >= $offset) {
            return null;
        }

        $variable = '$'.$match[1];
        $prefix = substr($source, $functionStart, $offset - $functionStart);
        $tokens = $this->sourceTokens($prefix);
        $resolved = null;

        foreach ($tokens as $index => $entry) {
            $token = $entry['token'];
            if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) {
                continue;
            }

            $equalsIndex = $this->nextSignificantTokenIndex($tokens, $index + 1);
            if ($equalsIndex === null || $tokens[$equalsIndex]['token'] !== '=') {
                $resolved = null;

                continue;
            }

            $valueIndex = $this->nextSignificantTokenIndex($tokens, $equalsIndex + 1);
            if ($valueIndex === null) {
                $resolved = null;

                continue;
            }
            $valueToken = $tokens[$valueIndex]['token'];
            if (! is_array($valueToken) || $valueToken[0] !== T_CONSTANT_ENCAPSED_STRING) {
                $resolved = null;

                continue;
            }

            $statementEndIndex = $this->nextSignificantTokenIndex($tokens, $valueIndex + 1);
            if ($statementEndIndex === null || $tokens[$statementEndIndex]['token'] !== ';') {
                $resolved = null;

                continue;
            }

            $resolved = $this->literalTableName($valueToken[1]);
        }

        return $resolved;
    }

    /** @return list<array{method:string,offset:int,end_offset:int,argument:string,receiver:?string}> */
    private function rawSqlPersistenceInvocations(string $source): array
    {
        $connectionReceivers = [];
        foreach ([
            'Illuminate\\Database\\Connection',
            'Illuminate\\Database\\ConnectionInterface',
        ] as $connectionType) {
            array_push($connectionReceivers, ...$this->typedPersistenceReceivers($source, $connectionType));
        }

        $managerReceivers = $this->typedPersistenceReceivers($source, 'Illuminate\\Database\\DatabaseManager');
        $allowedReceivers = ['DB' => true, 'DB::connection()' => true];
        foreach (array_values(array_unique($connectionReceivers)) as $receiver) {
            $allowedReceivers[$receiver] = true;
        }
        foreach ($managerReceivers as $receiver) {
            $allowedReceivers[$receiver] = true;
            $allowedReceivers[$receiver.'->connection()'] = true;
        }

        foreach ($this->persistenceMethodInvocations($source, ['connection']) as $call) {
            if ($call['receiver'] !== 'DB' && ! isset($allowedReceivers[$call['receiver'] ?? ''])) {
                continue;
            }
            $assignment = $this->simpleAssignmentForInvocation($source, $call['offset']);
            if ($assignment !== null) {
                $allowedReceivers[$assignment['variable']] = true;
            }
        }

        $calls = [];
        foreach ($this->persistenceMethodInvocations($source, self::RAW_SQL_METHODS) as $call) {
            if ($call['receiver'] !== null && isset($allowedReceivers[$call['receiver']])) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    private function rawSqlReferencesTable(string $sql, string $table): bool
    {
        $code = $this->sqlCodeWithoutCommentsAndStrings($sql);
        $identifier = '`?'.preg_quote($table, '/').'`?';
        $qualifiedIdentifier = '(?:`?[A-Za-z0-9_]+`?\s*\.\s*)?'.$identifier;

        if (preg_match('/\b(?:FROM|JOIN|UPDATE|INTO|TABLE)\s+'.$qualifiedIdentifier.'(?=\s|,|\)|;|$)/i', $code) === 1) {
            return true;
        }

        return preg_match('/\bTRUNCATE\s+(?:TABLE\s+)?'.$qualifiedIdentifier.'(?=\s|;|$)/i', $code) === 1;
    }

    private function sqlCodeWithoutCommentsAndStrings(string $sql): string
    {
        $length = strlen($sql);
        $result = '';
        $quote = null;
        $blockComment = false;
        $lineComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $result .= '  ';
                    $index++;
                } else {
                    $result .= $char === "\n" ? "\n" : ' ';
                }

                continue;
            }

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $result .= "\n";
                } else {
                    $result .= ' ';
                }

                continue;
            }

            if ($quote !== null) {
                if ($char === '\\' && $index + 1 < $length) {
                    $result .= '  ';
                    $index++;

                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $result .= '  ';
                        $index++;

                        continue;
                    }
                    $quote = null;
                }
                $result .= ' ';

                continue;
            }

            $executableBlockComment = $char === '/'
                && $next === '*'
                && ($index + 2 < $length && $sql[$index + 2] === '!'
                    || $index + 3 < $length && strncasecmp(substr($sql, $index, 4), '/*M!', 4) === 0);
            if ($char === '/' && $next === '*' && ! $executableBlockComment) {
                $blockComment = true;
                $result .= '  ';
                $index++;

                continue;
            }

            $dashComment = $char === '-'
                && $next === '-'
                && ($index + 2 >= $length || ord($sql[$index + 2]) <= 32);
            if ($char === '#' || $dashComment) {
                $lineComment = true;
                $result .= $dashComment ? '  ' : ' ';
                if ($dashComment) {
                    $index++;
                }

                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
                $result .= ' ';

                continue;
            }

            $result .= $char;
        }

        return $result;
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
        foreach ($this->persistenceMethodInvocations($source, ['table', 'from', 'fromRaw', 'fromSub']) as $call) {
            $assignment = $this->simpleAssignmentForInvocation($source, $call['offset']);
            if ($assignment === null) {
                continue;
            }

            $variable = $assignment['variable'];
            $assignmentOffset = $assignment['offset'];
            $afterAssignment = $assignment['statement_end'];
            $functionEnd = $this->enclosingFunctionEnd($source, $assignmentOffset) ?? strlen($source);
            if ($functionEnd <= $afterAssignment) {
                continue;
            }

            $segment = substr($source, $afterAssignment, $functionEnd - $afterAssignment);
            $reassignmentOffset = $this->simpleVariableReassignmentOffset($segment, $variable);
            if ($reassignmentOffset !== null) {
                $segment = substr($segment, 0, $reassignmentOffset);
            }

            $hasMutation = false;
            foreach ($this->persistenceMethodInvocations($segment, self::PERSISTENCE_MUTATION_METHODS, false) as $mutationCall) {
                if ($mutationCall['receiver'] === $variable) {
                    $hasMutation = true;
                    break;
                }
            }
            if (! $hasMutation) {
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

    /** @return array{variable:string,offset:int,statement_end:int}|null */
    private function simpleAssignmentForInvocation(string $source, int $invocationOffset): ?array
    {
        $tokens = $this->sourceTokens($source);
        $invocationIndex = null;
        foreach ($tokens as $index => $token) {
            if ($token['offset'] === $invocationOffset) {
                $invocationIndex = $index;
                break;
            }
        }
        if ($invocationIndex === null) {
            return null;
        }

        for ($index = $invocationIndex - 1; $index >= 0; $index--) {
            $token = $tokens[$index]['token'];
            if (in_array($token, [';', '{', '}'], true)) {
                break;
            }
            if ($token !== '=') {
                continue;
            }

            $variableIndex = $this->previousSignificantTokenIndex($tokens, $index - 1);
            if ($variableIndex === null) {
                return null;
            }
            $variableToken = $tokens[$variableIndex]['token'];
            if (! is_array($variableToken) || $variableToken[0] !== T_VARIABLE) {
                return null;
            }

            for ($endIndex = $invocationIndex; $endIndex < count($tokens); $endIndex++) {
                if ($tokens[$endIndex]['token'] === ';') {
                    return [
                        'variable' => $variableToken[1],
                        'offset' => $tokens[$variableIndex]['offset'],
                        'statement_end' => $tokens[$endIndex]['offset'] + 1,
                    ];
                }
            }

            return null;
        }

        return null;
    }

    private function simpleVariableReassignmentOffset(string $source, string $variable): ?int
    {
        $tokens = $this->sourceTokens($source);
        foreach ($tokens as $index => $token) {
            $current = $token['token'];
            if (! is_array($current) || $current[0] !== T_VARIABLE || $current[1] !== $variable) {
                continue;
            }

            $nextIndex = $this->nextSignificantTokenIndex($tokens, $index + 1);
            if ($nextIndex !== null && $tokens[$nextIndex]['token'] === '=') {
                return $token['offset'];
            }
        }

        return null;
    }

    /** @param list<string> $violations */
    private function scanUnsupportedQuerySources(string $relativePath, string $source, array &$violations): void
    {
        foreach ($this->persistenceMethodInvocations($source, ['fromRaw', 'fromSub'], false) as $call) {
            $violations[] = sprintf(
                '%s:%d query source through %s is forbidden because durable-table ownership cannot be statically attributed.',
                $relativePath,
                $this->lineNumber($source, $call['offset']),
                $call['method'],
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
        foreach ($this->persistenceMethodInvocations(
            $source,
            ['statement', 'unprepared', 'affectingStatement', 'insert', 'update', 'delete'],
        ) as $call) {
            $method = $call['method'];
            if (in_array($method, ['insert', 'update', 'delete'], true) && $call['receiver'] !== 'DB') {
                continue;
            }

            $line = $this->lineNumber($source, $call['offset']);
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

            $sql = $this->literalRawSqlArgument($call['argument']);
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

    /** @param list<string> $violations */
    private function scanUnattributablePersistenceMechanisms(string $relativePath, string $source, array &$violations): void
    {
        $imports = $this->classImports($source);
        foreach ($imports as $import) {
            if (strcasecmp($import['fqcn'], 'Illuminate\\Support\\Facades\\DB') !== 0
                || strcasecmp($import['alias'], 'DB') === 0
            ) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d aliasing the DB facade as %s is forbidden because static persistence attribution must remain syntax-stable.',
                $relativePath,
                $this->lineNumber($source, $import['offset']),
                $import['alias'],
            );
        }

        $this->scanConnectionRawSql($relativePath, $source, $violations);
        $this->scanRuntimeSchemaMutations($relativePath, $source, $violations);

        foreach ($this->persistenceMethodInvocations($source, ['getPdo', 'getRawPdo']) as $call) {
            $violations[] = sprintf(
                '%s:%d direct PDO access is forbidden in runtime source because durable persistence ownership cannot be statically attributed.',
                $relativePath,
                $this->lineNumber($source, $call['offset']),
            );
        }

        $importMap = $this->classImportMap($source);
        $tokens = $this->sourceTokens($source);
        foreach ($tokens as $index => $entry) {
            $token = $entry['token'];
            if (! is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $classIndex = $this->nextSignificantTokenIndex($tokens, $index + 1);
            if ($classIndex === null) {
                continue;
            }
            $classToken = $tokens[$classIndex]['token'];
            if (! is_array($classToken)
                || ! in_array($classToken[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
            ) {
                continue;
            }

            $className = $classToken[1];
            $resolvedClass = str_starts_with($className, '\\')
                ? ltrim($className, '\\')
                : $this->resolveImportedClassReference($className, $importMap);
            if (strcasecmp($resolvedClass ?? $className, 'PDO') !== 0) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d direct PDO access is forbidden in runtime source because durable persistence ownership cannot be statically attributed.',
                $relativePath,
                $this->lineNumber($source, $entry['offset']),
            );
        }
    }

    /** @param list<string> $violations */
    private function scanConnectionRawSql(string $relativePath, string $source, array &$violations): void
    {
        $readMethods = ['select', 'selectOne', 'selectFromWriteConnection', 'selectResultSets', 'scalar', 'cursor'];
        $writeMethods = ['insert', 'update', 'delete'];

        foreach ($this->rawSqlPersistenceInvocations($source) as $call) {
            $method = $call['method'];
            if (! in_array($method, [...$readMethods, ...$writeMethods], true)) {
                continue;
            }

            $offset = $call['offset'];
            if (in_array($method, $writeMethods, true)) {
                $violations[] = sprintf(
                    '%s:%d raw Connection mutation API %s is forbidden because durable-table ownership cannot be attributed.',
                    $relativePath,
                    $this->lineNumber($source, $offset),
                    $method,
                );

                continue;
            }

            $sql = $this->literalRawSqlArgument($call['argument']);
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

    /** @return list<string> */
    private function typedPersistenceReceivers(string $source, string $fqcn): array
    {
        $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
        $typeNames = [strtolower($short) => true, strtolower(ltrim($fqcn, '\\')) => true];
        foreach ($this->importAliasesForType($source, $fqcn) as $alias) {
            $typeNames[strtolower($alias)] = true;
        }

        $tokens = $this->sourceTokens($source);
        $receivers = [];
        foreach ($tokens as $index => $entry) {
            $token = $entry['token'];
            if (! is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }
            if (! $this->variableHasDeclaredType($tokens, $index, $typeNames)) {
                continue;
            }

            $receivers[] = $token[1];
            if ($this->variableDeclarationHasVisibility($tokens, $index)) {
                $receivers[] = '$this->'.substr($token[1], 1);
            }
        }

        return array_values(array_unique($receivers));
    }

    /** @return list<string> */
    private function importAliasesForType(string $source, string $fqcn): array
    {
        $aliases = [];
        $target = strtolower(ltrim($fqcn, '\\'));
        foreach ($this->classImports($source) as $import) {
            if (strtolower($import['fqcn']) === $target) {
                $aliases[] = $import['alias'];
            }
        }

        return array_values(array_unique($aliases));
    }

    /** @return list<array{fqcn:string,alias:string,offset:int}> */
    private function classImports(string $source): array
    {
        $tokens = $this->sourceTokens($source);
        $imports = [];
        $braceDepth = 0;
        $namespaceDepth = 0;
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index]['token'];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $cursor = $index + 1;
                while (($cursor = $this->nextSignificantTokenIndex($tokens, $cursor)) !== null) {
                    $delimiter = $tokens[$cursor]['token'];
                    if ($delimiter === ';') {
                        $namespaceDepth = 0;
                        break;
                    }
                    if ($delimiter === '{') {
                        $namespaceDepth = $braceDepth + 1;
                        break;
                    }
                    $cursor++;
                }

                continue;
            }

            if ($token === '{') {
                $braceDepth++;

                continue;
            }
            if ($token === '}') {
                $braceDepth = max(0, $braceDepth - 1);
                if ($namespaceDepth > 0 && $braceDepth < $namespaceDepth) {
                    $namespaceDepth = 0;
                }

                continue;
            }

            if (! is_array($token) || $token[0] !== T_USE || $braceDepth !== $namespaceDepth) {
                continue;
            }

            $firstIndex = $this->nextSignificantTokenIndex($tokens, $index + 1);
            if ($firstIndex === null) {
                continue;
            }
            $firstToken = $tokens[$firstIndex]['token'];
            if ($firstToken === '(' || (is_array($firstToken) && in_array($firstToken[0], [T_FUNCTION, T_CONST], true))) {
                continue;
            }

            $statement = '';
            for ($cursor = $firstIndex; $cursor < $count; $cursor++) {
                $part = $tokens[$cursor]['token'];
                if ($part === ';') {
                    break;
                }
                if ($this->isTriviaToken($part)) {
                    continue;
                }
                if (is_array($part) && $part[0] === T_AS) {
                    $statement .= ' as ';

                    continue;
                }
                if (is_array($part) && in_array($part[0], [T_FUNCTION, T_CONST], true)) {
                    $statement .= strtolower($part[1]).' ';

                    continue;
                }
                $statement .= is_array($part) ? $part[1] : $part;
            }

            array_push(
                $imports,
                ...$this->parseClassImportStatement($statement, $tokens[$index]['offset']),
            );
        }

        return $imports;
    }

    /** @return list<array{fqcn:string,alias:string,offset:int}> */
    private function parseClassImportStatement(string $statement, int $offset): array
    {
        $statement = trim($statement);
        if ($statement === '') {
            return [];
        }

        $prefix = '';
        $specification = $statement;
        if (preg_match('/\A(.+\\\\)\{(.+)\}\z/s', $statement, $group) === 1) {
            $prefix = rtrim($group[1], '\\').'\\';
            $specification = $group[2];
        }

        $imports = [];
        foreach (explode(',', $specification) as $entry) {
            if (preg_match('/\A(?:function|const)\s+/i', trim($entry)) === 1) {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', trim($entry), 2);
            if ($parts === false || $parts === [] || $parts[0] === '') {
                continue;
            }

            $name = ltrim($prefix.trim($parts[0]), '\\');
            if (! $this->isClassReference($name)) {
                continue;
            }

            $alias = $parts[1] ?? basename(str_replace('\\', '/', $name));
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $alias) !== 1) {
                continue;
            }

            $imports[] = ['fqcn' => $name, 'alias' => $alias, 'offset' => $offset];
        }

        return $imports;
    }

    /** @return array<string,string> */
    private function classImportMap(string $source): array
    {
        $map = [];
        foreach ($this->classImports($source) as $import) {
            $map[strtolower($import['alias'])] = $import['fqcn'];
        }

        return $map;
    }

    /** @param array<string,string> $imports */
    private function resolveImportedClassReference(string $reference, array $imports): ?string
    {
        if ($reference === '') {
            return null;
        }
        if (str_starts_with($reference, '\\')) {
            return ltrim($reference, '\\');
        }

        $segments = explode('\\', $reference);
        $alias = strtolower(array_shift($segments) ?? '');
        if ($alias === '' || ! isset($imports[$alias])) {
            return null;
        }

        return $imports[$alias].($segments === [] ? '' : '\\'.implode('\\', $segments));
    }

    /**
     * @param  list<array{token:array|string,offset:int}>  $tokens
     * @param  array<string,true>  $typeNames
     */
    private function variableHasDeclaredType(array $tokens, int $variableIndex, array $typeNames): bool
    {
        $typeIndex = $this->previousSignificantTokenIndex($tokens, $variableIndex - 1);
        if ($typeIndex === null) {
            return false;
        }

        $typeToken = $tokens[$typeIndex]['token'];
        if ($this->tokenNamesDeclaredType($typeToken, $typeNames)) {
            return true;
        }

        if (! is_array($typeToken) || $typeToken[0] !== T_STRING || strtolower($typeToken[1]) !== 'null') {
            return false;
        }

        $pipeIndex = $this->previousSignificantTokenIndex($tokens, $typeIndex - 1);
        if ($pipeIndex === null || $tokens[$pipeIndex]['token'] !== '|') {
            return false;
        }

        $otherTypeIndex = $this->previousSignificantTokenIndex($tokens, $pipeIndex - 1);
        if ($otherTypeIndex === null) {
            return false;
        }

        return $this->tokenNamesDeclaredType($tokens[$otherTypeIndex]['token'], $typeNames);
    }

    /** @param array<string,true> $typeNames */
    private function tokenNamesDeclaredType(array|string $token, array $typeNames): bool
    {
        if (! is_array($token)
            || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
        ) {
            return false;
        }

        return isset($typeNames[strtolower(ltrim($token[1], '\\'))]);
    }

    /** @param list<array{token:array|string,offset:int}> $tokens */
    private function variableDeclarationHasVisibility(array $tokens, int $variableIndex): bool
    {
        for ($index = $variableIndex - 1; $index >= 0; $index--) {
            $token = $tokens[$index]['token'];
            if ($this->isTriviaToken($token) || $token === '?' || $token === '|') {
                continue;
            }
            if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'null') {
                continue;
            }
            if (is_array($token)
                && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_READONLY], true)
            ) {
                continue;
            }
            if (is_array($token) && in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                return true;
            }
            if (in_array($token, ['(', ',', ';', '{', '}'], true)
                || is_array($token) && in_array($token[0], [T_FUNCTION, T_FN], true)
            ) {
                return false;
            }
        }

        return false;
    }

    private function isLiteralReadOnlySql(string $sql): bool
    {
        $code = trim($this->sqlCodeWithoutCommentsAndStrings($sql));
        if ($code === '' || preg_match('/;\s*\S/s', $code) === 1) {
            return false;
        }
        if (preg_match('/^SELECT\b/is', $code) !== 1) {
            return false;
        }

        return preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $code) !== 1;
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
        foreach ($this->classImports($source) as $import) {
            if (strcasecmp($import['fqcn'], 'Illuminate\\Support\\Facades\\Schema') !== 0
                || strcasecmp($import['alias'], 'Schema') === 0
            ) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d aliasing the Schema facade as %s is forbidden because runtime DDL attribution must remain syntax-stable.',
                $relativePath,
                $this->lineNumber($source, $import['offset']),
                $import['alias'],
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
        return $this->literalRawSqlArgument($this->firstCallArgument($source, $offset));
    }

    private function literalRawSqlArgument(string $argument): ?string
    {
        $argumentWithoutTrivia = $this->phpExpressionWithoutTrivia($argument);
        if (preg_match(
            '/^<<<\'([A-Za-z_][A-Za-z0-9_]*)\'\R(.*?)\R\1\s*$/s',
            $argumentWithoutTrivia,
            $heredoc,
        ) === 1) {
            $sql = trim($heredoc[2]);

            return $sql === '' ? null : $sql;
        }

        $literalTokens = [];
        foreach ($this->sourceTokens($argument) as $entry) {
            if ($entry['offset'] < 0 || $this->isTriviaToken($entry['token'])) {
                continue;
            }
            $literalTokens[] = $entry['token'];
        }

        if (count($literalTokens) !== 1
            || ! is_array($literalTokens[0])
            || $literalTokens[0][0] !== T_CONSTANT_ENCAPSED_STRING
        ) {
            return null;
        }

        $decoded = $this->decodePhpConstantStringLiteral($literalTokens[0][1]);
        if ($decoded === null) {
            return null;
        }

        $sql = trim($decoded);

        return $sql === '' ? null : $sql;
    }

    private function decodePhpConstantStringLiteral(string $literal): ?string
    {
        $length = strlen($literal);
        if ($length < 2) {
            return null;
        }

        $quote = $literal[0];
        if (($quote !== '\'' && $quote !== '"') || $literal[$length - 1] !== $quote) {
            return null;
        }

        $body = substr($literal, 1, -1);
        $decoded = '';
        $bodyLength = strlen($body);
        for ($index = 0; $index < $bodyLength; $index++) {
            $character = $body[$index];
            if ($character !== '\\') {
                $decoded .= $character;

                continue;
            }

            if ($index + 1 >= $bodyLength) {
                return null;
            }

            $next = $body[$index + 1];
            if ($quote === '\'') {
                if ($next === '\\' || $next === '\'') {
                    $decoded .= $next;
                    $index++;
                } else {
                    $decoded .= '\\';
                }

                continue;
            }

            $simpleEscapes = [
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'v' => "\v",
                'e' => "\e",
                'f' => "\f",
                '\\' => '\\',
                '$' => '$',
                '"' => '"',
            ];
            if (isset($simpleEscapes[$next])) {
                $decoded .= $simpleEscapes[$next];
                $index++;

                continue;
            }

            if ($next >= '0' && $next <= '7') {
                $digits = $next;
                $index++;
                for ($digit = 0; $digit < 2 && $index + 1 < $bodyLength; $digit++) {
                    $candidate = $body[$index + 1];
                    if ($candidate < '0' || $candidate > '7') {
                        break;
                    }
                    $digits .= $candidate;
                    $index++;
                }
                $decoded .= chr(octdec($digits) & 0xFF);

                continue;
            }

            if ($next === 'x' || $next === 'X') {
                $digits = '';
                $index++;
                for ($digit = 0; $digit < 2 && $index + 1 < $bodyLength; $digit++) {
                    $candidate = $body[$index + 1];
                    if (! ctype_xdigit($candidate)) {
                        break;
                    }
                    $digits .= $candidate;
                    $index++;
                }
                if ($digits === '') {
                    $decoded .= '\\'.$next;
                } else {
                    $decoded .= chr(hexdec($digits));
                }

                continue;
            }

            if ($next === 'u' && $index + 2 < $bodyLength && $body[$index + 2] === '{') {
                $closingBrace = strpos($body, '}', $index + 3);
                if ($closingBrace === false) {
                    return null;
                }
                $digits = substr($body, $index + 3, $closingBrace - ($index + 3));
                if ($digits === '' || ! ctype_xdigit($digits)) {
                    return null;
                }
                $encoded = $this->utf8CodePoint(hexdec($digits));
                if ($encoded === null) {
                    return null;
                }
                $decoded .= $encoded;
                $index = $closingBrace;

                continue;
            }

            $decoded .= '\\'.$next;
            $index++;
        }

        return $decoded;
    }

    private function utf8CodePoint(int $codePoint): ?string
    {
        if ($codePoint < 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
            return null;
        }
        if ($codePoint <= 0x7F) {
            return chr($codePoint);
        }
        if ($codePoint <= 0x7FF) {
            return chr(0xC0 | ($codePoint >> 6)).chr(0x80 | ($codePoint & 0x3F));
        }
        if ($codePoint <= 0xFFFF) {
            return chr(0xE0 | ($codePoint >> 12))
                .chr(0x80 | (($codePoint >> 6) & 0x3F))
                .chr(0x80 | ($codePoint & 0x3F));
        }

        return chr(0xF0 | ($codePoint >> 18))
            .chr(0x80 | (($codePoint >> 12) & 0x3F))
            .chr(0x80 | (($codePoint >> 6) & 0x3F))
            .chr(0x80 | ($codePoint & 0x3F));
    }

    private function firstCallArgument(string $source, int $offset): string
    {
        $tokens = $this->sourceTokens($source);
        $index = $this->tokenIndexAtOrAfterOffset($tokens, $offset);
        if ($index === null) {
            return '';
        }

        $end = strlen($source);
        $depth = 0;
        $count = count($tokens);
        for (; $index < $count; $index++) {
            $entry = $tokens[$index];
            $token = $entry['token'];
            $text = is_array($token) ? $token[1] : $token;
            if ($offset >= $entry['offset'] + strlen($text) || is_array($token)) {
                continue;
            }

            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;

                continue;
            }
            if ($token === ')' || $token === ']' || $token === '}') {
                if ($depth === 0) {
                    $end = $entry['offset'];
                    break;
                }
                $depth--;

                continue;
            }
            if ($token === ',' && $depth === 0) {
                $end = $entry['offset'];
                break;
            }
        }

        return trim(substr($source, $offset, $end - $offset));
    }

    private function classifyRawStatement(string $sql): string
    {
        $sql = trim($this->sqlCodeWithoutCommentsAndStrings($sql));
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
                'app/Modules/Telegram/Application/TelegramPrivateMediaDeliveryProvenanceGuard.php',
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

    private function lineNumber(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }
}
