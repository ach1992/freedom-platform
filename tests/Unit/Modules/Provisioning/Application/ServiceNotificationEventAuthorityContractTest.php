<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Provisioning\Application;

use App\Modules\Provisioning\Application\ServiceDeliveryAttemptQueueService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ServiceNotificationEventAuthorityContractTest extends TestCase
{
    public function test_terminal_delete_notification_delivery_is_narrowly_source_bound_and_rollback_restores_predecessor(): void
    {
        $migration = $this->migrationSource();
        $v2 = $this->section(
            $migration,
            'private function deliveryAttemptInsertGuardV2(): string',
            'private function deliveryAttemptInsertGuardV1(): string',
        );
        $v1 = $this->section(
            $migration,
            'private function deliveryAttemptInsertGuardV1(): string',
            "\n};",
        );

        self::assertStringContainsString('DB::unprepared($this->deliveryAttemptInsertGuardV2());', $migration);
        self::assertStringContainsString('DB::unprepared($this->deliveryAttemptInsertGuardV1());', $migration);

        foreach ([
            "state_row.notification_type = 'service_state'",
            "state_row.threshold_code = 'state_deleted'",
            "operation_row.operation_type = 'delete'",
            "operation_row.state = 'succeeded'",
            "service_row.lifecycle_state = 'retired'",
            'service_row.remote_deleted_at IS NOT NULL',
            "'service-notification-state-cycle-v1'",
            "'service-notification-episode-v1'",
            "'service-notification:'",
            'COALESCE(state_row.latest_retry_ordinal + 1, 0)',
            'valid_terminal_notification_count <> 1',
        ] as $authorityMarker) {
            self::assertStringContainsString($authorityMarker, $v2);
        }

        self::assertStringContainsString('valid_service_id IS NULL THEN', $v1);
        self::assertStringNotContainsString('valid_terminal_notification_count', $v1);
        self::assertStringNotContainsString("state_row.threshold_code = 'state_deleted'", $v1);
    }

    private function migrationSource(): string
    {
        $file = (new ReflectionClass(ServiceDeliveryAttemptQueueService::class))->getFileName();
        if (! is_string($file)) {
            throw new RuntimeException('Service delivery queue source file is unavailable.');
        }
        $root = dirname($file, 5);
        $migration = file_get_contents(
            $root.'/database/migrations/2026_09_25_000210_expand_service_notification_event_authority.php',
        );
        if (! is_string($migration)) {
            throw new RuntimeException('Service notification event migration source is unavailable.');
        }

        return $migration;
    }

    private function section(string $source, string $from, string $to): string
    {
        $start = strpos($source, $from);
        $end = strpos($source, $to, $start === false ? 0 : $start);
        if (! is_int($start) || ! is_int($end) || $end <= $start) {
            throw new RuntimeException('Expected migration source section is unavailable.');
        }

        return substr($source, $start, $end - $start);
    }
}
