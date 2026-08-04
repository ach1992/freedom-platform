<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\ReleaseHealthVerifier;
use RuntimeException;
use Throwable;

final readonly class FilesystemReleaseActivator
{
    /** @requirement RUN-001 RUN-002 RUN-003 INS-001 SEC-010 QUA-011 */
    public function __construct(
        private ReleaseHealthVerifier $healthVerifier,
        private string $deploymentRoot,
        private string $journalPath,
    ) {}

    /**
     * @return array{status: 'activated'|'already_active', release: string, previous_release: string|null}
     */
    public function activate(string $releaseId): array
    {
        return $this->synchronized(fn (): array => $this->switchTo('activate', $releaseId));
    }

    /**
     * @return array{status: 'rolled_back'|'already_active', release: string, previous_release: string|null}
     */
    public function rollbackTo(string $releaseId): array
    {
        return $this->synchronized(fn (): array => $this->switchTo('rollback', $releaseId));
    }

    /**
     * @return array{status: 'activated'|'rolled_back'|'already_active', release: string, previous_release: string|null}
     */
    private function switchTo(string $action, string $releaseId): array
    {
        $root = $this->validatedRoot();
        $releases = $this->validatedReleasesDirectory($root);
        $candidate = $this->validatedCandidate($releases, $releaseId);
        $shared = $this->validatedSharedResources($root);
        $this->recoverTemporaryLink($root);
        $this->prepareSharedLinks($candidate, $shared['environment'], $shared['storage']);

        $previousRelease = $this->currentReleaseId($root, $releases);

        if ($previousRelease === $releaseId) {
            $this->writeJournal([
                'action' => $action,
                'release' => $releaseId,
                'previous_release' => $previousRelease,
                'status' => 'already_active',
                'composer_lock_sha256' => hash_file('sha256', $candidate.'/composer.lock'),
            ]);

            return [
                'status' => 'already_active',
                'release' => $releaseId,
                'previous_release' => $previousRelease,
            ];
        }

        $this->writeJournal([
            'action' => $action,
            'release' => $releaseId,
            'previous_release' => $previousRelease,
            'status' => 'activating',
            'composer_lock_sha256' => hash_file('sha256', $candidate.'/composer.lock'),
        ]);
        $this->replaceCurrent($root, $releaseId);

        try {
            $this->healthVerifier->verify($candidate);
        } catch (Throwable) {
            try {
                $this->restorePrevious($root, $previousRelease);
                $this->writeJournal([
                    'action' => $action,
                    'release' => $releaseId,
                    'previous_release' => $previousRelease,
                    'status' => 'verification_failed_rolled_back',
                    'composer_lock_sha256' => hash_file('sha256', $candidate.'/composer.lock'),
                ]);
            } catch (Throwable) {
                $this->writeJournal([
                    'action' => $action,
                    'release' => $releaseId,
                    'previous_release' => $previousRelease,
                    'status' => 'automatic_rollback_failed',
                    'composer_lock_sha256' => hash_file('sha256', $candidate.'/composer.lock'),
                ]);

                throw new RuntimeException('Release verification failed and automatic rollback could not be completed.');
            }

            throw new RuntimeException('Release verification failed and the previous release was restored.');
        }

        $status = $action === 'rollback' ? 'rolled_back' : 'activated';
        $this->writeJournal([
            'action' => $action,
            'release' => $releaseId,
            'previous_release' => $previousRelease,
            'status' => $status,
            'composer_lock_sha256' => hash_file('sha256', $candidate.'/composer.lock'),
        ]);

        return [
            'status' => $status,
            'release' => $releaseId,
            'previous_release' => $previousRelease,
        ];
    }

    private function validatedRoot(): string
    {
        if (! str_starts_with($this->deploymentRoot, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The deployment root must be absolute.');
        }

        $root = realpath($this->deploymentRoot);

        if ($root === false || ! is_dir($root) || is_link($this->deploymentRoot)) {
            throw new RuntimeException('The deployment root is unavailable.');
        }

        return $root;
    }

    private function validatedReleasesDirectory(string $root): string
    {
        $releases = realpath($root.'/releases');

        if ($releases === false || ! is_dir($releases) || dirname($releases) !== $root) {
            throw new RuntimeException('The releases directory is unavailable.');
        }

        return $releases;
    }

    private function validatedCandidate(string $releases, string $releaseId): string
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $releaseId) !== 1
            || str_contains($releaseId, '..')
        ) {
            throw new RuntimeException('The release identifier is invalid.');
        }

        $candidatePath = $releases.'/'.$releaseId;
        $candidate = realpath($candidatePath);

        if ($candidate === false
            || ! is_dir($candidate)
            || is_link($candidatePath)
            || dirname($candidate) !== $releases
            || basename($candidate) !== $releaseId
        ) {
            throw new RuntimeException('The candidate release is unavailable or unsafe.');
        }

        foreach (['artisan', 'public/index.php', 'composer.lock'] as $required) {
            $path = $candidate.'/'.$required;

            if (! is_file($path) || is_link($path) || ! is_readable($path)) {
                throw new RuntimeException('The candidate release is incomplete.');
            }
        }

        return $candidate;
    }

    /** @return array{environment: string, storage: string} */
    private function validatedSharedResources(string $root): array
    {
        $shared = realpath($root.'/shared');

        if ($shared === false || ! is_dir($shared) || dirname($shared) !== $root) {
            throw new RuntimeException('The shared deployment directory is unavailable.');
        }

        $environment = realpath($shared.'/.env');
        $storage = realpath($shared.'/storage');

        if ($environment === false
            || ! is_file($environment)
            || is_link($shared.'/.env')
            || dirname($environment) !== $shared
            || ! is_readable($environment)
        ) {
            throw new RuntimeException('The shared environment file is unavailable or unsafe.');
        }

        if ($storage === false
            || ! is_dir($storage)
            || is_link($shared.'/storage')
            || dirname($storage) !== $shared
        ) {
            throw new RuntimeException('The shared storage directory is unavailable or unsafe.');
        }

        return ['environment' => $environment, 'storage' => $storage];
    }

    private function prepareSharedLinks(string $candidate, string $environment, string $storage): void
    {
        $this->ensureApprovedLink($candidate.'/.env', '../../shared/.env', $environment);
        $this->ensureApprovedLink($candidate.'/storage', '../../shared/storage', $storage);
    }

    private function ensureApprovedLink(string $linkPath, string $relativeTarget, string $expectedRealPath): void
    {
        if (file_exists($linkPath) || is_link($linkPath)) {
            if (! is_link($linkPath) || realpath($linkPath) !== $expectedRealPath) {
                throw new RuntimeException('The candidate contains an unapproved shared-resource path.');
            }

            return;
        }

        if (! symlink($relativeTarget, $linkPath) || realpath($linkPath) !== $expectedRealPath) {
            @unlink($linkPath);

            throw new RuntimeException('The shared deployment link could not be prepared.');
        }
    }

    private function currentReleaseId(string $root, string $releases): ?string
    {
        $current = $root.'/current';

        if (! file_exists($current) && ! is_link($current)) {
            return null;
        }

        if (! is_link($current)) {
            throw new RuntimeException('The current release pointer is not a symlink.');
        }

        $resolved = realpath($current);

        if ($resolved === false || dirname($resolved) !== $releases) {
            throw new RuntimeException('The current release pointer is unsafe.');
        }

        return basename($resolved);
    }

    private function recoverTemporaryLink(string $root): void
    {
        $temporary = $root.'/.current.next';

        if (! file_exists($temporary) && ! is_link($temporary)) {
            return;
        }

        if (! is_link($temporary) || ! unlink($temporary)) {
            throw new RuntimeException('A stale release activation path could not be recovered safely.');
        }
    }

    private function replaceCurrent(string $root, string $releaseId): void
    {
        $current = $root.'/current';
        $temporary = $root.'/.current.next';

        if (! symlink('releases/'.$releaseId, $temporary)) {
            throw new RuntimeException('The temporary release pointer could not be created.');
        }

        if (! rename($temporary, $current)) {
            if (is_link($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException('The current release pointer could not be replaced atomically.');
        }
    }

    private function restorePrevious(string $root, ?string $previousRelease): void
    {
        $current = $root.'/current';

        if ($previousRelease === null) {
            if ((file_exists($current) || is_link($current)) && (! is_link($current) || ! unlink($current))) {
                throw new RuntimeException('The failed first activation could not be removed.');
            }

            return;
        }

        $this->recoverTemporaryLink($root);
        $this->replaceCurrent($root, $previousRelease);
    }

    /**
     * @param  array{action: string, release: string, previous_release: string|null, status: string, composer_lock_sha256: string|false}  $record
     */
    private function writeJournal(array $record): void
    {
        if (! is_string($record['composer_lock_sha256'])) {
            throw new RuntimeException('The release manifest hash could not be calculated.');
        }

        $contents = json_encode([
            'version' => 1,
            'action' => $record['action'],
            'release' => $record['release'],
            'previous_release' => $record['previous_release'],
            'status' => $record['status'],
            'composer_lock_sha256' => $record['composer_lock_sha256'],
            'updated_at' => gmdate(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        $this->atomicWrite($this->journalPath, $contents);
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The release journal directory could not be prepared.');
        }

        $temporary = tempnam($directory, '.release-journal-');

        if ($temporary === false) {
            throw new RuntimeException('The release journal temporary file could not be created.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)
            ) {
                throw new RuntimeException('The release journal could not be written atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function synchronized(callable $callback): mixed
    {
        $root = $this->validatedRoot();
        $lockPath = $root.'/shared/release-activation.lock';
        $handle = fopen($lockPath, 'c+b');

        if ($handle === false) {
            throw new RuntimeException('The release activation lock could not be opened.');
        }

        try {
            if (! flock($handle, LOCK_EX) || ! chmod($lockPath, 0600)) {
                throw new RuntimeException('The release activation lock could not be acquired securely.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
