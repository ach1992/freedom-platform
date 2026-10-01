<?php

declare(strict_types=1);

use App\Modules\Operations\Application\UpdateMigrationIdentity;
use App\Modules\Operations\Infrastructure\PharUpdatePackageVerifier;
use PharData;
use PharFileInfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const RELEASE_AUTHORITY = 'freedom_platform_release_v1';

/** @return string */
function run(array $command, ?string $cwd = null): string
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('Release command could not be started.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0 || ! is_string($stdout) || ! is_string($stderr)) {
        throw new RuntimeException('Release command failed: '.implode(' ', $command)."\n".(is_string($stderr) ? trim($stderr) : ''));
    }

    return $stdout;
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeTree($path.'/'.$entry);
    }
    @rmdir($path);
}

/** @return array<string,mixed> */
function decodeObject(string $path): array
{
    $contents = file_get_contents($path);
    if (! is_string($contents) || $contents === '') {
        throw new RuntimeException('Release metadata is unavailable.');
    }

    $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException('Release metadata must be a JSON object.');
    }

    return $decoded;
}

function jsonObject(array $value): string
{
    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return $json."\n";
}

/** @return list<string> */
function stageFiles(string $stage): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $entry) {
        if (! $entry->isFile() || $entry->isLink()) {
            throw new RuntimeException('Release staging contains an unsupported filesystem entry.');
        }
        $path = str_replace('\\', '/', $entry->getPathname());
        $prefix = rtrim(str_replace('\\', '/', $stage), '/').'/';
        if (! str_starts_with($path, $prefix)) {
            throw new RuntimeException('Release staging path could not be resolved safely.');
        }
        $relative = substr($path, strlen($prefix));
        if ($relative === '' || str_contains($relative, '..') || str_contains($relative, "\0")) {
            throw new RuntimeException('Release staging contains an unsafe path.');
        }
        $files[] = $relative;
    }

    sort($files, SORT_STRING);

    return $files;
}

function assertSourceArchive(string $archivePath): void
{
    $archive = new PharData($archivePath);
    $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $entry) {
        if (! $entry instanceof PharFileInfo
            || $entry->isLink()
            || (! $entry->isFile() && ! $entry->isDir())
        ) {
            throw new RuntimeException('Release source contains an unsupported link or special entry.');
        }
    }
}

/** @param list<string> $files */
function buildTar(string $stage, array $files, string $target, string $listPath): void
{
    $tarVersion = run(['tar', '--version']);
    if (! str_contains($tarVersion, 'GNU tar')) {
        throw new RuntimeException('Deterministic RC packaging requires GNU tar.');
    }

    $list = implode("\0", $files)."\0";
    if (file_put_contents($listPath, $list, LOCK_EX) !== strlen($list)) {
        throw new RuntimeException('Release TAR file list could not be written.');
    }

    run([
        'tar',
        '--format=ustar',
        '--mtime=@0',
        '--owner=0',
        '--group=0',
        '--numeric-owner',
        '--no-recursion',
        '--null',
        '--files-from='.$listPath,
        '--create',
        '--file='.$target,
    ], $stage);
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/release/build-update-package.php <release-metadata.json> <output-directory>\n");
    exit(64);
}

$root = realpath(dirname(__DIR__, 2));
if ($root === false || ! is_dir($root)) {
    throw new RuntimeException('Repository root is unavailable.');
}

$gitRoot = realpath(trim(run(['git', 'rev-parse', '--show-toplevel'], $root)));
if ($gitRoot === false || $gitRoot !== $root) {
    throw new RuntimeException('Release builder must run from the repository that owns this script.');
}

$status = trim(run(['git', 'status', '--porcelain=v1', '--untracked-files=no'], $root));
if ($status !== '') {
    throw new RuntimeException('Release builder requires a clean tracked working tree.');
}

$head = trim(run(['git', 'rev-parse', 'HEAD'], $root));
$tree = trim(run(['git', 'rev-parse', 'HEAD^{tree}'], $root));
if (preg_match('/\A[0-9a-f]{40}\z/', $head) !== 1 || preg_match('/\A[0-9a-f]{40}\z/', $tree) !== 1) {
    throw new RuntimeException('Release source Git identity is invalid.');
}

$metadataInput = $argv[1];
$metadataPath = realpath($metadataInput);
if ($metadataPath === false || ! is_file($metadataPath) || ! str_starts_with($metadataPath, $root.'/')) {
    throw new RuntimeException('Release metadata path is invalid.');
}
$metadataRelative = substr($metadataPath, strlen($root) + 1);
run(['git', 'ls-files', '--error-unmatch', '--', $metadataRelative], $root);
$metadata = decodeObject($metadataPath);

$releaseId = $metadata['release_id'] ?? null;
if (! is_string($releaseId) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $releaseId) !== 1) {
    throw new RuntimeException('Release identifier is invalid.');
}

$outputInput = $argv[2];
if (! str_starts_with($outputInput, '/')) {
    $outputInput = $root.'/'.$outputInput;
}
if (! is_dir($outputInput) && ! mkdir($outputInput, 0700, true) && ! is_dir($outputInput)) {
    throw new RuntimeException('Release output directory could not be created.');
}
$output = realpath($outputInput);
if ($output === false || ! is_dir($output) || is_link($outputInput)) {
    throw new RuntimeException('Release output directory is unsafe.');
}

$tempRoot = sys_get_temp_dir().'/freedom-rc-build-'.bin2hex(random_bytes(8));
$stage = $tempRoot.'/stage';
if (! mkdir($stage, 0700, true) && ! is_dir($stage)) {
    throw new RuntimeException('Release staging directory could not be created.');
}

try {
    $sourceArchive = $tempRoot.'/source.tar';
    $sourcePaths = [
        'app',
        'bootstrap',
        'config',
        'database',
        'deploy',
        'public',
        'resources',
        'routes',
        '.env.example',
        'CHANGELOG.md',
        'LICENSE',
        'README.md',
        'artisan',
        'composer.json',
        'composer.lock',
        'RELEASE_NOTES.md',
    ];

    run(['git', 'archive', '--format=tar', '--output='.$sourceArchive, 'HEAD', '--', ...$sourcePaths], $root);
    assertSourceArchive($sourceArchive);
    (new PharData($sourceArchive))->extractTo($stage, null, true);

    $payloadFiles = stageFiles($stage);
    if (! in_array('RELEASE_NOTES.md', $payloadFiles, true)
        || ! in_array('artisan', $payloadFiles, true)
        || ! in_array('composer.json', $payloadFiles, true)
        || ! in_array('composer.lock', $payloadFiles, true)
        || ! in_array('public/index.php', $payloadFiles, true)
    ) {
        throw new RuntimeException('Release payload is missing a required source file.');
    }

    $checksums = [];
    $payloadBytes = 0;
    foreach ($payloadFiles as $relative) {
        $path = $stage.'/'.$relative;
        $checksum = hash_file('sha256', $path);
        $size = filesize($path);
        if (! is_string($checksum) || ! is_int($size) || $size < 0) {
            throw new RuntimeException('Release payload metadata could not be calculated.');
        }
        $checksums[$relative] = $checksum;
        $payloadBytes += $size;
    }
    ksort($checksums, SORT_STRING);

    $schema = UpdateMigrationIdentity::fromDirectory($stage.'/database/migrations');
    $upgrade = $metadata['upgrade'] ?? null;
    if (! is_array($upgrade) || ($upgrade['to_schema_sha256'] ?? null) !== $schema) {
        throw new RuntimeException('Release metadata target schema does not match packaged migrations.');
    }

    $checksumContents = jsonObject([
        'version' => 1,
        'files' => $checksums,
    ]);
    if (file_put_contents($stage.'/release-checksums.json', $checksumContents, LOCK_EX) !== strlen($checksumContents)) {
        throw new RuntimeException('Release checksum list could not be written.');
    }

    $requiredFreeBytes = max(1, $payloadBytes * 3);
    if ($requiredFreeBytes > 20_000_000_000) {
        throw new RuntimeException('Release payload exceeds the supported disk requirement boundary.');
    }

    $manifest = [
        'version' => 1,
        'authority' => RELEASE_AUTHORITY,
        'release_id' => $releaseId,
        'application_version' => $metadata['application_version'] ?? null,
        'upgrade' => $upgrade,
        'runtime' => $metadata['runtime'] ?? null,
        'framework' => $metadata['framework'] ?? null,
        'composer_lock_sha256' => $checksums['composer.lock'],
        'checksums_sha256' => hash('sha256', $checksumContents),
        'required_free_bytes' => $requiredFreeBytes,
        'rollback' => $metadata['rollback'] ?? null,
    ];
    $manifestContents = jsonObject($manifest);
    if (file_put_contents($stage.'/release-manifest.json', $manifestContents, LOCK_EX) !== strlen($manifestContents)) {
        throw new RuntimeException('Release manifest could not be written.');
    }

    $archiveFiles = stageFiles($stage);
    $package = $output.'/freedom-platform-'.$releaseId.'.tar';
    $rebuild = $tempRoot.'/rebuild.tar';
    buildTar($stage, $archiveFiles, $package, $tempRoot.'/package-files.list');
    buildTar($stage, $archiveFiles, $rebuild, $tempRoot.'/rebuild-files.list');

    $packageSha256 = hash_file('sha256', $package);
    $rebuildSha256 = hash_file('sha256', $rebuild);
    if (! is_string($packageSha256) || ! is_string($rebuildSha256) || ! hash_equals($packageSha256, $rebuildSha256)) {
        throw new RuntimeException('Release package reproducibility verification failed.');
    }

    $verifier = new PharUpdatePackageVerifier($output);
    $verified = $verifier->verify($package, $packageSha256);
    $extracted = $tempRoot.'/verified';
    $verifier->extract($verified, $extracted);
    $verifier->verifyExtracted($verified, $extracted);

    $result = [
        'release_id' => $releaseId,
        'source_commit' => $head,
        'source_tree' => $tree,
        'package' => basename($package),
        'package_bytes' => filesize($package),
        'package_sha256' => $packageSha256,
        'manifest_sha256' => $verified->manifestSha256,
        'checksums_sha256' => hash('sha256', $checksumContents),
        'schema_sha256' => $schema,
        'payload_bytes' => $payloadBytes,
        'required_free_bytes' => $requiredFreeBytes,
        'reproducible_rebuild_sha256' => $rebuildSha256,
        'verifier' => 'PharUpdatePackageVerifier',
    ];
    echo jsonObject($result);
} catch (Throwable $throwable) {
    if (isset($package) && is_string($package) && is_file($package)) {
        @unlink($package);
    }
    throw $throwable;
} finally {
    removeTree($tempRoot);
}
