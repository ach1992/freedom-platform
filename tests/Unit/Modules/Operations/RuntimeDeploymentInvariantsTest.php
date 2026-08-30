<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\RuntimeDeploymentInvariants;
use PHPUnit\Framework\TestCase;

final class RuntimeDeploymentInvariantsTest extends TestCase
{
    private RuntimeDeploymentInvariants $invariants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invariants = new RuntimeDeploymentInvariants;
    }

    public function test_mariadb_server_requires_supported_family_version_and_identity(): void
    {
        self::assertTrue($this->invariants->mariaDbServerCompatible(
            'mysql',
            '10.11.9-MariaDB-1:10.11.9+maria~ubu2204',
            'server-uid-1',
        ));
        self::assertTrue($this->invariants->mariaDbServerCompatible(
            'mariadb',
            '10.11.14-MariaDB-0+deb12u2',
            'server-uid-2',
        ));
        self::assertFalse($this->invariants->mariaDbServerCompatible(
            'mysql',
            '10.11.8-MariaDB-0+deb12u1',
            'server-uid-3',
        ));
        self::assertFalse($this->invariants->mariaDbServerCompatible(
            'mysql',
            '8.4.0',
            'server-uid-4',
        ));
        self::assertFalse($this->invariants->mariaDbServerCompatible(
            'mysql',
            '10.11.14-MariaDB-0+deb12u2',
            '',
        ));
    }

    public function test_database_session_requires_utf8mb4_matching_collation_and_strict_mode(): void
    {
        self::assertTrue($this->invariants->databaseSessionCompatible(
            'utf8mb4',
            'utf8mb4_unicode_ci',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            'utf8mb4_unicode_ci',
            'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION,ERROR_FOR_DIVISION_BY_ZERO',
        ));
        self::assertFalse($this->invariants->databaseSessionCompatible(
            'utf8mb4',
            'utf8mb4_unicode_ci',
            'utf8mb4',
            'utf8mb4_general_ci',
            'utf8mb4_unicode_ci',
            'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION',
        ));
        self::assertFalse($this->invariants->databaseSessionCompatible(
            'utf8mb4',
            'utf8mb4_unicode_ci',
            'utf8mb4',
            'utf8mb4_unicode_ci',
            'utf8mb4_unicode_ci',
            'NO_ENGINE_SUBSTITUTION',
        ));
    }

    public function test_redis_authentication_accepts_password_or_password_bearing_url_only(): void
    {
        self::assertTrue($this->invariants->redisConnectionAuthenticated([
            'password' => 'configured-secret',
            'url' => null,
        ]));
        self::assertTrue($this->invariants->redisConnectionAuthenticated([
            'password' => null,
            'url' => 'redis://user:encoded%2Dsecret@127.0.0.1:6379/0',
        ]));
        self::assertFalse($this->invariants->redisConnectionAuthenticated([
            'password' => null,
            'url' => 'redis://127.0.0.1:6379/0',
        ]));
    }

    public function test_queue_policy_requires_after_commit_and_retry_after_above_every_worker_timeout(): void
    {
        $configuration = [
            'driver' => 'redis',
            'retry_after' => 420,
            'after_commit' => true,
        ];
        $supervisor = <<<'CONF'
command=php artisan queue:work redis --timeout=120
command=php artisan queue:work redis --timeout=300
CONF;

        self::assertTrue($this->invariants->redisQueueAfterCommitCompatible($configuration));
        self::assertTrue($this->invariants->redisQueueRetryCompatible($configuration, $supervisor));

        $configuration['after_commit'] = false;
        self::assertFalse($this->invariants->redisQueueAfterCommitCompatible($configuration));

        $configuration['after_commit'] = true;
        $configuration['retry_after'] = 300;
        self::assertFalse($this->invariants->redisQueueRetryCompatible($configuration, $supervisor));
        self::assertFalse($this->invariants->redisQueueRetryCompatible($configuration, 'missing worker timeout'));
    }
}
