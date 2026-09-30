<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use RuntimeException;

final class UpdateMigrationIdentity
{
    /** @param array<string,string> $checksumsByMigrationName */
    public static function fromChecksums(array $checksumsByMigrationName): string
    {
        if ($checksumsByMigrationName === []) {
            throw new RuntimeException('Update migration identity inputs are unavailable.');
        }

        ksort($checksumsByMigrationName, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($checksumsByMigrationName as $migration => $checksum) {
            self::assertMigrationName($migration);
            if (preg_match('/\A[0-9a-f]{64}\z/', $checksum) !== 1) {
                throw new RuntimeException('Update migration identity contains an invalid migration checksum.');
            }
            hash_update($hash, $migration.".php\0".$checksum."\n");
        }

        return hash_final($hash);
    }

    public static function fromDirectory(string $directory): string
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || is_link($directory)) {
            throw new RuntimeException('Update migration identity directory is unavailable.');
        }

        $files = glob($root.'/*.php');
        if ($files === false || $files === []) {
            throw new RuntimeException('Update migration identity inputs are unavailable.');
        }

        $checksums = [];
        foreach ($files as $file) {
            $resolved = realpath($file);
            if ($resolved === false
                || dirname($resolved) !== $root
                || ! is_file($resolved)
                || is_link($file)
            ) {
                throw new RuntimeException('An update migration identity input is unavailable or unsafe.');
            }
            $migration = basename($resolved, '.php');
            self::assertMigrationName($migration);
            $checksum = hash_file('sha256', $resolved);
            if (! is_string($checksum)) {
                throw new RuntimeException('An update migration identity checksum could not be calculated.');
            }
            $checksums[$migration] = $checksum;
        }

        return self::fromChecksums($checksums);
    }

    /** @return list<string> */
    public static function namesFromDirectory(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root) || is_link($directory)) {
            throw new RuntimeException('Update migration identity directory is unavailable.');
        }
        $files = glob($root.'/*.php');
        if ($files === false || $files === []) {
            throw new RuntimeException('Update migration identity inputs are unavailable.');
        }

        $names = [];
        foreach ($files as $file) {
            $resolved = realpath($file);
            if ($resolved === false || dirname($resolved) !== $root || ! is_file($resolved) || is_link($file)) {
                throw new RuntimeException('An update migration identity input is unavailable or unsafe.');
            }
            $migration = basename($resolved, '.php');
            self::assertMigrationName($migration);
            $names[] = $migration;
        }
        sort($names, SORT_STRING);
        if (count($names) !== count(array_unique($names))) {
            throw new RuntimeException('Update migration identity contains duplicate migration names.');
        }

        return $names;
    }

    private static function assertMigrationName(string $migration): void
    {
        if ($migration === ''
            || strlen($migration) > 191
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $migration) !== 1
        ) {
            throw new RuntimeException('Update migration identity contains an invalid migration name.');
        }
    }
}
