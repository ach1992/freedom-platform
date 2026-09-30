<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\WorkerRuntimeConfiguration;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @requirement OPS-003 RUN-003 RUN-004 QUA-011 */
final class WorkerRuntimeConfigurationTest extends TestCase
{
    public function test_process_environment_overrides_cached_configuration(): void
    {
        $runtime = WorkerRuntimeConfiguration::resolve(
            [
                'enabled' => false,
                'worker_id' => null,
                'queue_group' => 'cached-default',
                'release_version' => 'cached-release',
                'boot_id' => null,
                'interval_seconds' => 60,
            ],
            $this->environment([
                'WORKER_HEARTBEAT_ENABLED' => 'true',
                'WORKER_NAME' => 'freedom-platform-critical_01',
                'WORKER_QUEUE_GROUP' => 'critical-payments,bank-verification',
                'WORKER_RELEASE_ID' => 'staging-release',
                'WORKER_BOOT_ID' => str_repeat('a', 32),
                'WORKER_HEARTBEAT_INTERVAL_SECONDS' => '30',
            ]),
        );

        self::assertTrue($runtime->enabled);
        self::assertSame('freedom-platform-critical_01', $runtime->workerId);
        self::assertSame('critical-payments,bank-verification', $runtime->queueGroup);
        self::assertSame('staging-release', $runtime->releaseVersion);
        self::assertSame(str_repeat('a', 32), $runtime->bootId);
        self::assertSame(30, $runtime->intervalSeconds);
    }

    public function test_cached_configuration_remains_the_fallback_for_non_worker_processes(): void
    {
        $runtime = WorkerRuntimeConfiguration::resolve(
            [
                'enabled' => false,
                'worker_id' => null,
                'queue_group' => 'default',
                'release_version' => 'cached-release',
                'boot_id' => null,
                'interval_seconds' => 30,
            ],
            $this->environment([]),
        );

        self::assertFalse($runtime->enabled);
        self::assertSame('', $runtime->workerId);
        self::assertSame('default', $runtime->queueGroup);
        self::assertSame('cached-release', $runtime->releaseVersion);
        self::assertNull($runtime->bootId);
        self::assertSame(30, $runtime->intervalSeconds);
    }

    #[DataProvider('invalidRuntimeProvider')]
    public function test_invalid_enabled_worker_runtime_is_rejected(
        array $environment,
        string $expectedMessage,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        WorkerRuntimeConfiguration::resolve(
            [
                'enabled' => false,
                'worker_id' => null,
                'queue_group' => 'default',
                'release_version' => null,
                'boot_id' => null,
                'interval_seconds' => 30,
            ],
            $this->environment($environment),
        );
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidRuntimeProvider(): iterable
    {
        $validIdentity = [
            'WORKER_RELEASE_ID' => 'release-1',
            'WORKER_BOOT_ID' => str_repeat('b', 32),
        ];

        yield 'invalid boolean' => [
            ['WORKER_HEARTBEAT_ENABLED' => 'sometimes'],
            'Worker heartbeat enabled must be a boolean value.',
        ];

        yield 'missing identity' => [
            $validIdentity + [
                'WORKER_HEARTBEAT_ENABLED' => 'true',
                'WORKER_QUEUE_GROUP' => 'critical',
            ],
            'An enabled queue worker heartbeat requires a unique worker ID.',
        ];

        yield 'missing queue group' => [
            $validIdentity + [
                'WORKER_HEARTBEAT_ENABLED' => 'true',
                'WORKER_NAME' => 'worker-01',
                'WORKER_QUEUE_GROUP' => ' ',
            ],
            'An enabled queue worker heartbeat requires a queue group.',
        ];

        yield 'missing physical release' => [
            [
                'WORKER_HEARTBEAT_ENABLED' => 'true',
                'WORKER_NAME' => 'worker-01',
                'WORKER_QUEUE_GROUP' => 'critical',
                'WORKER_BOOT_ID' => str_repeat('b', 32),
            ],
            'An enabled queue worker heartbeat requires the exact physical release ID.',
        ];

        yield 'missing boot generation' => [
            [
                'WORKER_HEARTBEAT_ENABLED' => 'true',
                'WORKER_NAME' => 'worker-01',
                'WORKER_QUEUE_GROUP' => 'critical',
                'WORKER_RELEASE_ID' => 'release-1',
            ],
            'An enabled queue worker heartbeat requires a valid boot ID.',
        ];

        yield 'invalid interval' => [
            $validIdentity + [
                'WORKER_HEARTBEAT_ENABLED' => 'true',
                'WORKER_NAME' => 'worker-01',
                'WORKER_QUEUE_GROUP' => 'critical',
                'WORKER_HEARTBEAT_INTERVAL_SECONDS' => '0',
            ],
            'Worker heartbeat interval must be a positive integer.',
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return Closure(string): (string|false)
     */
    private function environment(array $values): Closure
    {
        return static fn (string $name): string|false => $values[$name] ?? false;
    }
}
