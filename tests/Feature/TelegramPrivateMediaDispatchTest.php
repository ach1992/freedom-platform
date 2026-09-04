<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardReceiptSubmission;
use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaFetcher;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardSubmission;
use App\Modules\Telegram\Application\TelegramInteractionDispatcher;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use App\Modules\Telegram\Application\TelegramPrivateMediaDownload;
use App\Modules\Telegram\Application\TelegramPrivateMediaIngestor;
use App\Modules\Telegram\Application\TelegramPrivateMediaInteractionGateway;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use App\Shared\Application\RestrictedValue;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class TelegramPrivateMediaDispatchFetcher implements TelegramPrivateMediaFetcher
{
    public int $calls = 0;

    public function __construct(private readonly string $content) {}

    public function fetch(
        RestrictedValue $fileId,
        RestrictedValue $expectedFileUniqueId,
        int $maximumBytes,
    ): TelegramPrivateMediaDownload {
        $this->calls++;

        return TelegramPrivateMediaDownload::fromBytes($this->content, strlen($this->content));
    }
}

final class TelegramPrivateMediaDispatchSubmission implements TelegramCustomerPurchaseCardToCardReceiptSubmission
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    private ?string $submissionPublicId = null;

    public int $transientFailuresRemaining = 0;

    public function __construct(
        public readonly string $paymentIntentPublicId,
        public readonly string $reservationPublicId,
    ) {}

    public function submitReceiptForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
        DateTimeImmutable $submittedAt,
        string $evidenceHash,
        string $privateReceiptReference,
        string $operationKey,
    ): TelegramCustomerPurchaseCardToCardSubmission {
        $this->calls[] = compact(
            'actorUserId',
            'subjectUserId',
            'reservationPublicId',
            'submittedAt',
            'evidenceHash',
            'privateReceiptReference',
            'operationKey',
        );

        if ($actorUserId !== $subjectUserId || ! hash_equals($this->reservationPublicId, $reservationPublicId)) {
            throw new RuntimeException('Fake receipt submission received invalid authority.');
        }
        if ($this->transientFailuresRemaining > 0) {
            $this->transientFailuresRemaining--;
            throw new RuntimeException('Simulated transient receipt submission failure.');
        }

        $replayed = $this->submissionPublicId !== null;
        $this->submissionPublicId ??= strtoupper((string) Str::ulid());

        return new TelegramCustomerPurchaseCardToCardSubmission(
            $this->submissionPublicId,
            $this->paymentIntentPublicId,
            $this->reservationPublicId,
            910_000,
            $replayed,
        );
    }
}

/** @requirement BUY-003 PAY-002 PAY-003 C2C-002 C2C-004 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-001 QUA-004 */
final class TelegramPrivateMediaDispatchTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram private-media dispatch verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
        $this->seed();
        Queue::fake();
        Storage::fake('telegram_private_media');
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
            'telegram.private_media_max_bytes' => 1_048_576,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_private_media_dispatch_fail_processed_89003');
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_private_photo_is_bound_to_active_c2c_session_and_replay_creates_one_pending_submission(): void
    {
        $telegramUserId = 9821;
        [$userId, $accountId, $sessionPublicId] = $this->startActor($telegramUserId);
        $paymentIntentPublicId = strtoupper((string) Str::ulid());
        $reservationPublicId = strtoupper((string) Str::ulid());
        $this->moveToC2cInstructions($accountId, $sessionPublicId, $paymentIntentPublicId, $reservationPublicId);

        $png = $this->onePixelPng();
        $fetcher = new TelegramPrivateMediaDispatchFetcher($png);
        $submission = new TelegramPrivateMediaDispatchSubmission($paymentIntentPublicId, $reservationPublicId);
        $this->bindMediaDependencies($fetcher, $submission);

        $fileId = 'private-provider-file-dispatch-1';
        $fileUniqueId = 'private-provider-unique-dispatch-1';
        $this->accept($this->photoPayload(89001, $telegramUserId, $fileId, $fileUniqueId, strlen($png)));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 89001);
        $processor->process('123456789', 89001);

        self::assertSame(1, $fetcher->calls);
        self::assertCount(1, $submission->calls);
        self::assertSame($userId, $submission->calls[0]['actorUserId']);
        self::assertSame($userId, $submission->calls[0]['subjectUserId']);
        self::assertSame($reservationPublicId, $submission->calls[0]['reservationPublicId']);
        self::assertSame(hash('sha256', $png), $submission->calls[0]['evidenceHash']);
        self::assertMatchesRegularExpression('/\Atelegram-private-media:[0-9A-HJKMNP-TV-Z]{26}\z/', (string) $submission->calls[0]['privateReceiptReference']);

        $active = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount($accountId);
        self::assertNotNull($active);
        self::assertSame('purchase_card_to_card_submitted', $active->state);
        self::assertSame([
            'c2c_reservation_public_id' => $reservationPublicId,
            'payment_intent_public_id' => $paymentIntentPublicId,
        ], $active->payload);
        self::assertSame(1, DB::table('telegram_private_media')->count());
        self::assertSame('associated', DB::table('telegram_private_media')->value('state'));
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 89001)->count());

        $durable = $this->durableOrdinaryTelegramEvidence();
        self::assertStringNotContainsString($fileId, $durable);
        self::assertStringNotContainsString($fileUniqueId, $durable);
        self::assertStringNotContainsString(hash('sha256', $png), $durable);
        self::assertStringNotContainsString((string) $submission->calls[0]['privateReceiptReference'], $durable);

        $media = DB::table('telegram_private_media')->first();
        self::assertNotNull($media);
        self::assertStringNotContainsString($fileId, (string) $media->encrypted_file_id);
        self::assertStringNotContainsString($fileUniqueId, (string) $media->encrypted_file_unique_id);
        Storage::disk('telegram_private_media')->assertExists((string) $media->storage_path);

        $this->accept($this->textPayload(89011, $telegramUserId, '/menu'));
        $processor->process('123456789', 89011);
        $home = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount($accountId);
        self::assertNotNull($home);
        self::assertSame(TelegramNavigationEntryGateway::STATE, $home->state);
        self::assertSame([], $home->payload);
    }

    public function test_transient_submission_failure_preserves_private_media_for_safe_retry(): void
    {
        $telegramUserId = 9824;
        [, $accountId, $sessionPublicId] = $this->startActor($telegramUserId);
        $paymentIntentPublicId = strtoupper((string) Str::ulid());
        $reservationPublicId = strtoupper((string) Str::ulid());
        $this->moveToC2cInstructions($accountId, $sessionPublicId, $paymentIntentPublicId, $reservationPublicId);

        $png = $this->onePixelPng();
        $fetcher = new TelegramPrivateMediaDispatchFetcher($png);
        $submission = new TelegramPrivateMediaDispatchSubmission($paymentIntentPublicId, $reservationPublicId);
        $submission->transientFailuresRemaining = 1;
        $this->bindMediaDependencies($fetcher, $submission);

        $this->accept($this->photoPayload(
            89004,
            $telegramUserId,
            'private-provider-file-dispatch-transient',
            'private-provider-unique-dispatch-transient',
            strlen($png),
        ));
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        try {
            $processor->process('123456789', 89004);
            self::fail('The simulated transient submission failure must keep the update retryable.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }

        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 89004,
            'state' => 'failed',
            'attempt_count' => 1,
        ]);
        self::assertSame(1, $fetcher->calls);
        self::assertCount(1, $submission->calls);
        self::assertSame('stored', DB::table('telegram_private_media')->where('update_id', 89004)->value('state'));
        $storedPath = (string) DB::table('telegram_private_media')->where('update_id', 89004)->value('storage_path');
        Storage::disk('telegram_private_media')->assertExists($storedPath);
        self::assertSame(
            'purchase_card_to_card_instructions',
            DB::table('telegram_interaction_sessions')
                ->where('telegram_account_id', $accountId)
                ->where('status', 'active')
                ->value('state'),
            'Transient failure must roll back the session claim with the payment attempt.',
        );

        $processor->process('123456789', 89004);

        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 89004,
            'state' => 'processed',
            'attempt_count' => 2,
        ]);
        self::assertSame(1, $fetcher->calls, 'Stored private media must be replayed without provider redownload.');
        self::assertCount(2, $submission->calls);
        self::assertSame($submission->calls[0]['operationKey'], $submission->calls[1]['operationKey']);
        self::assertSame($submission->calls[0]['privateReceiptReference'], $submission->calls[1]['privateReceiptReference']);
        self::assertSame('associated', DB::table('telegram_private_media')->where('update_id', 89004)->value('state'));
        Storage::disk('telegram_private_media')->assertExists($storedPath);
        $active = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount($accountId);
        self::assertNotNull($active);
        self::assertSame('purchase_card_to_card_submitted', $active->state);
        self::assertSame([
            'c2c_reservation_public_id' => $reservationPublicId,
            'payment_intent_public_id' => $paymentIntentPublicId,
        ], $active->payload);
    }

    public function test_post_dispatch_failure_replays_bound_media_submission_and_acknowledgement_without_duplicates(): void
    {
        $telegramUserId = 9823;
        [, $accountId, $sessionPublicId] = $this->startActor($telegramUserId);
        $paymentIntentPublicId = strtoupper((string) Str::ulid());
        $reservationPublicId = strtoupper((string) Str::ulid());
        $this->moveToC2cInstructions($accountId, $sessionPublicId, $paymentIntentPublicId, $reservationPublicId);

        $png = $this->onePixelPng();
        $fetcher = new TelegramPrivateMediaDispatchFetcher($png);
        $submission = new TelegramPrivateMediaDispatchSubmission($paymentIntentPublicId, $reservationPublicId);
        $this->bindMediaDependencies($fetcher, $submission);

        $this->accept($this->photoPayload(
            89003,
            $telegramUserId,
            'private-provider-file-dispatch-retry',
            'private-provider-unique-dispatch-retry',
            strlen($png),
        ));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $operationsBefore = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_private_media_dispatch_fail_processed_89003
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 89003 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-private-media-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 89003);
                self::fail('The simulated post-dispatch failure must keep the private-media update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_private_media_dispatch_fail_processed_89003');
        }

        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 89003,
            'state' => 'failed',
            'attempt_count' => 1,
        ]);
        self::assertSame(1, $fetcher->calls);
        self::assertCount(1, $submission->calls);
        self::assertSame(1, DB::table('telegram_private_media')->count());
        self::assertSame('associated', DB::table('telegram_private_media')->value('state'));
        $operationsAfterFailure = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        self::assertSame($operationsBefore + 1, $operationsAfterFailure);
        $transitionSessionId = (int) DB::table('telegram_interaction_sessions')
            ->where('public_id', $sessionPublicId)
            ->value('id');
        $transitionCountAfterFailure = DB::table('telegram_interaction_transitions')
            ->where('telegram_interaction_session_id', $transitionSessionId)
            ->count();

        $processor->process('123456789', 89003);

        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 89003,
            'state' => 'processed',
            'attempt_count' => 2,
        ]);
        self::assertSame(1, $fetcher->calls, 'Accepted media must be replayed from private persistence without redownload.');
        self::assertCount(2, $submission->calls, 'The owning financial contract may be re-entered only with the same idempotency identity.');
        self::assertSame($submission->calls[0]['operationKey'], $submission->calls[1]['operationKey']);
        self::assertSame($submission->calls[0]['privateReceiptReference'], $submission->calls[1]['privateReceiptReference']);
        self::assertSame(1, DB::table('telegram_private_media')->count());
        self::assertSame('associated', DB::table('telegram_private_media')->value('state'));
        self::assertSame(
            $operationsAfterFailure,
            DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count(),
            'Receipt acknowledgement delivery must replay rather than duplicate.',
        );
        self::assertSame(
            $transitionCountAfterFailure,
            DB::table('telegram_interaction_transitions')->where('telegram_interaction_session_id', $transitionSessionId)->count(),
            'Session transitions must replay rather than duplicate after processor recovery.',
        );
        $active = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount($accountId);
        self::assertNotNull($active);
        self::assertSame('purchase_card_to_card_submitted', $active->state);
        self::assertSame([
            'c2c_reservation_public_id' => $reservationPublicId,
            'payment_intent_public_id' => $paymentIntentPublicId,
        ], $active->payload);
    }

    public function test_group_or_non_owner_media_is_rejected_before_private_file_or_submission_effect(): void
    {
        $telegramUserId = 9822;
        [, $accountId, $sessionPublicId] = $this->startActor($telegramUserId);
        $paymentIntentPublicId = strtoupper((string) Str::ulid());
        $reservationPublicId = strtoupper((string) Str::ulid());
        $this->moveToC2cInstructions($accountId, $sessionPublicId, $paymentIntentPublicId, $reservationPublicId);

        $fetcher = new TelegramPrivateMediaDispatchFetcher($this->onePixelPng());
        $submission = new TelegramPrivateMediaDispatchSubmission($paymentIntentPublicId, $reservationPublicId);
        $this->bindMediaDependencies($fetcher, $submission);

        $payload = $this->photoPayload(89002, $telegramUserId, 'group-file-id', 'group-unique-id', 68);
        $payload['message']['chat'] = ['id' => -100123456789, 'type' => 'supergroup'];
        $this->accept($payload);
        $this->app->make(TelegramUpdateProcessor::class)->process('123456789', 89002);

        self::assertSame(0, $fetcher->calls);
        self::assertSame([], $submission->calls);
        self::assertSame(0, DB::table('telegram_private_media')->count());
        self::assertSame(0, DB::table('telegram_interaction_update_bindings')->where('update_id', 89002)->count());
        self::assertSame('purchase_card_to_card_instructions', DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->where('status', 'active')->value('state'));
    }

    /** @return array{0:int,1:int,2:string} */
    private function startActor(int $telegramUserId): array
    {
        $this->accept($this->textPayload(88990 + ($telegramUserId % 10), $telegramUserId, '/start'));
        $this->app->make(TelegramUpdateProcessor::class)->process('123456789', 88990 + ($telegramUserId % 10));
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $session = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount((int) $account->id);
        self::assertNotNull($session);

        return [(int) $account->user_id, (int) $account->id, $session->publicId];
    }

    private function moveToC2cInstructions(
        int $accountId,
        string $sessionPublicId,
        string $paymentIntentPublicId,
        string $reservationPublicId,
    ): void {
        $sessions = $this->app->make(TelegramInteractionSessionService::class);
        $active = $sessions->activeForAccount($accountId);
        self::assertNotNull($active);
        $sessions->transition(
            $sessionPublicId,
            $active->version,
            'purchase_card_to_card_instructions',
            [
                'payment_method_code' => 'card_to_card',
                'payment_intent_public_id' => $paymentIntentPublicId,
                'c2c_reservation_public_id' => $reservationPublicId,
            ],
            'private-media-test-to-c2c:'.hash('sha256', $sessionPublicId),
        );
    }

    private function bindMediaDependencies(
        TelegramPrivateMediaDispatchFetcher $fetcher,
        TelegramPrivateMediaDispatchSubmission $submission,
    ): void {
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        $this->app->instance(TelegramCustomerPurchaseCardToCardReceiptSubmission::class, $submission);
        foreach ([
            TelegramPrivateMediaIngestor::class,
            TelegramPrivateMediaInteractionGateway::class,
            TelegramInteractionDispatcher::class,
            TelegramUpdateProcessor::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    /** @param array<string,mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string,mixed> */
    private function textPayload(int $updateId, int $telegramUserId, string $text): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'date' => 1_780_000_000,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => 'private_media_dispatch',
                    'language_code' => 'fa',
                ],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function photoPayload(
        int $updateId,
        int $telegramUserId,
        string $fileId,
        string $fileUniqueId,
        int $fileSize,
    ): array {
        $payload = $this->textPayload($updateId, $telegramUserId, 'unused');
        unset($payload['message']['text']);
        $payload['message']['photo'] = [[
            'file_id' => $fileId,
            'file_unique_id' => $fileUniqueId,
            'width' => 1,
            'height' => 1,
            'file_size' => $fileSize,
        ]];

        return $payload;
    }

    private function durableOrdinaryTelegramEvidence(): string
    {
        $tables = [
            'processed_telegram_updates',
            'telegram_interaction_sessions',
            'telegram_interaction_transitions',
            'telegram_interaction_update_bindings',
            'telegram_interaction_callbacks',
            'telegram_delivery_operations',
            'outbox_messages',
        ];
        $parts = [];
        foreach ($tables as $table) {
            $parts[] = json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR);
        }

        return implode("\n", $parts);
    }

    private function onePixelPng(): string
    {
        $decoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
            true,
        );
        if (! is_string($decoded)) {
            throw new RuntimeException('PNG test fixture could not be decoded.');
        }

        return $decoded;
    }
}
