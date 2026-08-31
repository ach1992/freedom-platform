<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryLifecycleDatabaseAuthority;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Shared\Application\Clock;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\ConfidentialTelegramPresentationTestFactory;
use Tests\Support\TelegramInteractivePresentationTestFactory;
use Tests\TestCase;
use Throwable;

final class TelegramConfidentialDeliveryMigrationSafetyTest extends TestCase
{
    use DatabaseTruncation;

    private const ROLLBACK_TABLE = 'telegram_delivery_confidential_presentations_rollback';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram confidential delivery migration safety requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
    }

    public function test_migration_refuses_non_empty_semantically_drifted_surface(): void
    {
        $created = ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            901001,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('durable confidential drift fixture'),
            'confidential-drift-request',
            'correlation-confidential-drift',
        );
        $surface = new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
        $interactiveSurface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        self::assertTrue($surface->isReady(DB::connection()));
        self::assertTrue($interactiveSurface->isReady(DB::connection()));

        DB::unprepared(
            'CREATE OR REPLACE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$interactiveSurface::legacyV2InsertTriggerBody(),
        );
        self::assertTrue($interactiveSurface->isReady(
            DB::connection(),
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV2InsertTriggerBody(),
        ));
        self::assertFalse($interactiveSurface->isReady(DB::connection()));

        DB::unprepared(<<<'SQL'
ALTER TABLE telegram_delivery_confidential_presentations
MODIFY presentation_ciphertext LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
SQL);
        self::assertFalse($surface->isReady(DB::connection()));

        try {
            (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
            self::fail('A non-empty semantically drifted confidential surface must not be destructively repaired.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot repair a non-empty unrecognized surface', $exception->getMessage());
        }

        self::assertSame(1, DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $created->publicId)
            ->count());
        self::assertFalse($surface->isReady(DB::connection()));
        self::assertTrue($interactiveSurface->isReady(
            DB::connection(),
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV2InsertTriggerBody(),
        ));
        self::assertFalse($interactiveSurface->isReady(DB::connection()));
    }

    public function test_migration_down_refuses_durable_confidential_presentations_before_destructive_ddl(): void
    {
        ConfidentialTelegramPresentationTestFactory::queue(
            $this->queue(),
            TelegramDeliveryAction::Send,
            901002,
            null,
            ConfidentialTelegramPresentationTestFactory::plainText('durable rollback refusal fixture'),
            'confidential-rollback-durable-request',
            'correlation-confidential-rollback-durable',
        );

        try {
            (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->down();
            self::fail('Confidential authority rollback must refuse durable rows before destructive DDL.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('cannot be removed while durable confidential presentations exist', $exception->getMessage());
        }

        self::assertTrue(Schema::hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));
        self::assertSame(1, DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->count());
    }

    public function test_migration_down_external_fk_failure_preserves_guards_and_retry(): void
    {
        $migration = require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php');
        $surface = new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
        DB::unprepared('DROP TABLE IF EXISTS telegram_confidential_fk_probe');
        $this->createIncomingForeignKeyProbe();

        try {
            try {
                $migration->down();
                self::fail('Confidential rollback must fail closed while an incoming FK depends on the surface.');
            } catch (QueryException) {
                // MariaDB rejects dependency-sensitive DROP.
            }

            self::assertTrue(Schema::hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));
            self::assertFalse($surface->isReady(DB::connection()));
            self::assertSame($this->expectedTriggerNames(), $this->triggerNames());
            self::assertTrue($this->baseAuthorityIsReady());

            $fixture = ConfidentialTelegramPresentationTestFactory::prepareV3OperationWithoutCompanion(
                app(DatabaseManager::class),
                app(Clock::class),
                901003,
                'confidential-fk-guard-fixture',
                'correlation-confidential-fk-guard',
            );
            $this->insertConfidentialThroughExactAuthority($fixture);

            try {
                DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->insert([
                    'delivery_operation_public_id' => (string) Str::ulid(),
                    'presentation_ciphertext' => 'unauthorized-ciphertext',
                    'presentation_hash' => str_repeat('a', 64),
                    'created_at' => '2026-09-01 00:00:00.000000',
                ]);
                self::fail('Surviving confidential INSERT guard must reject unauthorized creation after failed DROP.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('creation authority is invalid', strtolower($exception->getMessage()));
            }

            try {
                DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
                    ->where('delivery_operation_public_id', $fixture['public_id'])
                    ->update(['presentation_hash' => str_repeat('b', 64)]);
                self::fail('Surviving confidential UPDATE guard must preserve immutable rows after failed DROP.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('confidential presentations are immutable', strtolower($exception->getMessage()));
            }

            try {
                DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
                    ->where('delivery_operation_public_id', $fixture['public_id'])
                    ->delete();
                self::fail('Surviving confidential DELETE guard must preserve durable rows after failed DROP.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('confidential presentations are non-deletable', strtolower($exception->getMessage()));
            }

            DB::unprepared('DROP TABLE telegram_confidential_fk_probe');
            self::assertTrue($surface->isReady(DB::connection()), 'Removing only the external FK must restore readiness.');
            self::assertSame($this->expectedTriggerNames(), $this->triggerNames());

            DB::statement('TRUNCATE TABLE '.TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE);
            $migration->down();
            self::assertFalse(Schema::hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));
            self::assertTrue($this->baseAuthorityIsReady());
            self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(
                DB::connection(),
                TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV2InsertTriggerBody(),
            ));

            $migration->up();
            self::assertTrue($surface->isReady(DB::connection()));
        } finally {
            DB::unprepared('DROP TABLE IF EXISTS telegram_confidential_fk_probe');
            if (! Schema::hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)) {
                $migration->up();
            }
        }
    }

    public function test_migration_up_dependency_failure_preserves_guards_and_recovers_without_repair(): void
    {
        $migration = require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php');
        $surface = new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
        self::assertTrue($surface->isReady(DB::connection()));
        self::assertSame(0, DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->count());

        DB::unprepared('DROP TABLE IF EXISTS telegram_confidential_fk_probe');
        $this->createIncomingForeignKeyProbe();
        try {
            self::assertFalse($surface->isReady(DB::connection()));
            try {
                $migration->up();
                self::fail('Confidential authority re-entry must not strip guards before dependency-sensitive rebuild DROP.');
            } catch (QueryException) {
                // MariaDB rejects rebuild DROP while incoming FK exists.
            }

            self::assertTrue(Schema::hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));
            self::assertSame($this->expectedTriggerNames(), $this->triggerNames());
            DB::unprepared('DROP TABLE telegram_confidential_fk_probe');
            self::assertTrue($surface->isReady(DB::connection()));
            $migration->up();
            self::assertTrue($surface->isReady(DB::connection()));
        } finally {
            DB::unprepared('DROP TABLE IF EXISTS telegram_confidential_fk_probe');
        }
    }

    public function test_down_reentry_resumes_from_persistent_fence_and_staged_table(): void
    {
        $migration = require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php');
        $database = app(DatabaseManager::class);
        $connection = DB::connection();
        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);
        $establishFence = new ReflectionMethod($migration, 'establishPersistentRuntimeFence');
        $establishFence->setAccessible(true);
        $stageTable = new ReflectionMethod($migration, 'stageConfidentialTableForRollback');
        $stageTable->setAccessible(true);
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);

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

        self::assertFalse($this->baseAuthorityIsReady());
        self::assertFalse(Schema::hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));
        self::assertTrue(Schema::hasTable(self::ROLLBACK_TABLE));

        $migration->down();

        self::assertTrue($this->baseAuthorityIsReady());
        self::assertFalse(Schema::hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));
        self::assertFalse(Schema::hasTable(self::ROLLBACK_TABLE));
        self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(
            $connection,
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV2InsertTriggerBody(),
        ));

        $migration->up();
        self::assertTrue((new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady($connection));
    }

    public function test_down_reentry_restores_v1_after_interrupted_post_drop_fence(): void
    {
        $migration = require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php');
        $database = app(DatabaseManager::class);
        $connection = DB::connection();
        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
        $lifecycleConnection = $lifecycleAuthority->requireConnection($connection);
        $lifecycleAuthority->principalUsername($lifecycleConnection);
        $establishFence = new ReflectionMethod($migration, 'establishPersistentRuntimeFence');
        $establishFence->setAccessible(true);
        $stageTable = new ReflectionMethod($migration, 'stageConfidentialTableForRollback');
        $stageTable->setAccessible(true);
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);

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

        self::assertFalse($this->baseAuthorityIsReady());
        self::assertFalse(Schema::hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));
        self::assertFalse(Schema::hasTable(self::ROLLBACK_TABLE));

        $migration->down();

        self::assertTrue($this->baseAuthorityIsReady());
        self::assertFalse(Schema::hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));
        self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(
            $connection,
            TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::legacyV2InsertTriggerBody(),
        ));

        $migration->up();
        self::assertTrue((new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady($connection));
    }

    public function test_ddl_lock_owner_session_loss_blocks_protected_confidential_ddl_after_contender_acquires_lock(): void
    {
        $database = app(DatabaseManager::class);
        $defaultConnection = config('database.default');
        self::assertIsString($defaultConnection);
        $runtimeConfig = config('database.connections.'.$defaultConnection);
        self::assertIsArray($runtimeConfig);
        config(['database.connections.telegram_confidential_runtime_killer' => $runtimeConfig]);

        $runtimeConnection = DB::connection();
        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
        $lifecycleConnection = $lifecycleAuthority->requireConnection($runtimeConnection);
        $killer = $database->connection('telegram_confidential_runtime_killer');
        $migration = require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php');
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);
        $ddlInstallationLockName = new ReflectionMethod($migration, 'ddlInstallationLockName');
        $ddlInstallationLockName->setAccessible(true);
        $ddlLockName = $ddlInstallationLockName->invoke($migration, $runtimeConnection);
        self::assertIsString($ddlLockName);
        $killedConnectionId = null;
        $contenderOwnsDdlLock = false;

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
                    $acquired = $killer->selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$ddlLockName], false);
                    self::assertNotNull($acquired);
                    self::assertSame(1, (int) $acquired->acquired);
                    $contenderOwnsDdlLock = true;

                    $runtimeConnection->statement(
                        'RENAME TABLE `telegram_delivery_confidential_presentations` TO `'.self::ROLLBACK_TABLE.'`',
                    );
                });
                self::fail('Losing the confidential DDL-lock owner must abort before protected DDL continues.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('DDL installation lock cleanup failed', $exception->getMessage());
            }

            self::assertIsInt($killedConnectionId);
            self::assertTrue($contenderOwnsDdlLock);
            self::assertFalse($killer->getSchemaBuilder()->hasTable(self::ROLLBACK_TABLE));
            self::assertTrue($killer->getSchemaBuilder()->hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));

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
                    // Isolated best-effort cleanup.
                }
            }
            $database->purge('telegram_confidential_runtime_killer');
        }

        self::assertTrue($this->baseAuthorityIsReady());
        self::assertTrue((new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady($runtimeConnection));
    }

    public function test_lifecycle_session_loss_cannot_overlap_confidential_ddl_guarded_by_runtime_session(): void
    {
        $database = app(DatabaseManager::class);
        $defaultConnection = config('database.default');
        self::assertIsString($defaultConnection);
        $runtimeConfig = config('database.connections.'.$defaultConnection);
        $lifecycleConfig = config('database.connections.telegram_lifecycle');
        self::assertIsArray($runtimeConfig);
        self::assertIsArray($lifecycleConfig);
        config([
            'database.connections.telegram_confidential_runtime_contender' => $runtimeConfig,
            'database.connections.telegram_confidential_lifecycle_killer' => $lifecycleConfig,
        ]);

        $runtimeConnection = DB::connection();
        $lifecycleAuthority = new TelegramDeliveryLifecycleDatabaseAuthority($database);
        $lifecycleConnection = $lifecycleAuthority->requireConnection($runtimeConnection);
        $runtimeContender = $database->connection('telegram_confidential_runtime_contender');
        $lifecycleContender = $database->connection('telegram_confidential_lifecycle_killer');
        $migration = require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php');
        $withInstallationLock = new ReflectionMethod($migration, 'withInstallationLock');
        $withInstallationLock->setAccessible(true);
        $ddlInstallationLockName = new ReflectionMethod($migration, 'ddlInstallationLockName');
        $ddlInstallationLockName->setAccessible(true);
        $ddlLockName = $ddlInstallationLockName->invoke($migration, $runtimeConnection);
        self::assertIsString($ddlLockName);

        $interactiveMigration = require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php');
        $interactiveWithInstallationLock = new ReflectionMethod($interactiveMigration, 'withInstallationLock');
        $interactiveWithInstallationLock->setAccessible(true);
        $interactiveDdlInstallationLockName = new ReflectionMethod($interactiveMigration, 'ddlInstallationLockName');
        $interactiveDdlInstallationLockName->setAccessible(true);
        $interactiveDdlLockName = $interactiveDdlInstallationLockName->invoke($interactiveMigration, $runtimeContender);
        self::assertIsString($interactiveDdlLockName);
        self::assertSame($interactiveDdlLockName, $ddlLockName);
        $secondRunnerEntered = false;
        $ddlRoundTripCompleted = false;
        $firstRuntimeConnectionId = null;

        try {
            try {
                $withInstallationLock->invoke($migration, $runtimeConnection, $lifecycleConnection, function () use (
                    $interactiveMigration,
                    $interactiveWithInstallationLock,
                    $runtimeConnection,
                    $runtimeContender,
                    $lifecycleContender,
                    $ddlLockName,
                    &$secondRunnerEntered,
                    &$ddlRoundTripCompleted,
                    &$firstRuntimeConnectionId,
                ): void {
                    $runtimeSession = $runtimeConnection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                    $lifecycleSession = app(DatabaseManager::class)->connection('telegram_lifecycle')
                        ->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                    self::assertNotNull($runtimeSession);
                    self::assertNotNull($lifecycleSession);
                    $firstRuntimeConnectionId = (int) $runtimeSession->connection_id;
                    $lifecycleContender->getPdo()->exec('KILL CONNECTION '.(int) $lifecycleSession->connection_id);

                    try {
                        $interactiveWithInstallationLock->invoke(
                            $interactiveMigration,
                            $runtimeContender,
                            $lifecycleContender,
                            function () use (&$secondRunnerEntered): void {
                                $secondRunnerEntered = true;
                            },
                        );
                        self::fail('A #209 interactive installation runner must not overlap #212 protected DDL after lifecycle-session loss.');
                    } catch (RuntimeException $exception) {
                        self::assertStringContainsString('Could not acquire the Telegram interactive DDL installation lock', $exception->getMessage());
                    }
                    self::assertFalse($secondRunnerEntered);

                    $owner = $runtimeContender->selectOne('SELECT IS_USED_LOCK(?) AS lock_owner', [$ddlLockName], false);
                    self::assertNotNull($owner);
                    self::assertSame($firstRuntimeConnectionId, (int) $owner->lock_owner);

                    $runtimeConnection->statement(
                        'RENAME TABLE `telegram_delivery_confidential_presentations` TO `'.self::ROLLBACK_TABLE.'`',
                    );
                    $runtimeConnection->statement(
                        'RENAME TABLE `'.self::ROLLBACK_TABLE.'` TO `telegram_delivery_confidential_presentations`',
                    );

                    $interactiveSurface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
                    $runtimeConnection->unprepared(
                        'CREATE OR REPLACE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
                        .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
                        .' FOR EACH ROW '.$interactiveSurface::insertTriggerBody(),
                    );
                    self::assertTrue($interactiveSurface->isReady($runtimeConnection));
                    $ddlRoundTripCompleted = true;
                });
                self::fail('Dead lifecycle session must make shared-lock cleanup fail closed after guarded DDL.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('shared installation lock cleanup failed', $exception->getMessage());
            }

            self::assertIsInt($firstRuntimeConnectionId);
            self::assertFalse($secondRunnerEntered);
            self::assertTrue($ddlRoundTripCompleted);
            self::assertTrue($runtimeConnection->getSchemaBuilder()->hasTable(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE));
            self::assertFalse($runtimeConnection->getSchemaBuilder()->hasTable(self::ROLLBACK_TABLE));

            $acquired = $runtimeContender->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$ddlLockName], false);
            self::assertNotNull($acquired);
            self::assertSame(1, (int) $acquired->acquired);
            $released = $runtimeContender->selectOne('SELECT RELEASE_LOCK(?) AS released', [$ddlLockName], false);
            self::assertNotNull($released);
            self::assertSame(1, (int) $released->released);
        } finally {
            $database->purge('telegram_confidential_runtime_contender');
            $database->purge('telegram_confidential_lifecycle_killer');
        }

        self::assertTrue($this->baseAuthorityIsReady());
        self::assertTrue((new TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1)->isReady($runtimeConnection));
    }

    private function queue(): TelegramDeliveryQueueService
    {
        $database = app(DatabaseManager::class);
        $clock = app(Clock::class);
        $runtime = new TelegramConfidentialMigrationRuntime('123456');

        return new TelegramDeliveryQueueService(
            $database,
            $clock,
            new DatabaseOutboxPublisher($database, $clock),
            $runtime,
            new TelegramDeliveryDatabaseCapability,
            TelegramInteractivePresentationTestFactory::service($clock, $runtime),
            ConfidentialTelegramPresentationTestFactory::service($clock),
        );
    }

    private function createIncomingForeignKeyProbe(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE telegram_confidential_fk_probe (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_operation_public_id CHAR(26) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT telegram_confidential_fk_probe_fk
        FOREIGN KEY (delivery_operation_public_id)
        REFERENCES telegram_delivery_confidential_presentations (delivery_operation_public_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);
    }

    /** @return list<string> */
    private function triggerNames(): array
    {
        /** @var list<string> $names */
        $names = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->pluck('TRIGGER_NAME')
            ->map(static fn (mixed $name): string => (string) $name)
            ->sort()
            ->values()
            ->all();

        return $names;
    }

    /** @return list<string> */
    private function expectedTriggerNames(): array
    {
        $names = [
            TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::DELETE_TRIGGER,
            TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::INSERT_TRIGGER,
            TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::UPDATE_TRIGGER,
        ];
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @param  array{public_id:string,outbox_event_id:string,presentation_ciphertext:string,presentation_hash:string,ciphertext_hash:string,request_fingerprint:string}  $fixture
     */
    private function insertConfidentialThroughExactAuthority(array $fixture): void
    {
        $connection = DB::connection();
        $connection->statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_confidential_authority = 'telegram_delivery_confidential_queue_v1',
    @app_telegram_delivery_confidential_public_id = ?,
    @app_telegram_delivery_confidential_presentation_hash = ?,
    @app_telegram_delivery_confidential_ciphertext_hash = ?,
    @app_telegram_delivery_confidential_fingerprint = ?
SQL, [
            (new TelegramDeliveryDatabaseCapability)->value(),
            $fixture['public_id'],
            $fixture['presentation_hash'],
            $fixture['ciphertext_hash'],
            $fixture['request_fingerprint'],
        ]);

        try {
            $connection->table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->insert([
                'delivery_operation_public_id' => $fixture['public_id'],
                'presentation_ciphertext' => $fixture['presentation_ciphertext'],
                'presentation_hash' => $fixture['presentation_hash'],
                'created_at' => '2026-09-01 00:00:00.000000',
            ]);
        } finally {
            $connection->statement(<<<'SQL'
SET @app_telegram_delivery_confidential_fingerprint = NULL,
    @app_telegram_delivery_confidential_ciphertext_hash = NULL,
    @app_telegram_delivery_confidential_presentation_hash = NULL,
    @app_telegram_delivery_confidential_public_id = NULL,
    @app_telegram_delivery_confidential_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
        }
    }

    private function baseAuthorityIsReady(): bool
    {
        return (new TelegramDeliveryDatabaseAuthoritySurfaceV1)->isReady(
            DB::connection(),
            (new TelegramDeliveryDatabaseCapability)->expectedHash(),
        );
    }
}

final readonly class TelegramConfidentialMigrationRuntime implements TelegramDeliveryRuntime
{
    public function __construct(private string $botId) {}

    public function botId(): string
    {
        return $this->botId;
    }
}
