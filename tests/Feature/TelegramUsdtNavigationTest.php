<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseUsdtPayment;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOrderReceipt;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodsDecision;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodSelection;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseUsdtInstructions;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseUsdtSubmission;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class TelegramUsdtNavigationPayment implements TelegramCustomerPurchaseUsdtPayment
{
    /** @var list<array<string,int|string>> */
    public array $initiateCalls = [];

    /** @var list<array<string,int|string>> */
    public array $submitCalls = [];

    public int $initiateEffects = 0;

    public int $submitEffects = 0;

    /** @var array<string,TelegramCustomerPurchaseUsdtInstructions> */
    private array $initiations = [];

    /** @var array<string,TelegramCustomerPurchaseUsdtSubmission> */
    private array $submissions = [];

    public function initiateForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseUsdtInstructions {
        $this->assertAuthority($actorUserId, $subjectUserId, $orderPublicId, $quotePublicId, $quoteConfigurationHash, $decisionPublicId, $decisionConfigurationHash);
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Unexpected Telegram USDT initiation operation.');
        }
        $this->initiateCalls[] = ['operationKey' => $operationKey];
        if (isset($this->initiations[$operationKey])) {
            $accepted = $this->initiations[$operationKey];

            return new TelegramCustomerPurchaseUsdtInstructions(
                $accepted->authorityPublicId,
                $accepted->paymentIntentPublicId,
                $accepted->amountQuotePublicId,
                $accepted->orderPublicId,
                $accepted->quotePublicId,
                $accepted->decisionPublicId,
                $accepted->network,
                $accepted->destinationAddress,
                $accepted->exactUsdt,
                $accepted->expiresAt,
                true,
            );
        }
        $this->initiateEffects++;
        $accepted = new TelegramCustomerPurchaseUsdtInstructions(
            str_pad('01J', 26, '0'),
            str_pad('01H', 26, '0'),
            str_pad('01M', 26, '0'),
            $orderPublicId,
            $quotePublicId,
            $decisionPublicId,
            'BEP20',
            '0x'.str_repeat('b', 40),
            '1.250000',
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 minutes'),
            false,
        );
        $this->initiations[$operationKey] = $accepted;

        return $accepted;
    }

    public function submitTxidForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $authorityPublicId,
        string $paymentIntentPublicId,
        string $amountQuotePublicId,
        string $txid,
        string $operationKey,
    ): TelegramCustomerPurchaseUsdtSubmission {
        $this->assertAuthority($actorUserId, $subjectUserId, $orderPublicId, $quotePublicId, $quoteConfigurationHash, $decisionPublicId, $decisionConfigurationHash);
        if ($authorityPublicId !== str_pad('01J', 26, '0')
            || $paymentIntentPublicId !== str_pad('01H', 26, '0')
            || $amountQuotePublicId !== str_pad('01M', 26, '0')
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Unexpected Telegram USDT submission identity.');
        }
        $txid = strtolower(trim($txid));
        if (preg_match('/\A0x[a-f0-9]{64}\z/', $txid) !== 1) {
            throw new RuntimeException('Unexpected Telegram USDT TXID.');
        }
        $this->submitCalls[] = ['txid' => $txid, 'operationKey' => $operationKey];
        if (isset($this->submissions[$operationKey])) {
            $accepted = $this->submissions[$operationKey];

            return new TelegramCustomerPurchaseUsdtSubmission(
                $accepted->submissionPublicId,
                $accepted->authorityPublicId,
                $accepted->paymentIntentPublicId,
                $accepted->txid,
                $accepted->state,
                true,
            );
        }
        $this->submitEffects++;
        $accepted = new TelegramCustomerPurchaseUsdtSubmission(
            str_pad('01Q', 26, '0'),
            $authorityPublicId,
            $paymentIntentPublicId,
            $txid,
            'submitted',
            false,
        );
        $this->submissions[$operationKey] = $accepted;

        return $accepted;
    }

    private function assertAuthority(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): void {
        if ($actorUserId !== $subjectUserId
            || $orderPublicId !== str_pad('01N', 26, '0')
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)
            || $decisionPublicId !== str_pad('01P', 26, '0')
            || $decisionConfigurationHash !== str_repeat('b', 64)) {
            throw new RuntimeException('Unexpected Telegram USDT authority.');
        }
    }
}

final readonly class TelegramUsdtNavigationOrder implements TelegramCustomerPurchaseOrder
{
    public function openForSelf(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash, string $correlationId): TelegramCustomerPurchaseOrderReceipt
    {
        return $this->receipt($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    public function currentForSelf(int $actorUserId, int $subjectUserId, string $orderPublicId, string $quotePublicId, string $quoteConfigurationHash): TelegramCustomerPurchaseOrderReceipt
    {
        if ($orderPublicId !== str_pad('01N', 26, '0')) {
            throw new RuntimeException('Unexpected Telegram USDT Order.');
        }

        return $this->receipt($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    private function receipt(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash): TelegramCustomerPurchaseOrderReceipt
    {
        if ($actorUserId !== $subjectUserId || $quotePublicId !== str_pad('01K', 26, '0') || $quoteConfigurationHash !== str_repeat('a', 64)) {
            throw new RuntimeException('Unexpected Telegram USDT Order authority.');
        }

        return new TelegramCustomerPurchaseOrderReceipt(str_pad('01N', 26, '0'), $quotePublicId, $quoteConfigurationHash, 1_250_000, 'IRR', true);
    }
}

final readonly class TelegramUsdtNavigationPaymentMethods implements TelegramCustomerPurchasePaymentMethods
{
    public function discoverForSelf(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash, string $decisionKey): TelegramCustomerPurchasePaymentMethodsDecision
    {
        return $this->decision($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    public function currentForSelf(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash, string $decisionPublicId, string $decisionConfigurationHash): TelegramCustomerPurchasePaymentMethodsDecision
    {
        if ($decisionPublicId !== str_pad('01P', 26, '0') || $decisionConfigurationHash !== str_repeat('b', 64)) {
            throw new RuntimeException('Unexpected Telegram USDT PAY-001 identity.');
        }

        return $this->decision($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    public function selectForSelf(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash, string $decisionPublicId, string $decisionConfigurationHash, string $methodCode): TelegramCustomerPurchasePaymentMethodSelection
    {
        $this->currentForSelf($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash, $decisionPublicId, $decisionConfigurationHash);
        if ($methodCode !== 'usdt_bep20') {
            throw new RuntimeException('Unexpected Telegram USDT payment method.');
        }

        return new TelegramCustomerPurchasePaymentMethodSelection($decisionPublicId, $quotePublicId, $methodCode);
    }

    private function decision(int $actorUserId, int $subjectUserId, string $quotePublicId, string $quoteConfigurationHash): TelegramCustomerPurchasePaymentMethodsDecision
    {
        if ($actorUserId !== $subjectUserId || $quotePublicId !== str_pad('01K', 26, '0') || $quoteConfigurationHash !== str_repeat('a', 64)) {
            throw new RuntimeException('Unexpected Telegram USDT payment-method authority.');
        }

        return new TelegramCustomerPurchasePaymentMethodsDecision(
            str_pad('01P', 26, '0'),
            $quotePublicId,
            $quoteConfigurationHash,
            str_repeat('b', 64),
            ['usdt_bep20'],
            true,
        );
    }
}

/** @requirement ONB-002 BUY-001 BUY-003 PAY-001 PAY-002 USDT-003 ARCH-003 ARCH-004 DAT-002 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 */
final class TelegramUsdtNavigationTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram USDT navigation verification requires MariaDB/MySQL.');
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

    public function test_usdt_selection_and_txid_submission_are_restart_safe_and_exact_update_replay_has_one_effect(): void
    {
        [$processor, $sessionId, $telegramUserId, $payment] = $this->prepareTxidInput(9830, 8300, 'usdt_submit');
        self::assertSame(1, $payment->initiateEffects);
        self::assertCount(1, $payment->initiateCalls);
        self::assertStringContainsString('BEP20', $this->latestConfidentialPresentation());
        self::assertStringContainsString('1.250000 USDT', $this->latestConfidentialPresentation());
        self::assertStringContainsString('0x'.str_repeat('b', 40), $this->latestConfidentialPresentation());

        $txid = '0x'.str_repeat('AB', 32);
        $this->accept($this->payload(8310, $telegramUserId, 'usdt_submit', 'fa', $txid));
        $processor->process('123456789', 8310);

        self::assertSame(1, $payment->submitEffects);
        self::assertCount(1, $payment->submitCalls);
        self::assertSame(strtolower($txid), $payment->submitCalls[0]['txid']);
        $session = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'payload']);
        self::assertNotNull($session);
        self::assertSame('purchase_usdt_submitted', (string) $session->state);
        self::assertStringContainsString(strtolower($txid), (string) $session->payload);
        self::assertStringContainsString('در انتظار بررسی بلاکچین', $this->latestConfidentialPresentation());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('service_subscriptions')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());

        $this->accept($this->payload(8310, $telegramUserId, 'usdt_submit', 'fa', $txid));
        $processor->process('123456789', 8310);
        self::assertSame(1, $payment->submitEffects);
        self::assertCount(1, $payment->submitCalls);
        self::assertSame(1, DB::table('processed_telegram_updates')->where('update_id', 8310)->count());
    }

    public function test_invalid_txid_and_back_create_no_submission_or_settlement_effect(): void
    {
        [$processor, $sessionId, $telegramUserId, $payment, $accountId] = $this->prepareTxidInput(9840, 8400, 'usdt_invalid');

        $this->accept($this->payload(8410, $telegramUserId, 'usdt_invalid', 'fa', 'not-a-txid'));
        $processor->process('123456789', 8410);
        self::assertSame(0, $payment->submitEffects);
        self::assertCount(0, $payment->submitCalls);
        self::assertSame('purchase_usdt_txid_input', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'));
        self::assertStringContainsString('TXID معتبر نیست', $this->latestConfidentialPresentation());

        $token = $this->callbackToken('navigation.back', $accountId);
        $this->accept($this->callbackPayload(8411, $telegramUserId, 'usdt_invalid', 'fa', $token));
        $processor->process('123456789', 8411);
        self::assertSame('purchase_payment_methods', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'));
        self::assertSame(0, $payment->submitEffects);
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    public function test_menu_from_txid_input_returns_home_without_usdt_submission(): void
    {
        [$processor, $sessionId, $telegramUserId, $payment] = $this->prepareTxidInput(9850, 8500, 'usdt_home');

        $this->accept($this->payload(8510, $telegramUserId, 'usdt_home', 'en', '/menu'));
        $processor->process('123456789', 8510);

        self::assertSame('home', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'));
        self::assertSame(0, $payment->submitEffects);
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    /** @return array{TelegramUpdateProcessor,int,int,TelegramUsdtNavigationPayment,int} */
    private function prepareTxidInput(int $telegramUserId, int $baseUpdateId, string $username): array
    {
        $payment = new TelegramUsdtNavigationPayment;
        $this->app->instance(TelegramCustomerPurchaseUsdtPayment::class, $payment);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, new TelegramUsdtNavigationOrder);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, new TelegramUsdtNavigationPaymentMethods);
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload($baseUpdateId, $telegramUserId, $username, 'fa', '/start'));
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
            'telegram-usdt-test-methods:'.hash('sha256', $username),
        );
        $callback = $this->app->make(TelegramInteractionCallbackService::class)->issue(
            $methods->publicId,
            $methods->version,
            'navigation.purchase.payment_method.select',
            ['method_code' => 'usdt_bep20'],
            'telegram-usdt-test-select:'.hash('sha256', $username),
        );
        $token = $this->app->make(StringEncrypter::class)->decryptString((string) DB::table('telegram_interaction_callbacks')
            ->where('public_id', $callback->publicId)
            ->value('token_ciphertext'));
        $this->accept($this->callbackPayload($baseUpdateId + 1, $telegramUserId, $username, 'fa', $token));
        $processor->process('123456789', $baseUpdateId + 1);
        self::assertSame('purchase_usdt_txid_input', DB::table('telegram_interaction_sessions')->where('id', (int) $home->id)->value('state'));
        self::assertSame(1, $payment->initiateEffects);

        return [$processor, (int) $home->id, $telegramUserId, $payment, $accountId];
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
}
