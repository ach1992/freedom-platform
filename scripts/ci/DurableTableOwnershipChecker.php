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
        $discovered = $this->discoveredTables();
        $owners = $this->config['durable_table_owners'] ?? [];
        if (! is_array($owners)) {
            return ['durable_table_owners must be an exact table => owner map.'];
        }

        $violations = [];
        foreach ($discovered as $table => $location) {
            $owner = $owners[$table] ?? null;
            if (! is_string($owner) || trim($owner) === '') {
                $violations[] = sprintf(
                    '%s durable table %s has no explicit architecture owner/classification.',
                    $location,
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

    /** @return array<string,string> table => source:line */
    private function discoveredTables(): array
    {
        $directory = $this->root.'/database/migrations';
        if (! is_dir($directory)) {
            return [];
        }

        $tables = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (! is_string($source)) {
                throw new RuntimeException('Unable to read migration: '.$file->getPathname());
            }

            $relativePath = str_replace('\\', '/', ltrim(str_replace($this->root, '', $file->getPathname()), DIRECTORY_SEPARATOR));

            preg_match_all(
                '/Schema::create\(\s*[\'\"]([A-Za-z0-9_]+)[\'\"]/',
                $source,
                $schemaMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($schemaMatches as $match) {
                $this->record($tables, $match[1][0], $relativePath, $source, $match[0][1]);
            }

            preg_match_all(
                '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i',
                $source,
                $rawMatches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($rawMatches as $match) {
                $this->record($tables, $match[1][0], $relativePath, $source, $match[0][1]);
            }
        }

        ksort($tables, SORT_STRING);

        return $tables;
    }

    /** @param array<string,string> $tables */
    private function record(array &$tables, string $table, string $path, string $source, int $offset): void
    {
        $location = $path.':'.(substr_count(substr($source, 0, $offset), "\n") + 1);
        if (isset($tables[$table]) && $tables[$table] !== $location) {
            throw new RuntimeException(sprintf(
                'Durable table %s is created in more than one migration location (%s and %s).',
                $table,
                $tables[$table],
                $location,
            ));
        }

        $tables[$table] = $location;
    }
}
