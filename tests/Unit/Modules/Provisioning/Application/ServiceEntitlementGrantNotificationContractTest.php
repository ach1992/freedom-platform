<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Provisioning\Application;

use App\Modules\Provisioning\Application\ServiceDeliveryAttemptQueueService;
use App\Modules\Provisioning\Application\ServiceDeliveryEffectExecutor;
use App\Modules\Provisioning\Application\ServiceEntitlementGrantNotificationOutboxHandler;
use App\Modules\Provisioning\Application\ServiceEntitlementGrantNotificationService;
use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Application\ServiceNotificationSourceAuthority;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ServiceEntitlementGrantNotificationContractTest extends TestCase
{
    public function test_successful_grant_publishes_a_dedicated_safe_outbox_command_inside_finalization(): void
    {
        self::assertSame(
            'provisioning.service_entitlement_grant.notification_requested',
            ServiceEntitlementGrantNotificationService::OUTBOX_EVENT_TYPE,
        );
        self::assertSame(
            'service_entitlement_grant_item',
            ServiceEntitlementGrantNotificationService::OUTBOX_AGGREGATE_TYPE,
        );

        $source = $this->classSource(ServiceMutationExecutor::class);
        self::assertStringContainsString('private function publishGrantNotificationCommand(', $source);
        self::assertStringContainsString('$type->isAdministrativeEntitlementGrant()', $source);
        self::assertStringContainsString("'service_entitlement_grant_item_public_id' => \$itemPublicId", $source);
        self::assertStringContainsString('ServiceEntitlementGrantNotificationService::OUTBOX_EVENT_TYPE', $source);
        self::assertStringNotContainsString('customer_notified_at', $source);
    }

    public function test_notification_queue_reuses_canonical_delivery_attempt_and_fences_unsafe_retries(): void
    {
        $source = $this->classSource(ServiceDeliveryAttemptQueueService::class);

        self::assertStringContainsString('public function queueEntitlementGrantNotification(', $source);
        self::assertStringContainsString('ServiceDeliveryPurpose::Notification', $source);
        self::assertStringContainsString("'failed_final'", $source);
        self::assertStringContainsString('$prior->retry_after_seconds !== null', $source);
        self::assertStringContainsString('service_entitlement_grant_notification_bindings', $source);
        self::assertStringContainsString('assertEntitlementGrantNotificationReplay', $source);

        $batchLock = strpos(
            $source,
            '$batch = $connection->table(\'service_entitlement_grant_batches\')',
            strpos($source, 'public function queueEntitlementGrantNotification('),
        );
        $itemLock = strpos(
            $source,
            '$item = $connection->table(\'service_entitlement_grant_items\')',
            strpos($source, 'public function queueEntitlementGrantNotification('),
        );
        self::assertIsInt($batchLock);
        self::assertIsInt($itemLock);
        self::assertLessThan($itemLock, $batchLock);
    }

    public function test_delivery_executor_requires_exactly_one_notification_source(): void
    {
        $executor = $this->classSource(ServiceDeliveryEffectExecutor::class);
        self::assertStringContainsString('service_notification_delivery_bindings', $executor);
        self::assertStringContainsString('service_entitlement_grant_notification_bindings', $executor);
        self::assertStringContainsString(
            'Service notification delivery must have exactly one durable presentation source.',
            $executor,
        );

        $sourceAuthority = $this->classSource(ServiceNotificationSourceAuthority::class);
        self::assertStringContainsString('service_entitlement_grant_notification_bindings', $sourceAuthority);
    }

    public function test_database_assets_preserve_cross_source_exclusivity_and_provider_freshness(): void
    {
        $root = $this->repositoryRoot();
        $migration = $this->file(
            $root.'/database/migrations/2026_09_26_000110_enable_service_entitlement_grant_notification_delivery.php',
        );
        $deliveryGuard = $this->file(
            $root.'/database/sql/service-entitlement-grant-notification/delivery-effect-update-guard-v2.sql',
        );
        $thresholdGuard = $this->file(
            $root.'/database/sql/service-entitlement-grant-notification/service-notification-binding-insert-guard-v2.sql',
        );

        self::assertStringContainsString('service_operational_authority_capability', $migration);
        self::assertStringContainsString('service_notification_delivery_bindings threshold_binding', $migration);
        self::assertStringContainsString("prior_effect.state = 'failed_final'", $migration);
        self::assertStringContainsString('prior_effect.retry_after_seconds IS NULL', $migration);
        self::assertStringContainsString("CONCAT('grant-notification:', item_row.public_id", $migration);
        self::assertStringContainsString('service_entitlement_grant_notification_bindings grant_binding', $deliveryGuard);
        self::assertStringContainsString('service_entitlement_grant_notification_bindings grant_binding', $thresholdGuard);
    }

    public function test_outbox_handler_is_strict_about_envelope_identity(): void
    {
        $reflection = new ReflectionClass(ServiceEntitlementGrantNotificationOutboxHandler::class);
        $handler = $reflection->newInstanceWithoutConstructor();
        $message = new OutboxMessage(
            id: '00000000-0000-4000-8000-000000000001',
            eventKey: 'wrong',
            eventType: ServiceEntitlementGrantNotificationService::OUTBOX_EVENT_TYPE,
            aggregateType: ServiceEntitlementGrantNotificationService::OUTBOX_AGGREGATE_TYPE,
            aggregateId: '01K6A000000000000000000000',
            payload: ['service_entitlement_grant_item_public_id' => '01K6A000000000000000000000'],
            correlationId: 'grant-notification-test',
            attempt: 1,
            contractVersion: ServiceEntitlementGrantNotificationService::OUTBOX_CONTRACT_VERSION,
        );

        self::assertSame(
            OutboxDispatchOutcome::DefinitiveFailure,
            $handler->handle($message),
        );
    }

    /** @param class-string $class */
    private function classSource(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        if (! is_string($file)) {
            throw new RuntimeException('Reflected class source file is unavailable.');
        }

        return $this->file($file);
    }

    private function repositoryRoot(): string
    {
        $file = (new ReflectionClass(ServiceEntitlementGrantNotificationService::class))->getFileName();
        if (! is_string($file)) {
            throw new RuntimeException('Notification service source file is unavailable.');
        }

        return dirname($file, 5);
    }

    private function file(string $path): string
    {
        $source = file_get_contents($path);
        if (! is_string($source)) {
            throw new RuntimeException('Required source file cannot be read: '.$path);
        }

        return $source;
    }
}
