<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseZarinpalPayment;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOrderReceipt;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodsDecision;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodSelection;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseZarinpalRedirect;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramResolvedInlineKeyboardMarkup;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramZarinpalNavigationPayment implements TelegramCustomerPurchaseZarinpalPayment
{
    /** @var list<array<string,int|string>> */
    public array $prepareCalls = [];

    public int $prepareEffects = 0;

    /** @var array<string,TelegramCustomerPurchaseZarinpalRedirect> */
    private array $accepted = [];

    public function __construct(
        private readonly string $state = 'redirectable',
        private readonly ?string $redirectUrl = null,
        private readonly bool $deny = false,
    ) {}

    public function prepareForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseZarinpalRedirect {
        if ($actorUserId !== $subjectUserId
            || $orderPublicId !== str_pad('01N', 26, '0')
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)
            || $decisionPublicId !== str_pad('01P', 26, '0')
            || $decisionConfigurationHash !== str_repeat('b', 64)
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new AuthorizationException('Unexpected Telegram Zarinpal authority.');
        }
        $this->prepareCalls[] = ['operationKey' => $operationKey];
        if ($this->deny) {
            throw new AuthorizationException('Telegram Zarinpal authority denied.');
        }
        if (isset($this->accepted[$operationKey])) {
            $accepted = $this->accepted[$operationKey];

            return new TelegramCustomerPurchaseZarinpalRedirect(
                $accepted->requestPublicId,
                $accepted->paymentIntentPublicId,
                $accepted->state,
                $accepted->redirectUrl,
                true,
                $accepted->manualReviewRequired,
            );
        }
        $this->prepareEffects++;
        $redirectUrl = $this->state === 'redirectable'
            ? ($this->redirectUrl ?? 'https://payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20))
            : null;
        $accepted = new TelegramCustomerPurchaseZarinpalRedirect(
            str_pad('01R', 26, '0'),
            str_pad('01H', 26, '0'),
            $this->state,
            $redirectUrl,
            false,
            in_array($this->state, ['uncertain', 'manual_review'], true),
        );
        $this->accepted[$operationKey] = $accepted;

        return $accepted;
    }
}

final readonly class TelegramZarinpalNavigationOrder implements TelegramCustomerPurchaseOrder
{
    public function openForSelf(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash, string $correlationId): TelegramCustomerPurchaseOrderReceipt
    {
        return $this->receipt($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    public function currentForSelf(int $actorUserId, int $subjectUserId, string $orderPublicId, string $quotePublicId, string $quoteConfigurationHash): TelegramCustomerPurchaseOrderReceipt
    {
        if ($orderPublicId !== str_pad('01N', 26, '0')) {
            throw new AuthorizationException('Unexpected Telegram Zarinpal Order.');
        }

        return $this->receipt($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    private function receipt(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash): TelegramCustomerPurchaseOrderReceipt
    {
        if ($actorUserId !== $subjectUserId
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)) {
            throw new AuthorizationException('Unexpected Telegram Zarinpal Order authority.');
        }

        return new TelegramCustomerPurchaseOrderReceipt(str_pad('01N', 26, '0'), $quotePublicId, $quoteConfigurationHash, 1_250_000, 'IRR', true);
    }
}

final readonly class TelegramZarinpalNavigationPaymentMethods implements TelegramCustomerPurchasePaymentMethods
{
    public function discoverForSelf(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash, string $decisionKey): TelegramCustomerPurchasePaymentMethodsDecision
    {
        return $this->decision($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    public function currentForSelf(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash, string $decisionPublicId, string $decisionConfigurationHash): TelegramCustomerPurchasePaymentMethodsDecision
    {
        if ($decisionPublicId !== str_pad('01P', 26, '0') || $decisionConfigurationHash !== str_repeat('b', 64)) {
            throw new AuthorizationException('Unexpected Telegram Zarinpal PAY-001 identity.');
        }

        return $this->decision($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    public function selectForSelf(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash, string $decisionPublicId, string $decisionConfigurationHash, string $methodCode): TelegramCustomerPurchasePaymentMethodSelection
    {
        $this->currentForSelf($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash, $decisionPublicId, $decisionConfigurationHash);
        if ($methodCode !== 'zarinpal') {
            throw new AuthorizationException('Unexpected Telegram Zarinpal payment method.');
        }

        return new TelegramCustomerPurchasePaymentMethodSelection($decisionPublicId, $quotePublicId, $methodCode);
    }

    private function decision(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash): TelegramCustomerPurchasePaymentMethodsDecision
    {
        if ($actorUserId !== $subjectUserId
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)) {
            throw new AuthorizationException('Unexpected Telegram Zarinpal payment-method authority.');
        }

        return new TelegramCustomerPurchasePaymentMethodsDecision(
            str_pad('01P', 26, '0'),
            $quotePublicId,
            $quoteConfigurationHash,
            str_repeat('b', 64),
            ['zarinpal'],
            true,
        );
    }
}

/** @requirement BUY-001 BUY-003 PAY-001 PAY-002 IPG-001 ARCH-003 ARCH-004 DAT-002 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 */
final class TelegramZarinpalNavigationTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Zarinpal navigation verification requires MariaDB/MySQL.');
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

    public function test_zarinpal_selection_presents_typed_startpay_once_without_settlement_or_provisioning(): void
    {
        $payment = new TelegramZarinpalNavigationPayment;
        [$processor, $sessionId, $telegramUserId, $accountId, $selectionToken, $selectionUpdateId] = $this->prepareSelection(
            $payment,
            9910,
            9100,
            'zarinpal_redirect',
            'fa',
        );

        self::assertSame(1, $payment->prepareEffects);
        self::assertCount(1, $payment->prepareCalls);
        self::assertSame('purchase_zarinpal_redirect', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'));
        $sessionPayload = (string) DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('payload');
        self::assertStringNotContainsString('payment.zarinpal.com', $sessionPayload);
        self::assertStringContainsString('درگاه زرین‌پال آماده است', $this->latestConfidentialPresentation());

        $expectedUrl = 'https://payment.zarinpal.com/pg/StartPay/A'.str_repeat('1', 20);
        $providerPayload = $this->latestProviderKeyboardPayload();
        self::assertSame($expectedUrl, $providerPayload['inline_keyboard'][0][0]['url'] ?? null);
        self::assertSame('primary', $providerPayload['inline_keyboard'][0][0]['style'] ?? null);
        self::assertArrayNotHasKey('callback_data', $providerPayload['inline_keyboard'][0][0]);
        self::assertSame('بازگشت', $providerPayload['inline_keyboard'][1][0]['text'] ?? null);
        self::assertArrayHasKey('callback_data', $providerPayload['inline_keyboard'][1][0]);
        self::assertArrayNotHasKey('url', $providerPayload['inline_keyboard'][1][0]);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('service_subscriptions')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());

        // A later update while the durable redirect session is active may need
        // to reconstruct presentation after a process crash. It must replay the
        // accepted application identity without producing another provider effect.
        $this->accept($this->payload(9110, $telegramUserId, 'zarinpal_redirect', 'fa', 'نمایش دوباره'));
        $processor->process('123456789', 9110);
        self::assertSame(1, $payment->prepareEffects);
        self::assertCount(2, $payment->prepareCalls);
        self::assertSame('purchase_zarinpal_redirect', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'));
        self::assertSame($expectedUrl, $this->latestProviderKeyboardPayload()['inline_keyboard'][0][0]['url'] ?? null);

        $this->accept($this->callbackPayload($selectionUpdateId, $telegramUserId, 'zarinpal_redirect', 'fa', $selectionToken));
        $processor->process('123456789', $selectionUpdateId);
        self::assertSame(1, $payment->prepareEffects);
        self::assertCount(2, $payment->prepareCalls);
        self::assertSame(1, DB::table('processed_telegram_updates')->where('update_id', $selectionUpdateId)->count());
        self::assertIsNumeric($accountId);
    }

    public function test_uncertain_zarinpal_request_never_retries_provider_on_later_input_and_back_is_safe(): void
    {
        $payment = new TelegramZarinpalNavigationPayment('uncertain');
        [$processor, $sessionId, $telegramUserId, $accountId] = $this->prepareSelection(
            $payment,
            9920,
            9200,
            'zarinpal_uncertain',
            'fa',
        );

        self::assertSame('purchase_zarinpal_pending', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'));
        self::assertSame(1, $payment->prepareEffects);
        self::assertStringContainsString('نتیجه درخواست زرین‌پال', $this->latestConfidentialPresentation());
        $providerPayload = $this->latestProviderKeyboardPayload();
        self::assertCount(1, $providerPayload['inline_keyboard']);
        self::assertArrayHasKey('callback_data', $providerPayload['inline_keyboard'][0][0]);
        self::assertArrayNotHasKey('url', $providerPayload['inline_keyboard'][0][0]);

        $this->accept($this->payload(9210, $telegramUserId, 'zarinpal_uncertain', 'fa', 'status?'));
        $processor->process('123456789', 9210);
        self::assertSame(1, $payment->prepareEffects);
        self::assertCount(1, $payment->prepareCalls);

        $token = $this->callbackToken('navigation.back', $accountId);
        $this->accept($this->callbackPayload(9211, $telegramUserId, 'zarinpal_uncertain', 'fa', $token));
        $processor->process('123456789', 9211);
        self::assertSame('purchase_payment_methods', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'));
        self::assertSame(1, $payment->prepareEffects);
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
    }

    public function test_non_allowlisted_redirect_fails_closed_without_reinvoking_zarinpal(): void
    {
        $payment = new TelegramZarinpalNavigationPayment(
            'redirectable',
            'https://evil.example/pg/StartPay/A'.str_repeat('2', 20),
        );
        [$processor, $sessionId, $telegramUserId] = $this->prepareSelection(
            $payment,
            9930,
            9300,
            'zarinpal_unsafe_url',
            'en',
        );

        self::assertSame(1, $payment->prepareEffects);
        self::assertSame('purchase_zarinpal_pending', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'));
        self::assertStringContainsString('not available', $this->latestConfidentialPresentation());
        $snapshot = $this->latestKeyboardSnapshot();
        self::assertStringNotContainsString('evil.example', $snapshot->json());
        self::assertCount(1, $snapshot->callbackPublicIds());

        $this->accept($this->payload(9310, $telegramUserId, 'zarinpal_unsafe_url', 'en', 'retry'));
        $processor->process('123456789', 9310);
        self::assertSame(1, $payment->prepareEffects);
        self::assertCount(1, $payment->prepareCalls);
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    public function test_authorization_denial_creates_no_zarinpal_effect_and_returns_home(): void
    {
        $payment = new TelegramZarinpalNavigationPayment(deny: true);
        [, $sessionId] = $this->prepareSelection(
            $payment,
            9940,
            9400,
            'zarinpal_denied',
            'fa',
        );

        self::assertSame(0, $payment->prepareEffects);
        self::assertCount(1, $payment->prepareCalls);
        self::assertSame('home', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'));
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('service_subscriptions')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
    }

    /**
     * @return array{TelegramUpdateProcessor,int,int,int,string,int}
     */
    private function prepareSelection(
        TelegramZarinpalNavigationPayment $payment,
        int $telegramUserId,
        int $baseUpdateId,
        string $username,
        string $locale,
    ): array {
        $this->app->instance(TelegramCustomerPurchaseZarinpalPayment::class, $payment);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, new TelegramZarinpalNavigationOrder);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, new TelegramZarinpalNavigationPaymentMethods);
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload($baseUpdateId, $telegramUserId, $username, $locale, '/start'));
        $processor->process('123456789', $baseUpdateId);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $home = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->first(['id', 'public_id', 'version']);
        self::assertNotNull($home);

        $paymentState = [
            'page' => 1,
            'offering_selection' => str_repeat('c', 40),
            'quote_public_id' => str_pad('01K', 26, '0'),
            'quote_configuration_hash' => str_repeat('a', 64),
            'payment_decision_public_id' => str_pad('01P', 26, '0'),
            'payment_decision_configuration_hash' => str_repeat('b', 64),
            'order_public_id' => str_pad('01N', 26, '0'),
        ];
        $methods = $this->app->make(TelegramInteractionSessionService::class)->transition(
            (string) $home->public_id,
            (int) $home->version,
            'purchase_payment_methods',
            $paymentState,
            'telegram-zarinpal-test-methods:'.hash('sha256', $username),
        );
        $callback = $this->app->make(TelegramInteractionCallbackService::class)->issue(
            $methods->publicId,
            $methods->version,
            'navigation.purchase.payment_method.select',
            ['method_code' => 'zarinpal'],
            'telegram-zarinpal-test-select:'.hash('sha256', $username),
        );
        $token = $this->app->make(StringEncrypter::class)->decryptString((string) DB::table('telegram_interaction_callbacks')
            ->where('public_id', $callback->publicId)
            ->value('token_ciphertext'));
        $selectionUpdateId = $baseUpdateId + 1;
        $this->accept($this->callbackPayload($selectionUpdateId, $telegramUserId, $username, $locale, $token));
        $processor->process('123456789', $selectionUpdateId);

        return [$processor, (int) $home->id, $telegramUserId, $accountId, $token, $selectionUpdateId];
    }

    /** @param array<string,mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)->postJson('/api/telegram/webhook', $payload)->assertOk();
    }

    /** @return array<string,mixed> */
    private function payload(int $updateId, int $telegramUserId, string $username, string $languageCode, string $text): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'date' => 1_700_000_000,
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'username' => $username, 'language_code' => $languageCode],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function callbackPayload(int $updateId, int $telegramUserId, string $username, string $languageCode, string $token): array
    {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'callback-'.$updateId,
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'username' => $username, 'language_code' => $languageCode],
                'message' => [
                    'message_id' => $updateId,
                    'date' => 1_700_000_000,
                    'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                ],
                'data' => $token,
            ],
        ];
    }

    private function callbackToken(string $action, int $telegramAccountId): string
    {
        $sessionId = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $telegramAccountId)->value('id');
        self::assertIsNumeric($sessionId);
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($callback);

        return $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);
    }

    private function latestConfidentialPresentation(): string
    {
        $operationPublicId = DB::table('telegram_delivery_operations')->orderByDesc('id')->value('public_id');
        self::assertIsString($operationPublicId);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
    }

    private function latestKeyboardSnapshot(): TelegramInlineKeyboardSnapshot
    {
        $operationPublicId = DB::table('telegram_delivery_operations')->orderByDesc('id')->value('public_id');
        self::assertIsString($operationPublicId);
        $snapshot = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->value('keyboard_snapshot');
        self::assertIsString($snapshot);

        return TelegramInlineKeyboardSnapshot::restore($snapshot);
    }

    /** @return array<string,mixed> */
    private function latestProviderKeyboardPayload(): array
    {
        $snapshot = $this->latestKeyboardSnapshot();
        $resolvedCallbacks = [];
        foreach ($snapshot->callbackPublicIds() as $publicId) {
            $ciphertext = DB::table('telegram_interaction_callbacks')->where('public_id', $publicId)->value('token_ciphertext');
            self::assertIsString($ciphertext);
            $resolvedCallbacks[$publicId] = $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
        }

        return TelegramResolvedInlineKeyboardMarkup::resolve($snapshot, $resolvedCallbacks)->providerPayload();
    }
}
