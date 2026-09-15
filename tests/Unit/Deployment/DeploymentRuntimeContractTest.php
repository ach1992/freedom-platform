<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class DeploymentRuntimeContractTest extends TestCase
{
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
