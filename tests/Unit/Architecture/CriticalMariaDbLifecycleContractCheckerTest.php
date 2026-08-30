<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FreedomPlatform\CI\CriticalMariaDbLifecycleContractChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 3).'/scripts/ci/CriticalMariaDbLifecycleContractChecker.php';

final class CriticalMariaDbLifecycleContractCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/freedom-critical-mariadb-lifecycle-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_complete_two_surface_contract_is_accepted(): void
    {
        $this->write('database/migrations/a.php', "<?php\nreturn null;");
        $this->write('database/migrations/b.php', "<?php\nreturn null;");
        $this->write('tests/A.php', $this->evidenceSource());
        $this->write('tests/B.php', $this->evidenceSource());

        self::assertSame([], $this->checker($this->completeConfig())->violations());
    }

    public function test_missing_required_rule_is_rejected(): void
    {
        $this->write('database/migrations/a.php', "<?php\nreturn null;");
        $this->write('database/migrations/b.php', "<?php\nreturn null;");
        $this->write('tests/A.php', $this->evidenceSource());
        $this->write('tests/B.php', $this->evidenceSource());
        $config = $this->completeConfig();
        unset($config['critical_mariadb_authority_surfaces']['surface_a']['rules']['rollback_preflight']);

        $violations = $this->checker($config)->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('missing required rule rollback_preflight', $violations[0]);
    }

    public function test_comment_or_string_symbol_does_not_satisfy_evidence(): void
    {
        $this->write('database/migrations/a.php', "<?php\nreturn null;");
        $this->write('database/migrations/b.php', "<?php\nreturn null;");
        $this->write('tests/A.php', <<<'PHP'
<?php
// function metadataEvidence() {}
$text = 'function metadataEvidence() {}';
function installFence(): void {}
function reentryEvidence(): void {}
function rollbackPreflight(): void {}
function ddlToctou(): void {}
function dependencyChecks(): void {}
function postflightReadiness(): void {}
PHP);
        $this->write('tests/B.php', $this->evidenceSource());

        $violations = $this->checker($this->completeConfig())->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('metadataEvidence is not a declared function/method', $violations[0]);
    }

    /** @param array<string,mixed> $config */
    private function checker(array $config): CriticalMariaDbLifecycleContractChecker
    {
        return new CriticalMariaDbLifecycleContractChecker($this->root, $config);
    }

    /** @return array<string,mixed> */
    private function completeConfig(): array
    {
        return [
            'critical_mariadb_authority_surfaces' => [
                'surface_a' => $this->surface('database/migrations/a.php', 'tests/A.php'),
                'surface_b' => $this->surface('database/migrations/b.php', 'tests/B.php'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function surface(string $migration, string $evidenceFile): array
    {
        $rules = [];
        foreach ([
            'metadata_evidence' => 'metadataEvidence',
            'install_upgrade_fencing' => 'installFence',
            'interrupted_reentry' => 'reentryEvidence',
            'rollback_preflight' => 'rollbackPreflight',
            'ddl_toctou' => 'ddlToctou',
            'dependency_checks' => 'dependencyChecks',
            'postflight_readiness' => 'postflightReadiness',
        ] as $rule => $symbol) {
            $rules[$rule] = [
                'strategy' => 'test_strategy',
                'evidence' => [['file' => $evidenceFile, 'symbol' => $symbol]],
            ];
        }

        return ['migration' => $migration, 'rules' => $rules];
    }

    private function evidenceSource(): string
    {
        return <<<'PHP'
<?php
function metadataEvidence(): void {}
function installFence(): void {}
function reentryEvidence(): void {}
function rollbackPreflight(): void {}
function ddlToctou(): void {}
function dependencyChecks(): void {}
function postflightReadiness(): void {}
PHP;
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->root.'/'.$relativePath;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $content."\n");
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
