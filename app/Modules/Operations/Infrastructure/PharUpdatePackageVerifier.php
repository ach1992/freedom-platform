<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\UpdatePackageVerifier;
use App\Modules\Operations\Application\UpdateMigrationIdentity;
use App\Modules\Operations\Application\VerifiedUpdatePackage;
use PharData;
use PharFileInfo;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final readonly class PharUpdatePackageVerifier implements UpdatePackageVerifier
{
    private const MANIFEST = 'release-manifest.json';

    private const CHECKSUMS = 'release-checksums.json';

    private const AUTHORITY = 'freedom_platform_release_v1';

    private const MAX_FILES = 50_000;

    private const MAX_FILE_BYTES = 268_435_456;

    private const MAX_PAYLOAD_BYTES = 2_147_483_648;

    public function __construct(private string $packageRoot) {}

    /** @requirement UPD-001 RUN-006 SEC-001 SEC-008 QUA-001 */
    public function verify(string $packagePath, string $trustedPackageSha256): VerifiedUpdatePackage
    {
        if (! class_exists(PharData::class)) {
            throw new RuntimeException('The update archive runtime prerequisite is unavailable.');
        }

        $package = $this->validatedPackagePath($packagePath);
        $trustedPackageSha256 = strtolower(trim($trustedPackageSha256));
        $this->assertSha256($trustedPackageSha256, 'The trusted update package checksum is invalid.');

        $packageSha256 = hash_file('sha256', $package);
        if (! is_string($packageSha256) || ! hash_equals($trustedPackageSha256, $packageSha256)) {
            throw new RuntimeException('The update package trusted checksum verification failed.');
        }

        $this->assertRawTarShape($package);
        $archive = $this->archive($package);
        $entries = $this->entries($archive, $package);

        $manifestContents = $this->metadataContents($entries, self::MANIFEST);
        $checksumsContents = $this->metadataContents($entries, self::CHECKSUMS);
        $manifestSha256 = hash('sha256', $manifestContents);
        $checksumsSha256 = hash('sha256', $checksumsContents);

        $manifest = $this->decodeObject($manifestContents, 'The release manifest is invalid.');
        $checksums = $this->decodeObject($checksumsContents, 'The release checksum list is invalid.');

        if (($manifest['version'] ?? null) !== 1
            || ($manifest['authority'] ?? null) !== self::AUTHORITY
        ) {
            throw new RuntimeException('The release manifest authority or version is unsupported.');
        }

        if (($checksums['version'] ?? null) !== 1 || ! is_array($checksums['files'] ?? null)) {
            throw new RuntimeException('The release checksum list format is unsupported.');
        }

        $releaseId = $this->releaseId($manifest['release_id'] ?? null, 'The release identifier is invalid.');
        $applicationVersion = $this->boundedString(
            $manifest['application_version'] ?? null,
            1,
            64,
            'The release application version is invalid.',
        );
        $upgrade = $manifest['upgrade'] ?? null;
        if (! is_array($upgrade)) {
            throw new RuntimeException('The release upgrade compatibility metadata is missing.');
        }

        $fromRelease = $this->releaseId(
            $upgrade['from_release'] ?? null,
            'The release predecessor identifier is invalid.',
        );
        if (hash_equals($fromRelease, $releaseId)) {
            throw new RuntimeException('The release predecessor must differ from the candidate release.');
        }

        $fromApplicationVersion = $this->boundedString(
            $upgrade['from_application_version'] ?? null,
            1,
            64,
            'The predecessor application version is invalid.',
        );

        $fromSchemaSha256 = $this->sha256Value(
            $upgrade['from_schema_sha256'] ?? null,
            'The predecessor schema identity is invalid.',
        );
        $toSchemaSha256 = $this->sha256Value(
            $upgrade['to_schema_sha256'] ?? null,
            'The target schema identity is invalid.',
        );

        $runtime = $manifest['runtime'] ?? null;
        if (! is_array($runtime)) {
            throw new RuntimeException('The release runtime compatibility metadata is missing.');
        }
        $phpMinimum = $this->versionString($runtime['php_min'] ?? null, 'The minimum PHP version is invalid.');
        $phpMaximumExclusive = $this->versionString(
            $runtime['php_max_exclusive'] ?? null,
            'The maximum PHP version is invalid.',
        );
        if (version_compare($phpMinimum, $phpMaximumExclusive, '>=')
            || version_compare(PHP_VERSION, $phpMinimum, '<')
            || version_compare(PHP_VERSION, $phpMaximumExclusive, '>=')
        ) {
            throw new RuntimeException('The release PHP runtime is incompatible.');
        }

        $framework = $manifest['framework'] ?? null;
        $laravelMajor = is_array($framework)
            ? filter_var($framework['laravel_major'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 99],
            ])
            : false;
        if ($laravelMajor === false) {
            throw new RuntimeException('The release framework compatibility metadata is invalid.');
        }

        $composerLockSha256 = $this->sha256Value(
            $manifest['composer_lock_sha256'] ?? null,
            'The release Composer lock identity is invalid.',
        );
        $expectedChecksumsSha256 = $this->sha256Value(
            $manifest['checksums_sha256'] ?? null,
            'The release checksum-list identity is invalid.',
        );
        if (! hash_equals($expectedChecksumsSha256, $checksumsSha256)) {
            throw new RuntimeException('The release checksum list does not match the manifest.');
        }

        $requiredFreeBytes = filter_var($manifest['required_free_bytes'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 20_000_000_000],
        ]);
        if ($requiredFreeBytes === false) {
            throw new RuntimeException('The release disk requirement is invalid.');
        }

        $rollback = $manifest['rollback'] ?? null;
        $compatibleSchemas = is_array($rollback)
            ? ($rollback['previous_code_compatible_schema_sha256'] ?? null)
            : null;
        if (! is_array($compatibleSchemas) || count($compatibleSchemas) > 16) {
            throw new RuntimeException('The release rollback compatibility metadata is invalid.');
        }

        $rollbackCompatibleSchemaSha256 = [];
        foreach ($compatibleSchemas as $compatibleSchema) {
            $rollbackCompatibleSchemaSha256[] = $this->sha256Value(
                $compatibleSchema,
                'The release rollback schema identity is invalid.',
            );
        }
        if (count($rollbackCompatibleSchemaSha256) !== count(array_unique($rollbackCompatibleSchemaSha256))) {
            throw new RuntimeException('The release rollback compatibility metadata contains duplicates.');
        }

        $payloadChecksums = [];
        foreach ($checksums['files'] as $path => $sha256) {
            if (! is_string($path)) {
                throw new RuntimeException('The release checksum list contains an invalid path.');
            }
            $this->assertSafeRelativePath($path);
            $this->assertPayloadPath($path);
            $payloadChecksums[$path] = $this->sha256Value(
                $sha256,
                'The release checksum list contains an invalid checksum.',
            );
        }
        if ($payloadChecksums === []) {
            throw new RuntimeException('The release checksum list is empty.');
        }

        $payloadFiles = [];
        $payloadBytes = 0;
        foreach ($entries as $relative => $entry) {
            if ($entry->isDir()) {
                continue;
            }
            if (in_array($relative, [self::MANIFEST, self::CHECKSUMS], true)) {
                continue;
            }
            $this->assertPayloadPath($relative);
            $payloadFiles[] = $relative;
            $payloadBytes += $entry->getSize();
        }

        sort($payloadFiles, SORT_STRING);
        $checksumFiles = array_keys($payloadChecksums);
        sort($checksumFiles, SORT_STRING);
        if ($payloadFiles !== $checksumFiles) {
            throw new RuntimeException('The release archive contents do not exactly match the checksum list.');
        }

        foreach (['artisan', 'composer.json', 'composer.lock', 'public/index.php', 'RELEASE_NOTES.md'] as $required) {
            if (! isset($payloadChecksums[$required])) {
                throw new RuntimeException('The release package is missing a required file.');
            }
        }

        $migrationChecksums = [];
        foreach ($payloadChecksums as $path => $checksum) {
            if (preg_match('/\Adatabase\/migrations\/([^\/]+)\.php\z/', $path, $matches) === 1) {
                $migrationChecksums[$matches[1]] = $checksum;
            }
        }
        $releaseSchemaSha256 = UpdateMigrationIdentity::fromChecksums($migrationChecksums);
        if (! hash_equals($toSchemaSha256, $releaseSchemaSha256)) {
            throw new RuntimeException('The target schema identity does not match the packaged migrations.');
        }

        foreach ($payloadChecksums as $path => $expectedSha256) {
            $entry = $entries[$path] ?? null;
            if (! $entry instanceof PharFileInfo || ! $entry->isFile()) {
                throw new RuntimeException('A checksummed release file is unavailable.');
            }
            $contents = $entry->getContent();
            if (! is_string($contents) || ! hash_equals($expectedSha256, hash('sha256', $contents))) {
                throw new RuntimeException('A release file checksum verification failed.');
            }
        }

        if (! hash_equals($composerLockSha256, $payloadChecksums['composer.lock'])) {
            throw new RuntimeException('The release Composer lock checksum does not match the manifest.');
        }

        $composerLock = $this->decodeObject(
            $entries['composer.lock']->getContent(),
            'The packaged Composer lock file is invalid.',
        );
        $actualLaravelMajor = $this->laravelMajor($composerLock);
        if ($actualLaravelMajor !== (int) $laravelMajor) {
            throw new RuntimeException('The packaged Laravel framework version is incompatible with the manifest.');
        }

        return new VerifiedUpdatePackage(
            packagePath: $package,
            packageSha256: $packageSha256,
            manifestSha256: $manifestSha256,
            releaseId: $releaseId,
            applicationVersion: $applicationVersion,
            fromRelease: $fromRelease,
            fromApplicationVersion: $fromApplicationVersion,
            fromSchemaSha256: $fromSchemaSha256,
            toSchemaSha256: $toSchemaSha256,
            rollbackCompatibleSchemaSha256: $rollbackCompatibleSchemaSha256,
            composerLockSha256: $composerLockSha256,
            laravelMajor: (int) $laravelMajor,
            phpMinimum: $phpMinimum,
            phpMaximumExclusive: $phpMaximumExclusive,
            requiredFreeBytes: (int) $requiredFreeBytes,
            payloadBytes: $payloadBytes,
            checksumsSha256: $checksumsSha256,
            payloadChecksums: $payloadChecksums,
        );
    }

    /** @requirement UPD-001 RUN-006 SEC-001 SEC-008 QUA-001 */
    public function extract(VerifiedUpdatePackage $package, string $destination): void
    {
        $verifiedPath = $this->validatedPackagePath($package->packagePath);
        $currentSha256 = hash_file('sha256', $verifiedPath);
        if (! is_string($currentSha256) || ! hash_equals($package->packageSha256, $currentSha256)) {
            throw new RuntimeException('The verified update package changed before extraction.');
        }

        if (! str_starts_with($destination, DIRECTORY_SEPARATOR)
            || is_link($destination)
        ) {
            throw new RuntimeException('The update staging destination is invalid.');
        }
        $parent = realpath(dirname($destination));
        if ($parent === false || ! is_dir($parent) || is_link(dirname($destination))) {
            throw new RuntimeException('The update staging parent is unavailable.');
        }

        if (file_exists($destination)) {
            $resolvedDestination = realpath($destination);
            $entries = scandir($destination);
            $mode = fileperms($destination);
            if ($resolvedDestination === false
                || ! is_dir($resolvedDestination)
                || dirname($resolvedDestination) !== $parent
                || ! is_array($entries)
                || array_values(array_diff($entries, ['.', '..'])) !== []
                || ! is_int($mode)
                || ($mode & 0022) !== 0
                || ! is_writable($resolvedDestination)
            ) {
                throw new RuntimeException('The pre-created update staging directory is unsafe or not empty.');
            }
        } elseif (! mkdir($destination, 0750) || ! is_dir($destination)) {
            throw new RuntimeException('The update staging directory could not be created.');
        }

        try {
            $archive = $this->archive($verifiedPath);
            $entries = $this->entries($archive, $verifiedPath);
            foreach ($entries as $relative => $entry) {
                $target = $destination.'/'.$relative;
                if ($entry->isDir()) {
                    if (! is_dir($target) && ! mkdir($target, 0750, true) && ! is_dir($target)) {
                        throw new RuntimeException('A release staging directory could not be created.');
                    }
                    if (! chmod($target, 0750)) {
                        throw new RuntimeException('A release staging directory permission could not be set.');
                    }

                    continue;
                }

                $directory = dirname($target);
                if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
                    throw new RuntimeException('A release staging parent could not be created.');
                }

                $contents = $entry->getContent();
                $fileMode = ($entry->getPerms() & 0111) !== 0 ? 0750 : 0640;
                if (! is_string($contents)
                    || file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)
                    || ! chmod($target, $fileMode)
                ) {
                    throw new RuntimeException('A release file could not be staged.');
                }
            }

            $this->verifyExtracted($package, $destination);
        } catch (Throwable $throwable) {
            $this->removeTree($destination);

            throw $throwable;
        }
    }

    /** @requirement UPD-001 RUN-002 RUN-006 SEC-001 SEC-008 QUA-001 */
    public function verifyExtracted(VerifiedUpdatePackage $package, string $releasePath): void
    {
        if (! str_starts_with($releasePath, DIRECTORY_SEPARATOR)
            || is_link($releasePath)
        ) {
            throw new RuntimeException('The staged release path is unavailable or unsafe.');
        }

        $release = realpath($releasePath);
        if ($release === false || ! is_dir($release)) {
            throw new RuntimeException('The staged release path is unavailable or unsafe.');
        }

        $manifestHash = hash_file('sha256', $release.'/'.self::MANIFEST);
        $checksumsHash = hash_file('sha256', $release.'/'.self::CHECKSUMS);
        if (! is_string($manifestHash)
            || ! is_string($checksumsHash)
            || ! hash_equals($package->manifestSha256, $manifestHash)
            || ! hash_equals($package->checksumsSha256, $checksumsHash)
        ) {
            throw new RuntimeException('The staged release metadata failed verification.');
        }

        foreach ($package->payloadChecksums as $relative => $expectedSha256) {
            $path = $release.'/'.$relative;
            if (! is_file($path) || is_link($path)) {
                throw new RuntimeException('A staged release file is unavailable or unsafe.');
            }
            $actual = hash_file('sha256', $path);
            if (! is_string($actual) || ! hash_equals($expectedSha256, $actual)) {
                throw new RuntimeException('A staged release file failed checksum verification.');
            }
        }
    }

    private function assertRawTarShape(string $packagePath): void
    {
        $handle = fopen($packagePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The update package archive could not be inspected.');
        }

        $entries = 0;
        $totalBytes = 0;
        $zeroBlocks = 0;

        try {
            while (! feof($handle)) {
                $header = fread($handle, 512);
                if ($header === false || ($header !== '' && strlen($header) !== 512)) {
                    throw new RuntimeException('The update package TAR header is truncated.');
                }
                if ($header === '') {
                    break;
                }

                if ($header === str_repeat("\0", 512)) {
                    $zeroBlocks++;
                    if ($zeroBlocks >= 2) {
                        while (! feof($handle)) {
                            $trailing = fread($handle, 8192);
                            if ($trailing === false) {
                                throw new RuntimeException('The update package TAR trailer is unreadable.');
                            }
                            if ($trailing !== '' && trim($trailing, "\0") !== '') {
                                throw new RuntimeException('The update package TAR contains data after its end marker.');
                            }
                        }
                        break;
                    }

                    continue;
                }

                if ($zeroBlocks !== 0) {
                    throw new RuntimeException('The update package TAR end marker is malformed.');
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $relative = $prefix === '' ? $name : $prefix.'/'.$name;
                $this->assertSafeRelativePath($relative);

                $type = $header[156] ?? "\0";
                if (! in_array($type, ["\0", '0', '5'], true)) {
                    throw new RuntimeException('The update package contains an unsupported link or special entry.');
                }

                $sizeField = trim(substr($header, 124, 12), "\0 ");
                if ($sizeField !== '' && preg_match('/\A[0-7]+\z/', $sizeField) !== 1) {
                    throw new RuntimeException('The update package TAR file size is invalid.');
                }
                $size = $sizeField === '' ? 0 : octdec($sizeField);
                if ($size < 0 || $size > self::MAX_FILE_BYTES) {
                    throw new RuntimeException('The update package contains an oversized file.');
                }
                if ($type === '5' && $size !== 0) {
                    throw new RuntimeException('The update package directory entry is malformed.');
                }

                $entries++;
                if ($entries > self::MAX_FILES) {
                    throw new RuntimeException('The update package contains too many entries.');
                }
                $totalBytes += $size;
                if ($totalBytes > self::MAX_PAYLOAD_BYTES) {
                    throw new RuntimeException('The update package payload is too large.');
                }

                $padded = (int) (ceil($size / 512) * 512);
                if ($padded > 0 && fseek($handle, $padded, SEEK_CUR) !== 0) {
                    throw new RuntimeException('The update package TAR payload is truncated.');
                }
            }
        } finally {
            fclose($handle);
        }

        if ($entries < 3 || $zeroBlocks < 2) {
            throw new RuntimeException('The update package TAR shape is incomplete.');
        }
    }

    private function validatedPackagePath(string $packagePath): string
    {
        $root = realpath($this->packageRoot);
        $package = realpath($packagePath);

        if ($root === false
            || ! is_dir($root)
            || is_link($this->packageRoot)
            || $package === false
            || dirname($package) !== $root
            || ! is_file($package)
            || is_link($packagePath)
            || ! is_readable($package)
            || strtolower(pathinfo($package, PATHINFO_EXTENSION)) !== 'tar'
        ) {
            throw new RuntimeException('The update package path is unavailable or outside the controlled package root.');
        }

        return $package;
    }

    private function archive(string $packagePath): PharData
    {
        try {
            return new PharData($packagePath);
        } catch (Throwable $throwable) {
            throw new RuntimeException('The update package archive is invalid.', 0, $throwable);
        }
    }

    /** @return array<string, PharFileInfo> */
    private function entries(PharData $archive, string $packagePath): array
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $entry) {
            if (! $entry instanceof PharFileInfo) {
                throw new RuntimeException('The update package contains an unsupported archive entry.');
            }

            $relative = $this->relativeName($entry, $packagePath);
            $this->assertSafeRelativePath($relative);

            if (isset($entries[$relative])) {
                throw new RuntimeException('The update package contains a duplicate archive path.');
            }
            if ($entry->isLink()
                || (($entry->getPerms() & 0170000) === 0120000)
                || (! $entry->isFile() && ! $entry->isDir())
            ) {
                throw new RuntimeException('The update package contains an unsupported link or special entry.');
            }
            if ($entry->isFile()
                && ($entry->getSize() < 0 || $entry->getSize() > self::MAX_FILE_BYTES)
            ) {
                throw new RuntimeException('The update package contains an oversized file.');
            }

            $entries[$relative] = $entry;
            if (count($entries) > self::MAX_FILES) {
                throw new RuntimeException('The update package contains too many entries.');
            }
        }

        if ($entries === []
            || ! isset($entries[self::MANIFEST], $entries[self::CHECKSUMS])
            || count(array_filter($entries, static fn (PharFileInfo $entry): bool => $entry->isFile())) < 3
        ) {
            throw new RuntimeException('The update package shape is incomplete.');
        }

        $payloadBytes = 0;
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $payloadBytes += $entry->getSize();
                if ($payloadBytes > self::MAX_PAYLOAD_BYTES) {
                    throw new RuntimeException('The update package payload is too large.');
                }
            }
        }

        return $entries;
    }

    private function relativeName(PharFileInfo $entry, string $packagePath): string
    {
        $path = str_replace('\\', '/', $entry->getPathName());
        $prefix = 'phar://'.str_replace('\\', '/', $packagePath).'/';

        if (! str_starts_with($path, $prefix)) {
            throw new RuntimeException('The update package archive path could not be resolved safely.');
        }

        return substr($path, strlen($prefix));
    }

    /** @param array<string, PharFileInfo> $entries */
    private function metadataContents(array $entries, string $name): string
    {
        $entry = $entries[$name] ?? null;
        if (! $entry instanceof PharFileInfo || ! $entry->isFile() || $entry->getSize() > 1_048_576) {
            throw new RuntimeException('The update package metadata is unavailable or oversized.');
        }

        $contents = $entry->getContent();
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('The update package metadata is unreadable.');
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json, string $message): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RuntimeException($message, 0, $throwable);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    private function assertSafeRelativePath(string $path): void
    {
        if ($path === ''
            || strlen($path) > 1024
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            throw new RuntimeException('The update package contains an unsafe path.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('The update package contains path traversal.');
            }
        }
    }

    private function assertPayloadPath(string $path): void
    {
        if ($path === '.env'
            || $path === 'storage'
            || str_starts_with($path, 'storage/')
            || $path === 'vendor'
            || str_starts_with($path, 'vendor/')
            || (str_starts_with($path, 'bootstrap/cache/') && $path !== 'bootstrap/cache/.gitignore')
            || in_array($path, [self::MANIFEST, self::CHECKSUMS], true)
        ) {
            throw new RuntimeException('The update package contains an untrusted or reserved payload path.');
        }
    }

    private function releaseId(mixed $value, string $message): string
    {
        if (! is_string($value)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $value) !== 1
            || str_contains($value, '..')
        ) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private function boundedString(mixed $value, int $minimum, int $maximum, string $message): string
    {
        if (! is_string($value)
            || strlen($value) < $minimum
            || strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private function sha256Value(mixed $value, string $message): string
    {
        if (! is_string($value)) {
            throw new RuntimeException($message);
        }
        $value = strtolower($value);
        $this->assertSha256($value, $message);

        return $value;
    }

    private function assertSha256(string $value, string $message): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new RuntimeException($message);
        }
    }

    private function versionString(mixed $value, string $message): string
    {
        if (! is_string($value)
            || preg_match('/\A\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?\z/', $value) !== 1
        ) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    /** @param array<string, mixed> $composerLock */
    private function laravelMajor(array $composerLock): int
    {
        $packages = $composerLock['packages'] ?? null;
        if (! is_array($packages)) {
            throw new RuntimeException('The packaged Composer lock file has no production package set.');
        }

        foreach ($packages as $package) {
            if (! is_array($package) || ($package['name'] ?? null) !== 'laravel/framework') {
                continue;
            }

            $version = $package['version'] ?? null;
            if (! is_string($version) || preg_match('/\Av?(\d+)\./', $version, $matches) !== 1) {
                throw new RuntimeException('The packaged Laravel framework version is invalid.');
            }

            return (int) $matches[1];
        }

        throw new RuntimeException('The packaged Laravel framework dependency is missing.');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException('An update staging file could not be removed safely.');
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if (! is_array($entries)) {
            throw new RuntimeException('An update staging directory could not be inspected safely.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path.'/'.$entry);
        }

        if (! rmdir($path)) {
            throw new RuntimeException('An update staging directory could not be removed safely.');
        }
    }
}
