<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\Contracts\ReleaseHealthVerifier;
use App\Modules\Operations\Infrastructure\FilesystemReleaseActivator;
use RuntimeException;
use Tests\TestCase;

final class FilesystemReleaseActivatorTest extends TestCase
{
    /** @requirement RUN-001 RUN-002 RUN-003 INS-001 SEC-010 QUA-011 */
    public function test_first_activation_prepares_shared_links_switches_current_and_writes_redacted_journal(): void
    {
        $root = $this->deployment('first');
        $candidate = $this->release($root, '1.0.0');
        $verifier = new RecordingReleaseHealthVerifier;
        $activator = $this->activator($root, $verifier);

        try {
            $result = $activator->activate('1.0.0');

            $this->assertSame([
                'status' => 'activated',
                'release' => '1.0.0',
                'previous_release' => null,
            ], $result);
            $this->assertSame($candidate, realpath($root.'/current'));
            $this->assertSame($root.'/shared/.env', realpath($candidate.'/.env'));
            $this->assertSame($root.'/shared/storage', realpath($candidate.'/storage'));
            $this->assertSame(['1.0.0'], $verifier->releases);

            $journal = (string) file_get_contents($root.'/shared/release-journal.json');
            $this->assertStringContainsString('"status": "activated"', $journal);
            $this->assertStringContainsString('"release": "1.0.0"', $journal);
            $this->assertStringNotContainsString('test-only-environment-secret', $journal);
            $this->assertSame(0600, fileperms($root.'/shared/release-journal.json') & 0777);
            $this->assertSame(0600, fileperms($root.'/shared/release-activation.lock') & 0777);
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement RUN-001 RUN-002 RUN-003 INS-001 SEC-010 QUA-011 */
    public function test_upgrade_and_explicit_rollback_use_atomic_current_pointer(): void
    {
        $root = $this->deployment('upgrade');
        $first = $this->release($root, '1.0.0');
        $second = $this->release($root, '1.1.0');
        $verifier = new RecordingReleaseHealthVerifier;
        $activator = $this->activator($root, $verifier);

        try {
            $activator->activate('1.0.0');
            $upgrade = $activator->activate('1.1.0');
            $rollback = $activator->rollbackTo('1.0.0');

            $this->assertSame($second, realpath($root.'/releases/1.1.0'));
            $this->assertSame('1.0.0', $upgrade['previous_release']);
            $this->assertSame('rolled_back', $rollback['status']);
            $this->assertSame('1.1.0', $rollback['previous_release']);
            $this->assertSame($first, realpath($root.'/current'));
            $this->assertSame(['1.0.0', '1.1.0', '1.0.0'], $verifier->releases);
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement RUN-001 RUN-002 RUN-003 INS-001 SEC-010 QUA-011 */
    public function test_failed_upgrade_health_verification_restores_previous_release(): void
    {
        $root = $this->deployment('failed-upgrade');
        $first = $this->release($root, '1.0.0');
        $this->release($root, '1.1.0');
        $verifier = new RecordingReleaseHealthVerifier('1.1.0');
        $activator = $this->activator($root, $verifier);

        try {
            $activator->activate('1.0.0');

            try {
                $activator->activate('1.1.0');
                $this->fail('A failed candidate health check must abort activation.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Release verification failed and the previous release was restored.',
                    $exception->getMessage(),
                );
                $this->assertStringNotContainsString('test-only-health-details', $exception->getMessage());
            }

            $this->assertSame($first, realpath($root.'/current'));
            $journal = (string) file_get_contents($root.'/shared/release-journal.json');
            $this->assertStringContainsString('verification_failed_rolled_back', $journal);
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement RUN-001 RUN-002 RUN-003 INS-001 SEC-010 QUA-011 */
    public function test_failed_first_activation_removes_current_pointer(): void
    {
        $root = $this->deployment('failed-first');
        $this->release($root, '1.0.0');
        $activator = $this->activator($root, new RecordingReleaseHealthVerifier('1.0.0'));

        try {
            try {
                $activator->activate('1.0.0');
                $this->fail('A failed first release must not remain active.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Release verification failed and the previous release was restored.',
                    $exception->getMessage(),
                );
            }

            $this->assertFileDoesNotExist($root.'/current');
            $this->assertFalse(is_link($root.'/current'));
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement RUN-001 RUN-002 RUN-003 INS-001 SEC-010 QUA-011 */
    public function test_it_rejects_traversal_symlink_escape_and_unsafe_current_pointer(): void
    {
        $root = $this->deployment('unsafe');
        $this->release($root, '1.0.0');
        $outside = $root.'-outside';
        mkdir($outside, 0700, true);
        $this->releaseAt($outside);
        symlink($outside, $root.'/releases/escape');
        $activator = $this->activator($root, new RecordingReleaseHealthVerifier);

        try {
            foreach (['../1.0.0', 'escape'] as $releaseId) {
                try {
                    $activator->activate($releaseId);
                    $this->fail('An unsafe release target must be rejected.');
                } catch (RuntimeException $exception) {
                    $this->assertNotSame('', $exception->getMessage());
                }
            }

            mkdir($root.'/current', 0700);

            try {
                $activator->activate('1.0.0');
                $this->fail('A non-symlink current path must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The current release pointer is not a symlink.', $exception->getMessage());
            }
        } finally {
            $this->removeTree($root);
            $this->removeTree($outside);
        }
    }

    /** @requirement RUN-001 RUN-002 RUN-003 INS-001 SEC-010 QUA-011 */
    public function test_it_rejects_incomplete_release_and_missing_shared_resources(): void
    {
        $root = $this->deployment('missing');
        mkdir($root.'/releases/1.0.0', 0700, true);
        $activator = $this->activator($root, new RecordingReleaseHealthVerifier);

        try {
            try {
                $activator->activate('1.0.0');
                $this->fail('An incomplete release must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The candidate release is incomplete.', $exception->getMessage());
            }

            $this->releaseAt($root.'/releases/1.0.0');
            unlink($root.'/shared/.env');

            try {
                $activator->activate('1.0.0');
                $this->fail('Missing shared environment must block activation.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The shared environment file is unavailable or unsafe.', $exception->getMessage());
            }
        } finally {
            $this->removeTree($root);
        }
    }

    /** @requirement RUN-001 RUN-002 RUN-003 INS-001 SEC-010 QUA-011 */
    public function test_stale_temporary_symlink_is_removed_but_a_regular_file_is_refused(): void
    {
        $root = $this->deployment('stale');
        $candidate = $this->release($root, '1.0.0');
        $activator = $this->activator($root, new RecordingReleaseHealthVerifier);

        try {
            symlink('releases/missing', $root.'/.current.next');
            $activator->activate('1.0.0');
            $this->assertSame($candidate, realpath($root.'/current'));
            $this->assertFalse(is_link($root.'/.current.next'));

            unlink($root.'/current');
            file_put_contents($root.'/.current.next', 'unsafe');

            try {
                $activator->activate('1.0.0');
                $this->fail('A regular stale temporary path must not be deleted automatically.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'A stale release activation path could not be recovered safely.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $this->removeTree($root);
        }
    }

    private function activator(string $root, ReleaseHealthVerifier $verifier): FilesystemReleaseActivator
    {
        return new FilesystemReleaseActivator(
            $verifier,
            $root,
            $root.'/shared/release-journal.json',
        );
    }

    private function deployment(string $case): string
    {
        $root = storage_path('framework/testing/release-activation-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($root.'/releases', 0700, true);
        mkdir($root.'/shared/storage', 0700, true);
        file_put_contents($root.'/shared/.env', "APP_KEY=test-only-environment-secret\n");

        return $root;
    }

    private function release(string $root, string $releaseId): string
    {
        $path = $root.'/releases/'.$releaseId;
        $this->releaseAt($path);

        return (string) realpath($path);
    }

    private function releaseAt(string $path): void
    {
        if (! is_dir($path.'/public') && ! mkdir($path.'/public', 0700, true) && ! is_dir($path.'/public')) {
            $this->fail('Could not create release fixture.');
        }

        file_put_contents($path.'/artisan', "<?php\n");
        file_put_contents($path.'/public/index.php', "<?php\n");
        file_put_contents($path.'/composer.lock', '{"packages":[]}');
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeTree($path.'/'.$entry);
        }

        @rmdir($path);
    }
}

final class RecordingReleaseHealthVerifier implements ReleaseHealthVerifier
{
    /** @var list<string> */
    public array $releases = [];

    public function __construct(private readonly ?string $failRelease = null) {}

    public function verify(string $releasePath): void
    {
        $release = basename($releasePath);
        $this->releases[] = $release;

        if ($release === $this->failRelease) {
            throw new RuntimeException('test-only-health-details');
        }
    }
}
