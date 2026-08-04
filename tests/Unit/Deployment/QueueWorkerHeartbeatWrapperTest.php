<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class QueueWorkerHeartbeatWrapperTest extends TestCase
{
    /** @requirement RUN-003 OPS-003 QUA-011 QUA-013 */
    public function test_wrapper_is_valid_bash_and_runs_heartbeat_as_a_managed_sidecar(): void
    {
        $root = dirname(__DIR__, 3);
        $path = $root.'/deploy/bin/queue-worker-with-heartbeat.sh';
        $script = file_get_contents($path);

        self::assertIsString($script);
        exec('bash -n '.escapeshellarg($path), $output, $status);
        self::assertSame(0, $status, implode(PHP_EOL, $output));
        self::assertStringContainsString('operations:worker-heartbeat', $script);
        self::assertStringContainsString('heartbeat_loop &', $script);
        self::assertStringContainsString('trap cleanup EXIT', $script);
        self::assertStringContainsString("trap 'stop_children TERM' TERM INT HUP", $script);
        self::assertStringContainsString('queue:work redis "$@"', $script);
        self::assertStringNotContainsString('eval ', $script);
    }

    /** @requirement RUN-003 OPS-003 QUA-013 */
    public function test_every_supervisor_worker_uses_the_heartbeat_wrapper(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 3).'/deploy/supervisor/freedom-platform.conf');

        self::assertIsString($configuration);
        self::assertSame(3, substr_count($configuration, 'command=/www/acdomains/hell.hellpservice.ir/current/deploy/bin/queue-worker-with-heartbeat.sh'));
        self::assertStringNotContainsString('/artisan queue:work', $configuration);
        self::assertStringContainsString('--queue=provisioning,telegram-ingress,telegram-delivery,synchronization,default', $configuration);
        self::assertSame(3, substr_count($configuration, 'stopasgroup=true'));
        self::assertSame(3, substr_count($configuration, 'killasgroup=true'));
    }

    /** @requirement RUN-002 RUN-003 OPS-003 QUA-013 */
    public function test_release_preparation_restores_wrapper_execute_permission(): void
    {
        $deployment = file_get_contents(dirname(__DIR__, 3).'/deploy/staging/core-deploy.sh');

        self::assertIsString($deployment);
        self::assertStringContainsString(
            'chmod 0750 "$release_path/deploy/bin/queue-worker-with-heartbeat.sh"',
            $deployment,
        );
    }
}
