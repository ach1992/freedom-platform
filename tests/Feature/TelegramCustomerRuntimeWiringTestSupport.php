<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\Contracts\SmsDeliveryAttemptRecorder;
use App\Modules\Identity\Application\Contracts\SmsProvider;
use App\Modules\Identity\Application\FallbackSmsDispatcher;
use App\Modules\Identity\Application\OtpChallengeIssuer;
use App\Modules\Identity\Application\SmsDeliveryResult;
use App\Modules\Identity\Application\SmsOtpMessage;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use App\Modules\Payments\Zarinpal\Application\TelegramCustomerWalletTopUpZarinpalService;
use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerWalletTopUpPayment;
use App\Modules\Telegram\Application\TelegramInteractionDispatcher;
use App\Modules\Telegram\Application\TelegramNavigationCompositeHandler;
use App\Modules\Telegram\Application\TelegramPhoneVerificationNavigationHandler;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use App\Modules\Telegram\Application\TelegramWalletTopUpNavigationHandler;
use Database\Seeders\WalletFinancialFoundationSeeder;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class TelegramRuntimeSmsProvider implements SmsProvider
{
    public ?string $lastCode = null;
    public ?string $lastDestination = null;
    public int $calls = 0;

    public function __construct(private readonly string $providerCode) {}

    public function code(): string
    {
        return $this->providerCode;
    }

    public function sendOtp(SmsOtpMessage $message): SmsDeliveryResult
    {
        $this->calls++;
        $this->lastCode = $message->code;
        $this->lastDestination = $message->destination->e164();

        return SmsDeliveryResult::accepted('telegram-runtime-sms-'.$this->calls);
    }
}

final class TelegramRuntimeZarinpalTransport implements ZarinpalTransport
{
    public const AUTHORITY = 'A99999999999999999999999999999999999';

    public int $requestCalls = 0;
    public int $verifyCalls = 0;

    public function request(
        string $merchantId,
        int $amountIrr,
        string $callbackUrl,
        string $description,
        string $orderId,
    ): ZarinpalRequestResult {
        $this->requestCalls++;

        return ZarinpalRequestResult::accepted(self::AUTHORITY);
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        $this->verifyCalls++;

        return ZarinpalVerifyResult::verified('990000001', 100);
    }

    public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
    {
        return ZarinpalInquiryResult::available('PAID');
    }

    public function unverified(string $merchantId): array
    {
        return [];
    }
}

trait TelegramCustomerRuntimeWiringTestSupport
{
    private TelegramRuntimeSmsProvider $sms;
    private TelegramRuntimeZarinpalTransport $zarinpal;

    private function setUpTelegramRuntimeWiring(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram customer identity/wallet wiring requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();

        $this->seed();
        $this->seed(WalletFinancialFoundationSeeder::class);
        Queue::fake();

        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => '123456789:'.str_repeat('a', 35),
            'telegram.webhook_secret' => str_repeat('w', 32),
            'telegram.webhook_path' => 'api/telegram/webhook',
            'telegram.max_body_bytes' => 1_048_576,
            'telegram.queue' => 'critical',
            'telegram.processing_lease_seconds' => 120,
            'telegram.api_base_url' => 'https://api.telegram.org',
            'telegram.api_timeout_seconds' => 15,
            'services.zarinpal.enabled' => true,
            'services.zarinpal.merchant_id' => '00000000-0000-0000-0000-000000000000',
            'services.zarinpal.callback_url' => 'https://bot.example.test/payments/zarinpal/callback',
        ]);

        $this->sms = new TelegramRuntimeSmsProvider('telegram_runtime_primary');
        $fallback = new TelegramRuntimeSmsProvider('telegram_runtime_fallback');
        $this->app->instance(
            FallbackSmsDispatcher::class,
            new FallbackSmsDispatcher(
                $this->sms,
                $fallback,
                $this->app->make(SmsDeliveryAttemptRecorder::class),
            ),
        );

        $this->zarinpal = new TelegramRuntimeZarinpalTransport;
        $this->app->instance(ZarinpalTransport::class, $this->zarinpal);

        foreach ([
            OtpChallengeIssuer::class,
            ZarinpalPaymentService::class,
            TelegramCustomerWalletTopUpPayment::class,
            TelegramCustomerWalletTopUpZarinpalService::class,
            TelegramPhoneVerificationNavigationHandler::class,
            TelegramWalletTopUpNavigationHandler::class,
            TelegramNavigationCompositeHandler::class,
            TelegramInteractionDispatcher::class,
            TelegramUpdateProcessor::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    /** @return array{0:TelegramUpdateProcessor,1:int,2:int} */
    private function openMyAccount(int $baseUpdateId, int $telegramUserId, string $username): array
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->acceptTelegramRuntime(
            $this->telegramRuntimePayload($baseUpdateId, $telegramUserId, $username, 'fa', '/start'),
        );
        $processor->process('123456789', $baseUpdateId);

        $account = DB::table('telegram_accounts')
            ->where('telegram_user_id', $telegramUserId)
            ->first(['id', 'user_id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;

        $token = $this->telegramRuntimeCallbackToken('navigation.my_account', $accountId);
        $this->acceptTelegramRuntime($this->telegramRuntimeCallbackPayload(
            $baseUpdateId + 1,
            $telegramUserId,
            $username,
            'fa',
            $token,
        ));
        $processor->process('123456789', $baseUpdateId + 1);
        self::assertSame('my_account', DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->value('state'));

        return [$processor, $accountId, (int) $account->user_id];
    }

    private function openPhoneVerification(
        TelegramUpdateProcessor $processor,
        int $accountId,
        int $updateId,
        int $telegramUserId,
        string $username,
    ): void {
        $token = $this->telegramRuntimeCallbackToken('navigation.phone_verification', $accountId);
        $this->acceptTelegramRuntime($this->telegramRuntimeCallbackPayload(
            $updateId,
            $telegramUserId,
            $username,
            'fa',
            $token,
        ));
        $processor->process('123456789', $updateId);

        self::assertSame('phone_verification_method', DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->value('state'));
    }

    private function telegramRuntimeCallbackToken(string $action, int $telegramAccountId): string
    {
        $sessionId = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('id');
        self::assertIsNumeric($sessionId);
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action)
            ->where('action_payload', '{}')
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($callback);
        self::assertIsString($callback->token_ciphertext);

        return $this->app->make(StringEncrypter::class)
            ->decryptString((string) $callback->token_ciphertext);
    }

    /** @param array<string,mixed> $payload */
    private function acceptTelegramRuntime(array $payload, ?string $remoteAddress = null): void
    {
        if ($remoteAddress !== null) {
            $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress]);
        }

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('w', 32))
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string,mixed> */
    private function telegramRuntimePayload(
        int $updateId,
        int $telegramUserId,
        string $username,
        string $languageCode,
        string $text,
    ): array {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'date' => 1_700_000_000,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => $username,
                    'language_code' => $languageCode,
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
    private function telegramRuntimeContactPayload(
        int $updateId,
        int $telegramUserId,
        string $username,
        string $phoneNumber,
        int $contactUserId,
    ): array {
        $payload = $this->telegramRuntimePayload($updateId, $telegramUserId, $username, 'fa', 'unused');
        unset($payload['message']['text']);
        $payload['message']['contact'] = [
            'phone_number' => $phoneNumber,
            'user_id' => $contactUserId,
            'first_name' => 'Test contact',
            'vcard' => 'test-only-vcard',
        ];

        return $payload;
    }

    /** @return array<string,mixed> */
    private function telegramRuntimeCallbackPayload(
        int $updateId,
        int $telegramUserId,
        string $username,
        string $languageCode,
        string $token,
    ): array {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'callback-'.$updateId,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => $username,
                    'language_code' => $languageCode,
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
}
