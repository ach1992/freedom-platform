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
                'interval_seconds' => 60,
            ],
            $this->environment([
                'WORKER_HEARTBEAT_ENABLED' => 'true',
                'WORKER_NAME' => 'freedom-platform-critical_01',
                'WORKER_QUEUE_GROUP' => 'critical-payments,bank-verification',
                'APP_VERSION' => 'staging-release',
                'WORKER_HEARTBEAT_INTERVAL_SECONDS' => '30',
            ]),
        );

        self::assertTrue($runtime->enabled);
        self::assertSame('freedom-platform-critical_01', $runtime->workerId);
        self::assertSame('critical-payments,bank-verification', $runtime->queueGroup);
        self::assertSame('staging-release', $runtime->releaseVersion);
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
                'interval_seconds' => 30,
            ],
            $this->environment([]),
        );

        self::assertFalse($runtime->enabled);
        self::assertSame('', $runtime->workerId);
        self::assertSame('default', $runtime->queueGroup);
        self::assertSame('cached-release', $runtime->releaseVersion);
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
                'interval_seconds' => 30,
            ],
            $this->environment($environment),
        );
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalidRuntimeProvider(): iterable
    {
        yield 'invalid boolean' => [
            ['WORKER_HEARTBEAT_ENABLED' => 'sometimes'],
            'Worker heartbeat enabled must be a boolean value.',
        ];

        yield 'missing identity' => [
            [
                'WORKER_HEARTBEAT_ENABLED' => 'true',
                'WORKER_QUEUE_GROUP' => 'critical',
            ],
            'An enabled queue worker heartbeat requires a unique worker ID.',
        ];

        yield 'missing queue group' => [
            [
                'WORKER_HEARTBEAT_ENABLED' => 'true',
                'WORKER_NAME' => 'worker-01',
                'WORKER_QUEUE_GROUP' => ' ',
            ],
            'An enabled queue worker heartbeat requires a queue group.',
        ];

        yield 'invalid interval' => [
            [
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
