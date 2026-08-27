<?php

declare(strict_types=1);

namespace FreedomPlatform\CI;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class DurableTableOwnershipChecker
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly string $root,
        private readonly array $config,
    ) {}

    /** @return list<string> */
    public function violations(): array
    {
        $scan = $this->scanMigrations();
        $discovered = $scan['tables'];
        $owners = $this->config['durable_table_owners'] ?? [];
        if (! is_array($owners)) {
            return ['durable_table_owners must be an exact table => owner map.'];
        }

        $violations = $scan['violations'];
        foreach ($discovered as $table => $locations) {
            $owner = $owners[$table] ?? null;
            if (! is_string($owner) || trim($owner) === '') {
                $violations[] = sprintf(
                    '%s durable table %s has no explicit architecture owner/classification.',
                    $locations[0],
                    $table,
                );
            }
        }

        foreach ($owners as $table => $owner) {
            if (! is_string($table) || ! is_string($owner) || trim($owner) === '') {
                $violations[] = 'durable_table_owners contains an invalid table or owner entry.';

                continue;
            }

            if (! array_key_exists($table, $discovered)) {
                $violations[] = sprintf(
                    'durable_table_owners contains stale/undiscovered table %s; ownership must match migration-created durable tables.',
                    $table,
                );
            }
        }

        $violations = array_values(array_unique($violations));
        sort($violations, SORT_STRING);

        return $violations;
    }

    /** @return array{tables:array<string,list<string>>,violations:list<string>} */
    private function scanMigrations(): array
    {
        $directory = $this->root.'/database/migrations';
        if (! is_dir($directory)) {
            return ['tables' => [], 'violations' => []];
        }

        $tables = [];
        $violations = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'sql'], true)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (! is_string($source)) {
                throw new RuntimeException('Unable to read migration: '.$file->getPathname());
            }

            $relativePath = str_replace('\\', '/', ltrim(str_replace($this->root, '', $file->getPathname()), DIRECTORY_SEPARATOR));

            if ($file->getExtension() === 'sql') {
                $this->recordRawCreateTables($tables, $relativePath, $source);
                $this->recordUnsupportedTableRenames($violations, $relativePath, $source);

                continue;
            }

            $this->recordAliasedMigrationPrimitiveViolations($violations, $relativePath, $source);

            preg_match_all(
                '/Schema::create\(\s*[\'\"]([A-Za-z0-9_]+)[\'\"]/',
                $source,
                $schemaMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($schemaMatches as $match) {
                $this->record($tables, $match[1][0], $relativePath, $source, $match[0][1]);
            }

            $this->resolveHelperCreatedTables($tables, $violations, $relativePath, $source);
            $this->resolveBlueprintCreatedTables($tables, $violations, $relativePath, $source);

            preg_match_all(
                '/Schema::create\(\s*(?![\'\"]|\$)/',
                $source,
                $unsupportedCreateMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($unsupportedCreateMatches as $match) {
                $violations[] = sprintf(
                    '%s:%d durable table creation is not statically attributable; use a literal table name or a helper whose table parameter is supplied only by literal callsites.',
                    $relativePath,
                    $this->lineNumber($source, $match[0][1]),
                );
            }

            $this->recordRawCreateTables($tables, $relativePath, $source);
            $this->recordUnsupportedTableRenames($violations, $relativePath, $source);

        }

        ksort($tables, SORT_STRING);

        return ['tables' => $tables, 'violations' => $violations];
    }

    /**
     * @param  array<string,list<string>>  $tables
     * @param  list<string>  $violations
     */
    private function resolveHelperCreatedTables(array &$tables, array &$violations, string $path, string $source): void
    {
        preg_match_all(
            '/Schema::create\(\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*,/',
            $source,
            $dynamicCreates,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($dynamicCreates as $create) {
            $variable = $create[1][0];
            $offset = $create[0][1];
            $method = $this->containingMethodParameter($source, $offset, $variable);
            if ($method === null) {
                $violations[] = sprintf(
                    '%s:%d dynamic Schema::create table %s is not tied to a resolvable helper parameter.',
                    $path,
                    $this->lineNumber($source, $offset),
                    '$'.$variable,
                );

                continue;
            }

            [$methodName, $parameterIndex] = $method;
            if ($parameterIndex !== 0) {
                $violations[] = sprintf(
                    '%s:%d Schema::create helper %s uses table parameter %s outside the first argument position; ownership discovery fails closed until this helper is made statically resolvable.',
                    $path,
                    $this->lineNumber($source, $offset),
                    $methodName,
                    '$'.$variable,
                );

                continue;
            }

            $callPattern = '/\$this->'.preg_quote($methodName, '/').'\s*\(/';
            preg_match_all($callPattern, $source, $allCalls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            if ($allCalls === []) {
                $violations[] = sprintf(
                    '%s:%d Schema::create helper %s has no statically visible callsites.',
                    $path,
                    $this->lineNumber($source, $offset),
                    $methodName,
                );

                continue;
            }

            $literalPattern = '/\$this->'.preg_quote($methodName, '/').'\s*\(\s*([\'\"])([A-Za-z0-9_]+)\1\s*[,)]/';
            preg_match_all($literalPattern, $source, $literalCalls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            if (count($literalCalls) !== count($allCalls)) {
                $violations[] = sprintf(
                    '%s:%d Schema::create helper %s has a non-literal table callsite; durable ownership discovery fails closed.',
                    $path,
                    $this->lineNumber($source, $offset),
                    $methodName,
                );
            }

            foreach ($literalCalls as $call) {
                $this->record($tables, $call[2][0], $path, $source, $call[0][1]);
            }
        }
    }

    /**
     * @param  array<string,list<string>>  $tables
     * @param  list<string>  $violations
     */
    private function resolveBlueprintCreatedTables(array &$tables, array &$violations, string $path, string $source): void
    {
        $blueprintClass = '(?:Blueprint|'.preg_quote('\\Illuminate\\Database\\Schema\\Blueprint', '/').'|'.preg_quote('Illuminate\\Database\\Schema\\Blueprint', '/').')';
        $recognizedCreates = [];

        preg_match_all(
            '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*new\s+'.$blueprintClass.'\s*\(\s*[^,]+,\s*([\'\"])([A-Za-z0-9_]+)\2\s*\)\s*;/',
            $source,
            $literalBlueprints,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($literalBlueprints as $blueprint) {
            $variable = $blueprint[1][0];
            $offset = $blueprint[0][1];
            if (! $this->blueprintCreatesTable($source, $offset, $variable)) {
                continue;
            }
            $recognizedCreates[$offset] = true;
            $this->record($tables, $blueprint[3][0], $path, $source, $offset);
        }

        preg_match_all(
            '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*new\s+'.$blueprintClass.'\s*\(\s*[^,]+,\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\)\s*;/',
            $source,
            $dynamicBlueprints,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($dynamicBlueprints as $blueprint) {
            $blueprintVariable = $blueprint[1][0];
            $tableVariable = $blueprint[2][0];
            $offset = $blueprint[0][1];
            if (! $this->blueprintCreatesTable($source, $offset, $blueprintVariable)) {
                continue;
            }
            $recognizedCreates[$offset] = true;

            $method = $this->containingMethodParameter($source, $offset, $tableVariable);
            if ($method === null) {
                $violations[] = sprintf(
                    '%s:%d Blueprint table creation variable $%s is not tied to a resolvable helper parameter.',
                    $path,
                    $this->lineNumber($source, $offset),
                    $tableVariable,
                );

                continue;
            }

            [$methodName, $parameterIndex] = $method;
            if ($parameterIndex !== 0) {
                $violations[] = sprintf(
                    '%s:%d Blueprint helper %s uses its table parameter outside the first argument position; ownership discovery fails closed.',
                    $path,
                    $this->lineNumber($source, $offset),
                    $methodName,
                );

                continue;
            }

            $callPattern = '/\$this->'.preg_quote($methodName, '/').'\s*\(/';
            preg_match_all($callPattern, $source, $allCalls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $literalPattern = '/\$this->'.preg_quote($methodName, '/').'\s*\(\s*([\'\"])([A-Za-z0-9_]+)\1\s*[,)]/';
            preg_match_all($literalPattern, $source, $literalCalls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            if ($allCalls === []) {
                $violations[] = sprintf(
                    '%s:%d Blueprint helper %s has no statically visible callsites.',
                    $path,
                    $this->lineNumber($source, $offset),
                    $methodName,
                );

                continue;
            }
            if (count($literalCalls) !== count($allCalls)) {
                $violations[] = sprintf(
                    '%s:%d Blueprint helper %s has a non-literal table callsite; durable ownership discovery fails closed.',
                    $path,
                    $this->lineNumber($source, $offset),
                    $methodName,
                );
            }
            foreach ($literalCalls as $call) {
                $this->record($tables, $call[2][0], $path, $source, $call[0][1]);
            }
        }

        preg_match_all(
            '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*new\s+'.$blueprintClass.'\s*\(/',
            $source,
            $allBlueprints,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($allBlueprints as $blueprint) {
            $variable = $blueprint[1][0];
            $offset = $blueprint[0][1];
            if (isset($recognizedCreates[$offset]) || ! $this->blueprintCreatesTable($source, $offset, $variable)) {
                continue;
            }
            $violations[] = sprintf(
                '%s:%d Blueprint table creation could not be statically resolved to a literal table set.',
                $path,
                $this->lineNumber($source, $offset),
            );
        }
    }

    private function blueprintCreatesTable(string $source, int $offset, string $variable): bool
    {
        $tail = substr($source, $offset);

        return preg_match('/'.preg_quote($variable, '/').'\s*->\s*create\s*\(/', $tail) === 1;
    }

    /** @param list<string> $violations */
    private function recordUnsupportedTableRenames(array &$violations, string $path, string $source): void
    {
        $patterns = [
            '/\bSchema::rename\s*\(/' => 'Schema::rename',
            '/\bRENAME\s+TABLE\b/i' => 'raw RENAME TABLE',
            '/\bALTER\s+TABLE\b[^;\r\n]*\bRENAME\b/i' => 'raw ALTER TABLE ... RENAME',
        ];
        foreach ($patterns as $pattern => $mechanism) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $violations[] = sprintf(
                    '%s:%d durable table rename via %s is not ownership-attributable; model the post-rename durable ownership lifecycle explicitly before using table rename.',
                    $path,
                    $this->lineNumber($source, $match[0][1]),
                    $mechanism,
                );
            }
        }
    }

    /** @param array<string,list<string>> $tables */
    private function recordRawCreateTables(array &$tables, string $path, string $source): void
    {
        preg_match_all(
            '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i',
            $source,
            $rawMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        foreach ($rawMatches as $match) {
            $this->record($tables, $match[1][0], $path, $source, $match[0][1]);
        }
    }

    /** @param list<string> $violations */
    private function recordAliasedMigrationPrimitiveViolations(array &$violations, string $path, string $source): void
    {
        $schemaFacade = preg_quote('Illuminate\\Support\\Facades\\Schema', '/');
        $blueprint = preg_quote('Illuminate\\Database\\Schema\\Blueprint', '/');
        $patterns = [
            '/\buse\s+'.$schemaFacade.'\s+as\s+[A-Za-z_][A-Za-z0-9_]*\s*;/' => 'Schema facade',
            '/\buse\s+'.$blueprint.'\s+as\s+[A-Za-z_][A-Za-z0-9_]*\s*;/' => 'Blueprint',
        ];
        foreach ($patterns as $pattern => $primitive) {
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $violations[] = sprintf(
                    '%s:%d aliasing %s is forbidden because durable-table discovery must remain syntax-stable.',
                    $path,
                    $this->lineNumber($source, $match[0][1]),
                    $primitive,
                );
            }
        }
    }

    /** @return array{string,int}|null */
    private function containingMethodParameter(string $source, int $offset, string $variable): ?array
    {
        preg_match_all(
            '/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\)\s*(?::\s*[^\{]+)?\{/s',
            $source,
            $methods,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $candidate = null;
        foreach ($methods as $method) {
            $methodOffset = $method[0][1];
            if ($methodOffset >= $offset) {
                continue;
            }
            if ($candidate === null || $methodOffset > $candidate[0]) {
                $candidate = [$methodOffset, $method[1][0], $method[2][0]];
            }
        }
        if ($candidate === null) {
            return null;
        }

        preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $candidate[2], $parameters);
        foreach ($parameters[1] ?? [] as $index => $parameter) {
            if ($parameter === $variable) {
                return [$candidate[1], $index];
            }
        }

        return null;
    }

    /** @param array<string,list<string>> $tables */
    private function record(array &$tables, string $table, string $path, string $source, int $offset): void
    {
        $location = $path.':'.$this->lineNumber($source, $offset);
        $tables[$table] ??= [];

        if (! in_array($location, $tables[$table], true)) {
            $tables[$table][] = $location;
        }
    }

    private function lineNumber(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }
}
