<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardDestination;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardReservation;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryOperationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use App\Modules\Telegram\Application\TelegramProtectedPresentationResolver;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use App\Shared\Application\RestrictedValue;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\ConfidentialTelegramPresentationTestFactory;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;
use Tests\Support\TelegramInteractivePresentationTestFactory;
use Tests\TestCase;

final class TelegramProtectedReferenceTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final readonly class TelegramProtectedReferenceTestRuntime implements TelegramDeliveryRuntime
{
    public function botId(): string
    {
        return '123456';
    }
}

final class TelegramProtectedReferenceTestTransport implements TelegramMutationTransport
{
    public int $attempts = 0;

    public function mutate(TelegramMutationRequest $request): TelegramMutationResult
    {
        $this->attempts++;
        throw new RuntimeException('Generic Telegram transport must not receive a protected-reference operation.');
    }
}

final class TelegramProtectedReferenceTestSender implements ProtectedTelegramMessageSender
{
    public int $attempts = 0;

    /** @var list<ProtectedTelegramPresentation> */
    public array $presentations = [];

    public function __construct(public ProtectedTelegramSendResult $result) {}

    public function send(int $telegramUserId, ProtectedTelegramPresentation $presentation): ProtectedTelegramSendResult
    {
        if ($telegramUserId !== 900001) {
            throw new RuntimeException('Protected Telegram test recipient changed unexpectedly.');
        }
        $this->attempts++;
        $this->presentations[] = $presentation;

        return $this->result;
    }
}

final readonly class TelegramProtectedReferenceTestCardToCard implements TelegramCustomerPurchaseCardToCardPayment
{
    public function __construct(
        private int $expectedUserId,
        private string $reservationPublicId,
        private DateTimeImmutable $expiresAt,
    ) {}

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
        throw new RuntimeException('Protected-reference resolver must not create card-to-card authority.');
    }

    public function destinationForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
    ): TelegramCustomerPurchaseCardToCardDestination {
        if ($actorUserId !== $this->expectedUserId
            || $subjectUserId !== $this->expectedUserId
            || ! hash_equals($this->reservationPublicId, $reservationPublicId)) {
            throw new RuntimeException('Protected-reference resolver used an unexpected authority identity.');
        }

        return new TelegramCustomerPurchaseCardToCardDestination(
            $reservationPublicId,
            RestrictedValue::fromString('4242424242424242'),
            '424242******4242',
            1_001_000,
            $this->expiresAt,
        );
    }
}

/** @requirement ARCH-003 ARCH-004 C2C-001 DAT-002 DAT-003 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 QUA-007 QUA-010 */
final class TelegramProtectedReferenceDeliveryTest extends TestCase
{
    use DatabaseTruncation;

    private TelegramProtectedReferenceTestClock $clock;

    private TelegramProtectedReferenceTestRuntime $runtime;

    private int $userId;

    private string $reservationPublicId;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Protected Telegram reference delivery verification requires MariaDB/MySQL.');
        }

        $migration = require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
        $migration->up();
        $this->clock = new TelegramProtectedReferenceTestClock(new DateTimeImmutable('2026-09-04T12:00:00+00:00'));
        $this->runtime = new TelegramProtectedReferenceTestRuntime;
        $this->reservationPublicId = (string) Str::ulid();
        $this->userId = $this->telegramAccount();
    }

    public function test_protected_reference_persists_only_safe_identity_and_reveals_pan_at_provider_boundary(): void
    {
        $reference = TelegramProtectedPresentationReference::cardToCardDestination($this->reservationPublicId, 'fa');
        $created = NonRestrictedTelegramPresentationTestFactory::queueProtectedReference(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900001,
            $reference,
            'protected-reference-success-001',
            'correlation-protected-reference-success',
        );

        $operation = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first();
        $outbox = DB::table('outbox_messages')->where('id', $created->outboxEventId)->first();
        self::assertNotNull($operation);
        self::assertNotNull($outbox);
        self::assertSame($reference->durableText(), $operation->presentation_text);
        self::assertStringNotContainsString('4242424242424242', (string) $operation->presentation_text);
        self::assertStringNotContainsString('4242424242424242', (string) $outbox->payload);
        self::assertStringNotContainsString('4242424242424242', (string) $operation->request_fingerprint);

        $generic = new TelegramProtectedReferenceTestTransport;
        $sender = new TelegramProtectedReferenceTestSender(new ProtectedTelegramSendResult(
            ProtectedTelegramSendOutcome::Success,
            'telegram_success',
            messageId: 4401,
        ));
        $result = $this->executor($generic, $sender)->execute(
            $created->publicId,
            $created->outboxEventId,
            'correlation-protected-reference-success',
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
        );

        self::assertSame(TelegramDeliveryOperationState::Succeeded, $result->state);
        self::assertSame(0, $generic->attempts);
        self::assertSame(1, $sender->attempts);
        self::assertCount(1, $sender->presentations);
        self::assertSame('4242424242424242', $sender->presentations[0]->copyText());
        self::assertStringContainsString('4242 4242 4242 4242', $sender->presentations[0]->text());
        self::assertSame('[PROTECTED_TELEGRAM_PRESENTATION]', (string) $sender->presentations[0]);
        self::assertSame('succeeded', DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->value('state'));
        self::assertStringNotContainsString(
            '4242424242424242',
            (string) DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->value('presentation_text'),
        );
    }

    public function test_uncertain_protected_result_is_not_blindly_sent_again(): void
    {
        $created = NonRestrictedTelegramPresentationTestFactory::queueProtectedReference(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900001,
            TelegramProtectedPresentationReference::cardToCardDestination($this->reservationPublicId, 'en'),
            'protected-reference-uncertain-001',
            'correlation-protected-reference-uncertain',
        );
        $generic = new TelegramProtectedReferenceTestTransport;
        $sender = new TelegramProtectedReferenceTestSender(new ProtectedTelegramSendResult(
            ProtectedTelegramSendOutcome::UncertainResult,
            'telegram_transport_uncertain',
        ));
        $executor = $this->executor($generic, $sender);

        $first = $executor->execute(
            $created->publicId,
            $created->outboxEventId,
            'correlation-protected-reference-uncertain',
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
        );
        $second = $executor->execute(
            $created->publicId,
            $created->outboxEventId,
            'correlation-protected-reference-uncertain',
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
        );

        self::assertSame(TelegramDeliveryOperationState::Uncertain, $first->state);
        self::assertSame(TelegramDeliveryOperationState::Uncertain, $second->state);
        self::assertSame(1, $sender->attempts);
        self::assertSame(0, $generic->attempts);
        self::assertSame(1, (int) DB::table('telegram_delivery_operations')
            ->where('public_id', $created->publicId)
            ->value('provider_attempts'));
    }

    public function test_missing_protected_dependencies_fail_before_provider_boundary(): void
    {
        $created = NonRestrictedTelegramPresentationTestFactory::queueProtectedReference(
            $this->queue(),
            TelegramDeliveryAction::Send,
            900001,
            TelegramProtectedPresentationReference::cardToCardDestination($this->reservationPublicId, 'en'),
            'protected-reference-misconfigured-001',
            'correlation-protected-reference-misconfigured',
        );
        $generic = new TelegramProtectedReferenceTestTransport;
        $database = $this->app->make(DatabaseManager::class);
        $executor = new TelegramDeliveryOperationExecutor(
            $database,
            $this->clock,
            $this->runtime,
            $generic,
            new TelegramDeliveryDatabaseCapability,
            TelegramInteractivePresentationTestFactory::service($this->clock, $this->runtime),
            ConfidentialTelegramPresentationTestFactory::service($this->clock),
        );

        try {
            $executor->execute(
                $created->publicId,
                $created->outboxEventId,
                'correlation-protected-reference-misconfigured',
                TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE,
            );
            self::fail('Missing protected dependencies must fail before provider entry.');
        } catch (RuntimeException $exception) {
            self::assertSame('Protected Telegram delivery dependencies are unavailable.', $exception->getMessage());
        }

        self::assertSame(0, $generic->attempts);
        $operation = DB::table('telegram_delivery_operations')->where('public_id', $created->publicId)->first(['state', 'provider_attempts']);
        self::assertNotNull($operation);
        self::assertSame('prepared', (string) $operation->state);
        self::assertSame(0, (int) $operation->provider_attempts);
    }

    private function telegramAccount(): int
    {
        $timestamp = $this->clock->value->format('Y-m-d H:i:s.u');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $timestamp,
            'last_seen_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('telegram_accounts')->insert([
            'user_id' => $userId,
            'bot_id' => 123456,
            'telegram_user_id' => 900001,
            'username' => 'protected_reference_test',
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $timestamp,
            'last_seen_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $userId;
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

    private function executor(
        TelegramMutationTransport $transport,
        ProtectedTelegramMessageSender $sender,
    ): TelegramDeliveryOperationExecutor {
        $database = $this->app->make(DatabaseManager::class);
        $cardToCard = new TelegramProtectedReferenceTestCardToCard(
            $this->userId,
            $this->reservationPublicId,
            $this->clock->value->modify('+30 minutes'),
        );

        return new TelegramDeliveryOperationExecutor(
            $database,
            $this->clock,
            $this->runtime,
            $transport,
            new TelegramDeliveryDatabaseCapability,
            TelegramInteractivePresentationTestFactory::service($this->clock, $this->runtime),
            ConfidentialTelegramPresentationTestFactory::service($this->clock),
            $sender,
            new TelegramProtectedPresentationResolver(
                $cardToCard,
                $this->app->make(Translator::class),
            ),
        );
    }
}
