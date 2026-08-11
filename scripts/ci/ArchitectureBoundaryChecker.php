<?php

declare(strict_types=1);

namespace FreedomPlatform\CI;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ArchitectureBoundaryChecker
{
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

        foreach ($this->phpFiles('app/Modules') as $relativePath => $source) {
            $this->scanModuleFile($relativePath, $source, $violations, $edges);
            $this->scanPersistence($relativePath, $source, $violations);
        }

        foreach ($this->phpFiles('app/Shared') as $relativePath => $source) {
            $this->scanSharedFile($relativePath, $source, $violations);
        }

        foreach ($this->phpFiles('routes') as $relativePath => $source) {
            $this->scanPersistence($relativePath, $source, $violations);
        }

        $edges = array_values(array_unique($edges));
        sort($edges, SORT_STRING);
        array_push($violations, ...$this->cycleViolations($edges));

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

        if ($sourceLayer === 'Domain') {
            preg_match_all('/^use\s+(Illuminate|Symfony|Monolog)\\\\/m', $source, $frameworkImports, PREG_OFFSET_CAPTURE);
            foreach ($frameworkImports[0] ?? [] as $frameworkImport) {
                $violations[] = sprintf(
                    '%s:%d Domain may not import framework infrastructure.',
                    $relativePath,
                    $this->lineNumber($source, $frameworkImport[1]),
                );
            }
        }

        preg_match_all(
            '/^use\s+App\\\\Modules\\\\([A-Za-z0-9_]+)\\\\(Domain|Application|Infrastructure|Presentation)(?:\\\\[^;]+)?;/m',
            $source,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        foreach ($matches as $match) {
            $targetModule = $match[1][0];
            $targetLayer = $match[2][0];
            $line = $this->lineNumber($source, $match[0][1]);

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
        preg_match_all('/^use\s+App\\\\Modules\\\\/m', $source, $featureImports, PREG_OFFSET_CAPTURE);
        foreach ($featureImports[0] ?? [] as $featureImport) {
            $violations[] = sprintf(
                '%s:%d Shared code must not depend on a feature module.',
                $relativePath,
                $this->lineNumber($source, $featureImport[1]),
            );
        }

        if (! str_starts_with($relativePath, 'app/Shared/Domain/')) {
            return;
        }

        preg_match_all('/^use\s+(Illuminate|Symfony|Monolog)\\\\/m', $source, $frameworkImports, PREG_OFFSET_CAPTURE);
        foreach ($frameworkImports[0] ?? [] as $frameworkImport) {
            $violations[] = sprintf(
                '%s:%d Shared Domain may not import framework infrastructure.',
                $relativePath,
                $this->lineNumber($source, $frameworkImport[1]),
            );
        }
    }

    /** @param list<string> $violations */
    private function scanPersistence(string $relativePath, string $source, array &$violations): void
    {
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
            if (preg_match('/->\s*(insert|insertGetId|insertOrIgnore|update|delete|upsert|updateOrInsert|increment|decrement|truncate)\s*\(/', $statement) !== 1) {
                continue;
            }

            $line = $this->lineNumber($source, $offset);
            $sourceModule = null;
            $sourceLayer = null;
            if (preg_match('#^app/Modules/([^/]+)/([^/]+)/#', $relativePath, $pathParts) === 1) {
                $sourceModule = $pathParts[1];
                $sourceLayer = $pathParts[2];
            }

            $exceptionKey = $relativePath.'|'.$table;
            $exceptions = $this->config['persistence_exceptions'] ?? [];
            $persistenceException = is_array($exceptions) && in_array($exceptionKey, $exceptions, true);

            if ((str_starts_with($relativePath, 'routes/') || $sourceLayer === 'Presentation') && ! $persistenceException) {
                $violations[] = sprintf(
                    '%s:%d direct persistence mutation of %s from %s is forbidden; call an Application boundary.',
                    $relativePath,
                    $line,
                    $table,
                    str_starts_with($relativePath, 'routes/') ? 'routes' : 'Presentation',
                );
            }

            $owner = $this->protectedTableOwner($table);
            if ($owner === null || $sourceModule === null || $owner === $sourceModule || $persistenceException) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d %s mutation of protected table %s owned by %s is forbidden.',
                $relativePath,
                $line,
                $sourceModule,
                $table,
                $owner,
            );
        }
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

    private function protectedTableOwner(string $table): ?string
    {
        $owners = $this->config['protected_table_owners'] ?? [];
        if (! is_array($owners)) {
            return null;
        }

        foreach ($owners as $pattern => $owner) {
            if (is_string($pattern) && is_string($owner) && preg_match($pattern, $table) === 1) {
                return $owner;
            }
        }

        return null;
    }

    /** @param list<string> $edges
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
