<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Promotions\Application\ReferralAttributionService;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceDeliveryResender;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use App\Modules\Telegram\Application\TelegramOwnedServiceDeliveryResendStatus;
use App\Modules\Telegram\Application\TelegramOwnedServiceDetail;
use App\Modules\Telegram\Application\TelegramOwnedServiceListItem;
use App\Modules\Telegram\Application\TelegramOwnedServicePage;
use App\Modules\Telegram\Application\TelegramOwnedServiceSearchResult;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class TelegramNavigationOwnedServiceSearchProjection implements TelegramOwnedServiceProjection
{
    public bool $detailAvailable = true;

    public function __construct(
        private readonly string $selectionToken,
        private readonly string $servicePublicId,
    ) {}

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage
    {
        if ($actorUserId !== $subjectUserId || $page !== 1 || $pageSize !== 6) {
            throw new RuntimeException('Unexpected searchable My Services page request.');
        }

        return new TelegramOwnedServicePage([
            new TelegramOwnedServiceListItem(
                $this->selectionToken,
                $this->servicePublicId,
                'active',
                'پلن جستجو',
                'Search plan',
                'سرور جستجو',
                'Search server',
                '2026-09-01 04:00:00.000000',
            ),
        ], 1, 1, 1);
    }

    public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail
    {
        if ($actorUserId !== $subjectUserId || $selectionToken !== $this->selectionToken || ! $this->detailAvailable) {
            throw new RuntimeException('Search detail projection is unavailable.');
        }

        return new TelegramOwnedServiceDetail(
            $this->servicePublicId,
            'active',
            'پلن جستجو',
            'Search plan',
            'سرور جستجو',
            'Search server',
            '2026-09-01 04:00:00.000000',
            'cached',
            'unavailable',
            'active',
            10 * 1024 * 1024 * 1024,
            3 * 1024 * 1024 * 1024,
            '2026-10-01 04:00:00.000000',
            '2026-08-31 23:55:00.000000',
        );
    }

    public function searchForSelf(int $actorUserId, int $subjectUserId, string $searchTerm): TelegramOwnedServiceSearchResult
    {
        if ($actorUserId !== $subjectUserId) {
            throw new RuntimeException('Unexpected cross-actor search request.');
        }

        return match ($searchTerm) {
            'match-search', 'cached-en' => TelegramOwnedServiceSearchResult::matched($this->selectionToken),
            'ambiguous-search' => TelegramOwnedServiceSearchResult::ambiguous(),
            default => TelegramOwnedServiceSearchResult::notFound(),
        };
    }
}

final class TelegramNavigationOwnedServiceDeliveryResender implements TelegramOwnedServiceDeliveryResender
{
    /** @var list<array{actor_user_id:int,service_public_id:string,request_key:string,correlation_id:string}> */
    public array $calls = [];

    public TelegramOwnedServiceDeliveryResendStatus $status = TelegramOwnedServiceDeliveryResendStatus::Queued;

    public function resendForSelf(
        int $actorUserId,
        string $servicePublicId,
        string $requestKey,
        string $correlationId,
    ): TelegramOwnedServiceDeliveryResendStatus {
        $this->calls[] = [
            'actor_user_id' => $actorUserId,
            'service_public_id' => $servicePublicId,
            'request_key' => $requestKey,
            'correlation_id' => $correlationId,
        ];

        return $this->status;
    }
}

/** @requirement ONB-002 ONB-003 USR-001 ARCH-003 ARCH-004 DAT-003 SEC-003 OPS-003 QUA-004 */
final class TelegramNavigationEntryTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram navigation entry verification requires MariaDB/MySQL.');
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
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6402');
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6903');
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_7007');
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6105');
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_start_creates_one_shared_navigation_session_and_one_persian_interactive_delivery_on_exact_replay(): void
    {
        $this->accept($this->payload(6101, 9601, 'navigation_fa', 'fa', '/start referral_1'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $processor->process('123456789', 6101);
        $processor->process('123456789', 6101);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', 9601)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $this->assertDatabaseHas('users', ['id' => (int) $account->user_id, 'locale' => 'fa']);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'telegram_account_id' => (int) $account->id,
            'flow' => TelegramNavigationEntryGateway::FLOW,
            'state' => TelegramNavigationEntryGateway::STATE,
            'status' => 'active',
            'version' => 1,
        ]);
        $sessionId = (int) DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->value('id');
        $this->assertDatabaseHas('telegram_interaction_update_bindings', [
            'bot_id' => '123456789',
            'update_id' => 6101,
            'telegram_interaction_session_id' => $sessionId,
            'session_version' => 1,
            'kind' => 'message',
        ]);

        $operation = DB::table('telegram_delivery_operations')->first();
        self::assertNotNull($operation);
        self::assertSame('send', (string) $operation->action);
        self::assertSame(9601, (int) $operation->recipient_chat_id);
        self::assertSame(trans('telegram.navigation.home', locale: 'fa'), (string) $operation->presentation_text);
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_account')
            ->first(['public_id', 'session_version', 'token_ciphertext']);
        self::assertNotNull($callback);
        self::assertSame(1, (int) $callback->session_version);
        $rawToken = $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);

        $snapshot = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $operation->public_id)
            ->first(['keyboard_snapshot']);
        self::assertNotNull($snapshot);
        self::assertStringContainsString((string) $callback->public_id, (string) $snapshot->keyboard_snapshot);
        self::assertStringNotContainsString($rawToken, (string) $snapshot->keyboard_snapshot);
        $outbox = DB::table('outbox_messages')->where('aggregate_id', (string) $operation->public_id)->first();
        self::assertNotNull($outbox);
        self::assertSame(TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE, (int) $outbox->contract_version);
        self::assertSame(
            '{"telegram_delivery_operation_public_id":"'.(string) $operation->public_id.'"}',
            (string) $outbox->payload,
        );
        self::assertStringNotContainsString($rawToken, (string) $outbox->payload);
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->count());
        self::assertSame(1, DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 6101,
            'state' => 'processed',
            'attempt_count' => 1,
        ]);
    }

    public function test_my_account_uses_same_session_confidential_v3_owner_boundaries_and_back_without_common_plaintext_leakage(): void
    {
        $telegramUserId = 9620;
        $this->accept($this->payload(6200, $telegramUserId, 'navigation_account', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6200);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $userId = (int) $account->user_id;
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->first(['id', 'public_id']);
        self::assertNotNull($session);
        $sessionId = (int) $session->id;
        $userPublicId = (string) DB::table('users')->where('id', $userId)->value('public_id');

        $rawNationalId = '1234567891';
        $lookupHash = hash('sha256', 'navigation-account-national-lookup');
        $now = now('UTC');
        DB::table('identity_items')->insert([
            'user_id' => $userId,
            'type' => 'national_id',
            'encrypted_value' => 'encrypted:'.$rawNationalId,
            'lookup_hash' => $lookupHash,
            'active_lookup_hash' => $lookupHash,
            'hash_key_version' => 1,
            'masked_value' => '******7891',
            'state' => 'verified',
            'ownership_check_required' => false,
            'ownership_check_status' => 'not_required',
            'version' => 1,
            'submitted_at' => $now,
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_profiles')->where('user_id', $userId)->update([
            'identity_verification_status' => 'verified',
            'updated_at' => $now,
        ]);

        $assetId = $this->ledgerAccount('navigation.asset.'.$userId, 'asset');
        $cashId = $this->ledgerAccount('navigation.cash.'.$userId, 'liability', $userId, 'cash');
        $promoId = $this->ledgerAccount('navigation.promo.'.$userId, 'liability', $userId, 'promotional');
        $ledger = $this->app->make(LedgerPostingService::class);
        $ledger->post(
            'navigation-account-cash-credit',
            'wallet_topup_capture',
            'navigation-account-cash-correlation',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(700_000)),
                new LedgerEntryDraft($cashId, LedgerDirection::Credit, IrrMoney::positive(700_000)),
            ],
        );
        $ledger->post(
            'navigation-account-promo-credit',
            'wallet_promotion_credit',
            'navigation-account-promo-correlation',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(125_000)),
                new LedgerEntryDraft($promoId, LedgerDirection::Credit, IrrMoney::positive(125_000)),
            ],
        );
        $referralToken = $this->app->make(ReferralAttributionService::class)->identityForUser($userId);
        $walletCounts = $this->walletMutationCounts();
        $referralCounts = $this->referralMutationCounts();

        $homeCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_account')
            ->first(['public_id', 'token_ciphertext']);
        self::assertNotNull($homeCallback);
        $homeToken = $this->app->make(StringEncrypter::class)->decryptString((string) $homeCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6201, $telegramUserId, 'navigation_account', 'fa', $homeToken));
        $processor->process('123456789', 6201);

        $sameSession = DB::table('telegram_interaction_sessions')->where('id', $sessionId)->first(['state', 'version']);
        self::assertNotNull($sameSession);
        self::assertSame('my_account', (string) $sameSession->state);
        self::assertSame(2, (int) $sameSession->version);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->count());
        self::assertSame($walletCounts, $this->walletMutationCounts());
        self::assertSame($referralCounts, $this->referralMutationCounts());

        $operations = DB::table('telegram_delivery_operations')->orderBy('id')->get();
        self::assertCount(2, $operations);
        $accountOperation = $operations[1];
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $accountOperation->presentation_text);
        $accountOutbox = DB::table('outbox_messages')->where('aggregate_id', (string) $accountOperation->public_id)->first();
        self::assertNotNull($accountOutbox);
        self::assertSame(TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL, (int) $accountOutbox->contract_version);
        self::assertSame(
            '{"telegram_delivery_operation_public_id":"'.(string) $accountOperation->public_id.'"}',
            (string) $accountOutbox->payload,
        );
        $companion = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $accountOperation->public_id)
            ->first(['presentation_ciphertext', 'presentation_hash']);
        self::assertNotNull($companion);
        $presentation = $this->app->make(StringEncrypter::class)->decryptString((string) $companion->presentation_ciphertext);
        self::assertStringContainsString('حساب من', $presentation);
        self::assertStringContainsString('مشتری', $presentation);
        self::assertStringContainsString('فعال', $presentation);
        self::assertStringContainsString('تأییدنشده', $presentation);
        self::assertStringContainsString('تأییدشده', $presentation);
        self::assertStringContainsString('کد ملی', $presentation);
        self::assertStringNotContainsString('customer', $presentation);
        self::assertStringNotContainsString('national_id', $presentation);
        self::assertStringContainsString($userPublicId, $presentation);
        self::assertStringContainsString('******7891', $presentation);
        self::assertStringContainsString('700,000', $presentation);
        self::assertStringContainsString('125,000', $presentation);
        self::assertStringContainsString($referralToken, $presentation);
        self::assertStringNotContainsString($rawNationalId, $presentation);
        self::assertStringNotContainsString($lookupHash, $presentation);

        $backCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.back')
            ->first(['public_id', 'session_version', 'token_ciphertext']);
        self::assertNotNull($backCallback);
        self::assertSame(2, (int) $backCallback->session_version);
        $backToken = $this->app->make(StringEncrypter::class)->decryptString((string) $backCallback->token_ciphertext);
        $accountSnapshot = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $accountOperation->public_id)
            ->first(['keyboard_snapshot']);
        self::assertNotNull($accountSnapshot);
        self::assertStringContainsString((string) $backCallback->public_id, (string) $accountSnapshot->keyboard_snapshot);

        $commonEvidence = json_encode([
            'operation' => (array) $accountOperation,
            'outbox' => (array) $accountOutbox,
            'keyboard' => (string) $accountSnapshot->keyboard_snapshot,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ([$rawNationalId, $lookupHash, $homeToken, $backToken] as $secret) {
            self::assertStringNotContainsString($secret, $commonEvidence);
        }
        self::assertStringNotContainsString($rawNationalId, (string) $companion->presentation_ciphertext);
        self::assertStringNotContainsString($lookupHash, (string) $companion->presentation_ciphertext);

        // Synchronize a second actor, then prove they cannot execute the first actor's fresh Back token.
        $this->accept($this->payload(6202, 9720, 'navigation_other', 'fa', '/start'));
        $processor->process('123456789', 6202);
        $operationCountBeforeCrossActor = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(6203, 9720, 'navigation_other', 'fa', $backToken));
        $processor->process('123456789', 6203);
        self::assertSame($operationCountBeforeCrossActor, DB::table('telegram_delivery_operations')->count());
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => $sessionId, 'state' => 'my_account', 'version' => 2]);
        $this->assertDatabaseHas('telegram_interaction_callbacks', ['public_id' => (string) $backCallback->public_id, 'state' => 'pending']);

        $this->accept($this->callbackPayload(6204, $telegramUserId, 'navigation_account', 'fa', $backToken));
        $processor->process('123456789', 6204);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => $sessionId, 'state' => 'home', 'version' => 3]);
        self::assertSame($operationCountBeforeCrossActor + 1, DB::table('telegram_delivery_operations')->count());
        $backHomeOperation = DB::table('telegram_delivery_operations')
            ->where('request_key_hash', hash('sha256', 'nav-home-delivery:telegram-callback:'.(string) $backCallback->public_id))
            ->first(['public_id']);
        self::assertNotNull($backHomeOperation);
        $backHomeOutbox = DB::table('outbox_messages')
            ->where('aggregate_id', (string) $backHomeOperation->public_id)
            ->first(['contract_version']);
        self::assertNotNull($backHomeOutbox);
        self::assertSame(
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE,
            (int) $backHomeOutbox->contract_version,
        );

        // Re-clicking the already completed old My Account token is replay-only and must not recreate effects.
        $beforeStaleReplay = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(6205, $telegramUserId, 'navigation_account', 'fa', $homeToken));
        $processor->process('123456789', 6205);
        self::assertSame($beforeStaleReplay, DB::table('telegram_delivery_operations')->count());
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => $sessionId, 'state' => 'home', 'version' => 3]);
    }

    public function test_menu_and_my_account_use_english_copy_without_requiring_referral_or_wallet_creation(): void
    {
        $telegramUserId = 9630;
        $this->accept($this->payload(6300, $telegramUserId, 'navigation_en', 'en', '/menu@FreedomBot'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6300);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['user_id']);
        self::assertNotNull($account);
        $userId = (int) $account->user_id;
        self::assertSame(0, DB::table('ledger_accounts')->where('owner_user_id', $userId)->count());
        $referralIdentityCount = DB::table('referral_identities')->where('user_id', $userId)->count();
        $referralToken = DB::table('referral_identities')->where('user_id', $userId)->value('token');

        $homeOperation = DB::table('telegram_delivery_operations')->first();
        self::assertNotNull($homeOperation);
        self::assertSame(trans('telegram.navigation.home', locale: 'en'), (string) $homeOperation->presentation_text);
        $callback = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->first(['token_ciphertext']);
        self::assertNotNull($callback);
        $token = $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);
        $this->accept($this->callbackPayload(6301, $telegramUserId, 'navigation_en', 'en', $token));
        $processor->process('123456789', 6301);

        $operation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first();
        self::assertNotNull($operation);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $operation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);
        $presentation = $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
        self::assertStringContainsString('My Account', $presentation);
        self::assertStringContainsString('Customer', $presentation);
        self::assertStringContainsString('Active', $presentation);
        self::assertStringContainsString('Unverified', $presentation);
        self::assertStringContainsString('Wallet', $presentation);
        self::assertStringContainsString('Referral', $presentation);
        if (is_string($referralToken)) {
            self::assertStringContainsString($referralToken, $presentation);
        } else {
            self::assertStringContainsString('Not available', $presentation);
        }
        self::assertSame(0, DB::table('ledger_accounts')->where('owner_user_id', $userId)->count());
        self::assertSame($referralIdentityCount, DB::table('referral_identities')->where('user_id', $userId)->count());
    }

    public function test_post_dispatch_failure_after_callback_completion_replays_without_duplicate_transition_back_callback_or_confidential_delivery(): void
    {
        $telegramUserId = 9640;
        $this->accept($this->payload(6401, $telegramUserId, 'navigation_callback_retry', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6401);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $sessionId = (int) DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->value('id');
        $transitionCountBefore = DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', $sessionId)->count();
        $callback = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->first(['public_id', 'token_ciphertext']);
        self::assertNotNull($callback);
        $token = $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);
        $this->accept($this->callbackPayload(6402, $telegramUserId, 'navigation_callback_retry', 'fa', $token));

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_processed_6402
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 6402 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-navigation-callback-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 6402);
                self::fail('The simulated post-dispatch failure must keep the callback update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6402');
        }

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6402, 'state' => 'failed', 'attempt_count' => 1]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => $sessionId, 'state' => 'my_account', 'version' => 2]);
        $this->assertDatabaseHas('telegram_interaction_callbacks', ['public_id' => (string) $callback->public_id, 'state' => 'completed', 'accepted_update_id' => 6402]);
        $transitionCount = DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', $sessionId)->count();
        $backCallbackCount = DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', $sessionId)->where('action', 'navigation.back')->count();
        $operationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $outboxCount = DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count();
        self::assertSame($transitionCountBefore + 1, $transitionCount);
        self::assertSame(1, $backCallbackCount);
        self::assertSame(2, $operationCount);
        self::assertSame(2, $outboxCount);

        $processor->process('123456789', 6402);

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6402, 'state' => 'processed', 'attempt_count' => 2]);
        $this->assertDatabaseHas('telegram_interaction_callbacks', ['public_id' => (string) $callback->public_id, 'state' => 'completed', 'accepted_update_id' => 6402]);
        self::assertSame($transitionCount, DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', $sessionId)->count());
        self::assertSame($backCallbackCount, DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', $sessionId)->where('action', 'navigation.back')->count());
        self::assertSame($operationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
        self::assertSame($outboxCount, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    public function test_my_services_list_and_detail_reuse_same_session_and_keep_service_identity_out_of_common_durable_state(): void
    {
        $selectionToken = str_repeat('a', 40);
        $servicePublicId = '01J00000000000000000000000';
        $projection = new class($selectionToken, $servicePublicId) implements TelegramOwnedServiceProjection
        {
            public bool $detailAvailable = true;

            public function __construct(
                private readonly string $selectionToken,
                private readonly string $servicePublicId,
            ) {}

            public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage
            {
                if ($actorUserId !== $subjectUserId || $page !== 1 || $pageSize !== 6) {
                    throw new RuntimeException('Unexpected My Services projection request.');
                }

                return new TelegramOwnedServicePage([
                    new TelegramOwnedServiceListItem(
                        $this->selectionToken,
                        $this->servicePublicId,
                        'active',
                        'پلن تست',
                        'Test plan',
                        'سرور تست',
                        'Test server',
                        '2026-09-01 04:00:00.000000',
                    ),
                ], 1, 1, 1);
            }

            public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail
            {
                if ($actorUserId !== $subjectUserId || $selectionToken !== $this->selectionToken) {
                    throw new RuntimeException('Unexpected Service detail projection request.');
                }
                if (! $this->detailAvailable) {
                    throw new RuntimeException('Service detail is no longer available to the actor.');
                }

                return new TelegramOwnedServiceDetail(
                    $this->servicePublicId,
                    'active',
                    'پلن تست',
                    'Test plan',
                    'سرور تست',
                    'Test server',
                    '2026-09-01 04:00:00.000000',
                    'current',
                    'present',
                    'active',
                    10 * 1024 * 1024 * 1024,
                    3 * 1024 * 1024 * 1024,
                    '2026-10-01 04:00:00.000000',
                    '2026-09-01 04:05:00.000000',
                );
            }

            public function searchForSelf(int $actorUserId, int $subjectUserId, string $searchTerm): TelegramOwnedServiceSearchResult
            {
                return TelegramOwnedServiceSearchResult::notFound();
            }
        };
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);

        $telegramUserId = 9650;
        $this->accept($this->payload(6500, $telegramUserId, 'navigation_services', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6500);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'public_id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('home', (string) $session->state);
        self::assertSame(1, (int) $session->version);

        $servicesCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_services')
            ->first(['public_id', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($servicesCallback);
        self::assertSame('{}', (string) $servicesCallback->action_payload);
        $servicesToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6501, $telegramUserId, 'navigation_services', 'fa', $servicesToken));
        $processor->process('123456789', 6501);

        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 2,
            'payload' => '{"page":1}',
        ]);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->count());

        $listOperation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first();
        self::assertNotNull($listOperation);
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $listOperation->presentation_text);
        $listCiphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $listOperation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($listCiphertext);
        $listText = $this->app->make(StringEncrypter::class)->decryptString($listCiphertext);
        self::assertStringContainsString('سرویس‌های من', $listText);
        self::assertStringContainsString($servicePublicId, $listText);
        self::assertStringContainsString('پلن تست', $listText);
        self::assertStringContainsString('سرور تست', $listText);

        $detailCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'navigation.service.'.$selectionToken)
            ->first(['public_id', 'action', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($detailCallback);
        self::assertSame('{}', (string) $detailCallback->action_payload);
        self::assertStringNotContainsString($servicePublicId, (string) $detailCallback->action);
        $detailToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $detailCallback->token_ciphertext);

        $listSnapshot = DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $listOperation->public_id)
            ->value('keyboard_snapshot');
        self::assertIsString($listSnapshot);
        $listOutbox = DB::table('outbox_messages')
            ->where('aggregate_id', (string) $listOperation->public_id)
            ->value('payload');
        self::assertIsString($listOutbox);
        $commonEvidence = $listSnapshot."\n".$listOutbox."\n".(string) DB::table('telegram_interaction_sessions')
            ->where('id', (int) $session->id)
            ->value('payload')."\n".(string) $detailCallback->action_payload;
        self::assertStringNotContainsString($servicePublicId, $commonEvidence);
        self::assertStringNotContainsString($selectionToken, $commonEvidence);
        self::assertStringNotContainsString($detailToken, $commonEvidence);

        $this->accept($this->payload(6502, 9750, 'navigation_services_other', 'fa', '/start'));
        $processor->process('123456789', 6502);
        $operationCountBeforeCrossActor = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(6503, 9750, 'navigation_services_other', 'fa', $detailToken));
        $processor->process('123456789', 6503);
        self::assertSame($operationCountBeforeCrossActor, DB::table('telegram_delivery_operations')->count());
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 2,
        ]);

        $projection->detailAvailable = false;
        $this->accept($this->callbackPayload(6504, $telegramUserId, 'navigation_services', 'fa', $detailToken));
        try {
            $processor->process('123456789', 6504);
            self::fail('Unavailable owner projection must keep the accepted callback retryable without advancing the session.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 6504,
            'state' => 'failed',
            'attempt_count' => 1,
        ]);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 2,
            'payload' => '{"page":1}',
        ]);
        $this->assertDatabaseHas('telegram_interaction_callbacks', [
            'public_id' => (string) $detailCallback->public_id,
            'state' => 'accepted',
            'accepted_update_id' => 6504,
        ]);

        $projection->detailAvailable = true;
        $processor->process('123456789', 6504);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 6504,
            'state' => 'processed',
            'attempt_count' => 2,
        ]);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'service_detail',
            'version' => 3,
            'payload' => '{"page":1}',
        ]);

        $detailOperation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first();
        self::assertNotNull($detailOperation);
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $detailOperation->presentation_text);
        $detailCiphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $detailOperation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($detailCiphertext);
        $detailText = $this->app->make(StringEncrypter::class)->decryptString($detailCiphertext);
        self::assertStringContainsString('جزئیات سرویس', $detailText);
        self::assertStringContainsString($servicePublicId, $detailText);
        self::assertStringContainsString('10.00 GiB', $detailText);
        self::assertStringContainsString('3.00 GiB', $detailText);
        self::assertStringContainsString('7.00 GiB', $detailText);
        self::assertStringContainsString('موجود', $detailText);

        $backCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 3)
            ->where('action', 'navigation.back')
            ->first(['token_ciphertext']);
        self::assertNotNull($backCallback);
        $backToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $backCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6505, $telegramUserId, 'navigation_services', 'fa', $backToken));
        $processor->process('123456789', 6505);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 4,
            'payload' => '{"page":1}',
        ]);
    }

    public function test_my_services_secure_resend_uses_owner_bound_callback_stable_identity_and_safe_confirmation(): void
    {
        $selectionToken = str_repeat('e', 40);
        $servicePublicId = '01J00000000000000000000004';
        $projection = new TelegramNavigationOwnedServiceSearchProjection($selectionToken, $servicePublicId);
        $resender = new TelegramNavigationOwnedServiceDeliveryResender;
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);
        $this->app->instance(TelegramOwnedServiceDeliveryResender::class, $resender);

        $telegramUserId = 9680;
        $this->accept($this->payload(7000, $telegramUserId, 'navigation_resend', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 7000);
        $ownerUserId = (int) DB::table('telegram_accounts')
            ->where('telegram_user_id', $telegramUserId)
            ->value('user_id');
        self::assertGreaterThan(0, $ownerUserId);

        $servicesCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_services')
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($servicesCallback);
        $servicesToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(7001, $telegramUserId, 'navigation_resend', 'fa', $servicesToken));
        $processor->process('123456789', 7001);

        $detailCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.service.'.$selectionToken)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($detailCallback);
        $detailToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $detailCallback->token_ciphertext);
        $this->accept($this->callbackPayload(7002, $telegramUserId, 'navigation_resend', 'fa', $detailToken));
        $processor->process('123456789', 7002);

        $accountId = (int) DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->value('id');
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('service_detail', (string) $session->state);
        self::assertSame(3, (int) $session->version);
        self::assertSame('{"page":1}', (string) $session->payload);

        $resendCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 3)
            ->where('action', 'navigation.service.resend')
            ->first(['public_id', 'action_payload', 'token_ciphertext']);
        self::assertNotNull($resendCallback);
        self::assertSame(
            json_encode(['service_selection' => $selectionToken], JSON_THROW_ON_ERROR),
            (string) $resendCallback->action_payload,
        );
        self::assertStringNotContainsString($servicePublicId, (string) $resendCallback->action_payload);
        $resendToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $resendCallback->token_ciphertext);

        $this->accept($this->payload(7003, 9780, 'navigation_resend_other', 'fa', '/start'));
        $processor->process('123456789', 7003);
        $this->accept($this->callbackPayload(7004, 9780, 'navigation_resend_other', 'fa', $resendToken));
        $processor->process('123456789', 7004);
        self::assertSame([], $resender->calls);

        $operationCountBefore = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(7005, $telegramUserId, 'navigation_resend', 'fa', $resendToken));
        $processor->process('123456789', 7005);
        self::assertSame([[
            'actor_user_id' => $ownerUserId,
            'service_public_id' => $servicePublicId,
            'request_key' => 'telegram-service-resend:'.(string) $resendCallback->public_id,
            'correlation_id' => 'telegram-resend:'.(string) $resendCallback->public_id,
        ]], $resender->calls);
        self::assertSame($operationCountBefore + 1, DB::table('telegram_delivery_operations')->count());
        $confirmation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('مسیر محافظت‌شده', $confirmation);
        self::assertStringNotContainsString($servicePublicId, $confirmation);
        self::assertStringNotContainsString($selectionToken, $confirmation);
        self::assertStringNotContainsString('http', mb_strtolower($confirmation));
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'service_detail',
            'version' => 3,
            'payload' => '{"page":1}',
        ]);

        $this->accept($this->callbackPayload(7006, $telegramUserId, 'navigation_resend', 'fa', $resendToken));
        $processor->process('123456789', 7007);
        self::assertCount(1, $resender->calls);
        self::assertSame($operationCountBefore + 1, DB::table('telegram_delivery_operations')->count());

        $backCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 3)
            ->where('action', 'navigation.back')
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($backCallback);
        $backToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $backCallback->token_ciphertext);
        $this->accept($this->callbackPayload(7007, $telegramUserId, 'navigation_resend', 'fa', $backToken));
        $processor->process('123456789', 7007);

        $detailAgain = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 4)
            ->where('action', 'navigation.service.'.$selectionToken)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($detailAgain);
        $detailAgainToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $detailAgain->token_ciphertext);
        $this->accept($this->callbackPayload(7008, $telegramUserId, 'navigation_resend', 'fa', $detailAgainToken));
        $processor->process('123456789', 7008);

        $blockedResend = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 5)
            ->where('action', 'navigation.service.resend')
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($blockedResend);
        $blockedToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $blockedResend->token_ciphertext);
        $resender->status = TelegramOwnedServiceDeliveryResendStatus::TemporarilyBlocked;
        $this->accept($this->callbackPayload(7009, $telegramUserId, 'navigation_resend', 'fa', $blockedToken));
        $processor->process('123456789', 7009);
        self::assertCount(2, $resender->calls);
        $blockedCopy = $this->latestConfidentialPresentation();
        self::assertStringContainsString('کمی بعد دوباره تلاش کنید', $blockedCopy);
        self::assertStringNotContainsString($servicePublicId, $blockedCopy);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'service_detail',
            'version' => 5,
            'payload' => '{"page":1}',
        ]);

        /** @var array<string, mixed> $english */
        $english = require resource_path('lang/en/telegram.php');
        self::assertStringContainsString('protected delivery', (string) $english['navigation']['services']['resend']['queued']);
        self::assertStringContainsString('try again later', mb_strtolower((string) $english['navigation']['services']['resend']['temporarily_blocked']));
    }

    public function test_my_services_empty_state_is_confidential_and_back_remains_deterministic(): void
    {
        $projection = new class implements TelegramOwnedServiceProjection
        {
            public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage
            {
                if ($actorUserId !== $subjectUserId || $page !== 1 || $pageSize !== 6) {
                    throw new RuntimeException('Unexpected empty My Services projection request.');
                }

                return new TelegramOwnedServicePage([], 1, 1, 0);
            }

            public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail
            {
                throw new RuntimeException('Empty My Services must not resolve a detail projection.');
            }

            public function searchForSelf(int $actorUserId, int $subjectUserId, string $searchTerm): TelegramOwnedServiceSearchResult
            {
                return TelegramOwnedServiceSearchResult::notFound();
            }
        };
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);

        $telegramUserId = 9650;
        $this->accept($this->payload(6550, $telegramUserId, 'navigation_services_empty', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6550);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id']);
        self::assertNotNull($session);
        $servicesCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('action', 'navigation.my_services')
            ->first(['token_ciphertext']);
        self::assertNotNull($servicesCallback);
        $servicesToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6551, $telegramUserId, 'navigation_services_empty', 'fa', $servicesToken));
        $processor->process('123456789', 6551);

        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'my_services',
            'version' => 2,
            'payload' => '{"page":1}',
        ]);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'like', 'navigation.service.%')
            ->count());

        $operation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first(['public_id', 'presentation_text']);
        self::assertNotNull($operation);
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $operation->presentation_text);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $operation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);
        $text = $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
        self::assertStringContainsString('سرویس‌های من', $text);
        self::assertStringContainsString('هنوز سرویسی برای شما ثبت نشده است.', $text);

        $backCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', 2)
            ->where('action', 'navigation.back')
            ->first(['token_ciphertext']);
        self::assertNotNull($backCallback);
        $backToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $backCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6552, $telegramUserId, 'navigation_services_empty', 'fa', $backToken));
        $processor->process('123456789', 6552);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'home',
            'version' => 3,
            'payload' => '{}',
        ]);
    }

    public function test_my_services_uses_english_list_and_no_sync_detail_copy(): void
    {
        $selectionToken = str_repeat('b', 40);
        $servicePublicId = '01J00000000000000000000001';
        $projection = new class($selectionToken, $servicePublicId) implements TelegramOwnedServiceProjection
        {
            public function __construct(
                private readonly string $selectionToken,
                private readonly string $servicePublicId,
            ) {}

            public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage
            {
                if ($actorUserId !== $subjectUserId || $page !== 1 || $pageSize !== 6) {
                    throw new RuntimeException('Unexpected English My Services projection request.');
                }

                return new TelegramOwnedServicePage([
                    new TelegramOwnedServiceListItem(
                        $this->selectionToken,
                        $this->servicePublicId,
                        'retired',
                        'پلن فارسی',
                        'English plan',
                        'سرور فارسی',
                        'English server',
                        null,
                    ),
                ], 1, 1, 1);
            }

            public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail
            {
                if ($actorUserId !== $subjectUserId || $selectionToken !== $this->selectionToken) {
                    throw new RuntimeException('Unexpected English Service detail projection request.');
                }

                return new TelegramOwnedServiceDetail(
                    $this->servicePublicId,
                    'retired',
                    'پلن فارسی',
                    'English plan',
                    'سرور فارسی',
                    'English server',
                    null,
                    'none',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                );
            }

            public function searchForSelf(int $actorUserId, int $subjectUserId, string $searchTerm): TelegramOwnedServiceSearchResult
            {
                return TelegramOwnedServiceSearchResult::notFound();
            }
        };
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);

        $telegramUserId = 9660;
        $this->accept($this->payload(6600, $telegramUserId, 'navigation_services_en', 'en', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 6600);

        $servicesCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.my_services')
            ->first(['token_ciphertext']);
        self::assertNotNull($servicesCallback);
        $servicesToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6601, $telegramUserId, 'navigation_services_en', 'en', $servicesToken));
        $processor->process('123456789', 6601);

        $listOperation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first(['public_id']);
        self::assertNotNull($listOperation);
        $listCiphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $listOperation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($listCiphertext);
        $listText = $this->app->make(StringEncrypter::class)->decryptString($listCiphertext);
        self::assertStringContainsString('My Services', $listText);
        self::assertStringContainsString('English plan', $listText);
        self::assertStringContainsString('English server', $listText);
        self::assertStringContainsString('Retired', $listText);
        self::assertStringContainsString($servicePublicId, $listText);
        self::assertStringNotContainsString('پلن فارسی', $listText);
        self::assertStringNotContainsString('سرور فارسی', $listText);

        $detailCallback = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.service.'.$selectionToken)
            ->first(['token_ciphertext']);
        self::assertNotNull($detailCallback);
        $detailToken = $this->app->make(StringEncrypter::class)
            ->decryptString((string) $detailCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6602, $telegramUserId, 'navigation_services_en', 'en', $detailToken));
        $processor->process('123456789', 6602);

        $detailOperation = DB::table('telegram_delivery_operations')->orderByDesc('id')->first(['public_id']);
        self::assertNotNull($detailOperation);
        $detailCiphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $detailOperation->public_id)
            ->value('presentation_ciphertext');
        self::assertIsString($detailCiphertext);
        $detailText = $this->app->make(StringEncrypter::class)->decryptString($detailCiphertext);
        self::assertStringContainsString('Service Details', $detailText);
        self::assertStringContainsString('English plan', $detailText);
        self::assertStringContainsString('English server', $detailText);
        self::assertStringContainsString('Retired', $detailText);
        self::assertStringContainsString('No synchronization evidence', $detailText);
        self::assertStringContainsString('Not available', $detailText);
        self::assertStringNotContainsString('پلن فارسی', $detailText);
        self::assertStringNotContainsString('سرور فارسی', $detailText);
    }

    public function test_my_services_search_keeps_query_transient_and_retries_match_before_transition(): void
    {
        $selectionToken = str_repeat('c', 40);
        $servicePublicId = '01J00000000000000000000002';
        $projection = new TelegramNavigationOwnedServiceSearchProjection($selectionToken, $servicePublicId);
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);
        $telegramUserId = 9670;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6700, $telegramUserId, 'navigation_search_fa', 'fa', '/start'));
        $processor->process('123456789', 6700);
        $servicesCallback = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_services')->first(['token_ciphertext']);
        self::assertNotNull($servicesCallback);
        $servicesToken = $this->app->make(StringEncrypter::class)->decryptString((string) $servicesCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6701, $telegramUserId, 'navigation_search_fa', 'fa', $servicesToken));
        $processor->process('123456789', 6701);

        $searchCallback = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.services.search')->first(['token_ciphertext']);
        self::assertNotNull($searchCallback);
        $searchToken = $this->app->make(StringEncrypter::class)->decryptString((string) $searchCallback->token_ciphertext);
        $this->accept($this->callbackPayload(6702, $telegramUserId, 'navigation_search_fa', 'fa', $searchToken));
        $processor->process('123456789', 6702);
        $session = DB::table('telegram_interaction_sessions')->where('telegram_user_id', $telegramUserId)->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('service_search', (string) $session->state);
        self::assertSame(3, (int) $session->version);
        self::assertSame('{"page":1}', (string) $session->payload);
        $prompt = $this->latestConfidentialPresentation();
        self::assertStringContainsString('جستجوی سرویس‌های من', $prompt);
        self::assertStringContainsString('شناسه دقیق سرویس', $prompt);

        $rawQuery = 'private-search-query-NEVER-PERSIST';
        $this->accept($this->payload(6703, $telegramUserId, 'navigation_search_fa', 'fa', $rawQuery));
        $processor->process('123456789', 6703);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3, 'payload' => '{"page":1}']);
        $notFound = $this->latestConfidentialPresentation();
        self::assertStringContainsString('سرویس منطبقی در حساب شما پیدا نشد', $notFound);
        self::assertStringNotContainsString($rawQuery, $notFound);
        self::assertStringNotContainsString($rawQuery, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
        $operationCountAfterNotFound = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 6703);
        self::assertSame($operationCountAfterNotFound, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());

        $this->accept($this->payload(6704, $telegramUserId, 'navigation_search_fa', 'fa', 'ambiguous-search'));
        $processor->process('123456789', 6704);
        self::assertStringContainsString('بیش از یک سرویس شما این نام کاربری را دارد', $this->latestConfidentialPresentation());
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3]);

        $projection->detailAvailable = false;
        $transitionCountBeforeMatch = DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count();
        $this->accept($this->payload(6705, $telegramUserId, 'navigation_search_fa', 'fa', 'match-search'));
        try {
            $processor->process('123456789', 6705);
            self::fail('Matched search must not transition while the owner detail projection is unavailable.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6705, 'state' => 'failed', 'attempt_count' => 1]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3]);
        self::assertSame($transitionCountBeforeMatch, DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count());

        $projection->detailAvailable = true;
        $processor->process('123456789', 6705);
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6705, 'state' => 'processed', 'attempt_count' => 2]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_detail', 'version' => 4, 'payload' => '{"page":1}']);
        self::assertSame($transitionCountBeforeMatch + 1, DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count());
        $detail = $this->latestConfidentialPresentation();
        self::assertStringContainsString($servicePublicId, $detail);
        self::assertStringContainsString('ذخیره‌شده', $detail);
        self::assertStringContainsString('موقتاً در دسترس نیست', $detail);
        self::assertStringContainsString('10.00 GiB', $detail);
        self::assertStringContainsString('3.00 GiB', $detail);
        self::assertStringNotContainsString('match-search', $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
    }

    public function test_my_services_search_post_dispatch_failure_replays_without_duplicate_callback_or_delivery(): void
    {
        $projection = new TelegramNavigationOwnedServiceSearchProjection(str_repeat('e', 40), '01J00000000000000000000004');
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);
        $telegramUserId = 9690;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6900, $telegramUserId, 'navigation_search_retry', 'fa', '/start'));
        $processor->process('123456789', 6900);
        $services = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_services')->first(['token_ciphertext']);
        self::assertNotNull($services);
        $this->accept($this->callbackPayload(6901, $telegramUserId, 'navigation_search_retry', 'fa', $this->app->make(StringEncrypter::class)->decryptString((string) $services->token_ciphertext)));
        $processor->process('123456789', 6901);
        $search = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.services.search')->first(['token_ciphertext']);
        self::assertNotNull($search);
        $this->accept($this->callbackPayload(6902, $telegramUserId, 'navigation_search_retry', 'fa', $this->app->make(StringEncrypter::class)->decryptString((string) $search->token_ciphertext)));
        $processor->process('123456789', 6902);

        $session = DB::table('telegram_interaction_sessions')->where('telegram_user_id', $telegramUserId)->first(['id', 'state', 'version']);
        self::assertNotNull($session);
        self::assertSame('service_search', (string) $session->state);
        self::assertSame(3, (int) $session->version);

        $rawQuery = 'private-search-retry-NEVER-PERSIST';
        $this->accept($this->payload(6903, $telegramUserId, 'navigation_search_retry', 'fa', $rawQuery));
        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_processed_6903
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 6903 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-service-search-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 6903);
                self::fail('The simulated post-search-dispatch failure must keep the search update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
                self::assertStringNotContainsString($rawQuery, $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6903');
        }

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6903, 'state' => 'failed', 'attempt_count' => 1]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3, 'payload' => '{"page":1}']);
        $errorEvidence = DB::table('processed_telegram_updates')->where('update_id', 6903)->first(['last_error_class', 'last_error_code']);
        self::assertNotNull($errorEvidence);
        self::assertStringNotContainsString($rawQuery, json_encode($errorEvidence, JSON_THROW_ON_ERROR));

        $transitionCount = DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count();
        $backCallbackCount = DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', (int) $session->id)->where('action', 'navigation.back')->count();
        $operationCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $outboxCount = DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count();
        $confidentialCount = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->count();
        self::assertStringNotContainsString($rawQuery, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));

        $processor->process('123456789', 6903);

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6903, 'state' => 'processed', 'attempt_count' => 2]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['id' => (int) $session->id, 'state' => 'service_search', 'version' => 3, 'payload' => '{"page":1}']);
        self::assertSame($transitionCount, DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', (int) $session->id)->count());
        self::assertSame($backCallbackCount, DB::table('telegram_interaction_callbacks')->where('telegram_interaction_session_id', (int) $session->id)->where('action', 'navigation.back')->count());
        self::assertSame($operationCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
        self::assertSame($outboxCount, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
        self::assertSame($confidentialCount, DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)->count());
        self::assertStringNotContainsString($rawQuery, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));
    }

    public function test_my_services_search_english_prompt_not_found_ambiguous_and_cached_detail_copy(): void
    {
        $selectionToken = str_repeat('d', 40);
        $projection = new TelegramNavigationOwnedServiceSearchProjection($selectionToken, '01J00000000000000000000003');
        $this->app->instance(TelegramOwnedServiceProjection::class, $projection);
        $telegramUserId = 9680;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6800, $telegramUserId, 'navigation_search_en', 'en', '/start'));
        $processor->process('123456789', 6800);
        $services = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_services')->first(['token_ciphertext']);
        self::assertNotNull($services);
        $this->accept($this->callbackPayload(6801, $telegramUserId, 'navigation_search_en', 'en', $this->app->make(StringEncrypter::class)->decryptString((string) $services->token_ciphertext)));
        $processor->process('123456789', 6801);
        $search = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.services.search')->first(['token_ciphertext']);
        self::assertNotNull($search);
        $this->accept($this->callbackPayload(6802, $telegramUserId, 'navigation_search_en', 'en', $this->app->make(StringEncrypter::class)->decryptString((string) $search->token_ciphertext)));
        $processor->process('123456789', 6802);
        self::assertStringContainsString('Search My Services', $this->latestConfidentialPresentation());
        $searchBack = DB::table('telegram_interaction_callbacks')
            ->where('action', 'navigation.back')
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($searchBack);
        $this->accept($this->callbackPayload(6803, $telegramUserId, 'navigation_search_en', 'en', $this->app->make(StringEncrypter::class)->decryptString((string) $searchBack->token_ciphertext)));
        $processor->process('123456789', 6803);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['telegram_user_id' => $telegramUserId, 'state' => 'my_services', 'version' => 4, 'payload' => '{"page":1}']);
        $searchAgain = DB::table('telegram_interaction_callbacks')->where('action', 'navigation.services.search')->orderByDesc('id')->first(['token_ciphertext']);
        self::assertNotNull($searchAgain);
        $this->accept($this->callbackPayload(6804, $telegramUserId, 'navigation_search_en', 'en', $this->app->make(StringEncrypter::class)->decryptString((string) $searchAgain->token_ciphertext)));
        $processor->process('123456789', 6804);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['telegram_user_id' => $telegramUserId, 'state' => 'service_search', 'version' => 5, 'payload' => '{"page":1}']);

        $this->accept($this->payload(6805, $telegramUserId, 'navigation_search_en', 'en', 'unknown-en'));
        $processor->process('123456789', 6805);
        self::assertStringContainsString('No matching service was found in your account.', $this->latestConfidentialPresentation());
        $this->accept($this->payload(6806, $telegramUserId, 'navigation_search_en', 'en', 'ambiguous-search'));
        $processor->process('123456789', 6806);
        self::assertStringContainsString('More than one of your services uses that username.', $this->latestConfidentialPresentation());
        $this->accept($this->payload(6807, $telegramUserId, 'navigation_search_en', 'en', 'cached-en'));
        $processor->process('123456789', 6807);
        $detail = $this->latestConfidentialPresentation();
        self::assertStringContainsString('Cached — current synchronization unavailable', $detail);
        self::assertStringContainsString('Temporarily unavailable', $detail);
        self::assertStringContainsString('Search plan', $detail);
        self::assertStringNotContainsString('پلن جستجو', $detail);
    }

    public function test_admin_usdt_rate_journey_is_permission_filtered_confidential_and_crash_replay_idempotent(): void
    {
        Config::set('usdt.rate.min_irr', '100000');
        Config::set('usdt.rate.max_irr', '10000000');
        Config::set('usdt.rate.manual_irr', '900000');
        $telegramUserId = 9700;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7000, $telegramUserId, 'navigation_admin_rate', 'fa', '/start'));
        $processor->process('123456789', 7000);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')->where('action', 'navigation.admin')->count());

        $administratorId = $this->financeAdministratorForUser((int) $account->user_id);
        self::assertGreaterThan(0, $administratorId);
        $this->accept($this->payload(7001, $telegramUserId, 'navigation_admin_rate', 'fa', '/menu'));
        $processor->process('123456789', 7001);

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'state', 'version', 'payload']);
        self::assertNotNull($session);
        self::assertSame('home', (string) $session->state);
        $adminCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('session_version', (int) $session->version)
            ->where('action', 'navigation.admin')
            ->orderByDesc('id')
            ->first(['action_payload', 'token_ciphertext']);
        self::assertNotNull($adminCallback);
        self::assertSame('{}', (string) $adminCallback->action_payload);
        $adminToken = $this->app->make(StringEncrypter::class)->decryptString((string) $adminCallback->token_ciphertext);

        $otherTelegramUserId = 9701;
        $this->accept($this->payload(7010, $otherTelegramUserId, 'navigation_admin_other', 'fa', '/start'));
        $processor->process('123456789', 7010);
        $operationCountBeforeCrossActor = DB::table('telegram_delivery_operations')->count();
        $this->accept($this->callbackPayload(7011, $otherTelegramUserId, 'navigation_admin_other', 'fa', $adminToken));
        $processor->process('123456789', 7011);
        self::assertSame($operationCountBeforeCrossActor, DB::table('telegram_delivery_operations')->count());

        $this->accept($this->callbackPayload(7002, $telegramUserId, 'navigation_admin_rate', 'fa', $adminToken));
        $processor->process('123456789', 7002);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'admin_control',
            'payload' => '{}',
        ]);
        self::assertStringContainsString('مرکز مدیریت', $this->latestConfidentialPresentation());

        $rateCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('action', 'navigation.admin.usdt_rate')
            ->orderByDesc('id')
            ->first(['action_payload', 'token_ciphertext']);
        self::assertNotNull($rateCallback);
        self::assertSame('{}', (string) $rateCallback->action_payload);
        $rateToken = $this->app->make(StringEncrypter::class)->decryptString((string) $rateCallback->token_ciphertext);
        $this->accept($this->callbackPayload(7003, $telegramUserId, 'navigation_admin_rate', 'fa', $rateToken));
        $processor->process('123456789', 7003);

        $ratePresentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('900,000', $ratePresentation);
        self::assertStringContainsString('USDT', $ratePresentation);
        self::assertStringContainsString('NOWPayments', $ratePresentation);
        self::assertStringNotContainsString('payments.usdt.manage', $ratePresentation);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'admin_usdt_rate',
            'payload' => '{}',
        ]);
        $commonEvidence = $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId);
        self::assertStringNotContainsString('900000', $commonEvidence);
        self::assertStringNotContainsString('payments.usdt.manage', $commonEvidence);
        self::assertStringNotContainsString('created_by_administrator_id', $commonEvidence);

        $editCallback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $session->id)
            ->where('action', 'navigation.admin.usdt_rate.edit')
            ->orderByDesc('id')
            ->first(['action_payload', 'token_ciphertext']);
        self::assertNotNull($editCallback);
        self::assertSame('{}', (string) $editCallback->action_payload);
        $editToken = $this->app->make(StringEncrypter::class)->decryptString((string) $editCallback->token_ciphertext);
        $this->accept($this->callbackPayload(7004, $telegramUserId, 'navigation_admin_rate', 'fa', $editToken));
        $processor->process('123456789', 7004);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'admin_usdt_rate_edit',
            'payload' => '{}',
        ]);

        $this->accept($this->payload(7005, $telegramUserId, 'navigation_admin_rate', 'fa', '۹۰,۰۰۰'));
        $processor->process('123456789', 7005);
        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        self::assertStringContainsString('معتبر نیست', $this->latestConfidentialPresentation());

        $this->accept($this->payload(7006, $telegramUserId, 'navigation_admin_rate', 'fa', '۹۹۹۹۹'));
        $processor->process('123456789', 7006);
        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        self::assertStringContainsString('معتبر نیست', $this->latestConfidentialPresentation());

        $rawRateInput = '۹۱۰۰۰۰';
        $this->accept($this->payload(7007, $telegramUserId, 'navigation_admin_rate', 'fa', $rawRateInput));
        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_processed_7007
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 7007 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-admin-rate-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 7007);
                self::fail('The simulated post-rate-dispatch failure must keep the accepted rate update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
                self::assertStringNotContainsString($rawRateInput, $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_7007');
        }

        self::assertSame(1, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame('910000.00000000', (string) DB::table('usdt_manual_rate_versions')->value('rate_irr'));
        self::assertSame(1, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 7007,
            'state' => 'failed',
            'attempt_count' => 1,
        ]);
        self::assertStringNotContainsString($rawRateInput, $this->navigationCommonDurableEvidence((int) $session->id, $telegramUserId));

        $processor->process('123456789', 7007);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 7007,
            'state' => 'processed',
            'attempt_count' => 2,
        ]);
        self::assertSame(1, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'id' => (int) $session->id,
            'state' => 'admin_usdt_rate',
            'payload' => '{}',
        ]);
        $updatedPresentation = $this->latestConfidentialPresentation();
        self::assertStringContainsString('با موفقیت ثبت شد', $updatedPresentation);
        self::assertStringContainsString('910,000', $updatedPresentation);
    }

    public function test_admin_usdt_rate_edit_reauthorizes_after_permission_revocation_before_message_execution(): void
    {
        Config::set('usdt.rate.min_irr', '100000');
        Config::set('usdt.rate.max_irr', '10000000');
        Config::set('usdt.rate.manual_irr', '900000');
        $telegramUserId = 9710;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(7020, $telegramUserId, 'navigation_admin_revoke', 'en', '/start'));
        $processor->process('123456789', 7020);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $administratorId = $this->financeAdministratorForUser((int) $account->user_id);

        $this->accept($this->payload(7021, $telegramUserId, 'navigation_admin_revoke', 'en', '/menu'));
        $processor->process('123456789', 7021);
        $adminToken = $this->callbackToken('navigation.admin', (int) $account->id);
        $this->accept($this->callbackPayload(7022, $telegramUserId, 'navigation_admin_revoke', 'en', $adminToken));
        $processor->process('123456789', 7022);
        self::assertStringContainsString('Administrator Control Center', $this->latestConfidentialPresentation());
        $rateToken = $this->callbackToken('navigation.admin.usdt_rate', (int) $account->id);
        $this->accept($this->callbackPayload(7023, $telegramUserId, 'navigation_admin_revoke', 'en', $rateToken));
        $processor->process('123456789', 7023);
        self::assertStringContainsString('Manual USDT Rate', $this->latestConfidentialPresentation());
        self::assertStringContainsString('NOWPayments', $this->latestConfidentialPresentation());
        $editToken = $this->callbackToken('navigation.admin.usdt_rate.edit', (int) $account->id);
        $this->accept($this->callbackPayload(7024, $telegramUserId, 'navigation_admin_revoke', 'en', $editToken));
        $processor->process('123456789', 7024);
        self::assertStringContainsString('Change Manual USDT Rate', $this->latestConfidentialPresentation());

        DB::table('administrator_role_assignments')->where('administrator_id', $administratorId)->update([
            'revoked_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('administrators')->where('id', $administratorId)->update([
            'permission_version' => DB::raw('permission_version + 1'),
            'updated_at' => now('UTC'),
        ]);

        $this->accept($this->payload(7025, $telegramUserId, 'navigation_admin_revoke', 'en', '920000'));
        $processor->process('123456789', 7025);

        self::assertSame(0, DB::table('usdt_manual_rate_versions')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', 'payments.usdt.manual_rate.updated')->count());
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->first(['state', 'version']);
        self::assertNotNull($session);
        self::assertSame('home', (string) $session->state);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', DB::table('telegram_interaction_sessions')->where('telegram_account_id', (int) $account->id)->value('id'))
            ->where('session_version', (int) $session->version)
            ->where('action', 'navigation.admin')
            ->count());
    }

    public function test_arbitrary_text_and_non_private_start_do_not_implicitly_create_navigation_authority(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6103, 9603, 'navigation_ignored', 'fa', 'hello'));
        $processor->process('123456789', 6103);

        $this->accept($this->payload(6104, 9604, 'navigation_group', 'fa', '/start', 'group', -1009604));
        $processor->process('123456789', 6104);

        self::assertSame(0, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(0, DB::table('telegram_delivery_operations')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6103, 'state' => 'processed']);
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6104, 'state' => 'processed']);
    }

    public function test_post_dispatch_failure_replays_the_same_session_callback_and_delivery_operation_without_duplication(): void
    {
        $this->accept($this->payload(6105, 9605, 'navigation_retry', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_processed_6105
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 6105 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-navigation-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 6105);
                self::fail('The simulated post-dispatch failure must keep the navigation update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6105');
        }

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6105, 'state' => 'failed', 'attempt_count' => 1]);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 6105)->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->count());
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());

        $firstSessionId = (int) DB::table('telegram_interaction_sessions')->value('id');
        $firstCallbackId = (string) DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->value('public_id');
        $firstOperationId = (string) DB::table('telegram_delivery_operations')->value('public_id');

        $processor->process('123456789', 6105);

        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6105, 'state' => 'processed', 'attempt_count' => 2]);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->count());
        self::assertSame($firstSessionId, (int) DB::table('telegram_interaction_sessions')->value('id'));
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 6105)->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->count());
        self::assertSame($firstCallbackId, (string) DB::table('telegram_interaction_callbacks')->where('action', 'navigation.my_account')->value('public_id'));
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame($firstOperationId, (string) DB::table('telegram_delivery_operations')->value('public_id'));
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    /** @param array<string, mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function payload(
        int $updateId,
        int $telegramUserId,
        string $username,
        string $languageCode,
        string $text,
        string $chatType = 'private',
        ?int $chatId = null,
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
                    'id' => $chatId ?? $telegramUserId,
                    'type' => $chatType,
                ],
                'text' => $text,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function callbackPayload(
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

    private function financeAdministratorForUser(int $userId): int
    {
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = DB::table('roles')->where('code', 'finance')->where('is_active', true)->value('id');
        self::assertIsNumeric($roleId);
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => (int) $roleId,
            'granted_by_administrator_id' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $administratorId;
    }

    private function callbackToken(string $action, int $telegramAccountId): string
    {
        $sessionId = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('id');
        self::assertIsNumeric($sessionId);
        $ciphertext = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action)
            ->orderByDesc('id')
            ->value('token_ciphertext');
        self::assertIsString($ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
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

    /** @return array<string,int> */
    private function walletMutationCounts(): array
    {
        return [
            'accounts' => DB::table('ledger_accounts')->count(),
            'transactions' => DB::table('ledger_transactions')->count(),
            'entries' => DB::table('ledger_entries')->count(),
            'holds' => DB::table('wallet_holds')->count(),
        ];
    }

    /** @return array<string,int> */
    private function referralMutationCounts(): array
    {
        return [
            'identities' => DB::table('referral_identities')->count(),
            'relationships' => DB::table('referral_relationships')->count(),
            'events' => DB::table('referral_attribution_events')->count(),
        ];
    }
}
