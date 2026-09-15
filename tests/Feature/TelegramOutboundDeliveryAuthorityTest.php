<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryOperationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Modules\Telegram\Infrastructure\HttpTelegramMutationTransport;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use RuntimeException;
use Tests\Support\ConfidentialTelegramPresentationTestFactory;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;
use Tests\Support\TelegramInteractivePresentationTestFactory;
use Tests\TestCase;

/** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 QUA-007 QUA-010 */
final class TelegramOutboundDeliveryAuthorityTest extends TestCase
{
    use DatabaseTruncation;

    private TelegramOutboundTestClock $clock;

    private TelegramOutboundTestRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram outbound delivery authority verification requires MariaDB/MySQL.');
        }

        $migration = require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
        $migration->up();

        $this->clock = new TelegramOutboundTestClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));
        $this->runtime = new TelegramOutboundTestRuntime('123456');
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_queue_is_atomic_exact_replay_safe_conflict_safe_and_direct_dml_guarded(): void
    {
        $queue = $this->queue();
        $presentation = NonRestrictedTelegramPresentationTestFactory::plainText('مرحله بعدی خرید');

        $created = NonRestrictedTelegramPresentationTestFactory::queue($queue,
            TelegramDeliveryAction::Send,
            900001,
            null,
            $presentation,
            'telegram-send-request-001',
            'correlation-telegram-001',
        );

        self::assertFalse($created->replayed);
        self::assertSame(TelegramDeliveryOperationState::Prepared, $created->state);
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());
        $outbox = DB::table('outbox_messages')->where('id', $created->outboxEventId)->first();
        self::assertNotNull($outbox);
        self::assertSame('pending', $outbox->dispatch_state);
        self::assertSame(
            '{"telegram_delivery_operation_public_id":"'.$created->publicId.'"}',
            (string) $outbox->payload,
        );
        self::assertStringNotContainsString('مرحله بعدی خرید', (string) $outbox->payload);

        $replay = NonRestrictedTelegramPresentationTestFactory::queue($queue,
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('مرحله بعدی خرید'),
            'telegram-send-request-001',
            'correlation-telegram-001',
        );
        self::assertTrue($replay->replayed);
        self::assertSame($created->publicId, $replay->publicId);
        self::assertSame($created->outboxEventId, $replay->outboxEventId);
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());

        try {
            NonRestrictedTelegramPresentationTestFactory::queue($queue,
                TelegramDeliveryAction::Send,
                900001,
                null,
                NonRestrictedTelegramPresentationTestFactory::plainText('conflicting text'),
                'telegram-send-request-001',
                'correlation-telegram-001',
            );
            self::fail('Conflicting same-key Telegram delivery must fail closed.');
        } catch (DomainException) {
            // Expected.
        }

        try {
            DB::table('telegram_delivery_operations')
                ->where('public_id', $created->publicId)
                ->update(['recipient_chat_id' => 900002]);
            self::fail('Direct Telegram delivery recipient retargeting must be rejected.');
        } catch (QueryException) {
            // Expected.
        }

        try {
            DB::table('telegram_delivery_operations')
                ->where('public_id', $created->publicId)
                ->delete();
            self::fail('Direct Telegram delivery evidence deletion must be rejected.');
        } catch (QueryException) {
            // Expected.
        }

        try {
            DB::table('outbox_messages')->insert([
                'id' => '0198a4c7-ff31-7bb9-8222-000000017901',
                'event_key' => 'telegram-delivery-requested:01J00000000000000000000000',
                'event_type' => TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE,
                'aggregate_type' => TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE,
                'aggregate_id' => '01J00000000000000000000000',
                'payload' => '{"telegram_delivery_operation_public_id":"01J00000000000000000000000"}',
                'payload_hash' => hash('sha256', '{"telegram_delivery_operation_public_id":"01J00000000000000000000000"}'),
                'correlation_id' => 'correlation-forged-179',
                'available_at' => '2026-08-25 12:00:00.000000',
                'attempts' => 0,
                'created_at' => '2026-08-25 12:00:00.000000',
                'updated_at' => '2026-08-25 12:00:00.000000',
            ]);
            self::fail('Direct forged Telegram Outbox command must be rejected.');
        } catch (QueryException) {
            // Expected.
        }
    }

    public function test_success_runs_transport_outside_transaction_and_replay_never_mutates_twice(): void
    {
        $transport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 701),
        ], DB::getFacadeRoot());
        $created = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900010,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('delivery success'),
            'telegram-success-request',
            'correlation-success-179',
        );
        $executor = $this->executor($transport);
        $handler = $this->handler($executor);

        $dispatch = $this->dispatcher()->dispatchOne($handler);

        self::assertNotNull($dispatch);
        self::assertSame(1, $transport->attempts);
        self::assertSame([0], $transport->transactionLevels);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'succeeded',
            'provider_attempts' => 1,
            'telegram_message_id' => 701,
            'result_code' => 'telegram_success',
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'dispatch_state' => 'processed',
            'attempts' => 1,
        ]);

        $recovered = $executor->recover($created->publicId, $created->outboxEventId, 'correlation-success-179');
        $again = $executor->execute($created->publicId, $created->outboxEventId, 'correlation-success-179');
        self::assertSame(TelegramDeliveryOperationState::Succeeded, $recovered);
        self::assertSame(TelegramDeliveryOperationState::Succeeded, $again->state);
        self::assertSame(1, $transport->attempts);
    }

    public function test_authoritatively_definite_no_effect_retryable_outcome_uses_common_outbox_backoff_then_can_succeed(): void
    {
        $transport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::DefinitiveNoEffectRetryable, 'telegram_definite_no_effect_retryable'),
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 702),
        ], DB::getFacadeRoot());
        $created = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900011,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('retryable'),
            'telegram-retryable-request',
            'correlation-retry-179',
        );
        $handler = $this->handler($this->executor($transport));
        $dispatcher = $this->dispatcher();

        $first = $dispatcher->dispatchOne($handler);
        self::assertNotNull($first);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'retryable',
            'provider_attempts' => 1,
            'result_code' => 'telegram_definite_no_effect_retryable',
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'dispatch_state' => 'retry',
            'attempts' => 1,
        ]);
        self::assertNull($dispatcher->dispatchOne($handler));
        self::assertSame(1, $transport->attempts);

        $this->clock->advance('+5 seconds');
        $this->dispatcher()->dispatchOne($handler);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'succeeded',
            'provider_attempts' => 2,
            'telegram_message_id' => 702,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'dispatch_state' => 'processed',
            'attempts' => 2,
        ]);
        self::assertSame(2, $transport->attempts);
    }

    public function test_http_5xx_is_uncertain_and_never_crosses_provider_boundary_twice(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'error_code' => 502], 502)
            ->push(['ok' => true, 'result' => ['message_id' => 703, 'chat' => ['id' => 900015]]], 200);

        $created = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900015,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('server failure is ambiguous'),
            'telegram-http-5xx-request',
            'correlation-http-5xx-179',
        );
        $executor = $this->executor($this->httpTransport());
        $handler = $this->handler($executor);
        $dispatcher = $this->dispatcher();

        $first = $dispatcher->dispatchOne($handler);
        self::assertNotNull($first);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'uncertain',
            'provider_attempts' => 1,
            'result_code' => 'telegram_http_server_error_uncertain',
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'dispatch_state' => 'review_required',
            'review_reason' => 'uncertain_result',
            'attempts' => 1,
        ]);
        Http::assertSentCount(1);

        $this->clock->advance('+1 day');
        self::assertNull($dispatcher->dispatchOne($handler));
        self::assertSame(
            TelegramDeliveryOperationState::Uncertain,
            $executor->recover($created->publicId, $created->outboxEventId, 'correlation-http-5xx-179'),
        );
        self::assertSame(
            TelegramDeliveryOperationState::Uncertain,
            $executor->execute($created->publicId, $created->outboxEventId, 'correlation-http-5xx-179')->state,
        );
        Http::assertSentCount(1);
    }

    public function test_direct_execute_reentry_on_existing_sending_state_fails_to_uncertainty_without_transport(): void
    {
        $transport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 704),
        ], DB::getFacadeRoot());
        $created = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900016,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('direct reentry fence'),
            'telegram-direct-reentry-request-179',
            'correlation-direct-reentry-179',
        );
        $executor = $this->executor($transport);

        $this->enterProviderBoundaryWithoutCallingTransport($executor, $created->publicId);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'sending',
            'provider_attempts' => 1,
        ]);

        $receipt = $executor->execute($created->publicId, $created->outboxEventId, 'correlation-direct-reentry-179');

        self::assertSame(TelegramDeliveryOperationState::Uncertain, $receipt->state);
        self::assertSame('telegram_boundary_reentry_uncertain', $receipt->resultCode);
        self::assertSame(0, $transport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'uncertain',
            'provider_attempts' => 1,
            'result_code' => 'telegram_boundary_reentry_uncertain',
        ]);
    }

    public function test_interleaved_direct_execute_cannot_share_one_sending_boundary_between_invocations(): void
    {
        $secondTransport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 706),
        ], DB::getFacadeRoot());
        $created = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900017,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('concurrent direct reentry fence'),
            'telegram-concurrent-direct-reentry-request-179',
            'correlation-concurrent-direct-reentry-179',
        );
        $secondExecutor = $this->executor($secondTransport);
        $secondReceipt = null;
        $firstTransport = new class(function () use ($secondExecutor, $created, &$secondReceipt): void {
            $secondReceipt = $secondExecutor->execute(
                $created->publicId,
                $created->outboxEventId,
                'correlation-concurrent-direct-reentry-179',
            );
        }) implements TelegramMutationTransport
        {

            public int $attempts = 0;

            public function __construct(private readonly \Closure $onMutate) {}

            public function mutate(TelegramMutationRequest $request): TelegramMutationResult
            {
                unset($request);
                $this->attempts++;
                ($this->onMutate)();

                return new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 705);
            }
        };
        $firstExecutor = $this->executor($firstTransport);

        $firstReceipt = $firstExecutor->execute(
            $created->publicId,
            $created->outboxEventId,
            'correlation-concurrent-direct-reentry-179',
        );

        self::assertNotNull($secondReceipt);
        self::assertSame(TelegramDeliveryOperationState::Uncertain, $secondReceipt->state);
        self::assertSame(TelegramDeliveryOperationState::Uncertain, $firstReceipt->state);
        self::assertSame('telegram_boundary_reentry_uncertain', $firstReceipt->resultCode);
        self::assertSame(1, $firstTransport->attempts);
        self::assertSame(0, $secondTransport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'uncertain',
            'provider_attempts' => 1,
            'result_code' => 'telegram_boundary_reentry_uncertain',
        ]);
    }

    public function test_provider_retry_after_is_quarantined_and_never_retried_early_or_blindly(): void
    {
        $transport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(
                TelegramMutationOutcome::RetryAfter,
                'telegram_retry_after',
                retryAfterSeconds: 73,
            ),
        ], DB::getFacadeRoot());
        $created = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900012,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('retry after'),
            'telegram-retry-after-request',
            'correlation-retry-after-179',
        );
        $handler = $this->handler($this->executor($transport));
        $dispatcher = $this->dispatcher();

        $dispatcher->dispatchOne($handler);

        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'review_required',
            'provider_attempts' => 1,
            'result_code' => 'telegram_retry_after',
            'retry_after_seconds' => 73,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'dispatch_state' => 'review_required',
            'review_reason' => 'definitive_failure',
            'attempts' => 1,
        ]);
        $this->clock->advance('+1 day');
        self::assertNull($dispatcher->dispatchOne($handler));
        self::assertSame(1, $transport->attempts);
    }

    public function test_uncertain_result_and_crash_recovery_fail_toward_review_without_second_mutation(): void
    {
        $transport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::UncertainResult, 'telegram_transport_uncertain'),
        ], DB::getFacadeRoot());
        $created = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900013,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('uncertain'),
            'telegram-uncertain-request',
            'correlation-uncertain-179',
        );
        $executor = $this->executor($transport);
        $handler = $this->handler($executor);

        $this->dispatcher()->dispatchOne($handler);
        self::assertSame(1, $transport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'uncertain',
            'provider_attempts' => 1,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'dispatch_state' => 'review_required',
            'review_reason' => 'uncertain_result',
        ]);
        self::assertSame(TelegramDeliveryOperationState::Uncertain, $executor->recover($created->publicId, $created->outboxEventId, 'correlation-uncertain-179'));
        self::assertSame(1, $transport->attempts);

        $crashCreated = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900014,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('crash fence'),
            'telegram-crash-request',
            'correlation-crash-179',
        );
        $this->enterProviderBoundaryWithoutCallingTransport($executor, $crashCreated->publicId);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $crashCreated->publicId,
            'state' => 'sending',
            'provider_attempts' => 1,
        ]);

        self::assertSame(TelegramDeliveryOperationState::Uncertain, $executor->recover($crashCreated->publicId, $crashCreated->outboxEventId, 'correlation-crash-179'));
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $crashCreated->publicId,
            'state' => 'uncertain',
            'result_code' => 'telegram_boundary_recovery_uncertain',
        ]);
        self::assertSame(1, $transport->attempts);
    }

    public function test_edit_and_delete_are_target_bound_and_permanent_failures_do_not_loop(): void
    {
        $editTransport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 802),
        ], DB::getFacadeRoot());
        $edit = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Edit,
            900020,
            801,
            NonRestrictedTelegramPresentationTestFactory::plainText('edited'),
            'telegram-edit-request',
            'correlation-edit-179',
        );
        $this->dispatcher()->dispatchOne($this->handler($this->executor($editTransport)));
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $edit->publicId,
            'target_message_id' => 801,
            'state' => 'uncertain',
            'result_code' => 'telegram_edit_target_mismatch',
        ]);
        self::assertSame(1, $editTransport->attempts);

        try {
            DB::table('telegram_delivery_operations')
                ->where('public_id', $edit->publicId)
                ->update(['target_message_id' => 802]);
            self::fail('Edit target identity must be immutable.');
        } catch (QueryException) {
            // Expected.
        }

        $deleteTransport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success'),
        ], DB::getFacadeRoot());
        $delete = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Delete,
            900021,
            901,
            null,
            'telegram-delete-request',
            'correlation-delete-179',
        );
        $this->dispatcher()->dispatchOne($this->handler($this->executor($deleteTransport)));
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $delete->publicId,
            'target_message_id' => 901,
            'state' => 'succeeded',
            'telegram_message_id' => null,
        ]);

        $finalTransport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::DefinitiveFailure, 'telegram_api_error_403'),
        ], DB::getFacadeRoot());
        $failed = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900022,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('blocked'),
            'telegram-blocked-request',
            'correlation-blocked-179',
        );
        $finalDispatcher = $this->dispatcher();
        $finalHandler = $this->handler($this->executor($finalTransport));
        $finalDispatcher->dispatchOne($finalHandler);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $failed->publicId,
            'state' => 'failed_final',
            'provider_attempts' => 1,
            'result_code' => 'telegram_api_error_403',
        ]);
        $this->clock->advance('+1 day');
        self::assertNull($finalDispatcher->dispatchOne($finalHandler));
        self::assertSame(1, $finalTransport->attempts);
    }

    public function test_known_session_literals_and_visible_capability_hash_cannot_forge_delivery_authority(): void
    {
        $storedCapabilityHash = DB::table('telegram_delivery_authority_capability')
            ->where('id', 1)
            ->value('capability_hash');
        self::assertIsString($storedCapabilityHash);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $storedCapabilityHash);

        $publicId = '01J00000000000000000000001';
        $outboxEventId = '0198a4c7-ff31-7bb9-8222-000000017911';
        $requestHash = hash('sha256', 'forged-request-179');
        $fingerprint = hash('sha256', 'forged-fingerprint-179');
        $correlationId = 'correlation-forged-cap-179';
        $presentation = 'forged presentation';
        $payload = '{"telegram_delivery_operation_public_id":"'.$publicId.'"}';

        DB::statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_authority = 'telegram_delivery_queue_v1',
    @app_telegram_delivery_public_id = ?,
    @app_telegram_delivery_request_hash = ?,
    @app_telegram_delivery_fingerprint = ?,
    @app_telegram_delivery_correlation_id = ?,
    @app_telegram_delivery_action = 'send',
    @app_telegram_delivery_bot_id = '123456',
    @app_telegram_delivery_recipient_chat_id = 900091,
    @app_telegram_delivery_target_message_id = NULL,
    @app_telegram_delivery_presentation_hash = ?,
    @app_telegram_delivery_outbox_event_id = ?
SQL, [
            $storedCapabilityHash,
            $publicId,
            $requestHash,
            $fingerprint,
            $correlationId,
            hash('sha256', $presentation),
            $outboxEventId,
        ]);

        try {
            DB::table('outbox_messages')->insert([
                'id' => $outboxEventId,
                'event_key' => TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$publicId,
                'event_type' => TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE,
                'aggregate_type' => TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE,
                'aggregate_id' => $publicId,
                'payload' => $payload,
                'payload_hash' => hash('sha256', $payload),
                'correlation_id' => $correlationId,
                'available_at' => '2026-08-25 12:00:00.000000',
                'attempts' => 0,
                'created_at' => '2026-08-25 12:00:00.000000',
                'updated_at' => '2026-08-25 12:00:00.000000',
            ]);
            self::fail('Visible capability hash plus known queue literals must not forge a Telegram Outbox command.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('exact canonical safe envelope', $exception->getMessage());
        }

        try {
            DB::table('telegram_delivery_operations')->insert([
                'public_id' => $publicId,
                'request_key_hash' => $requestHash,
                'request_fingerprint' => $fingerprint,
                'correlation_id' => $correlationId,
                'action' => 'send',
                'bot_id' => '123456',
                'recipient_chat_id' => 900091,
                'target_message_id' => null,
                'presentation_text' => $presentation,
                'outbox_event_id' => $outboxEventId,
                'state' => 'prepared',
                'state_version' => 1,
                'provider_attempts' => 0,
                'created_at' => '2026-08-25 12:00:00.000000',
                'updated_at' => '2026-08-25 12:00:00.000000',
            ]);
            self::fail('Visible capability hash plus known queue literals must not forge a Telegram delivery operation.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('creation authority is invalid', $exception->getMessage());
        } finally {
            $this->clearDeliverySessionVariables();
        }

        self::assertSame(0, DB::table('outbox_messages')->where('id', $outboxEventId)->count());
        self::assertSame(0, DB::table('telegram_delivery_operations')->where('public_id', $publicId)->count());

        $created = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900092,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('legitimate before forged effect'),
            'telegram-forged-effect-request-179',
            'correlation-forged-effect-179',
        );

        DB::statement(<<<'SQL'
SET @app_telegram_delivery_capability = ?,
    @app_telegram_delivery_effect_authority = 'telegram_delivery_effect_v1',
    @app_telegram_delivery_effect_public_id = ?,
    @app_telegram_delivery_effect_expected_version = 1
SQL, [$storedCapabilityHash, $created->publicId]);

        try {
            DB::table('telegram_delivery_operations')
                ->where('public_id', $created->publicId)
                ->update([
                    'state' => 'sending',
                    'state_version' => 2,
                    'provider_attempts' => 1,
                    'provider_boundary_started_at' => '2026-08-25 12:00:01.000000',
                    'updated_at' => '2026-08-25 12:00:01.000000',
                ]);
            self::fail('Visible capability hash plus known effect literals must not forge a provider-boundary transition.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('transition authority is invalid', $exception->getMessage());
        } finally {
            $this->clearDeliverySessionVariables();
        }

        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'prepared',
            'state_version' => 1,
            'provider_attempts' => 0,
        ]);

        try {
            DB::table('telegram_delivery_authority_capability')
                ->where('id', 1)
                ->update(['capability_hash' => str_repeat('a', 64)]);
            self::fail('Telegram delivery capability hash must be immutable to raw DML.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('capability identity is immutable', $exception->getMessage());
        }
    }

    public function test_capability_cleanup_failure_disconnects_session_and_rolls_back_queue_authority(): void
    {
        $connection = DB::connection();
        $before = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id');
        self::assertNotNull($before);
        $beforeConnectionId = (int) $before->connection_id;

        $cleanupInterrupted = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$cleanupInterrupted): void {
            unset($bindings, $db);
            if ($cleanupInterrupted || ! str_contains(strtolower($query), '@app_telegram_delivery_capability = null')) {
                return;
            }

            $cleanupInterrupted = true;
            throw new RuntimeException('Injected Telegram delivery capability clear failure.');
        });

        try {
            NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
                TelegramDeliveryAction::Send,
                900093,
                null,
                NonRestrictedTelegramPresentationTestFactory::plainText('cleanup fault'),
                'telegram-cleanup-fault-request-179',
                'correlation-cleanup-fault-179',
            );
            self::fail('Telegram delivery capability cleanup failure must invalidate the privileged session.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected Telegram delivery capability clear failure.', $exception->getMessage());
        }
        self::assertTrue($cleanupInterrupted);

        $after = $connection->selectOne(<<<'SQL'
SELECT CONNECTION_ID() AS connection_id,
       @app_telegram_delivery_capability AS capability,
       @app_telegram_delivery_authority AS queue_authority,
       @app_telegram_delivery_effect_authority AS effect_authority
SQL);
        self::assertNotNull($after);
        self::assertNotSame($beforeConnectionId, (int) $after->connection_id);
        self::assertNull($after->capability);
        self::assertNull($after->queue_authority);
        self::assertNull($after->effect_authority);
        self::assertSame(0, DB::table('telegram_delivery_operations')->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)
            ->count());
    }

    public function test_outbox_handler_requires_exact_event_and_correlation_identity_before_transport(): void
    {
        $transport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 999),
        ], DB::getFacadeRoot());
        $created = NonRestrictedTelegramPresentationTestFactory::queue($this->queue(),
            TelegramDeliveryAction::Send,
            900094,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('identity bound'),
            'telegram-outbox-bind-request-179',
            'correlation-outbox-bind-179',
        );
        $handler = $this->handler($this->executor($transport));
        $payload = ['telegram_delivery_operation_public_id' => $created->publicId];

        $wrongEvent = new OutboxMessage(
            '0198a4c7-ff31-7bb9-8222-000000017912',
            TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$created->publicId,
            TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE,
            TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE,
            $created->publicId,
            $payload,
            'correlation-outbox-bind-179',
            1,
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION,
        );
        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $handler->handle($wrongEvent));
        self::assertSame(0, $transport->attempts);

        $wrongCorrelation = new OutboxMessage(
            $created->outboxEventId,
            TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$created->publicId,
            TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE,
            TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE,
            $created->publicId,
            $payload,
            'correlation-forged-bind-179',
            1,
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION,
        );
        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $handler->handle($wrongCorrelation));
        self::assertSame(0, $transport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'prepared',
            'provider_attempts' => 0,
        ]);
    }

    private function clearDeliverySessionVariables(): void
    {
        DB::statement(<<<'SQL'
SET @app_telegram_delivery_effect_expected_version = NULL,
    @app_telegram_delivery_effect_public_id = NULL,
    @app_telegram_delivery_effect_authority = NULL,
    @app_telegram_delivery_outbox_event_id = NULL,
    @app_telegram_delivery_presentation_hash = NULL,
    @app_telegram_delivery_target_message_id = NULL,
    @app_telegram_delivery_recipient_chat_id = NULL,
    @app_telegram_delivery_bot_id = NULL,
    @app_telegram_delivery_action = NULL,
    @app_telegram_delivery_correlation_id = NULL,
    @app_telegram_delivery_fingerprint = NULL,
    @app_telegram_delivery_request_hash = NULL,
    @app_telegram_delivery_public_id = NULL,
    @app_telegram_delivery_authority = NULL,
    @app_telegram_delivery_capability = NULL
SQL);
    }

    private function queue(): TelegramDeliveryQueueService
    {
        $database = app(DatabaseManager::class);

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
        return new TelegramDeliveryOperationExecutor(
            app(DatabaseManager::class),
            $this->clock,
            $this->runtime,
            $transport,
            new TelegramDeliveryDatabaseCapability,
            TelegramInteractivePresentationTestFactory::service($this->clock, $this->runtime),
            ConfidentialTelegramPresentationTestFactory::service($this->clock),
        );
    }

    private function httpTransport(): HttpTelegramMutationTransport
    {
        return new HttpTelegramMutationTransport(
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
        );
    }

    private function handler(TelegramDeliveryOperationExecutor $executor): TelegramDeliveryOutboxHandler
    {
        return new TelegramDeliveryOutboxHandler(static fn (): TelegramDeliveryOperationExecutor => $executor);
    }

    private function dispatcher(): DatabaseOutboxDispatcher
    {
        return new DatabaseOutboxDispatcher(app(DatabaseManager::class), $this->clock, 60);
    }

    private function enterProviderBoundaryWithoutCallingTransport(TelegramDeliveryOperationExecutor $executor, string $publicId): void
    {
        $reflection = new ReflectionClass($executor);
        $operationMethod = $reflection->getMethod('operation');
        $boundaryMethod = $reflection->getMethod('enterProviderBoundary');
        $database = app(DatabaseManager::class);

        $database->connection()->transaction(function ($connection) use ($executor, $publicId, $operationMethod, $boundaryMethod): void {
            $row = $operationMethod->invoke($executor, $connection, $publicId, true);
            $boundaryMethod->invoke($executor, $connection, $row);
        });
    }
}

final class TelegramOutboundTestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}

final readonly class TelegramOutboundTestRuntime implements TelegramDeliveryRuntime
{
    public function __construct(private string $botId) {}

    public function botId(): string
    {
        return $this->botId;
    }
}

final class RecordingTelegramMutationTransport implements TelegramMutationTransport
{
    /** @var list<TelegramMutationResult> */
    private array $results;

    public int $attempts = 0;

    /** @var list<int> */
    public array $transactionLevels = [];

    /** @var list<TelegramMutationRequest> */
    public array $requests = [];

    /** @param list<TelegramMutationResult> $results */
    public function __construct(array $results, private readonly DatabaseManager $database)
    {
        $this->results = $results;
    }

    public function mutate(TelegramMutationRequest $request): TelegramMutationResult
    {
        $this->attempts++;
        $this->transactionLevels[] = $this->database->connection()->transactionLevel();
        $this->requests[] = $request;

        return array_shift($this->results)
            ?? new TelegramMutationResult(TelegramMutationOutcome::UncertainResult, 'test_result_missing');
    }
}
