<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationService;
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
use RuntimeException;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-003 SEC-008 OPS-003 QUA-001 QUA-004 QUA-007 */
final class TelegramInteractiveDeliveryAuthorityTest extends TestCase
{
    use DatabaseTruncation;

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

    public function test_interactive_migration_down_fails_closed_on_external_fk_and_retries_cleanly(): void
    {
        $migration = require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php');
        DB::unprepared('DROP TABLE IF EXISTS telegram_interactive_fk_probe');
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

        try {
            try {
                $migration->down();
                self::fail('Interactive authority rollback must fail closed while an external FK depends on the surface.');
            } catch (QueryException) {
                // MariaDB rejects the destructive table drop while the external FK exists.
            }

            self::assertTrue(Schema::hasTable('telegram_delivery_interactive_presentations'));
            self::assertFalse((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(DB::connection()));

            DB::unprepared('DROP TABLE telegram_interactive_fk_probe');
            $migration->down();
            self::assertFalse(Schema::hasTable('telegram_delivery_interactive_presentations'));

            $migration->up();
            self::assertTrue((new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(DB::connection()));
        } finally {
            DB::unprepared('DROP TABLE IF EXISTS telegram_interactive_fk_probe');
            if (! Schema::hasTable('telegram_delivery_interactive_presentations')
                || ! (new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1)->isReady(DB::connection())) {
                $migration->up();
            }
        }
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
