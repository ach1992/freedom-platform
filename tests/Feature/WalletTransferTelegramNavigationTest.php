<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramCustomerWalletTransferService;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement WAL-003 BUY-003 ARCH-003 SEC-003 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
final class WalletTransferTelegramNavigationTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram wallet transfer navigation verification requires MariaDB/MySQL.');
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
            'wallet.transfers.enabled' => true,
            'wallet.transfers.allowed_buckets' => ['cash'],
            'wallet.transfers.minimum_irr' => 100_000,
            'wallet.transfers.maximum_irr' => 1_000_000,
            'wallet.transfers.daily_limit_irr' => 1_500_000,
            'wallet.transfers.fixed_fee_irr' => 10_000,
            'wallet.transfers.fee_basis_points' => 100,
            'wallet.transfers.confirmation_ttl_seconds' => 900,
            'wallet.transfers.fee_account_code' => 'system.wallet.transfer.fee',
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

    public function test_confirm_and_exact_update_replay_create_one_wallet_transfer_effect(): void
    {
        [$processor, $sessionId, $senderUserId, $senderTelegramId, $accountId, $senderWalletId, $recipientWalletId] =
            $this->prepareConfirmation(9990, 9900, 'wallet_sender_confirm');

        $confirm = $this->callbackToken('navigation.wallet.transfer.confirm', $accountId);
        $this->accept($this->callbackPayload(9910, $senderTelegramId, 'wallet_sender_confirm', 'fa', $confirm));
        $processor->process('123456789', 9910);

        self::assertSame(
            'wallet_transfer_completed',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'completed')->count());
        self::assertSame(2, DB::table('ledger_transactions')->count());
        $holds = $this->app->make(WalletHoldService::class);
        self::assertSame(586_000, $holds->balance($senderUserId, $senderWalletId)->availableBalance->amount);
        $recipientUserId = (int) DB::table('ledger_accounts')->where('id', $recipientWalletId)->value('owner_user_id');
        self::assertSame(400_000, $holds->balance($recipientUserId, $recipientWalletId)->availableBalance->amount);

        $this->accept($this->callbackPayload(9910, $senderTelegramId, 'wallet_sender_confirm', 'fa', $confirm));
        $processor->process('123456789', 9910);
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'completed')->count());
        self::assertSame(2, DB::table('ledger_transactions')->count());
        self::assertSame(1, DB::table('processed_telegram_updates')->where('update_id', 9910)->count());
    }

    public function test_back_from_confirmation_cancels_hold_once_and_returns_to_amount_without_ledger_transfer(): void
    {
        [$processor, $sessionId, $senderUserId, $senderTelegramId, $accountId, $senderWalletId] =
            $this->prepareConfirmation(9991, 9920, 'wallet_sender_cancel');

        $back = $this->callbackToken('navigation.back', $accountId);
        $this->accept($this->callbackPayload(9930, $senderTelegramId, 'wallet_sender_cancel', 'fa', $back));
        $processor->process('123456789', 9930);

        self::assertSame(
            'wallet_transfer_amount',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'cancelled')->count());
        self::assertSame(1, DB::table('ledger_transactions')->count());
        self::assertSame(
            700_000,
            $this->app->make(WalletHoldService::class)
                ->balance($senderUserId, $senderWalletId)
                ->availableBalance
                ->amount,
        );

        $this->accept($this->callbackPayload(9930, $senderTelegramId, 'wallet_sender_cancel', 'fa', $back));
        $processor->process('123456789', 9930);
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'cancelled')->count());
        self::assertSame(1, DB::table('ledger_transactions')->count());
    }

    public function test_cancel_command_cannot_terminalize_a_prepared_transfer_without_releasing_its_hold(): void
    {
        [$processor, $sessionId, , $senderTelegramId] =
            $this->prepareConfirmation(9992, 9940, 'wallet_sender_cancel_command');

        $this->accept($this->payload(9950, $senderTelegramId, 'wallet_sender_cancel_command', 'fa', '/cancel'));
        $processor->process('123456789', 9950);

        self::assertSame(
            'wallet_transfer_confirm',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame(
            'active',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('status'),
        );
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'pending_confirmation')->count());
        self::assertSame(1, DB::table('wallet_holds')->where('status', 'active')->count());
        self::assertSame(1, DB::table('ledger_transactions')->count());
    }

    public function test_recipient_status_rejection_after_confirm_claim_releases_hold_and_recovers_to_amount(): void
    {
        [$processor, $sessionId, $senderUserId, $senderTelegramId, $accountId, $senderWalletId, $recipientWalletId] =
            $this->prepareConfirmation(9993, 9960, 'wallet_sender_policy_reject');

        $recipientUserId = (int) DB::table('ledger_accounts')
            ->where('id', $recipientWalletId)
            ->value('owner_user_id');
        DB::table('users')->where('id', $recipientUserId)->update(['account_status' => 'suspended']);

        $confirm = $this->callbackToken('navigation.wallet.transfer.confirm', $accountId);
        $this->accept($this->callbackPayload(9970, $senderTelegramId, 'wallet_sender_policy_reject', 'fa', $confirm));
        $processor->process('123456789', 9970);

        self::assertSame(
            'wallet_transfer_amount',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame('active', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('status'));
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'cancelled')->count());
        self::assertSame(0, DB::table('wallet_holds')->where('status', 'active')->count());
        self::assertSame(1, DB::table('wallet_holds')->where('status', 'released')->count());
        self::assertSame(1, DB::table('ledger_transactions')->count());
        self::assertSame(
            1_000_000,
            $this->app->make(WalletHoldService::class)
                ->balance($senderUserId, $senderWalletId)
                ->availableBalance
                ->amount,
        );
    }

    public function test_interrupted_submission_reconciles_completed_transfer_without_second_ledger_effect(): void
    {
        [$processor, $sessionId, $senderUserId, $senderTelegramId] =
            $this->prepareConfirmation(9994, 9980, 'wallet_sender_interrupted');

        $session = DB::table('telegram_interaction_sessions')
            ->where('id', $sessionId)
            ->first(['public_id', 'version', 'payload']);
        self::assertNotNull($session);
        $state = json_decode((string) $session->payload, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $state['submit_action'] = 'confirm';

        $submitting = $this->app->make(TelegramInteractionSessionService::class)->transition(
            (string) $session->public_id,
            (int) $session->version,
            'wallet_transfer_submitting',
            $state,
            'telegram-wallet-interrupted-submit',
        );

        $transferKey = $state['transfer_key'] ?? null;
        self::assertIsString($transferKey);
        $this->app->make(TelegramCustomerWalletTransferService::class)->confirmForSelf(
            $senderUserId,
            $senderUserId,
            $transferKey,
            'tg-wallet-confirm:'.substr(hash('sha256', $transferKey), 0, 48),
            'tg-wallet:'.substr(hash('sha256', $transferKey), 0, 48),
        );

        self::assertSame(
            'wallet_transfer_submitting',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame($submitting->version, (int) DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('version'));
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'completed')->count());
        self::assertSame(2, DB::table('ledger_transactions')->count());

        $this->accept($this->payload(9990, $senderTelegramId, 'wallet_sender_interrupted', 'fa', '/back'));
        $processor->process('123456789', 9990);

        self::assertSame(
            'wallet_transfer_completed',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'completed')->count());
        self::assertSame(2, DB::table('ledger_transactions')->count());
    }

    /**
     * @return array{TelegramUpdateProcessor,int,int,int,int,int,int}
     */
    private function prepareConfirmation(int $telegramUserId, int $baseUpdateId, string $username): array
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->accept($this->payload($baseUpdateId, $telegramUserId, $username, 'fa', '/start'));
        $processor->process('123456789', $baseUpdateId);

        $senderAccount = DB::table('telegram_accounts')
            ->where('telegram_user_id', $telegramUserId)
            ->first(['id', 'user_id']);
        self::assertNotNull($senderAccount);
        $senderUserId = (int) $senderAccount->user_id;
        $accountId = (int) $senderAccount->id;

        [$recipientUserId] = $this->customer('en');
        $this->telegramAccount($recipientUserId, $telegramUserId + 100, 'recipient_user');
        $assetId = $this->ledgerAccount('system.wallet.telegram.nav.asset.'.$senderUserId, 'asset');
        $senderWalletId = $this->ledgerAccount('wallet.cash.telegram.nav.'.$senderUserId, 'liability', $senderUserId, 'cash');
        $recipientWalletId = $this->ledgerAccount('wallet.cash.telegram.nav.'.$recipientUserId, 'liability', $recipientUserId, 'cash');
        $this->ledgerAccount('system.wallet.transfer.fee', 'revenue');
        $fundingAmount = str_contains($username, 'cancel') ? 700_000 : 1_000_000;
        $this->fundWallet($assetId, $senderWalletId, $fundingAmount, $senderUserId);

        $this->accept($this->payload($baseUpdateId + 1, $telegramUserId, $username, 'fa', '/menu'));
        $processor->process('123456789', $baseUpdateId + 1);
        $entry = $this->callbackToken('navigation.wallet.transfer', $accountId);
        $this->accept($this->callbackPayload($baseUpdateId + 2, $telegramUserId, $username, 'fa', $entry));
        $processor->process('123456789', $baseUpdateId + 2);

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->first(['id', 'state']);
        self::assertNotNull($session);
        self::assertSame('wallet_transfer_recipient', (string) $session->state);

        $this->accept($this->payload($baseUpdateId + 3, $telegramUserId, $username, 'fa', '@recipient_user'));
        $processor->process('123456789', $baseUpdateId + 3);
        self::assertSame(
            'wallet_transfer_amount',
            DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->value('state'),
        );

        $this->accept($this->payload($baseUpdateId + 4, $telegramUserId, $username, 'fa', '400000'));
        $processor->process('123456789', $baseUpdateId + 4);
        self::assertSame(
            'wallet_transfer_confirm',
            DB::table('telegram_interaction_sessions')->where('id', (int) $session->id)->value('state'),
        );
        self::assertSame(1, DB::table('wallet_transfers')->where('status', 'pending_confirmation')->count());
        self::assertSame(1, DB::table('wallet_holds')->where('status', 'active')->count());

        return [
            $processor,
            (int) $session->id,
            $senderUserId,
            $telegramUserId,
            $accountId,
            $senderWalletId,
            $recipientWalletId,
        ];
    }

    /** @return array{0:int,1:string} */
    private function customer(string $locale): array
    {
        $now = now('UTC');
        $publicId = (string) Str::ulid();
        $id = (int) DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => $locale,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$id, $publicId];
    }

    private function telegramAccount(int $userId, int $telegramUserId, string $username): void
    {
        $now = now('UTC');
        DB::table('telegram_accounts')->insert([
            'user_id' => $userId,
            'bot_id' => 123456789,
            'telegram_user_id' => $telegramUserId,
            'username' => $username,
            'language_code' => 'en',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ledgerAccount(
        string $code,
        string $class,
        ?int $userId = null,
        ?string $bucket = null,
    ): int {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function fundWallet(int $assetId, int $walletId, int $amount, int $senderUserId): void
    {
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.telegram.wallet.nav.fund.'.$senderUserId,
            'wallet_topup_capture',
            'corr-telegram-wallet-nav-'.$senderUserId,
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'payment_intent',
            'pi-telegram-wallet-nav-'.$senderUserId,
        );
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
        $sessionId = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('id');
        self::assertIsNumeric($sessionId);
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($callback);

        return $this->app->make(StringEncrypter::class)
            ->decryptString((string) $callback->token_ciphertext);
    }
}
