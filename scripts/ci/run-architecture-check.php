<?php

declare(strict_types=1);

use FreedomPlatform\CI\ArchitectureBoundaryChecker;
use FreedomPlatform\CI\DurableTableOwnershipChecker;
use FreedomPlatform\CI\TelegramPresentationProvenanceChecker;

require __DIR__.'/ArchitectureBoundaryChecker.php';
require __DIR__.'/DurableTableOwnershipChecker.php';
require __DIR__.'/TelegramPresentationProvenanceChecker.php';

$config = require __DIR__.'/architecture-boundaries.php';
if (! is_array($config)) {
    fwrite(STDERR, "Architecture boundary configuration must return an array.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$checker = new ArchitectureBoundaryChecker($root, $config);
$result = $checker->check();
$ownershipViolations = (new DurableTableOwnershipChecker($root, $config))->violations();
$telegramProvenanceViolations = (new TelegramPresentationProvenanceChecker($root, $config))->violations();
$result['violations'] = array_values(array_unique(array_merge(
    $result['violations'],
    $ownershipViolations,
    $telegramProvenanceViolations,
)));
sort($result['violations'], SORT_STRING);

$reportDirectory = $root.'/build/evidence/static';
if (! is_dir($reportDirectory) && ! mkdir($reportDirectory, 0775, true) && ! is_dir($reportDirectory)) {
    fwrite(STDERR, "Unable to create architecture evidence directory.\n");
    exit(2);
}

$lines = [
    'Reviewed module dependency graph:',
];
foreach ($result['edges'] as $edge) {
    $lines[] = '- '.str_replace('>', ' -> ', $edge);
}
if ($result['edges'] === []) {
    $lines[] = '- none';
}
$lines[] = '';
$lines[] = 'Violations:';
foreach ($result['violations'] as $violation) {
    $lines[] = '- '.$violation;
}
if ($result['violations'] === []) {
    $lines[] = '- none';
}
$report = implode("\n", $lines)."\n";
file_put_contents($reportDirectory.'/architecture.txt', $report);

if ($result['violations'] !== []) {
    fwrite(STDERR, $report);
    exit(1);
}

fwrite(STDOUT, $report);
