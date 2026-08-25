<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryOperationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxMessage;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
final class TelegramOutboundDeliveryMigrationSafetyTest extends TestCase
{
    use DatabaseTruncation;

    private MigrationSafetyClock $clock;

    private MigrationSafetyRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram outbound migration safety requires MariaDB/MySQL.');
        }

        $this->runMigrationUp();
        $this->clock = new MigrationSafetyClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));
        $this->runtime = new MigrationSafetyRuntime('123456');
    }

    protected function tearDown(): void
    {
        try {
            $this->restoreAuthoritySurfaceForTestIsolation();
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_missing_capability_with_forged_durable_authority_fails_reentry_and_runtime_transport(): void
    {
        $this->dropDeliveryGuards();
        Schema::dropIfExists('telegram_delivery_authority_capability');
        $forged = $this->forgeDurableAuthority('missing-capability');

        $this->assertReentryFailsForDurableIncompleteSurface();
        self::assertFalse(Schema::hasTable('telegram_delivery_authority_capability'));

        $transport = new MigrationSafetyTransport();
        $this->handleForgedMessage($forged, $transport);
        self::assertSame(0, $transport->attempts);
    }

    public function test_unactivated_capability_with_forged_durable_authority_fails_reentry_and_runtime_transport(): void
    {
        $this->dropDeliveryGuards();
        DB::table('telegram_delivery_authority_capability')->where('id', 1)->update([
            'schema_version' => 0,
            'activated_at' => null,
        ]);
        $forged = $this->forgeDurableAuthority('unactivated-capability');

        $this->assertReentryFailsForDurableIncompleteSurface();
        self::assertSame(0, (int) DB::table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));

        $transport = new MigrationSafetyTransport();
        $this->handleForgedMessage($forged, $transport);
        self::assertSame(0, $transport->attempts);
    }

    public function test_unactivated_schema_cannot_release_quarantined_outbox_even_with_real_capability(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900103,
            null,
            NonRestrictedTelegramPresentation::plainText('release must require activated schema'),
            'migration-unactivated-release-179',
            'correlation-unactivated-release-179',
        );
        $operation = DB::table('telegram_delivery_operations')->where('public_id', $queued->publicId)->first();
        self::assertNotNull($operation);

        $this->dropDeliveryGuards();
        DB::table('outbox_messages')->where('id', $queued->outboxEventId)->update([
            'dispatch_state' => 'authority_pending',
        ]);
        DB::table('telegram_delivery_authority_capability')->where('id', 1)->update([
            'schema_version' => 0,
            'activated_at' => null,
        ]);

        $migration = $this->migration();
        (new ReflectionClass($migration))->getMethod('createOutboxUpdateGuard')->invoke($migration);

        DB::statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_authority = 'telegram_delivery_queue_v1',
    @app_telegram_delivery_public_id = ?,
    @app_telegram_delivery_request_hash = ?,
    @app_telegram_delivery_fingerprint = ?,
    @app_telegram_delivery_action = ?,
    @app_telegram_delivery_bot_id = ?,
    @app_telegram_delivery_recipient_chat_id = ?,
    @app_telegram_delivery_target_message_id = ?,
    @app_telegram_delivery_outbox_event_id = ?
SQL, [
            (new TelegramDeliveryDatabaseCapability())->value(),
            (string) $operation->public_id,
            (string) $operation->request_key_hash,
            (string) $operation->request_fingerprint,
            (string) $operation->action,
            (string) $operation->bot_id,
            (int) $operation->recipient_chat_id,
            $operation->target_message_id,
            (string) $operation->outbox_event_id,
        ]);

        try {
            DB::table('outbox_messages')->where('id', $queued->outboxEventId)->update([
                'dispatch_state' => 'pending',
            ]);
            self::fail('Outbox release must require the final activated delivery schema marker.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('can only release into a clean pending state', $exception->getMessage());
        } finally {
            DB::statement(<<<'SQL'
SET @app_telegram_delivery_outbox_event_id = NULL,
    @app_telegram_delivery_target_message_id = NULL,
    @app_telegram_delivery_recipient_chat_id = NULL,
    @app_telegram_delivery_bot_id = NULL,
    @app_telegram_delivery_action = NULL,
    @app_telegram_delivery_fingerprint = NULL,
    @app_telegram_delivery_request_hash = NULL,
    @app_telegram_delivery_public_id = NULL,
    @app_telegram_delivery_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
        }

        self::assertSame(
            'authority_pending',
            DB::table('outbox_messages')->where('id', $queued->outboxEventId)->value('dispatch_state'),
        );
    }

    public function test_pre_guard_capability_table_cannot_be_raw_activated(): void
    {
        $this->dropDeliveryGuards();
        Schema::dropIfExists('telegram_delivery_operations');
        Schema::dropIfExists('telegram_delivery_authority_capability');

        $migration = $this->migration();
        $reflection = new ReflectionClass($migration);
        $reflection->getMethod('createCapabilityTable')->invoke(
            $migration,
            (new TelegramDeliveryDatabaseCapability())->expectedHash(),
        );

        try {
            DB::table('telegram_delivery_authority_capability')->where('id', 1)->update([
                'schema_version' => 1,
                'activated_at' => '2026-08-25 12:00:00.000000',
            ]);
            self::fail('The pre-guard capability table must remain structurally disabled.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('telegram_delivery_capability_schema_version_chk', $exception->getMessage());
        }

        $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($capability);
        self::assertSame(0, (int) $capability->schema_version);
        self::assertNull($capability->activated_at);
    }

    public function test_zero_data_interrupted_install_rebuilds_and_activates_complete_surface(): void
    {
        $this->dropDeliveryGuards();
        DB::table('telegram_delivery_authority_capability')->where('id', 1)->update([
            'schema_version' => 0,
            'activated_at' => null,
        ]);

        $this->runMigrationUp();

        $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($capability);
        self::assertSame(1, (int) $capability->schema_version);
        self::assertNotNull($capability->activated_at);
        self::assertSame(9, $this->deliveryTriggerCount());

        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900101,
            null,
            NonRestrictedTelegramPresentation::plainText('activated after safe repair'),
            'migration-safe-repair-request-179',
            'correlation-migration-repair-179',
        );
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $queued->publicId,
            'state' => 'prepared',
        ]);
    }

    public function test_legacy_capability_shape_with_zero_data_is_rebuilt_safely(): void
    {
        $this->dropDeliveryGuards();
        Schema::dropIfExists('telegram_delivery_operations');
        Schema::dropIfExists('telegram_delivery_authority_capability');
        DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_authority_capability (
    id TINYINT UNSIGNED NOT NULL,
    capability_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        DB::table('telegram_delivery_authority_capability')->insert([
            'id' => 1,
            'capability_hash' => (new TelegramDeliveryDatabaseCapability())->expectedHash(),
            'created_at' => '2026-08-25 12:00:00.000000',
        ]);

        $this->runMigrationUp();

        self::assertTrue(Schema::hasColumns('telegram_delivery_authority_capability', ['schema_version', 'activated_at']));
        self::assertSame(1, (int) DB::table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));
        self::assertSame(9, $this->deliveryTriggerCount());
    }

    public function test_legacy_capability_shape_with_durable_authority_fails_before_repair(): void
    {
        $this->dropDeliveryGuards();
        Schema::dropIfExists('telegram_delivery_authority_capability');
        DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_authority_capability (
    id TINYINT UNSIGNED NOT NULL,
    capability_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        DB::table('telegram_delivery_authority_capability')->insert([
            'id' => 1,
            'capability_hash' => (new TelegramDeliveryDatabaseCapability())->expectedHash(),
            'created_at' => '2026-08-25 12:00:00.000000',
        ]);
        $this->forgeDurableAuthority('legacy-capability-durable');

        $this->assertReentryFailsForDurableIncompleteSurface();
        self::assertFalse(Schema::hasColumn('telegram_delivery_authority_capability', 'schema_version'));
    }

    public function test_capability_hash_mismatch_never_self_heals_even_without_durable_rows(): void
    {
        $this->dropDeliveryGuards();
        DB::table('telegram_delivery_authority_capability')->where('id', 1)->update([
            'capability_hash' => str_repeat('a', 64),
            'schema_version' => 0,
            'activated_at' => null,
        ]);

        try {
            $this->runMigrationUp();
            self::fail('Capability mismatch must fail closed instead of rebuilding.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('does not match the application key or singleton authority', $exception->getMessage());
        }

        self::assertSame(str_repeat('a', 64), DB::table('telegram_delivery_authority_capability')->where('id', 1)->value('capability_hash'));
    }

    public function test_non_singleton_capability_never_self_heals_even_without_durable_rows(): void
    {
        $this->dropDeliveryGuards();
        Schema::dropIfExists('telegram_delivery_authority_capability');
        DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_authority_capability (
    id TINYINT UNSIGNED NOT NULL,
    capability_hash CHAR(64) NOT NULL,
    schema_version TINYINT UNSIGNED NOT NULL,
    activated_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $expected = (new TelegramDeliveryDatabaseCapability())->expectedHash();
        DB::table('telegram_delivery_authority_capability')->insert([
            ['id' => 1, 'capability_hash' => $expected, 'schema_version' => 0, 'activated_at' => null, 'created_at' => '2026-08-25 12:00:00.000000'],
            ['id' => 2, 'capability_hash' => $expected, 'schema_version' => 0, 'activated_at' => null, 'created_at' => '2026-08-25 12:00:00.000000'],
        ]);

        try {
            $this->runMigrationUp();
            self::fail('Non-singleton capability must fail closed instead of rebuilding.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('does not match the application key or singleton authority', $exception->getMessage());
        }

        self::assertSame(2, DB::table('telegram_delivery_authority_capability')->count());
    }

    public function test_malformed_capability_activation_state_never_self_heals(): void
    {
        $this->dropDeliveryGuards();
        DB::statement('ALTER TABLE telegram_delivery_authority_capability DROP CONSTRAINT telegram_delivery_capability_schema_version_chk');
        DB::statement('ALTER TABLE telegram_delivery_authority_capability DROP CONSTRAINT telegram_delivery_capability_activation_chk');
        DB::table('telegram_delivery_authority_capability')->where('id', 1)->update([
            'schema_version' => 7,
            'activated_at' => null,
        ]);

        try {
            $this->runMigrationUp();
            self::fail('Malformed capability activation state must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('activation state is malformed', $exception->getMessage());
        }
    }

    public function test_missing_required_guard_with_durable_authority_fails_closed_instead_of_repairing(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900102,
            null,
            NonRestrictedTelegramPresentation::plainText('durable before guard loss'),
            'migration-durable-guard-loss-179',
            'correlation-guard-loss-179',
        );
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_update_guard');

        $this->assertReentryFailsForDurableIncompleteSurface();
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $queued->publicId,
            'state' => 'prepared',
        ]);
        self::assertSame(8, $this->deliveryTriggerCount());
    }

    public function test_activated_surface_with_missing_required_check_is_detected_as_drift_not_repaired(): void
    {
        DB::statement('ALTER TABLE telegram_delivery_operations DROP CONSTRAINT telegram_delivery_operations_attempts_chk');

        try {
            $this->runMigrationUp();
            self::fail('An activated authority surface with a missing required CHECK must not be silently repaired.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('activated authority surface is incomplete', $exception->getMessage());
        }

        self::assertFalse(DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'telegram_delivery_operations')
            ->where('CONSTRAINT_NAME', 'telegram_delivery_operations_attempts_chk')
            ->exists());
    }

    public function test_activated_surface_with_missing_required_unique_index_is_detected_as_drift_not_repaired(): void
    {
        DB::statement('ALTER TABLE telegram_delivery_operations DROP INDEX telegram_delivery_operations_outbox_unique');

        try {
            $this->runMigrationUp();
            self::fail('An activated authority surface with a missing required unique index must not be silently repaired.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('activated authority surface is incomplete', $exception->getMessage());
        }

        self::assertFalse(DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'telegram_delivery_operations')
            ->where('INDEX_NAME', 'telegram_delivery_operations_outbox_unique')
            ->exists());
    }

    public function test_activated_surface_with_missing_guard_is_detected_as_drift_not_repaired(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_insert_guard');
        self::assertSame(1, (int) DB::table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));
        self::assertSame(8, $this->deliveryTriggerCount());

        try {
            $this->runMigrationUp();
            self::fail('An activated but incomplete authority surface must not be silently repaired.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('activated authority surface is incomplete', $exception->getMessage());
        }

        self::assertSame(8, $this->deliveryTriggerCount());
    }

    /** @return object{public_id:string,outbox_event_id:string,correlation_id:string} */
    private function forgeDurableAuthority(string $suffix): object
    {
        $publicId = '01J00000000000000000000001';
        $outboxEventId = '0198a4c7-ff31-7bb9-8222-000000017999';
        $requestHash = hash('sha256', 'forged-request-'.$suffix);
        $fingerprint = hash('sha256', 'forged-fingerprint-'.$suffix);
        $correlationId = 'correlation-forged-migration-179';
        $presentation = 'forged partial migration authority';
        $payload = '{"telegram_delivery_operation_public_id":"'.$publicId.'"}';
        $now = '2026-08-25 12:00:00.000000';

        DB::table('outbox_messages')->insert([
            'id' => $outboxEventId,
            'event_key' => TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$publicId,
            'event_type' => TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE,
            'aggregate_type' => TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE,
            'aggregate_id' => $publicId,
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'correlation_id' => $correlationId,
            'available_at' => $now,
            'dispatch_state' => 'pending',
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('telegram_delivery_operations')->insert([
            'public_id' => $publicId,
            'request_key_hash' => $requestHash,
            'request_fingerprint' => $fingerprint,
            'correlation_id' => $correlationId,
            'action' => 'send',
            'bot_id' => '123456',
            'recipient_chat_id' => 900199,
            'target_message_id' => null,
            'presentation_text' => $presentation,
            'outbox_event_id' => $outboxEventId,
            'state' => 'prepared',
            'state_version' => 1,
            'provider_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (object) [
            'public_id' => $publicId,
            'outbox_event_id' => $outboxEventId,
            'correlation_id' => $correlationId,
        ];
    }

    private function assertReentryFailsForDurableIncompleteSurface(): void
    {
        try {
            $this->runMigrationUp();
            self::fail('Incomplete Telegram delivery authority with durable rows must fail re-entry.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot repair an incomplete authority surface after durable rows exist', $exception->getMessage());
        }
    }

    /** @param object{public_id:string,outbox_event_id:string,correlation_id:string} $forged */
    private function handleForgedMessage(object $forged, MigrationSafetyTransport $transport): void
    {
        $executor = new TelegramDeliveryOperationExecutor(
            app(DatabaseManager::class),
            $this->clock,
            $this->runtime,
            $transport,
            new TelegramDeliveryDatabaseCapability(),
        );
        $handler = new TelegramDeliveryOutboxHandler(static fn (): TelegramDeliveryOperationExecutor => $executor);
        $handler->handle(new OutboxMessage(
            $forged->outbox_event_id,
            TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$forged->public_id,
            TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE,
            TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE,
            $forged->public_id,
            ['telegram_delivery_operation_public_id' => $forged->public_id],
            $forged->correlation_id,
            1,
        ));
    }

    private function queue(): TelegramDeliveryQueueService
    {
        $database = app(DatabaseManager::class);

        return new TelegramDeliveryQueueService(
            $database,
            $this->clock,
            new DatabaseOutboxPublisher($database, $this->clock),
            $this->runtime,
            new TelegramDeliveryDatabaseCapability(),
        );
    }

    private function runMigrationUp(): void
    {
        $migration = $this->migration();
        (new ReflectionClass($migration))->getMethod('up')->invoke($migration);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
    }

    private function dropDeliveryGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_insert_guard');
    }

    private function restoreAuthoritySurfaceForTestIsolation(): void
    {
        $this->dropDeliveryGuards();
        DB::table('outbox_messages')
            ->whereRaw('LOWER(event_type) = ?', [TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE])
            ->delete();
        Schema::dropIfExists('telegram_delivery_operations');
        Schema::dropIfExists('telegram_delivery_authority_capability');
        $this->runMigrationUp();
    }

    private function deliveryTriggerCount(): int
    {
        return (int) DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->whereIn('TRIGGER_NAME', [
                'telegram_delivery_capability_insert_guard',
                'telegram_delivery_capability_update_guard',
                'telegram_delivery_capability_delete_guard',
                'outbox_telegram_delivery_envelope_insert_guard',
                'telegram_delivery_operations_insert_guard',
                'telegram_delivery_operations_update_guard',
                'telegram_delivery_operations_delete_guard',
                'outbox_telegram_delivery_envelope_update_guard',
                'outbox_telegram_delivery_envelope_delete_guard',
            ])
            ->count();
    }
}

final readonly class MigrationSafetyClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final readonly class MigrationSafetyRuntime implements TelegramDeliveryRuntime
{
    public function __construct(private string $botId) {}

    public function botId(): string
    {
        return $this->botId;
    }
}

final class MigrationSafetyTransport implements TelegramMutationTransport
{
    public int $attempts = 0;

    public function mutate(TelegramMutationRequest $request): TelegramMutationResult
    {
        unset($request);
        $this->attempts++;

        return new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 999);
    }
}
