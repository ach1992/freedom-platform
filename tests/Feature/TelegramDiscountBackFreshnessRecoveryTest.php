<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseDiscountQuote;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseDiscountQuotePreview;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOffering;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodsDecision;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuotePreview;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class StaleDiscountBackPurchaseCatalog implements TelegramCustomerPurchaseCatalog
{
    private readonly TelegramCustomerPurchaseOffering $offering;

    public function __construct()
    {
        $this->offering = new TelegramCustomerPurchaseOffering(
            str_repeat('c', 40),
            'purchase-standard',
            'دسته خرید',
            'Purchase category',
            'پلن خرید',
            'Purchase plan',
            'نسخه پایه',
            'Base variant',
            'استاندارد',
            'Standard',
            900_000,
            30,
            50 * 1024 * 1024 * 1024,
            2,
        );
    }

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramCustomerPurchaseCatalogPage
    {
        if ($actorUserId !== $subjectUserId || $page !== 1 || $pageSize !== 6) {
            throw new RuntimeException('Unexpected stale-Back purchase catalog page request.');
        }

        return new TelegramCustomerPurchaseCatalogPage([$this->offering], 1, 1, 1);
    }

    public function offeringForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramCustomerPurchaseOffering
    {
        if ($actorUserId !== $subjectUserId || ! hash_equals($this->offering->selectionToken, $selectionToken)) {
            throw new RuntimeException('Unexpected stale-Back purchase Offering request.');
        }

        return $this->offering;
    }
}

final class StaleDiscountBackPurchaseQuote implements TelegramCustomerPurchaseQuote
{
    public bool $previewAvailable = true;

    public function __construct(private readonly TelegramCustomerPurchaseCatalog $catalog) {}

    public function previewForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchaseQuotePreview {
        if (! $this->previewAvailable) {
            throw new AuthorizationException('Telegram purchase Quote preview is unavailable.');
        }
        if ($quotePublicId !== str_pad('01K', 26, '0') || $quoteConfigurationHash !== str_repeat('a', 64)) {
            throw new AuthorizationException('Telegram purchase Quote preview is unavailable.');
        }
        $offering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return new TelegramCustomerPurchaseQuotePreview(
            $offering,
            $quotePublicId,
            $quoteConfigurationHash,
            $offering->basePriceIrr,
            $offering->basePriceIrr,
            0,
            $offering->basePriceIrr,
            'IRR',
            $now,
            $now->modify('+15 minutes'),
            true,
        );
    }

    public function quoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        DateTimeImmutable $acceptedAt,
        string $quoteKey,
        string $correlationId,
    ): TelegramCustomerPurchaseQuotePreview {
        $offering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);

        return new TelegramCustomerPurchaseQuotePreview(
            $offering,
            str_pad('01K', 26, '0'),
            str_repeat('a', 64),
            $offering->basePriceIrr,
            $offering->basePriceIrr,
            0,
            $offering->basePriceIrr,
            'IRR',
            $acceptedAt,
            $acceptedAt->modify('+15 minutes'),
            false,
        );
    }
}

final class StaleDiscountBackDiscountQuote implements TelegramCustomerPurchaseDiscountQuote
{
    public function requoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        string $sourceQuotePublicId,
        string $sourceQuoteConfigurationHash,
        string $code,
        DateTimeImmutable $acceptedAt,
        string $operationKey,
    ): TelegramCustomerPurchaseDiscountQuotePreview {
        throw new RuntimeException('Stale-Back recovery must not invoke discount re-Quote authority.');
    }
}

final class StaleDiscountBackPaymentMethods implements TelegramCustomerPurchasePaymentMethods
{
    /** @var list<array{actor_user_id:int,subject_user_id:int,quote_public_id:string,quote_configuration_hash:string,decision_key:string}> */
    public array $calls = [];

    public function discoverForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionKey,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
        $this->calls[] = [
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'quote_public_id' => $quotePublicId,
            'quote_configuration_hash' => $quoteConfigurationHash,
            'decision_key' => $decisionKey,
        ];

        return new TelegramCustomerPurchasePaymentMethodsDecision(
            str_pad('01P', 26, '0'),
            $quotePublicId,
            $quoteConfigurationHash,
            str_repeat('b', 64),
            ['wallet'],
            false,
        );
    }
}

/** @requirement BUY-001 BUY-002 BUY-003 ARCH-003 ARCH-004 DAT-002 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 */
final class TelegramDiscountBackFreshnessRecoveryTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram stale-Back recovery verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
        $this->seed();
        Queue::fake();
        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'telegram.webhook_secret' => self::SECRET,
            'telegram.webhook_path' => 'api/telegram/webhook',
            'telegram.max_body_bytes' => 1_048_576,
            'telegram.queue' => 'critical',
            'telegram.processing_lease_seconds' => 120,
            'telegram.api_base_url' => 'https://api.telegram.org',
            'telegram.api_timeout_seconds' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_back_from_discount_input_recovers_to_catalog_when_quote_freshness_fails_and_old_payment_callback_cannot_continue(): void
    {
        $catalog = new StaleDiscountBackPurchaseCatalog;
        $quote = new StaleDiscountBackPurchaseQuote($catalog);
        $paymentMethods = new StaleDiscountBackPaymentMethods;
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->app->instance(TelegramCustomerPurchaseQuote::class, $quote);
        $this->app->instance(TelegramCustomerPurchaseDiscountQuote::class, new StaleDiscountBackDiscountQuote);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, $paymentMethods);

        $telegramUserId = 9791;
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->accept($this->payload(7400, $telegramUserId, '/start'));
        $processor->process('123456789', 7400);
        $accountId = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->value('id');
        self::assertIsNumeric($accountId);

        $this->accept($this->callbackPayload(7401, $telegramUserId, $this->callbackToken('navigation.purchase', (int) $accountId)));
        $processor->process('123456789', 7401);
        $this->accept($this->callbackPayload(7402, $telegramUserId, $this->callbackToken('navigation.purchase.'.str_repeat('c', 40), (int) $accountId)));
        $processor->process('123456789', 7402);
        $this->accept($this->callbackPayload(7403, $telegramUserId, $this->callbackToken('navigation.purchase.quote', (int) $accountId)));
        $processor->process('123456789', 7403);

        $stalePaymentToken = $this->callbackToken('navigation.purchase.payment_methods', (int) $accountId);
        $discountToken = $this->callbackToken('navigation.purchase.discount', (int) $accountId);
        $this->accept($this->callbackPayload(7404, $telegramUserId, $discountToken));
        $processor->process('123456789', 7404);
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $accountId)
            ->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('purchase_discount_input', (string) $session->state);

        $quote->previewAvailable = false;
        $backToken = $this->callbackToken('navigation.back', (int) $accountId);
        $this->accept($this->callbackPayload(7405, $telegramUserId, $backToken));
        $processor->process('123456789', 7405);

        $afterBack = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($afterBack);
        self::assertSame('purchase_catalog', (string) $afterBack->state);
        self::assertSame('{"page":1}', (string) $afterBack->payload);
        self::assertGreaterThan((int) $session->version, (int) $afterBack->version);
        self::assertSame([], $paymentMethods->calls);
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 7405, 'state' => 'processed']);

        $this->accept($this->callbackPayload(7406, $telegramUserId, $stalePaymentToken));
        $processor->process('123456789', 7406);

        $afterStalePayment = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->first(['state', 'version', 'payload']);
        self::assertNotNull($afterStalePayment);
        self::assertSame('purchase_catalog', (string) $afterStalePayment->state);
        self::assertSame((int) $afterBack->version, (int) $afterStalePayment->version);
        self::assertSame([], $paymentMethods->calls);
        self::assertSame(0, DB::table('payment_eligibility_decisions')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('orders')->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 7406, 'state' => 'processed']);
    }

    /** @param array<string,mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string,mixed> */
    private function payload(int $updateId, int $telegramUserId, string $text): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'date' => 1_700_000_000,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => 'discount_back_freshness',
                    'language_code' => 'en',
                ],
                'chat' => [
                    'id' => $telegramUserId,
                    'type' => 'private',
                ],
                'text' => $text,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function callbackPayload(int $updateId, int $telegramUserId, string $token): array
    {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'callback-'.$updateId,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => 'discount_back_freshness',
                    'language_code' => 'en',
                ],
                'message' => [
                    'message_id' => $updateId,
                    'date' => 1_700_000_000,
                    'chat' => [
                        'id' => $telegramUserId,
                        'type' => 'private',
                    ],
                ],
                'data' => $token,
            ],
        ];
    }

    private function callbackToken(string $action, int $telegramAccountId): string
    {
        $sessionId = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('id');
        self::assertIsNumeric($sessionId);
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action)
            ->orderByDesc('id')
            ->first(['action_payload', 'token_ciphertext']);
        self::assertNotNull($callback);
        self::assertSame('{}', (string) $callback->action_payload);
        self::assertIsString($callback->token_ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);
    }
}