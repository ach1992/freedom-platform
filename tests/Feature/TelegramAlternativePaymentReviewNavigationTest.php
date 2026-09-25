<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramAlternativePaymentReview;
use App\Modules\Telegram\Application\TelegramAlternativePaymentReviewCase;
use App\Modules\Telegram\Application\TelegramAlternativePaymentReviewEvidence;
use App\Modules\Telegram\Application\TelegramAlternativePaymentReviewNavigationHandler;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use App\Shared\Application\RestrictedValue;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class TelegramAlternativePaymentReviewFake implements TelegramAlternativePaymentReview
{
    public int $pendingCalls = 0;

    public int $findCalls = 0;

    public int $privateEvidenceCalls = 0;

    public function __construct(
        private readonly TelegramAlternativePaymentReviewCase $case,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $actorUserId > 0;
    }

    public function pending(int $actorUserId, int $limit = 15): array
    {
        $this->pendingCalls++;

        return [$this->case];
    }

    public function find(
        int $actorUserId,
        string $kind,
        string $reviewPublicId,
    ): TelegramAlternativePaymentReviewCase {
        $this->findCalls++;
        if ($kind !== $this->case->kind || ! hash_equals($this->case->reviewPublicId, strtoupper($reviewPublicId))) {
            throw new RuntimeException('Unexpected Telegram alternative-payment review lookup.');
        }

        return $this->case;
    }

    public function privateEvidence(
        int $actorUserId,
        string $kind,
        string $reviewPublicId,
    ): TelegramAlternativePaymentReviewEvidence {
        $this->privateEvidenceCalls++;

        return new TelegramAlternativePaymentReviewEvidence(
            $kind,
            strtoupper($reviewPublicId),
            match ($kind) {
                'c2c' => 'c2c_manual_submission',
                'gift_card' => 'gift_card_submission',
                'usdt' => 'usdt_txid_submission',
                default => throw new RuntimeException('Unexpected payment-review evidence kind.'),
            },
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            RestrictedValue::fromString('telegram-private-media:01ARZ3NDEKTSV4RRFFQ69G5FAW'),
            RestrictedValue::fromString(str_repeat('a', 64)),
        );
    }

    public function approveC2c(
        int $actorUserId,
        string $reviewPublicId,
        string $reservationPublicId,
        string $reason,
        string $requestKey,
    ): void {
        throw new RuntimeException('Approval is outside this navigation rendering test.');
    }

    public function approveGiftCard(
        int $actorUserId,
        string $reviewPublicId,
        string $externalRedemptionId,
        string $reason,
        string $requestKey,
    ): void {
        throw new RuntimeException('Approval is outside this navigation rendering test.');
    }

    public function approveUsdt(
        int $actorUserId,
        string $reviewPublicId,
        int $confirmations,
        string $transactionAt,
        string $reason,
        string $requestKey,
    ): void {
        throw new RuntimeException('Approval is outside this navigation rendering test.');
    }

    public function reject(
        int $actorUserId,
        string $kind,
        string $reviewPublicId,
        string $reason,
        string $requestKey,
    ): void {
        throw new RuntimeException('Rejection is outside this navigation rendering test.');
    }
}

/** @requirement C2C-002 C2C-005 GFT-002 GFT-004 USDT-003 ACL-002 PAY-002 PAY-003 SEC-002 SEC-003 DAT-003 QUA-004 */
final class TelegramAlternativePaymentReviewNavigationTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram alternative-payment review navigation verification requires MariaDB/MySQL.');
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

    public function test_owner_can_open_permission_filtered_payment_review_list_and_detail(): void
    {
        $reviewPublicId = strtoupper((string) Str::ulid());
        $subjectPublicId = strtoupper((string) Str::ulid());
        $reservationPublicId = strtoupper((string) Str::ulid());
        $fake = new TelegramAlternativePaymentReviewFake(new TelegramAlternativePaymentReviewCase(
            'c2c',
            $reviewPublicId,
            $subjectPublicId,
            'pending',
            'manual-receipt',
            'bank-ref-masked',
            910_000,
            'IRR',
            true,
            1,
            [$reservationPublicId],
            null,
            now('UTC')->format('Y-m-d H:i:s.u'),
        ));
        $this->app->instance(TelegramAlternativePaymentReview::class, $fake);

        $telegramUserId = 985001;
        $username = 'payment_review_admin';
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(9850, $telegramUserId, $username, 'fa', '/start'));
        $processor->process('123456789', 9850);

        $account = DB::table('telegram_accounts')
            ->where('telegram_user_id', $telegramUserId)
            ->first(['id', 'user_id']);
        self::assertNotNull($account);
        $this->ownerAdministrator((int) $account->user_id);

        $this->accept($this->payload(9851, $telegramUserId, $username, 'fa', '/menu'));
        $processor->process('123456789', 9851);

        $admin = $this->callbackToken('navigation.admin', (int) $account->id);
        $this->accept($this->callbackPayload(9852, $telegramUserId, $username, 'fa', $admin));
        $processor->process('123456789', 9852);
        self::assertSame('admin_control', $this->sessionState((int) $account->id));

        $reviews = $this->callbackToken(
            TelegramAlternativePaymentReviewNavigationHandler::ACTION_ENTRY,
            (int) $account->id,
        );
        $this->accept($this->callbackPayload(9853, $telegramUserId, $username, 'fa', $reviews));
        $processor->process('123456789', 9853);

        self::assertSame('admin_payment_reviews', $this->sessionState((int) $account->id));
        self::assertGreaterThanOrEqual(1, $fake->pendingCalls);
        $list = $this->latestConfidentialPresentation();
        self::assertStringContainsString($reviewPublicId, $list);
        self::assertStringContainsString($subjectPublicId, $list);
        self::assertStringContainsString('bank-ref-masked', $list);

        $open = $this->callbackToken(
            'navigation.admin.payment_reviews.select',
            (int) $account->id,
            ['kind' => 'c2c', 'review' => $reviewPublicId],
        );
        $this->accept($this->callbackPayload(9854, $telegramUserId, $username, 'fa', $open));
        $processor->process('123456789', 9854);

        self::assertSame('admin_payment_review_detail', $this->sessionState((int) $account->id));
        self::assertSame(1, $fake->findCalls);
        $detail = $this->latestConfidentialPresentation();
        self::assertStringContainsString($reviewPublicId, $detail);
        self::assertStringContainsString($reservationPublicId, $detail);

        $evidence = $this->callbackToken(
            'navigation.admin.payment_reviews.evidence',
            (int) $account->id,
        );
        $this->accept($this->callbackPayload(9855, $telegramUserId, $username, 'fa', $evidence));
        $processor->process('123456789', 9855);

        self::assertSame(0, $fake->privateEvidenceCalls, 'Raw private evidence must be resolved only at delivery time.');
        $protected = DB::table('telegram_delivery_operations')
            ->where('presentation_text', 'like', '[PROTECTED_TELEGRAM_REFERENCE:v4:payment_review_evidence:%')
            ->orderByDesc('id')
            ->value('presentation_text');
        self::assertIsString($protected);
        self::assertSame(
            '[PROTECTED_TELEGRAM_REFERENCE:v4:payment_review_evidence:c2c:'.$reviewPublicId.':fa]',
            $protected,
        );
        self::assertStringNotContainsString('telegram-private-media:', $protected);
        self::assertStringNotContainsString(str_repeat('a', 64), $protected);

        $sessionPayload = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->value('payload');
        self::assertIsString($sessionPayload);
        self::assertStringNotContainsString('telegram-private-media:', $sessionPayload);
        self::assertStringNotContainsString(str_repeat('a', 64), $sessionPayload);
        self::assertSame(0, DB::table('purchase_settlements')->count());
    }

    private function ownerAdministrator(int $userId): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string,mixed> */
    private function payload(
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
                'date' => 1_780_000_000,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => $username,
                    'language_code' => $languageCode,
                ],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    /** @return array<string,mixed> */
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
                    'date' => 1_780_000_000,
                    'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                ],
                'data' => $token,
            ],
        ];
    }

    /** @param array<string,mixed>|null $actionPayload */
    private function callbackToken(string $action, int $telegramAccountId, ?array $actionPayload = null): string
    {
        $sessionId = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('id');
        self::assertIsNumeric($sessionId);
        $query = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action);
        if ($actionPayload !== null) {
            $query->where('action_payload', json_encode($actionPayload, JSON_THROW_ON_ERROR));
        }
        $callback = $query->orderByDesc('id')->first(['token_ciphertext']);
        self::assertNotNull($callback);

        return $this->app->make(StringEncrypter::class)
            ->decryptString((string) $callback->token_ciphertext);
    }

    private function sessionState(int $telegramAccountId): string
    {
        $state = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('state');
        self::assertIsString($state);

        return $state;
    }

    private function latestConfidentialPresentation(): string
    {
        $operationPublicId = DB::table('telegram_delivery_operations')
            ->orderByDesc('id')
            ->value('public_id');
        self::assertIsString($operationPublicId);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
    }
}
