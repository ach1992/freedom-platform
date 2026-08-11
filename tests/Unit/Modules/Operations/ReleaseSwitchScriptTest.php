<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/** @requirement RUN-001 RUN-002 RUN-003 INS-001 SEC-008 SEC-010 QUA-011 */
final class ReleaseSwitchScriptTest extends TestCase
{
    public function test_cli_entry_point_activates_release_and_returns_only_status_metadata(): void
    {
        [$root, $release, $healthArguments] = $this->deployment('success');
        $process = new Process([
            PHP_BINARY,
            base_path('deploy/bin/release-switch.php'),
            '--root='.$root,
            '--release=1.0.0',
            '--php='.PHP_BINARY,
            '--timeout=5',
        ], base_path(), null, null, 15);

        try {
            $process->mustRun();
            $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame([
                'status' => 'activated',
                'release' => '1.0.0',
                'previous_release' => null,
            ], $result);
            $this->assertSame($release, realpath($root.'/current'));
            $this->assertSame(
                'health:check --critical --json --redact --no-ansi --no-interaction',
                trim((string) file_get_contents($healthArguments)),
            );
            $this->assertStringNotContainsString('test-only-deployment-secret', $process->getOutput());
            $this->assertSame('', $process->getErrorOutput());
        } finally {
            $this->removeTree($root);
        }
    }

    public function test_cli_entry_point_returns_generic_failure_without_paths_or_secrets(): void
    {
        [$root] = $this->deployment('failure');
        $process = new Process([
            PHP_BINARY,
            base_path('deploy/bin/release-switch.php'),
            '--root='.$root,
            '--release=../unsafe',
            '--php='.PHP_BINARY,
            '--timeout=5',
        ], base_path(), null, null, 15);

        try {
            $process->run();

            $this->assertFalse($process->isSuccessful());
            $this->assertSame('', $process->getOutput());
            $this->assertSame("Release switch failed.\n", $process->getErrorOutput());
            $this->assertStringNotContainsString($root, $process->getErrorOutput());
            $this->assertStringNotContainsString('test-only-deployment-secret', $process->getErrorOutput());
            $this->assertFalse(is_link($root.'/current'));
        } finally {
            $this->removeTree($root);
        }
    }

    /** @return array{string, string, string} */
    private function deployment(string $case): array
    {
        $root = storage_path('framework/testing/release-switch-cli-'.$case.'-'.bin2hex(random_bytes(4)));
        $release = $root.'/releases/1.0.0';
        $healthArguments = $release.'/health-arguments.log';
        mkdir($release.'/public', 0700, true);
        mkdir($root.'/shared/storage', 0700, true);
        file_put_contents($root.'/shared/.env', "APP_KEY=test-only-deployment-secret\n");
        file_put_contents($release.'/public/index.php', "<?php\n");
        file_put_contents($release.'/composer.lock', '{"packages":[]}');
        file_put_contents(
            $release.'/artisan',
            sprintf(
                <<<'PHP'
<?php

declare(strict_types=1);

file_put_contents(%s, implode(' ', array_slice($argv, 1)));
PHP,
                var_export($healthArguments, true),
            ),
        );

        return [$root, (string) realpath($release), $healthArguments];
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
