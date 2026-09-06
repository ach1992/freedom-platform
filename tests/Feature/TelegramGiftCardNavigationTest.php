<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseGiftCardPayment;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseGiftCardSubmission;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseGiftCardType;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class TelegramGiftCardNavigationPayment implements TelegramCustomerPurchaseGiftCardPayment
{
    /** @var list<array<string,int|string>> */
    public array $submitCalls = [];

    /** @var array<string,TelegramCustomerPurchaseGiftCardSubmission> */
    private array $submissions = [];

    public int $submitEffects = 0;

    public string $typeConfigurationHash;

    public function __construct()
    {
        $this->typeConfigurationHash = str_repeat('c', 64);
    }

    public function availableTypesForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): array {
        $this->assertAuthority(
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
        );

        return [new TelegramCustomerPurchaseGiftCardType(
            'steam-manual',
            'Steam Manual Gift Card',
            'Steam',
            'GLOBAL',
            'IRR',
            $this->typeConfigurationHash,
        )];
    }

    public function submitCodeForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $typeCode,
        string $typeConfigurationHash,
        int $claimedFaceValue,
        string $code,
        string $operationKey,
    ): TelegramCustomerPurchaseGiftCardSubmission {
        $this->assertAuthority(
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
        );
        if ($typeCode !== 'steam-manual'
            || $typeConfigurationHash !== $this->typeConfigurationHash
            || $claimedFaceValue !== 1_250_000
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Unexpected Telegram Gift Card submission request.');
        }
        $this->submitCalls[] = [
            'actorUserId' => $actorUserId,
            'subjectUserId' => $subjectUserId,
            'orderPublicId' => $orderPublicId,
            'quotePublicId' => $quotePublicId,
            'claimedFaceValue' => $claimedFaceValue,
            'code' => $code,
            'operationKey' => $operationKey,
        ];
        if (isset($this->submissions[$operationKey])) {
            $accepted = $this->submissions[$operationKey];

            return new TelegramCustomerPurchaseGiftCardSubmission(
                $accepted->submissionPublicId,
                $accepted->paymentIntentPublicId,
                $accepted->reviewPublicId,
                $accepted->typeCode,
                $accepted->maskedCode,
                $accepted->claimedFaceValue,
                $accepted->claimedCurrency,
                $accepted->state,
                true,
            );
        }
        $this->submitEffects++;
        $accepted = new TelegramCustomerPurchaseGiftCardSubmission(
            str_pad('01G', 26, '0'),
            str_pad('01H', 26, '0'),
            str_pad('01J', 26, '0'),
            'steam-manual',
            'STEA********6354',
            $claimedFaceValue,
            'IRR',
            'pending_manual_review',
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
            throw new RuntimeException('Unexpected Telegram Gift Card authority.');
        }
    }
}

/** @requirement ONB-002 BUY-001 BUY-003 PAY-001 PAY-002 GFT-001 GFT-002 GFT-003 ARCH-003 ARCH-004 DAT-002 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 */
final class TelegramGiftCardNavigationTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Gift Card navigation verification requires MariaDB/MySQL.');
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

    public function test_code_message_is_confined_to_encrypted_ingress_and_safe_gift_card_authority_while_exact_update_replay_has_one_effect(): void
    {
        [$processor, $accountId, $sessionId, $telegramUserId, $payment] = $this->prepareCodeInput(9810, 8100, 'gift_card_secret');
        $rawCode = 'STEAM-TG-SECRET-9081726354';

        $this->accept($this->payload(8110, $telegramUserId, 'gift_card_secret', 'fa', $rawCode));
        $processor->process('123456789', 8110);

        self::assertSame(1, $payment->submitEffects);
        self::assertCount(1, $payment->submitCalls);
        self::assertSame($rawCode, $payment->submitCalls[0]['code']);
        $session = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('purchase_gift_card_submitted', (string) $session->state);
        self::assertStringContainsString('STEA********6354', (string) $session->payload);
        self::assertStringNotContainsString($rawCode, (string) $session->payload);

        $storedUpdate = DB::table('processed_telegram_updates')
            ->where('bot_id', '123456789')
            ->where('update_id', 8110)
            ->first(['payload_ciphertext', 'state', 'attempt_count']);
        self::assertNotNull($storedUpdate);
        self::assertSame('processed', (string) $storedUpdate->state);
        self::assertSame(1, (int) $storedUpdate->attempt_count);
        self::assertStringNotContainsString($rawCode, (string) $storedUpdate->payload_ciphertext);
        self::assertStringContainsString(
            $rawCode,
            $this->app->make(StringEncrypter::class)->decryptString((string) $storedUpdate->payload_ciphertext),
        );

        self::assertStringNotContainsString($rawCode, $this->navigationCommonDurableEvidence($sessionId, $telegramUserId));
        $presentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('STEA********6354', $presentation);
        self::assertStringContainsString('در انتظار بررسی دستی', $presentation);
        self::assertStringNotContainsString($rawCode, $presentation);

        $this->accept($this->payload(8110, $telegramUserId, 'gift_card_secret', 'fa', $rawCode));
        $processor->process('123456789', 8110);
        self::assertSame(1, $payment->submitEffects);
        self::assertCount(1, $payment->submitCalls);
        self::assertSame(1, DB::table('processed_telegram_updates')->where('update_id', 8110)->count());
        self::assertSame(1, (int) DB::table('processed_telegram_updates')->where('update_id', 8110)->value('attempt_count'));
        self::assertStringNotContainsString($rawCode, $this->navigationCommonDurableEvidence($sessionId, $telegramUserId));
        self::assertSame($accountId, (int) DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('telegram_account_id'));
    }

    public function test_back_before_code_submission_creates_no_gift_card_effect(): void
    {
        [$processor, $accountId, $sessionId, $telegramUserId, $payment] = $this->prepareCodeInput(9820, 8200, 'gift_card_back');
        self::assertSame(0, $payment->submitEffects);
        self::assertCount(0, $payment->submitCalls);

        $token = $this->callbackToken('navigation.back', $accountId);
        $this->accept($this->callbackPayload(8210, $telegramUserId, 'gift_card_back', 'fa', $token));
        $processor->process('123456789', 8210);

        $session = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'payload']);
        self::assertNotNull($session);
        self::assertSame('purchase_gift_card_face_value', (string) $session->state);
        self::assertStringNotContainsString('gift_card_claimed_face_value', (string) $session->payload);
        self::assertSame(0, $payment->submitEffects);
        self::assertCount(0, $payment->submitCalls);
    }

    /** @return array{TelegramUpdateProcessor,int,int,int,TelegramGiftCardNavigationPayment} */
    private function prepareCodeInput(int $telegramUserId, int $baseUpdateId, string $username): array
    {
        $payment = new TelegramGiftCardNavigationPayment;
        $this->app->instance(TelegramCustomerPurchaseGiftCardPayment::class, $payment);
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload($baseUpdateId, $telegramUserId, $username, 'fa', '/start'));
        $processor->process('123456789', $baseUpdateId);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $userId = (int) $account->user_id;
        $home = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->first(['id', 'public_id', 'version']);
        self::assertNotNull($home);

        $selectedPayload = [
            'page' => 1,
            'offering_selection' => str_repeat('c', 40),
            'quote_public_id' => str_pad('01K', 26, '0'),
            'quote_configuration_hash' => str_repeat('a', 64),
            'payment_decision_public_id' => str_pad('01P', 26, '0'),
            'payment_decision_configuration_hash' => str_repeat('b', 64),
            'order_public_id' => str_pad('01N', 26, '0'),
            'payment_method_code' => 'gift_card',
        ];
        $giftTypeSession = $this->app->make(TelegramInteractionSessionService::class)->transition(
            (string) $home->public_id,
            (int) $home->version,
            'purchase_gift_card_type',
            $selectedPayload,
            'telegram-gift-card-test-type:'.hash('sha256', $username),
        );
        $typeCallback = $this->app->make(TelegramInteractionCallbackService::class)->issue(
            $giftTypeSession->publicId,
            $giftTypeSession->version,
            'navigation.purchase.gift_card.type.select',
            [
                'type_code' => 'steam-manual',
                'type_configuration_hash' => $payment->typeConfigurationHash,
            ],
            'telegram-gift-card-test-type-callback:'.hash('sha256', $username),
        );
        $typeToken = $this->app->make(StringEncrypter::class)->decryptString((string) DB::table('telegram_interaction_callbacks')
            ->where('public_id', $typeCallback->publicId)
            ->value('token_ciphertext'));
        $this->accept($this->callbackPayload($baseUpdateId + 1, $telegramUserId, $username, 'fa', $typeToken));
        $processor->process('123456789', $baseUpdateId + 1);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $home->id,
            'state' => 'purchase_gift_card_face_value',
        ]);

        $this->accept($this->payload($baseUpdateId + 2, $telegramUserId, $username, 'fa', '1250000'));
        $processor->process('123456789', $baseUpdateId + 2);
        $codeInput = DB::table('telegram_interaction_sessions')
            ->where('id', (int) $home->id)
            ->first(['state', 'payload']);
        self::assertNotNull($codeInput);
        self::assertSame('purchase_gift_card_code_input', (string) $codeInput->state);
        self::assertStringContainsString('1250000', (string) $codeInput->payload);
        self::assertSame(0, $payment->submitEffects);
        self::assertSame($userId, (int) DB::table('telegram_interaction_sessions')->where('id', (int) $home->id)->value('user_id'));

        return [$processor, $accountId, (int) $home->id, $telegramUserId, $payment];
    }

    /** @param array<string,mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
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

    private function navigationCommonDurableEvidence(int $sessionId, int $telegramUserId): string
    {
        return json_encode([
            'sessions' => DB::table('telegram_interaction_sessions')->where('id', $sessionId)->get()->all(),
            'transitions' => DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', $sessionId)->get()->all(),
            'update_bindings' => DB::table('telegram_interaction_update_bindings')->where('telegram_interaction_session_id', $sessionId)->get()->all(),
            'callbacks' => DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', $sessionId)->get(['action', 'action_payload', 'issue_request_hash', 'issue_command_hash'])->all(),
            'operations' => DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->get()->all(),
            'outbox' => DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->get()->all(),
            'keyboards' => DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->get(['delivery_operation_public_id', 'keyboard_snapshot'])->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
