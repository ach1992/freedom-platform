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
use App\Modules\Telegram\Application\TelegramInlineHttpsUrlButton;
use App\Modules\Telegram\Application\TelegramInlineHttpsUrlPurpose;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramInteractiveDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Application\TelegramResolvedInlineKeyboardMarkup;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Support\ConfidentialTelegramPresentationTestFactory;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;
use Tests\TestCase;

final class TelegramHttpsUrlButtonAuthorityTest extends TestCase
{
    use DatabaseTruncation;

    private TelegramHttpsUrlButtonTestClock $clock;

    private TelegramHttpsUrlButtonTestRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram HTTPS URL button authority verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();

        $this->clock = new TelegramHttpsUrlButtonTestClock(new DateTimeImmutable('2026-09-09T15:00:00+00:00'));
        $this->runtime = new TelegramHttpsUrlButtonTestRuntime('123456');
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

    public function test_legacy_callback_snapshot_is_byte_compatible_and_url_policy_is_exact(): void
    {
        $legacyJson = '{"rows":[[{"text":"Choose","callback_public_id":"01ARZ3NDEKTSV4RRFFQ69G5FAV","style":"primary"}]]}';
        $legacy = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton(
                'Choose',
                '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                TelegramInlineButtonStyle::Primary,
            ),
        ]]);

        self::assertSame($legacyJson, $legacy->json());
        self::assertSame('ad90fa17a65dcaaa76e3b0a1b5bf5e81c020b2e0bc27019ea3e551ef5ea9c1fe', $legacy->hash());
        self::assertSame($legacyJson, TelegramInlineKeyboardSnapshot::restore($legacyJson)->json());

        $url = $this->zarinpalUrl('A'.str_repeat('1', 20));
        $button = new TelegramInlineHttpsUrlButton(
            'Pay',
            $url,
            TelegramInlineHttpsUrlPurpose::ZarinpalStartPay,
            TelegramInlineButtonStyle::Primary,
        );
        $urlSnapshot = new TelegramInlineKeyboardSnapshot([[$button]]);
        self::assertSame([], $urlSnapshot->callbackPublicIds());
        self::assertSame([
            'inline_keyboard' => [[
                ['text' => 'Pay', 'url' => $url, 'style' => 'primary'],
            ]],
        ], TelegramResolvedInlineKeyboardMarkup::resolve($urlSnapshot, [])->providerPayload());
        self::assertStringNotContainsString($url, json_encode($button->__debugInfo(), JSON_THROW_ON_ERROR));

        $unsafe = [
            'http://payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20),
            'tg://payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20),
            '//payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20),
            'https://evil.example/pg/StartPay/A'.str_repeat('1', 20),
            'https://PAYMENT.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20),
            'https://user@payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20),
            'https://payment.zarinpal.com:443/pg/StartPay/A'.str_repeat('1', 20),
            'https://payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20).'?x=1',
            'https://payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20).'#fragment',
            'https://payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20).'/',
            "https://payment.zarinpal.com/pg/StartPay/A".str_repeat('1', 20)."\n",
        ];
        foreach ($unsafe as $candidate) {
            try {
                new TelegramInlineHttpsUrlButton('Pay', $candidate, TelegramInlineHttpsUrlPurpose::ZarinpalStartPay);
                self::fail('Unsafe Telegram HTTPS URL must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString($candidate, $exception->getMessage());
            }
        }

        $tampered = '{"rows":[[{"text":"Pay","https_url":"https://evil.example/pg/StartPay/A11111111111111111111","https_url_purpose":"zarinpal_start_pay","style":null}]]}';
        try {
            TelegramInlineKeyboardSnapshot::restore($tampered);
            self::fail('Persisted non-allowlisted Telegram URL must fail restoration.');
        } catch (InvalidArgumentException) {
            // Expected.
        }
    }

    public function test_url_only_keyboard_persists_replays_conflicts_and_dispatches_without_callback_rows(): void
    {
        $url = $this->zarinpalUrl('A'.str_repeat('2', 20));
        $keyboard = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineHttpsUrlButton(
                'Open payment',
                $url,
                TelegramInlineHttpsUrlPurpose::ZarinpalStartPay,
                TelegramInlineButtonStyle::Primary,
            ),
        ]]);

        self::assertSame(0, DB::table('telegram_interaction_callbacks')->count());
        $created = NonRestrictedTelegramPresentationTestFactory::queueInteractive(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900201,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('Continue to payment'),
            'https-url-only-request',
            'correlation-https-url-only',
            $keyboard,
        );
        self::assertFalse($created->replayed);
        self::assertSame(TelegramDeliveryOperationState::Prepared, $created->state);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')->count());

        $stored = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $created->publicId)
            ->first(['keyboard_snapshot', 'keyboard_snapshot_hash']);
        self::assertNotNull($stored);
        self::assertSame($keyboard->json(), (string) $stored->keyboard_snapshot);
        self::assertSame($keyboard->hash(), (string) $stored->keyboard_snapshot_hash);

        $replay = NonRestrictedTelegramPresentationTestFactory::queueInteractive(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900201,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('Continue to payment'),
            'https-url-only-request',
            'correlation-https-url-only',
            $keyboard,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($created->publicId, $replay->publicId);

        try {
            NonRestrictedTelegramPresentationTestFactory::queueInteractive(
                $this->queue(),
                TelegramDeliveryAction::Send,
                900201,
                null,
                NonRestrictedTelegramPresentationTestFactory::plainText('Continue to payment'),
                'https-url-only-request',
                'correlation-https-url-only',
                new TelegramInlineKeyboardSnapshot([[
                    new TelegramInlineHttpsUrlButton(
                        'Open payment',
                        $this->zarinpalUrl('A'.str_repeat('3', 20)),
                        TelegramInlineHttpsUrlPurpose::ZarinpalStartPay,
                        TelegramInlineButtonStyle::Primary,
                    ),
                ]]),
            );
            self::fail('Changed URL semantics must conflict with an existing request key.');
        } catch (DomainException) {
            // Expected.
        }

        $transport = new RecordingHttpsUrlTelegramMutationTransport(
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 7201),
            app(DatabaseManager::class),
        );
        $handler = new TelegramInteractiveDeliveryOutboxHandler(
            fn (): TelegramDeliveryOperationExecutor => $this->executor($transport),
        );
        $dispatch = (new DatabaseOutboxDispatcher(app(DatabaseManager::class), $this->clock, 60))->dispatchOne($handler);

        self::assertNotNull($dispatch);
        self::assertSame(1, $transport->attempts);
        self::assertSame([0], $transport->transactionLevels);
        self::assertCount(1, $transport->requests);
        self::assertSame([
            'inline_keyboard' => [[
                ['text' => 'Open payment', 'url' => $url, 'style' => 'primary'],
            ]],
        ], $transport->requests[0]->inlineKeyboard?->providerPayload());
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'succeeded',
            'provider_attempts' => 1,
            'telegram_message_id' => 7201,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'contract_version' => TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE,
            'dispatch_state' => 'processed',
        ]);
    }

    public function test_mixed_callback_and_url_keyboard_resolves_each_button_under_its_own_authority(): void
    {
        $account = $this->account(900202);
        $session = $this->sessions()->start(
            $account['telegram_account_id'],
            'customer.purchase',
            'payment_redirect',
            [],
            'https-mixed-session-start',
        );
        $callback = $this->callbacks()->issue(
            $session->publicId,
            $session->version,
            'purchase.back',
            [],
            'https-mixed-callback',
        );
        $url = $this->zarinpalUrl('A'.str_repeat('4', 20));
        $keyboard = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineHttpsUrlButton('Open payment', $url, TelegramInlineHttpsUrlPurpose::ZarinpalStartPay),
            new TelegramInlineCallbackButton('Back', $callback->publicId),
        ]]);

        $created = NonRestrictedTelegramPresentationTestFactory::queueInteractive(
            $this->queue(),
            TelegramDeliveryAction::Send,
            $account['telegram_user_id'],
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('Payment is ready'),
            'https-mixed-request',
            'correlation-https-mixed',
            $keyboard,
        );

        $transport = new RecordingHttpsUrlTelegramMutationTransport(
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 7202),
            app(DatabaseManager::class),
        );
        $handler = new TelegramInteractiveDeliveryOutboxHandler(
            fn (): TelegramDeliveryOperationExecutor => $this->executor($transport),
        );
        (new DatabaseOutboxDispatcher(app(DatabaseManager::class), $this->clock, 60))->dispatchOne($handler);

        self::assertSame(1, $transport->attempts);
        self::assertSame([
            'inline_keyboard' => [[
                ['text' => 'Open payment', 'url' => $url],
                ['text' => 'Back', 'callback_data' => $callback->token],
            ]],
        ], $transport->requests[0]->inlineKeyboard?->providerPayload());
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'succeeded',
        ]);
    }

    /** @return array{user_id:int,telegram_account_id:int,telegram_user_id:int} */
    private function account(int $telegramUserId): array
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
            'username' => 'https_url_test',
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

    private function zarinpalUrl(string $authority): string
    {
        return 'https://payment.zarinpal.com/pg/StartPay/'.$authority;
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

final class TelegramHttpsUrlButtonTestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class TelegramHttpsUrlButtonTestRuntime implements TelegramDeliveryRuntime
{
    public function __construct(private string $botId) {}

    public function botId(): string
    {
        return $this->botId;
    }
}

final class RecordingHttpsUrlTelegramMutationTransport implements TelegramMutationTransport
{
    public int $attempts = 0;

    /** @var list<int> */
    public array $transactionLevels = [];

    /** @var list<TelegramMutationRequest> */
    public array $requests = [];

    public function __construct(
        private TelegramMutationResult $result,
        private DatabaseManager $database,
    ) {}

    public function mutate(TelegramMutationRequest $request): TelegramMutationResult
    {
        $this->attempts++;
        $this->transactionLevels[] = $this->database->connection()->transactionLevel();
        $this->requests[] = $request;

        return $this->result;
    }
}
