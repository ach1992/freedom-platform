<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\TelegramChannelMembershipResolutionRequest;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleResolver;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardDestination;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardReservation;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryOperationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramMembershipJoinPresentationResolver;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use App\Modules\Telegram\Application\TelegramProtectedPresentationResolver;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Modules\Telegram\Infrastructure\HttpProtectedTelegramMessageSender;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\TelegramMembershipAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Support\ConfidentialTelegramPresentationTestFactory;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;
use Tests\Support\TelegramInteractivePresentationTestFactory;
use Tests\TestCase;

final class TelegramMembershipJoinPresentationTestClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-11T08:00:00+00:00');
    }
}

final readonly class TelegramMembershipJoinPresentationTestRuntime implements TelegramDeliveryRuntime
{
    public function botId(): string
    {
        return '123456';
    }
}

final class TelegramMembershipJoinPresentationTestTransport implements TelegramMutationTransport
{
    public int $attempts = 0;

    public function mutate(TelegramMutationRequest $request): TelegramMutationResult
    {
        $this->attempts++;
        throw new RuntimeException('Generic Telegram transport must not receive membership join presentation delivery.');
    }
}

final readonly class TelegramMembershipJoinPresentationNeverCardToCard implements TelegramCustomerPurchaseCardToCardPayment
{
    public function reserveForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseCardToCardReservation {
        throw new RuntimeException('Membership join presentation must not create card-to-card authority.');
    }

    public function destinationForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
    ): TelegramCustomerPurchaseCardToCardDestination {
        throw new RuntimeException('Membership join presentation must not resolve card-to-card authority.');
    }
}

/** @requirement ONB-003 CHN-001 SEC-001 SEC-003 SEC-008 DAT-003 QUA-001 QUA-004 QUA-007 */
final class TelegramMembershipJoinPresentationTest extends TestCase
{
    use DatabaseTruncation;

    private TelegramMembershipJoinPresentationTestClock $clock;

    private TelegramMembershipJoinPresentationTestRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Protected Telegram membership join presentation verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(TelegramMembershipAccessFoundationSeeder::class);
        $this->clock = new TelegramMembershipJoinPresentationTestClock;
        $this->runtime = new TelegramMembershipJoinPresentationTestRuntime;
    }

    public function test_reference_preserves_card_to_card_v1_and_membership_v2_contains_only_safe_identity(): void
    {
        $reservationPublicId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $legacy = TelegramProtectedPresentationReference::cardToCardDestination($reservationPublicId, 'en');
        self::assertSame(
            '[PROTECTED_TELEGRAM_REFERENCE:v1:card_to_card_destination:01ARZ3NDEKTSV4RRFFQ69G5FAV:en]',
            $legacy->durableText(),
        );
        self::assertSame($legacy->durableText(), TelegramProtectedPresentationReference::restore($legacy->durableText())->durableText());

        $configurationHash = str_repeat('a', 64);
        $reference = TelegramProtectedPresentationReference::membershipJoinPrompt(
            'bot_entry',
            null,
            $configurationHash,
            'fa',
        );
        $durable = $reference->durableText();

        self::assertSame(
            '[PROTECTED_TELEGRAM_REFERENCE:v2:membership_join_prompt:bot_entry:-:'.$configurationHash.':fa]',
            $durable,
        );
        self::assertSame($durable, TelegramProtectedPresentationReference::restore($durable)->durableText());
        self::assertSame('[PROTECTED_TELEGRAM_REFERENCE]', (string) $reference);
        self::assertSame(['redacted' => true, 'type' => 'protected_reference'], $reference->__debugInfo());
        self::assertStringNotContainsString('telegram_user_id', $durable);
        self::assertStringNotContainsString('user_id', $durable);
        $this->assertSerializationRejected($reference);
    }

    public function test_resolution_is_read_only_redis_free_http_free_and_invite_plaintext_is_redacted(): void
    {
        $privateUrl = 'https://t.me/+PrivateJoinSecret290A';
        $publicUrl = 'https://t.me/public_channel_290';
        $private = $this->activeChannel('join-private-290', -1002900000001, 'private', 'Private 290', $privateUrl);
        $public = $this->activeChannel('join-public-290', -1002900000002, 'public', 'Public 290', $publicUrl);
        $userId = $this->customerWithTelegram(790000001);
        $this->activeRule('join-read-only-290', [$private, $public]);
        $reference = $this->referenceFor($userId, 'fa');

        /** @var list<string> $sqlStatements */
        $sqlStatements = [];
        DB::listen(static function (QueryExecuted $query) use (&$sqlStatements): void {
            $sqlStatements[] = $query->sql;
        });

        /** @var list<string> $redisCommands */
        $redisCommands = [];
        Redis::purge('default');
        Redis::purge('cache');
        Redis::enableEvents();
        $redis = Redis::connection('cache');
        $redis->listen(static function (CommandExecuted $command) use (&$redisCommands): void {
            $redisCommands[] = $command->connectionName.':'.strtolower($command->command);
        });
        $redis->command('ping');
        self::assertSame(['cache:ping'], $redisCommands, 'Authenticated Redis command capture must be active before resolution.');
        $redisCommands = [];
        Http::fake();

        $presentation = $this->membershipResolver()->resolveForSelf($userId, $reference);

        self::assertTrue($presentation->hasHttpsUrlButtons());
        self::assertCount(2, $presentation->httpsUrlButtons());
        self::assertSame('عضویت در Private 290', $presentation->httpsUrlButtons()[0]->text);
        self::assertSame('عضویت در Public 290', $presentation->httpsUrlButtons()[1]->text);
        self::assertStringContainsString('برای ادامه', $presentation->text());
        self::assertSame('[PROTECTED_TELEGRAM_PRESENTATION]', (string) $presentation);
        self::assertSame(['redacted' => true, 'type' => 'text'], $presentation->__debugInfo());

        $inspectable = var_export($presentation, true)
            .print_r($presentation->httpsUrlButtons()[0], true)
            .json_encode($presentation->httpsUrlButtons()[0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($privateUrl, $inspectable);
        self::assertStringNotContainsString($publicUrl, $inspectable);
        self::assertSame('[PROTECTED_TELEGRAM_HTTPS_URL_BUTTON]', (string) $presentation->httpsUrlButtons()[0]);
        self::assertSame(
            ['redacted' => true, 'type' => 'https_url_button', 'text' => 'عضویت در Private 290'],
            $presentation->httpsUrlButtons()[0]->__debugInfo(),
        );
        try {
            $presentation->httpsUrlButtons()[0]->revealHttpsUrlForProvider();
            self::fail('Protected join URL must not be revealable outside the exact provider gateway.');
        } catch (LogicException $exception) {
            self::assertSame(
                'Protected Telegram HTTPS URL reveal is restricted to the exact provider gateway.',
                $exception->getMessage(),
            );
        }
        $this->assertSerializationRejected($presentation);
        $this->assertSerializationRejected($presentation->httpsUrlButtons()[0]);

        self::assertNotEmpty($sqlStatements, 'Membership join resolution must exercise its database read path.');
        foreach ($sqlStatements as $sql) {
            self::assertMatchesRegularExpression('/^\s*select\b/i', $sql, 'Resolver executed non-read-only SQL: '.$sql);
        }
        self::assertSame([], $redisCommands, 'Membership join resolution must not execute Redis/cache commands.');
        Http::assertNothingSent();
    }

    public function test_membership_v2_reference_replay_is_exact_and_changed_reference_semantics_conflict(): void
    {
        $joinUrl = 'https://t.me/+PrivateJoinSecret290Replay';
        $channelId = $this->activeChannel('join-replay-290', -1002900000009, 'private', 'Replay 290', $joinUrl);
        $userId = $this->customerWithTelegram(790000009);
        $this->activeRule('join-replay-rule-290', [$channelId]);
        $reference = $this->referenceFor($userId, 'en');
        $queue = $this->queue();

        $created = NonRestrictedTelegramPresentationTestFactory::queueProtectedReference(
            $queue,
            TelegramDeliveryAction::Send,
            790000009,
            $reference,
            'membership-join-replay-290-001',
            'correlation-membership-join-replay-290',
        );
        self::assertFalse($created->replayed);

        $replayed = NonRestrictedTelegramPresentationTestFactory::queueProtectedReference(
            $queue,
            TelegramDeliveryAction::Send,
            790000009,
            TelegramProtectedPresentationReference::restore($reference->durableText()),
            'membership-join-replay-290-001',
            'correlation-membership-join-replay-290',
        );
        self::assertTrue($replayed->replayed);
        self::assertSame($created->publicId, $replayed->publicId);
        self::assertSame($created->outboxEventId, $replayed->outboxEventId);
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());

        $operation = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first();
        self::assertNotNull($operation);
        self::assertSame($reference->durableText(), (string) $operation->presentation_text);
        self::assertStringNotContainsString($joinUrl, (string) $operation->request_fingerprint);

        try {
            NonRestrictedTelegramPresentationTestFactory::queueProtectedReference(
                $queue,
                TelegramDeliveryAction::Send,
                790000009,
                TelegramProtectedPresentationReference::membershipJoinPrompt(
                    'bot_entry',
                    null,
                    str_repeat('b', 64),
                    'en',
                ),
                'membership-join-replay-290-001',
                'correlation-membership-join-replay-290',
            );
            self::fail('Changed membership protected-reference semantics must conflict on the same request key.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('conflicting semantics', $exception->getMessage());
        }

        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
    }

    public function test_protected_delivery_persists_no_join_secret_and_reveals_ordered_links_only_to_provider(): void
    {
        $privateUrl = 'https://t.me/+PrivateJoinSecret290B';
        $publicUrl = 'https://telegram.me/public_channel_291';
        $private = $this->activeChannel('join-delivery-private-290', -1002900000011, 'private', 'Private Delivery', $privateUrl);
        $public = $this->activeChannel('join-delivery-public-290', -1002900000012, 'public', 'Public Delivery', $publicUrl);
        $userId = $this->customerWithTelegram(790000011);
        $this->activeRule('join-delivery-rule-290', [$private, $public]);
        $reference = $this->referenceFor($userId, 'en');

        $created = NonRestrictedTelegramPresentationTestFactory::queueProtectedReference(
            $this->queue(),
            TelegramDeliveryAction::Send,
            790000011,
            $reference,
            'membership-join-delivery-290-001',
            'correlation-membership-join-delivery-290',
        );
        $privateCiphertext = (string) DB::table('required_channels')->where('id', $private)->value('join_url_ciphertext');
        $publicCiphertext = (string) DB::table('required_channels')->where('id', $public)->value('join_url_ciphertext');
        $operation = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first();
        $outbox = DB::table('outbox_messages')->where('id', $created->outboxEventId)->first();
        self::assertNotNull($operation);
        self::assertNotNull($outbox);
        self::assertSame($reference->durableText(), (string) $operation->presentation_text);
        self::assertSame(0, DB::table('telegram_delivery_interactive_presentations')->count());
        $durableBefore = json_encode([$operation, $outbox], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([$privateUrl, $publicUrl, $privateCiphertext, $publicCiphertext] as $restricted) {
            self::assertStringNotContainsString($restricted, $durableBefore);
        }

        Http::fake(['*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 29011],
        ], 200)]);
        $generic = new TelegramMembershipJoinPresentationTestTransport;
        $result = $this->executor($generic)->execute(
            $created->publicId,
            $created->outboxEventId,
            'correlation-membership-join-delivery-290',
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
        );

        self::assertSame(TelegramDeliveryOperationState::Succeeded, $result->state);
        self::assertSame(0, $generic->attempts);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($privateUrl, $publicUrl): bool {
            $data = $request->data();

            return str_ends_with($request->url(), '/sendMessage')
                && ($data['chat_id'] ?? null) === 790000011
                && ($data['protect_content'] ?? null) === true
                && ($data['link_preview_options']['is_disabled'] ?? null) === true
                && ($data['reply_markup']['inline_keyboard'] ?? null) === [
                    [[
                        'text' => 'Join Private Delivery',
                        'url' => $privateUrl,
                    ]],
                    [[
                        'text' => 'Join Public Delivery',
                        'url' => $publicUrl,
                    ]],
                ];
        });

        $operationAfter = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first();
        $outboxAfter = DB::table('outbox_messages')->where('id', $created->outboxEventId)->first();
        self::assertNotNull($operationAfter);
        self::assertNotNull($outboxAfter);
        self::assertSame('succeeded', (string) $operationAfter->state);
        self::assertSame(1, (int) $operationAfter->provider_attempts);
        self::assertSame(0, DB::table('telegram_delivery_interactive_presentations')->count());
        $durableAfter = json_encode([$operationAfter, $outboxAfter], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            .json_encode(DB::table('audit_logs')->get()->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            .json_encode(DB::table('telegram_interaction_sessions')->get()->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([$privateUrl, $publicUrl, $privateCiphertext, $publicCiphertext] as $restricted) {
            self::assertStringNotContainsString($restricted, $durableAfter);
        }
    }

    public function test_configuration_drift_after_reference_creation_fails_closed_before_provider(): void
    {
        $joinUrl = 'https://t.me/+PrivateJoinSecret290C';
        $channelId = $this->activeChannel('join-drift-290', -1002900000021, 'private', 'Drift 290', $joinUrl);
        $userId = $this->customerWithTelegram(790000021);
        $this->activeRule('join-drift-rule-290', [$channelId]);
        $reference = $this->referenceFor($userId, 'en');
        $this->disableChannel($channelId);
        Http::fake();

        try {
            $this->membershipResolver()->resolveForSelf($userId, $reference);
            self::fail('Changed membership configuration must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('Protected Telegram membership configuration changed.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_current_rule_with_no_active_join_target_fails_closed_before_provider(): void
    {
        $joinUrl = 'https://t.me/+PrivateJoinSecret290D';
        $channelId = $this->activeChannel('join-no-target-290', -1002900000031, 'private', 'No Target 290', $joinUrl);
        $userId = $this->customerWithTelegram(790000031);
        $this->activeRule('join-no-target-rule-290', [$channelId]);
        $this->disableChannel($channelId);
        $reference = $this->referenceFor($userId, 'en');
        Http::fake();

        try {
            $this->membershipResolver()->resolveForSelf($userId, $reference);
            self::fail('Membership join resolution without an active safe target must fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('Protected Telegram membership join targets are unavailable.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_join_secret_hash_tamper_is_definitive_outbox_failure_without_provider_attempt(): void
    {
        $joinUrl = 'https://t.me/+PrivateJoinSecret290E';
        $channelId = $this->activeChannel(
            'join-hash-tamper-290',
            -1002900000041,
            'private',
            'Hash Tamper 290',
            $joinUrl,
            hash('sha256', 'https://t.me/+DifferentJoinSecret290'),
        );
        $userId = $this->customerWithTelegram(790000041);
        $this->activeRule('join-hash-tamper-rule-290', [$channelId]);
        $reference = $this->referenceFor($userId, 'en');
        $created = NonRestrictedTelegramPresentationTestFactory::queueProtectedReference(
            $this->queue(),
            TelegramDeliveryAction::Send,
            790000041,
            $reference,
            'membership-join-hash-tamper-290-001',
            'correlation-membership-join-hash-tamper-290',
        );
        Http::fake();
        $generic = new TelegramMembershipJoinPresentationTestTransport;
        $before = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first([
            'state',
            'state_version',
            'provider_attempts',
            'provider_boundary_started_at',
            'completed_at',
            'result_code',
        ]);
        self::assertNotNull($before);
        $executor = $this->executor($generic);
        $handler = new TelegramDeliveryOutboxHandler(
            static fn (): TelegramDeliveryOperationExecutor => $executor,
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
        );

        $outcome = $handler->handle(new OutboxMessage(
            $created->outboxEventId,
            TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$created->publicId,
            TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE,
            TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE,
            $created->publicId,
            ['telegram_delivery_operation_public_id' => $created->publicId],
            'correlation-membership-join-hash-tamper-290',
            1,
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
        ));

        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $outcome);
        self::assertSame(0, $generic->attempts);
        Http::assertNothingSent();
        $operation = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first();
        self::assertNotNull($operation);
        self::assertSame('prepared', (string) $operation->state);
        self::assertSame((int) $before->state_version, (int) $operation->state_version);
        self::assertSame(0, (int) $operation->provider_attempts);
        self::assertNull($operation->provider_boundary_started_at);
        self::assertNull($operation->completed_at);
        self::assertNull($operation->result_code);
    }

    public function test_decrypted_non_telegram_join_url_fails_closed_before_provider(): void
    {
        $joinUrl = 'https://example.test/not-a-telegram-invite';
        $channelId = $this->activeChannel('join-invalid-url-290', -1002900000051, 'private', 'Invalid URL 290', $joinUrl);
        $userId = $this->customerWithTelegram(790000051);
        $this->activeRule('join-invalid-url-rule-290', [$channelId]);
        $reference = $this->referenceFor($userId, 'en');
        Http::fake();

        try {
            $this->membershipResolver()->resolveForSelf($userId, $reference);
            self::fail('Non-Telegram membership join URLs must fail canonical validation.');
        } catch (DomainException $exception) {
            self::assertSame('Protected Telegram membership join URL is invalid.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_overlong_join_button_label_is_definitive_outbox_failure_without_provider_attempt(): void
    {
        $joinUrl = 'https://t.me/+PrivateJoinSecret290F';
        $channelId = $this->activeChannel(
            'join-long-label-290',
            -1002900000061,
            'private',
            str_repeat('L', 80),
            $joinUrl,
        );
        $userId = $this->customerWithTelegram(790000061);
        $this->activeRule('join-long-label-rule-290', [$channelId]);
        $reference = $this->referenceFor($userId, 'en');
        $created = NonRestrictedTelegramPresentationTestFactory::queueProtectedReference(
            $this->queue(),
            TelegramDeliveryAction::Send,
            790000061,
            $reference,
            'membership-join-long-label-290-001',
            'correlation-membership-join-long-label-290',
        );
        Http::fake();
        $generic = new TelegramMembershipJoinPresentationTestTransport;
        $before = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first([
            'state',
            'state_version',
            'provider_attempts',
            'provider_boundary_started_at',
            'completed_at',
            'result_code',
        ]);
        self::assertNotNull($before);
        $executor = $this->executor($generic);
        $handler = new TelegramDeliveryOutboxHandler(
            static fn (): TelegramDeliveryOperationExecutor => $executor,
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
        );

        $outcome = $handler->handle(new OutboxMessage(
            $created->outboxEventId,
            TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$created->publicId,
            TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE,
            TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE,
            $created->publicId,
            ['telegram_delivery_operation_public_id' => $created->publicId],
            'correlation-membership-join-long-label-290',
            1,
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
        ));

        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $outcome);
        self::assertSame(0, $generic->attempts);
        Http::assertNothingSent();
        $operation = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first();
        self::assertNotNull($operation);
        self::assertSame('prepared', (string) $operation->state);
        self::assertSame((int) $before->state_version, (int) $operation->state_version);
        self::assertSame(0, (int) $operation->provider_attempts);
        self::assertNull($operation->provider_boundary_started_at);
        self::assertNull($operation->completed_at);
        self::assertNull($operation->result_code);
    }

    private function referenceFor(int $userId, string $locale): TelegramProtectedPresentationReference
    {
        $plan = $this->rules()->resolve(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));
        if (! $plan->required) {
            throw new RuntimeException('Membership join test fixture unexpectedly resolved no requirement.');
        }

        return TelegramProtectedPresentationReference::membershipJoinPrompt(
            'bot_entry',
            null,
            $plan->configurationHash,
            $locale,
        );
    }

    private function membershipResolver(): TelegramMembershipJoinPresentationResolver
    {
        return new TelegramMembershipJoinPresentationResolver(
            $this->app->make(DatabaseManager::class),
            $this->app->make(StringEncrypter::class),
            $this->rules(),
            $this->app->make(Translator::class),
        );
    }

    private function rules(): TelegramChannelMembershipRuleResolver
    {
        return new TelegramChannelMembershipRuleResolver(
            $this->app->make(DatabaseManager::class),
            $this->clock,
        );
    }

    private function queue(): TelegramDeliveryQueueService
    {
        $database = $this->app->make(DatabaseManager::class);

        return new TelegramDeliveryQueueService(
            $database,
            $this->clock,
            new DatabaseOutboxPublisher($database, $this->clock),
            $this->runtime,
            new TelegramDeliveryDatabaseCapability,
            TelegramInteractivePresentationTestFactory::service($this->clock, $this->runtime),
            ConfidentialTelegramPresentationTestFactory::service($this->clock),
        );
    }

    private function executor(TelegramMutationTransport $transport): TelegramDeliveryOperationExecutor
    {
        $database = $this->app->make(DatabaseManager::class);

        return new TelegramDeliveryOperationExecutor(
            $database,
            $this->clock,
            $this->runtime,
            $transport,
            new TelegramDeliveryDatabaseCapability,
            TelegramInteractivePresentationTestFactory::service($this->clock, $this->runtime),
            ConfidentialTelegramPresentationTestFactory::service($this->clock),
            new HttpProtectedTelegramMessageSender(
                $this->app->make(Factory::class),
                new TelegramRuntimeConfiguration(
                    botToken: '123456:abcdefghijklmnopqrstuvwxyzABCDE',
                    botId: '123456',
                    webhookSecret: str_repeat('w', 32),
                    webhookUrl: 'https://example.test/api/telegram/webhook',
                    maximumBodyBytes: 1_048_576,
                    queue: 'telegram-ingress',
                    processingLeaseSeconds: 120,
                    apiBaseUrl: 'https://api.telegram.org',
                    apiTimeoutSeconds: 15,
                ),
            ),
            new TelegramProtectedPresentationResolver(
                new TelegramMembershipJoinPresentationNeverCardToCard,
                $this->app->make(Translator::class),
                $this->membershipResolver(),
            ),
        );
    }

    /** @param list<int> $channelIds */
    private function activeRule(string $key, array $channelIds): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $ruleId = (int) DB::table('channel_membership_rules')->insertGetId([
            'rule_key' => $key,
            'action' => 'bot_entry',
            'audience' => 'customers',
            'tier_code' => null,
            'customer_tag_id' => null,
            'plan_offering_id' => null,
            'match_mode' => 'all',
            'failure_policy' => 'fail_closed',
            'priority' => 100,
            'effective_from' => null,
            'effective_until' => null,
            'state' => 'draft',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($channelIds as $sortOrder => $channelId) {
            DB::table('channel_membership_rule_channels')->insert([
                'channel_membership_rule_id' => $ruleId,
                'required_channel_id' => $channelId,
                'sort_order' => $sortOrder,
                'created_at' => $now,
            ]);
        }
        DB::table('channel_membership_rules')->where('id', $ruleId)->update([
            'state' => 'active',
            'version' => 2,
            'updated_at' => $now,
        ]);

        return $ruleId;
    }

    private function activeChannel(
        string $key,
        int $chatId,
        string $visibility,
        string $title,
        string $joinUrl,
        ?string $hashOverride = null,
    ): int {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $ciphertext = $this->app->make(StringEncrypter::class)->encryptString($joinUrl);
        $id = (int) DB::table('required_channels')->insertGetId([
            'channel_key' => $key,
            'telegram_chat_id' => $chatId,
            'chat_type' => 'channel',
            'visibility' => $visibility,
            'display_title' => $title,
            'join_url_ciphertext' => $ciphertext,
            'join_url_hash' => $hashOverride ?? hash('sha256', $joinUrl),
            'sort_order' => 0,
            'state' => 'draft',
            'version' => 1,
            'verified_bot_id' => null,
            'verification_result_code' => null,
            'verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('required_channels')->where('id', $id)->update([
            'state' => 'active',
            'version' => 2,
            'verified_bot_id' => 123456,
            'verification_result_code' => 'telegram_membership_administrator',
            'verified_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function disableChannel(int $channelId): void
    {
        $version = (int) DB::table('required_channels')->where('id', $channelId)->value('version');
        DB::table('required_channels')->where('id', $channelId)->update([
            'state' => 'disabled',
            'version' => $version + 1,
            'verified_bot_id' => null,
            'verification_result_code' => null,
            'verified_at' => null,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function customerWithTelegram(int $telegramUserId): int
    {
        $userId = $this->customer();
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        DB::table('telegram_accounts')->insert([
            'user_id' => $userId,
            'bot_id' => 123456,
            'telegram_user_id' => $telegramUserId,
            'username' => null,
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function customer(): int
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
        $tierId = DB::table('customer_tiers')->where('code', 'normal')->value('id');
        if (! is_int($tierId) && ! is_string($tierId)) {
            throw new RuntimeException('Normal customer tier is unavailable.');
        }
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => (int) $tierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => 'unverified',
            'identity_verification_status' => 'unverified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function assertSerializationRejected(object $value): void
    {
        try {
            serialize($value);
            self::fail('Protected Telegram value must reject serialization.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('cannot be serialized', $exception->getMessage());
        }
    }
}
