<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationService;
use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
use App\Modules\Telegram\Application\TelegramDeliveryOperationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramInlineButtonStyle;
use App\Modules\Telegram\Application\TelegramInlineCallbackButton;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramInteractiveDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\ConfidentialTelegramPresentationTestFactory;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;
use Tests\TestCase;
use Throwable;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-003 SEC-008 OPS-003 QUA-001 QUA-004 QUA-007 */
final class TelegramInteractiveDeliveryAuthorityTest extends TestCase
{
    use DatabaseTruncation;

    private const ROLLBACK_TABLE = 'telegram_delivery_interactive_presentations_rollback';

    private TelegramInteractiveDeliveryTestClock $clock;

    private TelegramInteractiveDeliveryTestRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram interactive delivery authority verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();

        $this->clock = new TelegramInteractiveDeliveryTestClock(new DateTimeImmutable('2026-08-31T00:00:00+00:00'));
        $this->runtime = new TelegramInteractiveDeliveryTestRuntime('123456');
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(TelegramDeliveryRuntime::class, $this->runtime);
        foreach ([
            TelegramInteractionSessionService::class,
            TelegramInteractionCallbackService::class,
            TelegramDeliveryInteractivePresentationService::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_v2_keyboard_queue_is_redacted_replay_exact_restart_safe_and_uses_existing_provider_boundary(): void
    {
        $account = $this->account('interactive', 900101);
        $session = $this->sessions()->start(
            $account['telegram_account_id'],
            'customer.purchase',
            'choose_plan',
            ['page' => 1],
            'interactive-session-start',
        );
        $primary = $this->callbacks()->issue(
            $session->publicId,
            $session->version,
            'purchase.select',
            ['plan' => 'basic'],
            'interactive-callback-primary',
        );
        $secondary = $this->callbacks()->issue(
            $session->publicId,
            $session->version,
            'purchase.cancel',
            [],
            'interactive-callback-secondary',
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [
                new TelegramInlineCallbackButton('انتخاب', $primary->publicId, TelegramInlineButtonStyle::Primary),
                new TelegramInlineCallbackButton('انصراف', $secondary->publicId, TelegramInlineButtonStyle::Danger),
            ],
        ]);

        $created = NonRestrictedTelegramPresentationTestFactory::queueInteractive(
            $this->queue(),
            TelegramDeliveryAction::Send,
            $account['telegram_user_id'],
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('یک گزینه را انتخاب کنید'),
            'interactive-delivery-request-1',
            'correlation-interactive-1',
            $keyboard,
        );

        self::assertFalse($created->replayed);
        self::assertSame(TelegramDeliveryOperationState::Prepared, $created->state);
        $outbox = DB::table('outbox_messages')->where('id', $created->outboxEventId)->first();
        self::assertNotNull($outbox);
        self::assertSame(TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE, (int) $outbox->contract_version);
        self::assertSame(
            '{"telegram_delivery_operation_public_id":"'.$created->publicId.'"}',
            (string) $outbox->payload,
        );

        $snapshot = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $created->publicId)
            ->first();
        self::assertNotNull($snapshot);
        self::assertSame($keyboard->json(), (string) $snapshot->keyboard_snapshot);
        self::assertSame($keyboard->hash(), (string) $snapshot->keyboard_snapshot_hash);

        $operation = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first();
        self::assertNotNull($operation);
        foreach ([$primary->token, $secondary->token] as $rawToken) {
            self::assertStringNotContainsString($rawToken, (string) $outbox->payload);
            self::assertStringNotContainsString($rawToken, (string) $snapshot->keyboard_snapshot);
            self::assertStringNotContainsString($rawToken, json_encode((array) $operation, JSON_THROW_ON_ERROR));
        }

        $replay = NonRestrictedTelegramPresentationTestFactory::queueInteractive(
            $this->queue(),
            TelegramDeliveryAction::Send,
            $account['telegram_user_id'],
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('یک گزینه را انتخاب کنید'),
            'interactive-delivery-request-1',
            'correlation-interactive-1',
            $keyboard,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($created->publicId, $replay->publicId);
        self::assertSame(1, DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->count());

        try {
            NonRestrictedTelegramPresentationTestFactory::queueInteractive(
                $this->queue(),
                TelegramDeliveryAction::Send,
                $account['telegram_user_id'],
                null,
                NonRestrictedTelegramPresentationTestFactory::plainText('یک گزینه را انتخاب کنید'),
                'interactive-delivery-request-1',
                'correlation-interactive-1',
                new TelegramInlineKeyboardSnapshot([
                    [
                        new TelegramInlineCallbackButton('انتخاب', $primary->publicId, TelegramInlineButtonStyle::Success),
                        new TelegramInlineCallbackButton('انصراف', $secondary->publicId, TelegramInlineButtonStyle::Danger),
                    ],
                ]),
            );
            self::fail('A reused delivery request key must reject changed interactive semantics.');
        } catch (DomainException) {
            // Expected.
        }

        try {
            DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
                ->where('delivery_operation_public_id', $created->publicId)
                ->update(['keyboard_snapshot_hash' => str_repeat('a', 64)]);
            self::fail('Interactive presentation snapshots must be immutable at the database boundary.');
        } catch (QueryException) {
            // Expected.
        }

        $transport = new RecordingInteractiveTelegramMutationTransport(
            [new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 7001)],
            app(DatabaseManager::class),
        );
        $executor = $this->executor($transport);
        $handler = new TelegramInteractiveDeliveryOutboxHandler(
            static fn (): TelegramDeliveryOperationExecutor => $executor,
        );
        $result = (new DatabaseOutboxDispatcher(app(DatabaseManager::class), $this->clock, 60))->dispatchOne($handler);

        self::assertNotNull($result);
        self::assertSame(1, $transport->attempts);
        self::assertSame([0], $transport->transactionLevels);
        self::assertCount(1, $transport->requests);
        $markup = $transport->requests[0]->inlineKeyboard?->providerPayload();
        self::assertSame([
            'inline_keyboard' => [[
                ['text' => 'انتخاب', 'callback_data' => $primary->token, 'style' => 'primary'],
                ['text' => 'انصراف', 'callback_data' => $secondary->token, 'style' => 'danger'],
            ]],
        ], $markup);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'succeeded',
            'provider_attempts' => 1,
            'telegram_message_id' => 7001,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'contract_version' => 2,
            'dispatch_state' => 'processed',
            'attempts' => 1,
        ]);
    }

    public function test_interactive_migration_rebuilds_empty_incomplete_surface_and_restores_exact_readiness(): void
    {
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        self::assertTrue($surface->isReady(DB::connection()));
        self::assertSame(0, DB::table('telegram_delivery_interactive_presentations')->count());

        DB::unprepared('DROP TRIGGER telegram_delivery_interactive_presentations_update_guard');
        self::assertFalse($surface->isReady(DB::connection()));

        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();

        self::assertTrue($surface->isReady(DB::connection()));
        self::assertSame(0, DB::table('telegram_delivery_interactive_presentations')->count());
    }

    public function test_interactive_surface_rejects_column_metadata_drift_and_empty_migration_rebuilds_exact_surface(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        self::assertTrue($surface->isReady($connection));

        $tableBefore = $connection->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->first(['TABLE_COLLATION']);
        self::assertNotNull($tableBefore);
        self::assertSame('utf8mb4_bin', strtolower((string) $tableBefore->TABLE_COLLATION));

        $connection->statement(<<<'SQL'
ALTER TABLE telegram_delivery_interactive_presentations
MODIFY keyboard_snapshot LONGTEXT
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
SQL);

        $column = $connection->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('COLUMN_NAME', 'keyboard_snapshot')
            ->first(['COLUMN_TYPE', 'CHARACTER_SET_NAME', 'COLLATION_NAME']);
        $tableAfter = $connection->table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->first(['TABLE_COLLATION']);
        self::assertNotNull($column);
        self::assertNotNull($tableAfter);
        self::assertSame('longtext', strtolower((string) $column->COLUMN_TYPE));
        self::assertSame('utf8mb4', strtolower((string) $column->CHARACTER_SET_NAME));
        self::assertSame('utf8mb4_unicode_ci', strtolower((string) $column->COLLATION_NAME));
        self::assertSame('utf8mb4_bin', strtolower((string) $tableAfter->TABLE_COLLATION));
        self::assertFalse($surface->isReady($connection));
        $this->assertInteractiveCapabilityRejectsCurrentSurface();

        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();

        self::assertTrue($surface->isReady($connection));
        $restoredColumn = $connection->table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('COLUMN_NAME', 'keyboard_snapshot')
            ->first(['CHARACTER_SET_NAME', 'COLLATION_NAME']);
        self::assertNotNull($restoredColumn);
        self::assertSame('utf8mb4', strtolower((string) $restoredColumn->CHARACTER_SET_NAME));
        self::assertSame('utf8mb4_bin', strtolower((string) $restoredColumn->COLLATION_NAME));
    }

    public function test_interactive_surface_rejects_trigger_execution_context_drift_and_empty_migration_rebuilds_exact_surface(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        self::assertTrue($surface->isReady($connection));

        $session = $connection->selectOne('SELECT @@SESSION.sql_mode AS sql_mode', [], false);
        self::assertNotNull($session);
        $originalSqlMode = (string) ($session->sql_mode ?? '');
        $driftSqlMode = $this->toggleSqlMode($originalSqlMode, 'NO_BACKSLASH_ESCAPES');

        try {
            $connection->unprepared('DROP TRIGGER IF EXISTS '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::UPDATE_TRIGGER);
            $connection->statement('SET SESSION sql_mode = ?', [$driftSqlMode]);
            $connection->unprepared(
                'CREATE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::UPDATE_TRIGGER
                .' BEFORE UPDATE ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
                .' FOR EACH ROW '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::updateTriggerBody(),
            );
            $connection->statement('SET SESSION sql_mode = ?', [$originalSqlMode]);

            $trigger = $connection->table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', $connection->getDatabaseName())
                ->where('TRIGGER_NAME', TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::UPDATE_TRIGGER)
                ->first(['EVENT_MANIPULATION', 'ACTION_TIMING', 'ACTION_STATEMENT', 'SQL_MODE']);
            self::assertNotNull($trigger);
            self::assertSame('UPDATE', (string) $trigger->EVENT_MANIPULATION);
            self::assertSame('BEFORE', (string) $trigger->ACTION_TIMING);
            self::assertSame(
                $this->normalizeMetadataSqlForTest(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::updateTriggerBody()),
                $this->normalizeMetadataSqlForTest((string) $trigger->ACTION_STATEMENT),
            );
            self::assertNotSame(
                $this->normalizeSqlModeForTest($originalSqlMode),
                $this->normalizeSqlModeForTest((string) $trigger->SQL_MODE),
            );
            self::assertFalse($surface->isReady($connection));
            $this->assertInteractiveCapabilityRejectsCurrentSurface();
        } finally {
            $connection->statement('SET SESSION sql_mode = ?', [$originalSqlMode]);
        }

        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        self::assertTrue($surface->isReady($connection));
    }

    public function test_interactive_migration_refuses_non_empty_semantically_drifted_surface(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton('Semantic drift probe', (string) Str::ulid(), TelegramInlineButtonStyle::Primary)],
        ]);
        $fixture = NonRestrictedTelegramPresentationTestFactory::prepareInteractiveV2OperationWithoutSnapshot(
            app(DatabaseManager::class),
            $this->clock,
            900116,
            'interactive-semantic-drift-fixture',
            'correlation-interactive-semantic-drift',
            $keyboard,
        );
        $this->insertInteractiveSnapshotThroughExactAuthority($fixture['public_id'], $keyboard);
        self::assertSame(1, $connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->count());

        $connection->statement(<<<'SQL'
ALTER TABLE telegram_delivery_interactive_presentations
MODIFY keyboard_snapshot LONGTEXT
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
SQL);
        self::assertFalse($surface->isReady($connection));

        try {
            (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
            self::fail('A non-empty semantically unrecognized interactive surface must not be destructively repaired.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot repair a non-empty unrecognized surface', $exception->getMessage());
        }

        self::assertSame(1, $connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->count());
        self::assertFalse($surface->isReady($connection));
    }

    public function test_interactive_migration_down_refuses_durable_snapshots_before_destructive_ddl(): void
    {
        $account = $this->account('rollback_durable', 900105);
        $session = $this->sessions()->start(
            $account['telegram_account_id'],
            'customer.account',
            'confirm',
            [],
            'rollback-durable-session-start',
        );
        $callback = $this->callbacks()->issue(
            $session->publicId,
            $session->version,
            'account.confirm',
            [],
            'rollback-durable-callback-issue',
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton('تأیید', $callback->publicId, TelegramInlineButtonStyle::Success)],
        ]);
        NonRestrictedTelegramPresentationTestFactory::queueInteractive(
            $this->queue(),
            TelegramDeliveryAction::Send,
            $account['telegram_user_id'],
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('تأیید کنید'),
            'rollback-durable-delivery-request',
            'correlation-rollback-durable',
            $keyboard,
        );

        try {
            (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->down();
            self::fail('Interactive authority rollback must refuse durable snapshots before destructive DDL.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot be removed while durable snapshots exist', $exception->getMessage());
        }

        self::assertTrue(Schema::hasTable('telegram_delivery_interactive_presentations'));
        self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(DB::connection()));
        self::assertSame(1, DB::table('telegram_delivery_interactive_presentations')->count());
    }

    public function test_interactive_migration_down_fails_closed_on_external_fk_preserves_guards_and_retries_cleanly(): void
    {
        $migration = require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php');
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        DB::unprepared('DROP TABLE IF EXISTS telegram_interactive_fk_probe');
        $this->createInteractiveIncomingForeignKeyProbe();

        try {
            try {
                $migration->down();
                self::fail('Interactive authority rollback must fail closed while an external FK depends on the surface.');
            } catch (QueryException) {
                // MariaDB rejects the dependency-sensitive DROP TABLE.
            }

            self::assertTrue(Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));
            self::assertFalse($surface->isReady(DB::connection()));
            self::assertSame($this->expectedInteractiveTriggerNames(), $this->interactiveTriggerNames());
            self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
                DB::connection(),
                (new TelegramDeliveryDatabaseCapability)->expectedHash(),
            ));

            $keyboard = new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineCallbackButton('Guard probe', (string) Str::ulid(), TelegramInlineButtonStyle::Primary)],
            ]);
            $fixture = NonRestrictedTelegramPresentationTestFactory::prepareInteractiveV2OperationWithoutSnapshot(
                app(DatabaseManager::class),
                $this->clock,
                900106,
                'interactive-guard-preservation-fixture',
                'correlation-interactive-guard-preservation',
                $keyboard,
            );
            $this->insertInteractiveSnapshotThroughExactAuthority($fixture['public_id'], $keyboard);

            try {
                DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->insert([
                    'delivery_operation_public_id' => (string) Str::ulid(),
                    'keyboard_snapshot' => $keyboard->json(),
                    'keyboard_snapshot_hash' => $keyboard->hash(),
                    'created_at' => '2026-08-31 00:00:00.000000',
                ]);
                self::fail('Surviving interactive INSERT guard must reject unauthorized creation after a failed DROP.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('creation authority is invalid', strtolower($exception->getMessage()));
            }

            try {
                DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
                    ->where('delivery_operation_public_id', $fixture['public_id'])
                    ->update(['keyboard_snapshot_hash' => str_repeat('a', 64)]);
                self::fail('Surviving interactive UPDATE guard must preserve immutable snapshots after a failed DROP.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('interactive presentations are immutable', strtolower($exception->getMessage()));
            }

            try {
                DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
                    ->where('delivery_operation_public_id', $fixture['public_id'])
                    ->delete();
                self::fail('Surviving interactive DELETE guard must preserve durable snapshots after a failed DROP.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('interactive presentations are non-deletable', strtolower($exception->getMessage()));
            }

            DB::unprepared('DROP TABLE telegram_interactive_fk_probe');
            self::assertTrue($surface->isReady(DB::connection()), 'Removing only the external FK must restore readiness without migration repair.');
            self::assertSame($this->expectedInteractiveTriggerNames(), $this->interactiveTriggerNames());

            DB::statement('TRUNCATE TABLE '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE);
            $migration->down();
            self::assertFalse(Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));

            $migration->up();
            self::assertTrue($surface->isReady(DB::connection()));
        } finally {
            DB::unprepared('DROP TABLE IF EXISTS telegram_interactive_fk_probe');
            if (! Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
                || ! $surface->isReady(DB::connection())) {
                if (Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)) {
                    DB::statement('TRUNCATE TABLE '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE);
                }
                $migration->up();
            }
        }
    }

    public function test_interactive_migration_up_dependency_failure_preserves_existing_guards_and_recovers_without_repair(): void
    {
        $migration = require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php');
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        self::assertTrue($surface->isReady(DB::connection()));
        self::assertSame(0, DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->count());

        DB::unprepared('DROP TABLE IF EXISTS telegram_interactive_fk_probe');
        $this->createInteractiveIncomingForeignKeyProbe();

        try {
            self::assertFalse($surface->isReady(DB::connection()));
            try {
                $migration->up();
                self::fail('Interactive authority re-entry must not strip guards before a dependency-sensitive rebuild DROP.');
            } catch (QueryException) {
                // MariaDB rejects the rebuild DROP while the external FK exists.
            }

            self::assertTrue(Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));
            self::assertSame($this->expectedInteractiveTriggerNames(), $this->interactiveTriggerNames());

            DB::unprepared('DROP TABLE telegram_interactive_fk_probe');
            self::assertTrue($surface->isReady(DB::connection()), 'Removing the external FK alone must recover the still-guarded exact surface.');

            $migration->up();
            self::assertTrue($surface->isReady(DB::connection()));
        } finally {
            DB::unprepared('DROP TABLE IF EXISTS telegram_interactive_fk_probe');
        }
    }

    public function test_interactive_migration_down_reentry_resumes_from_persistent_fence_before_table_drop(): void
    {
        $migration = require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php');
        $database = app(DatabaseManager::class);
        $connection = DB::connection();
        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);
        $establishFence = new ReflectionMethod($migration, 'establishPersistentRuntimeFence');
        $establishFence->setAccessible(true);
        $stageTable = new ReflectionMethod($migration, 'stageInteractiveTableForRollback');
        $stageTable->setAccessible(true);
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);

        // Simulate process death after the durable state-0 fence commits and the
        // canonical table has been atomically moved to its rollback staging name,
        // but before v1 reactivation and destructive DROP begin.
        $withInstallationLock->invoke($migration, $connection, $lifecycleConnection, function () use (
            $establishFence,
            $stageTable,
            $migration,
            $connection,
            $lifecycleConnection,
        ): void {
            $establishFence->invoke($migration, $connection, $lifecycleConnection);
            $stageTable->invoke($migration, $connection);
        });

        self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
            $connection,
            (new TelegramDeliveryDatabaseCapability)->expectedHash(),
        ));
        self::assertFalse(Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));
        self::assertTrue(Schema::hasTable(self::ROLLBACK_TABLE));

        $migration->down();

        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
            $connection,
            (new TelegramDeliveryDatabaseCapability)->expectedHash(),
        ));
        self::assertFalse(Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));
        self::assertFalse(Schema::hasTable(self::ROLLBACK_TABLE));

        $migration->up();
        self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady($connection));
    }

    public function test_interactive_migration_down_reentry_restores_v1_after_interrupted_post_drop_fence(): void
    {
        $migration = require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php');
        $database = app(DatabaseManager::class);
        $connection = DB::connection();
        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);
        $establishFence = new ReflectionMethod($migration, 'establishPersistentRuntimeFence');
        $establishFence->setAccessible(true);
        $stageTable = new ReflectionMethod($migration, 'stageInteractiveTableForRollback');
        $stageTable->setAccessible(true);
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);

        // Simulate process death after state0, staging rename, and successful DROP,
        // but before the lifecycle finally block can reactivate shared v1 authority.
        $withInstallationLock->invoke($migration, $connection, $lifecycleConnection, function () use (
            $establishFence,
            $stageTable,
            $migration,
            $connection,
            $lifecycleConnection,
        ): void {
            $establishFence->invoke($migration, $connection, $lifecycleConnection);
            $stageTable->invoke($migration, $connection);
            $connection->statement('DROP TABLE `'.self::ROLLBACK_TABLE.'`');
        });

        self::assertFalse((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
            $connection,
            (new TelegramDeliveryDatabaseCapability)->expectedHash(),
        ));
        self::assertFalse(Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));
        self::assertFalse(Schema::hasTable(self::ROLLBACK_TABLE));

        $migration->down();

        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
            $connection,
            (new TelegramDeliveryDatabaseCapability)->expectedHash(),
        ));
        self::assertFalse(Schema::hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));
        self::assertFalse(Schema::hasTable(self::ROLLBACK_TABLE));

        $migration->up();
        self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady($connection));
    }

    public function test_interactive_ddl_lock_owner_session_loss_blocks_protected_ddl_after_contender_acquires_ddl_lock(): void
    {
        $database = app(DatabaseManager::class);
        $defaultConnection = config('database.default');
        self::assertIsString($defaultConnection);
        $runtimeConfig = config('database.connections.'.$defaultConnection);
        self::assertIsArray($runtimeConfig);
        config(['database.connections.telegram_interactive_runtime_killer' => $runtimeConfig]);

        $runtimeConnection = DB::connection();
        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
        $lifecycleConnection = $lifecycleAuthority->requireConnection($runtimeConnection);
        $killer = $database->connection('telegram_interactive_runtime_killer');
        $migration = require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php');
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);
        $ddlInstallationLockName = new ReflectionMethod($migration, 'ddlInstallationLockName');
        $ddlInstallationLockName->setAccessible(true);
        $ddlLockName = $ddlInstallationLockName->invoke($migration, $runtimeConnection);
        self::assertIsString($ddlLockName);
        $killedConnectionId = null;
        $contenderOwnsDdlLock = false;
        $stagedTableExisted = false;

        try {
            try {
                $withInstallationLock->invoke($migration, $runtimeConnection, $lifecycleConnection, function () use (
                    $runtimeConnection,
                    $killer,
                    $ddlLockName,
                    &$killedConnectionId,
                    &$contenderOwnsDdlLock,
                ): void {
                    $session = $runtimeConnection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                    self::assertNotNull($session);
                    $killedConnectionId = (int) $session->connection_id;
                    $killer->getPdo()->exec('KILL CONNECTION '.$killedConnectionId);

                    // MariaDB releases the DDL advisory lock with the dead session.
                    // A contender can own it before the first runner's next statement.
                    $acquired = $killer->selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$ddlLockName], false);
                    self::assertNotNull($acquired);
                    self::assertSame(1, (int) $acquired->acquired);
                    $contenderOwnsDdlLock = true;

                    // No liveness probe is issued against the killed session. Attempt
                    // the exact protected RENAME TABLE shape used by production rollback.
                    // Normal Laravel reconnect would execute it on a replacement session
                    // while the contender owns the DDL installation lock.
                    $runtimeConnection->statement(
                        'RENAME TABLE `telegram_delivery_interactive_presentations` TO `'.self::ROLLBACK_TABLE.'`',
                    );
                });
                self::fail('Losing the DDL-lock owner session must abort before protected DDL can continue.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('DDL installation lock cleanup failed', $exception->getMessage());
            }

            self::assertIsInt($killedConnectionId);
            self::assertTrue($contenderOwnsDdlLock);
            $stagedTableExisted = $killer->getSchemaBuilder()->hasTable(self::ROLLBACK_TABLE);
            self::assertTrue($killer->getSchemaBuilder()->hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));

            $released = $killer->selectOne('SELECT RELEASE_LOCK(?) AS released', [$ddlLockName], false);
            self::assertNotNull($released);
            self::assertSame(1, (int) $released->released);
            $contenderOwnsDdlLock = false;

            $restoredSession = $runtimeConnection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
            self::assertNotNull($restoredSession);
            self::assertNotSame($killedConnectionId, (int) $restoredSession->connection_id);
        } finally {
            if ($contenderOwnsDdlLock) {
                try {
                    $killer->selectOne('SELECT RELEASE_LOCK(?) AS released', [$ddlLockName], false);
                } catch (Throwable) {
                    // Best-effort isolated test cleanup; named connection is purged below.
                }
            }

            $killerSchema = $killer->getSchemaBuilder();
            if ($killerSchema->hasTable(self::ROLLBACK_TABLE)
                && ! $killerSchema->hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)) {
                $killer->statement(
                    'RENAME TABLE `'.self::ROLLBACK_TABLE.'` TO `telegram_delivery_interactive_presentations`',
                );
            }
            $database->purge('telegram_interactive_runtime_killer');
        }

        self::assertFalse($stagedTableExisted);
        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
            $runtimeConnection,
            (new TelegramDeliveryDatabaseCapability)->expectedHash(),
        ));
        self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady($runtimeConnection));
    }

    public function test_interactive_lifecycle_session_loss_cannot_overlap_ddl_guarded_by_runtime_session(): void
    {
        $database = app(DatabaseManager::class);
        $defaultConnection = config('database.default');
        self::assertIsString($defaultConnection);
        $runtimeConfig = config('database.connections.'.$defaultConnection);
        $lifecycleConfig = config('database.connections.telegram_lifecycle');
        self::assertIsArray($runtimeConfig);
        self::assertIsArray($lifecycleConfig);
        config([
            'database.connections.telegram_interactive_runtime_contender' => $runtimeConfig,
            'database.connections.telegram_interactive_lifecycle_killer' => $lifecycleConfig,
        ]);

        $runtimeConnection = DB::connection();
        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
        $lifecycleConnection = $lifecycleAuthority->requireConnection($runtimeConnection);
        $runtimeContender = $database->connection('telegram_interactive_runtime_contender');
        $lifecycleContender = $database->connection('telegram_interactive_lifecycle_killer');
        $migration = require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php');
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);
        $ddlInstallationLockName = new ReflectionMethod($migration, 'ddlInstallationLockName');
        $ddlInstallationLockName->setAccessible(true);
        $ddlLockName = $ddlInstallationLockName->invoke($migration, $runtimeConnection);
        self::assertIsString($ddlLockName);
        $secondRunnerEntered = false;
        $ddlRoundTripCompleted = false;
        $firstRuntimeConnectionId = null;

        try {
            try {
                $withInstallationLock->invoke($migration, $runtimeConnection, $lifecycleConnection, function () use (
                    $migration,
                    $withInstallationLock,
                    $runtimeConnection,
                    $lifecycleConnection,
                    $runtimeContender,
                    $lifecycleContender,
                    $ddlLockName,
                    &$secondRunnerEntered,
                    &$ddlRoundTripCompleted,
                    &$firstRuntimeConnectionId,
                ): void {
                    $runtimeSession = $runtimeConnection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                    $lifecycleSession = $lifecycleConnection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                    self::assertNotNull($runtimeSession);
                    self::assertNotNull($lifecycleSession);
                    $firstRuntimeConnectionId = (int) $runtimeSession->connection_id;

                    $lifecycleContender->getPdo()->exec('KILL CONNECTION '.(int) $lifecycleSession->connection_id);

                    // Exercise a complete second installation runner, not a bare lock
                    // probe. It can recover the shared #179 lifecycle lock after the
                    // killed lifecycle session disappears, but it must fail before its
                    // operation starts because the first runner's live DDL session still
                    // owns the dedicated interactive DDL lock.
                    try {
                        $withInstallationLock->invoke(
                            $migration,
                            $runtimeContender,
                            $lifecycleContender,
                            function () use (&$secondRunnerEntered): void {
                                $secondRunnerEntered = true;
                            },
                        );
                        self::fail('A second installation runner must not overlap protected interactive DDL.');
                    } catch (RuntimeException $exception) {
                        self::assertStringContainsString('Could not acquire the Telegram interactive DDL installation lock', $exception->getMessage());
                    }
                    self::assertFalse($secondRunnerEntered);

                    $owner = $runtimeContender->selectOne('SELECT IS_USED_LOCK(?) AS lock_owner', [$ddlLockName], false);
                    self::assertNotNull($owner);
                    self::assertSame($firstRuntimeConnectionId, (int) $owner->lock_owner);

                    $runtimeConnection->statement(
                        'RENAME TABLE `telegram_delivery_interactive_presentations` TO `'.self::ROLLBACK_TABLE.'`',
                    );
                    $runtimeConnection->statement(
                        'RENAME TABLE `'.self::ROLLBACK_TABLE.'` TO `telegram_delivery_interactive_presentations`',
                    );
                    $ddlRoundTripCompleted = true;
                });
                self::fail('The dead lifecycle session must make shared-lock cleanup fail closed after guarded DDL completes.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('shared installation lock cleanup failed', $exception->getMessage());
            }

            self::assertIsInt($firstRuntimeConnectionId);
            self::assertFalse($secondRunnerEntered);
            self::assertTrue($ddlRoundTripCompleted);
            self::assertTrue($runtimeConnection->getSchemaBuilder()->hasTable(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE));
            self::assertFalse($runtimeConnection->getSchemaBuilder()->hasTable(self::ROLLBACK_TABLE));

            // The first runner releases the DDL lock exactly even though shared-lock
            // cleanup fails. A later runner can therefore acquire it normally.
            $acquired = $runtimeContender->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$ddlLockName], false);
            self::assertNotNull($acquired);
            self::assertSame(1, (int) $acquired->acquired);
            $released = $runtimeContender->selectOne('SELECT RELEASE_LOCK(?) AS released', [$ddlLockName], false);
            self::assertNotNull($released);
            self::assertSame(1, (int) $released->released);
        } finally {
            $database->purge('telegram_interactive_runtime_contender');
            $database->purge('telegram_interactive_lifecycle_killer');
        }

        self::assertTrue((new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
            $runtimeConnection,
            (new TelegramDeliveryDatabaseCapability)->expectedHash(),
        ));
        self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady($runtimeConnection));
    }

    public function test_cross_actor_callback_authority_is_rejected_atomically_before_outbox_release(): void
    {
        $owner = $this->account('cross_actor_owner', 900103);
        $other = $this->account('cross_actor_other', 900104);
        $session = $this->sessions()->start(
            $owner['telegram_account_id'],
            'customer.account',
            'confirm',
            [],
            'cross-actor-session-start',
        );
        $callback = $this->callbacks()->issue(
            $session->publicId,
            $session->version,
            'account.confirm',
            [],
            'cross-actor-callback-issue',
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton('تأیید', $callback->publicId, TelegramInlineButtonStyle::Success)],
        ]);

        try {
            NonRestrictedTelegramPresentationTestFactory::queueInteractive(
                $this->queue(),
                TelegramDeliveryAction::Send,
                $other['telegram_user_id'],
                null,
                NonRestrictedTelegramPresentationTestFactory::plainText('تأیید کنید'),
                'cross-actor-delivery-request',
                'correlation-cross-actor-interactive',
                $keyboard,
            );
            self::fail('Interactive callbacks must not be queued for a different Telegram actor.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('interaction authority is stale', $exception->getMessage());
        }

        self::assertSame(0, DB::table('telegram_delivery_operations')->count());
        self::assertSame(0, DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->count());
        self::assertSame(0, DB::table('outbox_messages')->count());
    }

    public function test_stale_interaction_authority_fails_before_provider_boundary_and_direct_snapshot_forgery_is_rejected(): void
    {
        $account = $this->account('stale', 900102);
        $session = $this->sessions()->start(
            $account['telegram_account_id'],
            'customer.account',
            'confirm',
            [],
            'stale-session-start',
        );
        $callback = $this->callbacks()->issue(
            $session->publicId,
            $session->version,
            'account.confirm',
            [],
            'stale-callback-issue',
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton('تأیید', $callback->publicId, TelegramInlineButtonStyle::Success)],
        ]);
        $created = NonRestrictedTelegramPresentationTestFactory::queueInteractive(
            $this->queue(),
            TelegramDeliveryAction::Send,
            $account['telegram_user_id'],
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('تأیید کنید'),
            'stale-delivery-request',
            'correlation-stale-interactive',
            $keyboard,
        );

        $cancelled = $this->sessions()->cancelActive($account['telegram_account_id'], 'stale-session-cancel');
        self::assertNotNull($cancelled);

        $transport = new RecordingInteractiveTelegramMutationTransport(
            [new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 7002)],
            app(DatabaseManager::class),
        );
        $handler = new TelegramInteractiveDeliveryOutboxHandler(
            fn (): TelegramDeliveryOperationExecutor => $this->executor($transport),
        );
        $result = (new DatabaseOutboxDispatcher(app(DatabaseManager::class), $this->clock, 60))->dispatchOne($handler);

        self::assertNotNull($result);
        self::assertSame(0, $transport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'prepared',
            'provider_attempts' => 0,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'dispatch_state' => 'review_required',
            'attempts' => 1,
        ]);

        try {
            DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->insert([
                'delivery_operation_public_id' => (string) Str::ulid(),
                'keyboard_snapshot' => $keyboard->json(),
                'keyboard_snapshot_hash' => $keyboard->hash(),
                'created_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
            ]);
            self::fail('Direct interactive presentation inserts must be rejected by database authority guards.');
        } catch (QueryException) {
            // Expected.
        }
    }

    private function assertInteractiveCapabilityRejectsCurrentSurface(): void
    {
        $connection = DB::connection();
        $operationRan = false;

        try {
            $connection->transaction(function ($transaction) use (&$operationRan): void {
                (new TelegramDeliveryInteractivePresentationDatabaseCapability)->runStore(
                    $transaction,
                    (string) Str::ulid(),
                    str_repeat('a', 64),
                    function () use (&$operationRan): void {
                        $operationRan = true;
                    },
                );
            });
            self::fail('Semantically drifted interactive database authority must reject store arming.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('database authority is not ready', $exception->getMessage());
        }

        self::assertFalse($operationRan);
    }

    private function toggleSqlMode(string $sqlMode, string $mode): string
    {
        $modes = array_values(array_filter(
            array_map(static fn (string $value): string => trim($value), explode(',', $sqlMode)),
            static fn (string $value): bool => $value !== '',
        ));

        $index = array_search($mode, $modes, true);
        if ($index === false) {
            $modes[] = $mode;
        } else {
            unset($modes[$index]);
            $modes = array_values($modes);
        }

        return implode(',', $modes);
    }

    private function normalizeSqlModeForTest(string $sqlMode): string
    {
        $modes = array_values(array_filter(
            array_map(static fn (string $value): string => trim($value), explode(',', $sqlMode)),
            static fn (string $value): bool => $value !== '',
        ));
        sort($modes, SORT_STRING);

        return implode(',', $modes);
    }

    private function normalizeMetadataSqlForTest(string $sql): string
    {
        $sql = str_replace(["\r\n", "\r", '`'], ["\n", "\n", ''], $sql);
        $normalized = preg_replace('/\s+/', ' ', trim($sql));

        return is_string($normalized) ? $normalized : trim($sql);
    }

    /** @return array{user_id:int,telegram_account_id:int,telegram_user_id:int} */
    private function account(string $suffix, int $telegramUserId): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $telegramAccountId = (int) DB::table('telegram_accounts')->insertGetId([
            'user_id' => $userId,
            'bot_id' => 123456,
            'telegram_user_id' => $telegramUserId,
            'username' => 'interactive_'.$suffix,
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'user_id' => $userId,
            'telegram_account_id' => $telegramAccountId,
            'telegram_user_id' => $telegramUserId,
        ];
    }

    private function createInteractiveIncomingForeignKeyProbe(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE telegram_interactive_fk_probe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_operation_public_id CHAR(26) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT telegram_interactive_fk_probe_fk
        FOREIGN KEY (delivery_operation_public_id)
        REFERENCES telegram_delivery_interactive_presentations (delivery_operation_public_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);
    }

    /** @return list<string> */
    private function interactiveTriggerNames(): array
    {
        /** @var list<string> $names */
        $names = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->pluck('TRIGGER_NAME')
            ->map(static fn (mixed $name): string => (string) $name)
            ->sort()
            ->values()
            ->all();

        return $names;
    }

    /** @return list<string> */
    private function expectedInteractiveTriggerNames(): array
    {
        $names = [
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::DELETE_TRIGGER,
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER,
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::UPDATE_TRIGGER,
        ];
        sort($names, SORT_STRING);

        return $names;
    }

    private function insertInteractiveSnapshotThroughExactAuthority(
        string $operationPublicId,
        TelegramInlineKeyboardSnapshot $keyboard,
    ): void {
        $connection = DB::connection();
        $connection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_interactive_authority = 'telegram_delivery_interactive_queue_v1',
    @app_telegram_delivery_interactive_public_id = ?,
    @app_telegram_delivery_interactive_snapshot_hash = ?
SQL, [
            (new TelegramDeliveryDatabaseCapability)->value(),
            $operationPublicId,
            $keyboard->hash(),
        ]);

        try {
            $connection->table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->insert([
                'delivery_operation_public_id' => $operationPublicId,
                'keyboard_snapshot' => $keyboard->json(),
                'keyboard_snapshot_hash' => $keyboard->hash(),
                'created_at' => '2026-08-31 00:00:00.000000',
            ]);
        } finally {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_interactive_snapshot_hash = NULL,
    @app_telegram_delivery_interactive_public_id = NULL,
    @app_telegram_delivery_interactive_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
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
            $this->interactivePresentations(),
            ConfidentialTelegramPresentationTestFactory::service($this->clock),
        );
    }

    private function executor(TelegramMutationTransport $transport): TelegramDeliveryOperationExecutor
    {
        return new TelegramDeliveryOperationExecutor(
            app(DatabaseManager::class),
            $this->clock,
            $this->runtime,
            $transport,
            new TelegramDeliveryDatabaseCapability,
            $this->interactivePresentations(),
            ConfidentialTelegramPresentationTestFactory::service($this->clock),
        );
    }

    private function interactivePresentations(): TelegramDeliveryInteractivePresentationService
    {
        return new TelegramDeliveryInteractivePresentationService(
            $this->clock,
            $this->app->make(StringEncrypter::class),
            $this->runtime,
            new TelegramDeliveryInteractivePresentationDatabaseCapability,
        );
    }

    private function sessions(): TelegramInteractionSessionService
    {
        $this->app->forgetInstance(TelegramInteractionSessionService::class);

        return $this->app->make(TelegramInteractionSessionService::class);
    }

    private function callbacks(): TelegramInteractionCallbackService
    {
        $this->app->forgetInstance(TelegramInteractionCallbackService::class);

        return $this->app->make(TelegramInteractionCallbackService::class);
    }
}

final class TelegramInteractiveDeliveryTestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final readonly class TelegramInteractiveDeliveryTestRuntime implements TelegramDeliveryRuntime
{
    public function __construct(private string $botId) {}

    public function botId(): string
    {
        return $this->botId;
    }
}

final class RecordingInteractiveTelegramMutationTransport implements TelegramMutationTransport
{
    /** @var list<TelegramMutationResult> */
    private array $results;

    public int $attempts = 0;

    /** @var list<int> */
    public array $transactionLevels = [];

    /** @var list<TelegramMutationRequest> */
    public array $requests = [];

    /** @param list<TelegramMutationResult> $results */
    public function __construct(array $results, private readonly DatabaseManager $database)
    {
        $this->results = $results;
    }

    public function mutate(TelegramMutationRequest $request): TelegramMutationResult
    {
        $this->attempts++;
        $this->transactionLevels[] = $this->database->connection()->transactionLevel();
        $this->requests[] = $request;

        return array_shift($this->results)
            ?? new TelegramMutationResult(TelegramMutationOutcome::UncertainResult, 'interactive_test_result_missing');
    }
}
