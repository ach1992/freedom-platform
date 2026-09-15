<?php

declare(strict_types=1);

use App\Modules\Operations\Infrastructure\ArtisanReleaseHealthVerifier;
use App\Modules\Operations\Infrastructure\FilesystemReleaseActivator;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', [
    'root:',
    'release:',
    'php::',
    'journal::',
    'timeout::',
    'rollback',
]);

try {
    if (! is_array($options)
        || ! is_string($options['root'] ?? null)
        || ! is_string($options['release'] ?? null)
    ) {
        throw new RuntimeException('Invalid release-switch arguments.');
    }

    $root = $options['root'];
    $release = $options['release'];
    $phpBinary = is_string($options['php'] ?? null)
        ? $options['php']
        : '/www/server/php/84/bin/php';
    $journal = is_string($options['journal'] ?? null)
        ? $options['journal']
        : rtrim($root, DIRECTORY_SEPARATOR).'/shared/release-journal.json';
    $timeout = filter_var(
        $options['timeout'] ?? 60,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 600]],
    );

    if (! is_int($timeout)) {
        throw new RuntimeException('Invalid release-switch timeout.');
    }

    $activator = new FilesystemReleaseActivator(
        new ArtisanReleaseHealthVerifier($phpBinary, $timeout),
        $root,
        $journal,
    );
    $result = array_key_exists('rollback', $options)
        ? $activator->rollbackTo($release)
        : $activator->activate($release);

    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Release switch failed.\n");
    exit(1);
}
