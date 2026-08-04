<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class StagingDeploymentScriptContractTest extends TestCase
{
    /** @requirement RUN-003 OPS-003 QUA-013 */
    public function test_release_deployment_restarts_workers_before_runtime_evidence(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/deploy/staging/core-deploy.sh');
        self::assertIsString($script);

        $update = strpos($script, 'supervisorctl update');
        $restart = strpos($script, "supervisorctl restart 'freedom-platform-workers:*'");
        $status = strpos($script, "supervisorctl status 'freedom-platform-workers:*'");

        self::assertIsInt($update);
        self::assertIsInt($restart);
        self::assertIsInt($status);
        self::assertLessThan($restart, $update);
        self::assertLessThan($status, $restart);
    }

    /** @requirement SEC-009 OPS-003 QUA-013 */
    public function test_telegram_verification_restarts_workers_and_fails_closed(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3).'/deploy/staging/verify-telegram-webhook.sh');
        self::assertIsString($script);
        self::assertStringContainsString("supervisorctl restart 'freedom-platform-workers:*'", $script);
        self::assertStringContainsString('queue:clear redis --queue=critical', $script);
        self::assertStringContainsString('telegram:updates:requeue', $script);
        self::assertStringContainsString('set_exception_handler', $script);
        self::assertStringContainsString('exit(1);', $script);
    }

    /** @requirement ONB-001 OPS-003 QUA-013 */
    public function test_telegram_ingress_queue_is_consumed_by_supervisor(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 3).'/config/telegram.php');
        $supervisor = file_get_contents(dirname(__DIR__, 3).'/deploy/supervisor/freedom-platform.conf');
        self::assertIsString($configuration);
        self::assertIsString($supervisor);
        self::assertStringContainsString("TELEGRAM_WEBHOOK_QUEUE', 'telegram-ingress'", $configuration);
        self::assertStringContainsString('--queue=provisioning,telegram-ingress,telegram-delivery,synchronization,default', $supervisor);
    }
}
