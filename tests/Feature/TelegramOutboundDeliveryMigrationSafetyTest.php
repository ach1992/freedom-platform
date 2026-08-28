<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
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
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
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

        $transport = new MigrationSafetyTransport;
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

        $transport = new MigrationSafetyTransport;
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
            (new TelegramDeliveryDatabaseCapability)->value(),
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
        $createCapability = $reflection->getMethod('createCapabilityTable');
        $createCapability->invoke($migration, (new TelegramDeliveryDatabaseCapability)->expectedHash());

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

    public function test_runtime_session_with_exact_installation_lock_and_real_secret_cannot_spoof_lifecycle_transitions(): void
    {
        $connection = DB::connection();
        $migration = $this->migration();
        $reflection = new ReflectionClass($migration);
        $lifecycleConnection = app(DatabaseManager::class)->connection('telegram_lifecycle');
        $lockName = TelegramDeliveryDatabaseAuthoritySurfaceV1::installationLockName($connection);

        $acquired = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName], false);
        self::assertNotNull($acquired);
        self::assertSame(1, (int) $acquired->acquired, 'Ordinary runtime credentials must be able to acquire the advisory lock so the test proves it is not authorization.');

        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_lifecycle_authority = 'rollback'
SQL, [(new TelegramDeliveryDatabaseCapability)->value()]);

            try {
                $connection->table('telegram_delivery_authority_capability')
                    ->where('id', 1)
                    ->update([
                        'schema_version' => 0,
                        'activated_at' => null,
                    ]);
                self::fail('Runtime credentials must not deactivate authority even while owning the exact advisory lock.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('lifecycle principal is invalid', $exception->getMessage());
            } finally {
                $connection->statement(<<<'SQL'
SET @app_telegram_delivery_lifecycle_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
            }
        } finally {
            $released = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName], false);
            self::assertNotNull($released);
            self::assertSame(1, (int) $released->released);
        }

        self::assertSame(1, (int) $connection->table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));

        $withInstallationLock = $reflection->getMethod('withInstallationLock');
        $deactivate = $reflection->getMethod('deactivateAuthorityForRollback');
        $withInstallationLock->invoke($migration, $lifecycleConnection, function () use ($deactivate, $migration, $connection, $lifecycleConnection): void {
            $deactivate->invoke($migration, $connection, $lifecycleConnection);
        });
        self::assertSame(0, (int) $connection->table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));

        $acquired = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName], false);
        self::assertNotNull($acquired);
        self::assertSame(1, (int) $acquired->acquired);
        try {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_lifecycle_authority = 'activate'
SQL, [(new TelegramDeliveryDatabaseCapability)->value()]);

            try {
                $connection->table('telegram_delivery_authority_capability')
                    ->where('id', 1)
                    ->update([
                        'schema_version' => 1,
                        'activated_at' => '2026-08-25 12:00:00.000000',
                    ]);
                self::fail('Runtime credentials must not reactivate authority even while owning the exact advisory lock.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('lifecycle principal is invalid', $exception->getMessage());
            } finally {
                $connection->statement(<<<'SQL'
SET @app_telegram_delivery_lifecycle_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
            }
        } finally {
            $released = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName], false);
            self::assertNotNull($released);
            self::assertSame(1, (int) $released->released);
        }

        self::assertSame(0, (int) $connection->table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));
        $this->runMigrationUp();
        self::assertSame(1, (int) $connection->table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));
    }

    public function test_database_installation_lock_serializes_concurrent_runners_before_any_reset_decision(): void
    {
        $this->dropDeliveryGuards();
        DB::table('telegram_delivery_authority_capability')->where('id', 1)->update([
            'schema_version' => 0,
            'activated_at' => null,
        ]);

        $database = app(DatabaseManager::class);
        $defaultConnection = config('database.default');
        self::assertIsString($defaultConnection);
        $connectionConfig = config('database.connections.'.$defaultConnection);
        self::assertIsArray($connectionConfig);
        config(['database.connections.telegram_migration_contender' => $connectionConfig]);
        $contender = $database->connection('telegram_migration_contender');

        $primaryId = DB::connection()->selectOne('SELECT CONNECTION_ID() AS connection_id');
        $contenderId = $contender->selectOne('SELECT CONNECTION_ID() AS connection_id');
        self::assertNotNull($primaryId);
        self::assertNotNull($contenderId);
        self::assertNotSame((int) $primaryId->connection_id, (int) $contenderId->connection_id);

        $lockName = TelegramDeliveryDatabaseAuthoritySurfaceV1::installationLockName($contender);
        $acquired = $contender->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        self::assertNotNull($acquired);
        self::assertSame(1, (int) $acquired->acquired);

        try {
            try {
                $this->runMigrationUp();
                self::fail('A concurrent migration runner must not enter the Telegram authority state machine.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('installation lock is already held', $exception->getMessage());
            }

            self::assertSame(0, (int) DB::table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));
            self::assertSame(0, $this->deliveryTriggerCount());
            self::assertTrue(Schema::hasTable('telegram_delivery_operations'));
        } finally {
            $released = $contender->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            self::assertNotNull($released);
            self::assertSame(1, (int) $released->released);
            $database->purge('telegram_migration_contender');
        }

        $this->runMigrationUp();
        self::assertSame(1, (int) DB::table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));
        self::assertSame(9, $this->deliveryTriggerCount());
        self::assertTrue($this->surfaceReady());
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
            'capability_hash' => (new TelegramDeliveryDatabaseCapability)->expectedHash(),
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
            'capability_hash' => (new TelegramDeliveryDatabaseCapability)->expectedHash(),
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
        $expected = (new TelegramDeliveryDatabaseCapability)->expectedHash();
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

    public function test_same_named_weakened_trigger_is_rejected_before_queue_or_effect_transport(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900210,
            null,
            NonRestrictedTelegramPresentation::plainText('semantic trigger fence'),
            'semantic-trigger-request-179',
            'correlation-semantic-trigger-179',
        );

        DB::unprepared('DROP TRIGGER telegram_delivery_operations_update_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_delivery_operations_update_guard
BEFORE UPDATE ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    SET NEW.updated_at = NEW.updated_at;
END
SQL);

        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        self::assertTrue($surface->requiredTriggersPresent(DB::connection()));
        self::assertFalse($surface->semanticsMatchExpected(DB::connection()));

        DB::table('telegram_delivery_operations')
            ->where('public_id', $queued->publicId)
            ->update(['recipient_chat_id' => 900999]);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $queued->publicId,
            'recipient_chat_id' => 900999,
        ]);

        $this->assertSemanticDriftRejectsQueueAndEffect(
            $queued->publicId,
            $queued->outboxEventId,
            'correlation-semantic-trigger-179',
            'semantic-trigger',
        );
    }

    public function test_same_named_weakened_check_is_rejected_before_queue_or_effect_transport(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900211,
            null,
            NonRestrictedTelegramPresentation::plainText('semantic check fence'),
            'semantic-check-request-179',
            'correlation-semantic-check-179',
        );

        DB::statement('ALTER TABLE telegram_delivery_operations DROP CONSTRAINT telegram_delivery_operations_recipient_chk');
        DB::statement('ALTER TABLE telegram_delivery_operations ADD CONSTRAINT telegram_delivery_operations_recipient_chk CHECK (1)');

        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        self::assertTrue($surface->requiredChecksPresent(DB::connection()));
        self::assertFalse($surface->semanticsMatchExpected(DB::connection()));

        $this->assertSemanticDriftRejectsQueueAndEffect(
            $queued->publicId,
            $queued->outboxEventId,
            'correlation-semantic-check-179',
            'semantic-check',
        );
    }

    public function test_same_named_wrong_column_unique_index_is_rejected_before_queue_or_effect_transport(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900212,
            null,
            NonRestrictedTelegramPresentation::plainText('semantic index fence'),
            'semantic-index-request-179',
            'correlation-semantic-index-179',
        );

        DB::statement('ALTER TABLE telegram_delivery_operations DROP INDEX telegram_delivery_operations_request_unique');
        DB::statement('ALTER TABLE telegram_delivery_operations ADD UNIQUE INDEX telegram_delivery_operations_request_unique (request_fingerprint)');

        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        self::assertTrue($surface->requiredUniqueIndexesPresent(DB::connection()));
        self::assertFalse($surface->semanticsMatchExpected(DB::connection()));

        $this->assertSemanticDriftRejectsQueueAndEffect(
            $queued->publicId,
            $queued->outboxEventId,
            'correlation-semantic-index-179',
            'semantic-index',
        );
    }

    public function test_same_named_weakened_column_definition_is_rejected_before_queue_or_effect_transport(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900213,
            null,
            NonRestrictedTelegramPresentation::plainText('semantic column fence'),
            'semantic-column-request-179',
            'correlation-semantic-column-179',
        );

        DB::statement('ALTER TABLE telegram_delivery_operations MODIFY result_code VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');

        $column = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'telegram_delivery_operations')
            ->where('COLUMN_NAME', 'result_code')
            ->first(['COLUMN_NAME', 'COLUMN_TYPE']);
        self::assertNotNull($column);
        self::assertSame('result_code', (string) $column->COLUMN_NAME);
        self::assertSame('varchar(128)', (string) $column->COLUMN_TYPE);
        self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected(DB::connection()));

        $this->assertSemanticDriftRejectsQueueAndEffect(
            $queued->publicId,
            $queued->outboxEventId,
            'correlation-semantic-column-179',
            'semantic-column',
        );
    }

    public function test_unexpected_authority_table_trigger_is_rejected_before_queue_or_effect_transport(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900214,
            null,
            NonRestrictedTelegramPresentation::plainText('unexpected authority trigger fence'),
            'semantic-extra-trigger-request-179',
            'correlation-semantic-extra-trigger-179',
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_delivery_operations_unexpected_update_guard
BEFORE UPDATE ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    SET NEW.updated_at = NEW.updated_at;
END
SQL);

        try {
            self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected(DB::connection()));
            $this->assertSemanticDriftRejectsQueueAndEffect(
                $queued->publicId,
                $queued->outboxEventId,
                'correlation-semantic-extra-trigger-179',
                'semantic-extra-trigger',
            );
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_unexpected_update_guard');
        }
    }

    public function test_outbox_telegram_guards_must_remain_terminal_before_queue_or_effect_transport(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900215,
            null,
            NonRestrictedTelegramPresentation::plainText('terminal outbox trigger fence'),
            'semantic-outbox-terminal-request-179',
            'correlation-semantic-outbox-terminal-179',
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER outbox_telegram_delivery_late_insert_probe
BEFORE INSERT ON outbox_messages
FOR EACH ROW
BEGIN
    SET NEW.updated_at = NEW.updated_at;
END
SQL);

        try {
            self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected(DB::connection()));
            $this->assertSemanticDriftRejectsQueueAndEffect(
                $queued->publicId,
                $queued->outboxEventId,
                'correlation-semantic-outbox-terminal-179',
                'semantic-outbox-terminal',
            );
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_late_insert_probe');
        }
    }

    public function test_unexpected_shared_outbox_after_trigger_is_rejected_before_queue_or_effect_transport(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900216,
            null,
            NonRestrictedTelegramPresentation::plainText('shared outbox after trigger fence'),
            'semantic-outbox-after-request-179',
            'correlation-semantic-outbox-after-179',
        );

        DB::unprepared(<<<'SQL'
CREATE TRIGGER outbox_telegram_delivery_unexpected_after_update_probe
AFTER UPDATE ON outbox_messages
FOR EACH ROW
BEGIN
    SET @telegram_delivery_review_after_probe = @app_telegram_delivery_capability;
END
SQL);

        try {
            self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected(DB::connection()));
            $this->assertSemanticDriftRejectsQueueAndEffect(
                $queued->publicId,
                $queued->outboxEventId,
                'correlation-semantic-outbox-after-179',
                'semantic-outbox-after',
            );
            self::assertNull(DB::selectOne('SELECT @telegram_delivery_review_after_probe AS value')->value);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_unexpected_after_update_probe');
            DB::statement('SET @telegram_delivery_review_after_probe = NULL');
        }
    }

    public function test_replaced_earlier_shared_outbox_guard_cannot_preserve_readiness_or_exfiltrate_capability(): void
    {
        $queued = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900217,
            null,
            NonRestrictedTelegramPresentation::plainText('shared outbox replacement fence'),
            'semantic-outbox-replacement-request-179',
            'correlation-semantic-outbox-replacement-179',
        );
        $telegramOrderBefore = (int) DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', 'outbox_telegram_delivery_envelope_insert_guard')
            ->value('ACTION_ORDER');

        Schema::create('telegram_delivery_review_capability_leaks', static function ($table): void {
            $table->bigIncrements('id');
            $table->string('capability', 128)->nullable();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_service_delivery_envelope_insert_guard
BEFORE INSERT ON outbox_messages
FOR EACH ROW PRECEDES outbox_telegram_delivery_envelope_insert_guard
BEGIN
    IF LOWER(NEW.event_type) = 'telegram.delivery.requested' THEN
        INSERT INTO telegram_delivery_review_capability_leaks (capability)
        VALUES (@app_telegram_delivery_capability);
    END IF;
END
SQL);

        try {
            $telegramOrderAfter = (int) DB::table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('TRIGGER_NAME', 'outbox_telegram_delivery_envelope_insert_guard')
                ->value('ACTION_ORDER');
            self::assertSame($telegramOrderBefore, $telegramOrderAfter);
            self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected(DB::connection()));

            $this->assertSemanticDriftRejectsQueueAndEffect(
                $queued->publicId,
                $queued->outboxEventId,
                'correlation-semantic-outbox-replacement-179',
                'semantic-outbox-replacement',
            );
            self::assertSame(0, DB::table('telegram_delivery_review_capability_leaks')->count());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS outbox_service_delivery_envelope_insert_guard');
            $serviceDeliveryMigration = require database_path('migrations/2026_08_18_000100_create_service_delivery_attempt_authority.php');
            (new ReflectionClass($serviceDeliveryMigration))->getMethod('createOutboxInsertGuard')->invoke($serviceDeliveryMigration);
            Schema::dropIfExists('telegram_delivery_review_capability_leaks');
        }
    }

    public function test_unactivated_surface_cannot_activate_with_unexpected_shared_outbox_trigger(): void
    {
        $this->dropDeliveryGuards();
        Schema::dropIfExists('telegram_delivery_operations');
        Schema::dropIfExists('telegram_delivery_authority_capability');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER outbox_telegram_delivery_unexpected_after_insert_probe
AFTER INSERT ON outbox_messages
FOR EACH ROW
BEGIN
    SET @telegram_delivery_review_activation_probe = 1;
END
SQL);

        try {
            $this->runMigrationUp();
            self::fail('Unexpected shared Outbox triggers must prevent final Telegram delivery authority activation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('schema semantics do not match the immutable v1 contract', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_unexpected_after_insert_probe');
            DB::statement('SET @telegram_delivery_review_activation_probe = NULL');
        }

        $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($capability);
        self::assertSame(0, (int) $capability->schema_version);
        self::assertNull($capability->activated_at);
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected(DB::connection()));
        self::assertFalse($this->surfaceReady());
    }

    public function test_unactivated_surface_cannot_activate_when_same_named_guard_semantics_drift(): void
    {
        $this->dropDeliveryGuards();
        Schema::dropIfExists('telegram_delivery_operations');
        Schema::dropIfExists('telegram_delivery_authority_capability');

        $migration = $this->migration();
        $reflection = new ReflectionClass($migration);
        $reflection->getMethod('createCapabilityTable')->invoke(
            $migration,
            (new TelegramDeliveryDatabaseCapability)->expectedHash(),
        );
        foreach ([
            'installCapabilityGuards',
            'createOutboxInsertGuard',
            'createTable',
            'createOperationInsertGuard',
            'createOperationUpdateGuard',
            'createOperationDeleteGuard',
            'createOutboxUpdateGuard',
            'createOutboxDeleteGuard',
        ] as $method) {
            $reflection->getMethod($method)->invoke($migration);
        }

        DB::unprepared('DROP TRIGGER telegram_delivery_operations_update_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_delivery_operations_update_guard
BEFORE UPDATE ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    SET NEW.updated_at = NEW.updated_at;
END
SQL);
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->requiredTriggersPresent(DB::connection()));

        try {
            $reflection->getMethod('activateAuthority')->invoke($migration, DB::connection(), app(DatabaseManager::class)->connection('telegram_lifecycle'));
            self::fail('Semantic schema drift must prevent final Telegram delivery authority activation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('schema semantics do not match the immutable v1 contract', $exception->getMessage());
        }

        $capability = DB::table('telegram_delivery_authority_capability')->where('id', 1)->first();
        self::assertNotNull($capability);
        self::assertSame(0, (int) $capability->schema_version);
        self::assertNull($capability->activated_at);
        self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->semanticsMatchExpected(DB::connection()));
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

        self::assertRuntimeFenceRejectsPartialSurface('missing-check');
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

        self::assertRuntimeFenceRejectsPartialSurface('missing-index');
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

        self::assertRuntimeFenceRejectsPartialSurface('missing-guard');
        self::assertSame(8, $this->deliveryTriggerCount());
    }

    public function test_activated_marker_with_missing_insert_guards_cannot_send_forged_rows_even_after_dispatcher_reentry(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_insert_guard');
        self::assertSame(1, (int) DB::table('telegram_delivery_authority_capability')->where('id', 1)->value('schema_version'));
        self::assertFalse($this->surfaceReady());

        $forged = $this->forgeDurableAuthority('activated-partial-runtime');
        $transport = new MigrationSafetyTransport;
        $executor = new TelegramDeliveryOperationExecutor(
            app(DatabaseManager::class),
            $this->clock,
            $this->runtime,
            $transport,
            new TelegramDeliveryDatabaseCapability,
        );
        $handler = new TelegramDeliveryOutboxHandler(static fn (): TelegramDeliveryOperationExecutor => $executor);
        $dispatcher = new DatabaseOutboxDispatcher(app(DatabaseManager::class), $this->clock, 60, 2);

        self::assertNotNull($dispatcher->dispatchOne($handler));
        self::assertSame(0, $transport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $forged->public_id,
            'state' => 'prepared',
            'provider_attempts' => 0,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $forged->outbox_event_id,
            'dispatch_state' => 'retry',
            'attempts' => 1,
        ]);

        $this->clock->advance('+5 seconds');
        self::assertNotNull($dispatcher->dispatchOne($handler));
        self::assertSame(0, $transport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $forged->public_id,
            'state' => 'prepared',
            'provider_attempts' => 0,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $forged->outbox_event_id,
            'dispatch_state' => 'review_required',
            'review_reason' => 'retry_exhausted',
            'attempts' => 2,
        ]);
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

    private function surfaceReady(): bool
    {
        return (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
            DB::connection(),
            (new TelegramDeliveryDatabaseCapability)->expectedHash(),
        );
    }

    private function assertRuntimeFenceRejectsPartialSurface(string $suffix): void
    {
        self::assertFalse($this->surfaceReady());

        try {
            $this->queue()->queue(
                TelegramDeliveryAction::Send,
                900180,
                null,
                NonRestrictedTelegramPresentation::plainText('partial surface runtime fence'),
                'migration-runtime-fence-'.$suffix,
                'correlation-runtime-fence-179',
            );
            self::fail('Runtime must reject an activated marker when the database authority surface is incomplete.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('database authority is not fully activated', $exception->getMessage());
        }

        self::assertSame(0, DB::table('telegram_delivery_operations')->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->whereRaw('LOWER(event_type) = ?', [TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE])
            ->count());
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
            new TelegramDeliveryDatabaseCapability,
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

    private function assertSemanticDriftRejectsQueueAndEffect(
        string $publicId,
        string $outboxEventId,
        string $correlationId,
        string $suffix,
    ): void {
        self::assertFalse($this->surfaceReady());

        try {
            $this->queue()->queue(
                TelegramDeliveryAction::Send,
                900280,
                null,
                NonRestrictedTelegramPresentation::plainText('semantic drift queue fence'),
                'semantic-runtime-'.$suffix.'-179',
                'correlation-semantic-runtime-179',
            );
            self::fail('Queue authority must reject a same-named but semantically invalid database surface.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('database authority is not fully activated', $exception->getMessage());
        }

        $transport = new MigrationSafetyTransport;
        $executor = new TelegramDeliveryOperationExecutor(
            app(DatabaseManager::class),
            $this->clock,
            $this->runtime,
            $transport,
            new TelegramDeliveryDatabaseCapability,
        );
        try {
            $executor->execute($publicId, $outboxEventId, $correlationId);
            self::fail('Effect authority must reject a same-named but semantically invalid database surface before transport.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('database authority is not fully activated', $exception->getMessage());
        }
        self::assertSame(0, $transport->attempts);

        try {
            $this->runMigrationUp();
            self::fail('An activated semantically invalid authority surface with durable rows must never be silently repaired.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot repair an incomplete authority surface after durable rows exist', $exception->getMessage());
        }
    }

    private function queue(): TelegramDeliveryQueueService
    {
        $database = app(DatabaseManager::class);

        return new TelegramDeliveryQueueService(
            $database,
            $this->clock,
            new DatabaseOutboxPublisher($database, $this->clock),
            $this->runtime,
            new TelegramDeliveryDatabaseCapability,
        );
    }

    private function runMigrationUp(): void
    {
        $migration = $this->migration();
        $method = (new ReflectionClass($migration))->getMethod('up');
        $method->invoke($migration);
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
            ->whereIn('TRIGGER_NAME', TelegramDeliveryDatabaseAuthoritySurfaceV1::REQUIRED_TRIGGERS)
            ->count();
    }
}

final class MigrationSafetyClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
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
