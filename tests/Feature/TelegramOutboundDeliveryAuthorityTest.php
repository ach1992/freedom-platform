<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramDeliveryOperationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
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
        $presentation = NonRestrictedTelegramPresentation::plainText('مرحله بعدی خرید');

        $created = $queue->queue(
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

        $replay = $queue->queue(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentation::plainText('مرحله بعدی خرید'),
            'telegram-send-request-001',
            'correlation-telegram-001',
        );
        self::assertTrue($replay->replayed);
        self::assertSame($created->publicId, $replay->publicId);
        self::assertSame($created->outboxEventId, $replay->outboxEventId);
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)->count());

        try {
            $queue->queue(
                TelegramDeliveryAction::Send,
                900001,
                null,
                NonRestrictedTelegramPresentation::plainText('conflicting text'),
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
        $created = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900010,
            null,
            NonRestrictedTelegramPresentation::plainText('delivery success'),
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

        $recovered = $executor->recover($created->publicId);
        $again = $executor->execute($created->publicId);
        self::assertSame(TelegramDeliveryOperationState::Succeeded, $recovered);
        self::assertSame(TelegramDeliveryOperationState::Succeeded, $again->state);
        self::assertSame(1, $transport->attempts);
    }

    public function test_definite_retryable_rejection_uses_common_outbox_backoff_then_can_succeed(): void
    {
        $transport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(TelegramMutationOutcome::RetryableFailure, 'telegram_api_error_502'),
            new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success', messageId: 702),
        ], DB::getFacadeRoot());
        $created = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900011,
            null,
            NonRestrictedTelegramPresentation::plainText('retryable'),
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
            'result_code' => 'telegram_api_error_502',
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

    public function test_provider_retry_after_is_quarantined_and_never_retried_early_or_blindly(): void
    {
        $transport = new RecordingTelegramMutationTransport([
            new TelegramMutationResult(
                TelegramMutationOutcome::RetryAfter,
                'telegram_retry_after',
                retryAfterSeconds: 73,
            ),
        ], DB::getFacadeRoot());
        $created = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900012,
            null,
            NonRestrictedTelegramPresentation::plainText('retry after'),
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
        $created = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900013,
            null,
            NonRestrictedTelegramPresentation::plainText('uncertain'),
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
        self::assertSame(TelegramDeliveryOperationState::Uncertain, $executor->recover($created->publicId));
        self::assertSame(1, $transport->attempts);

        $crashCreated = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900014,
            null,
            NonRestrictedTelegramPresentation::plainText('crash fence'),
            'telegram-crash-request',
            'correlation-crash-179',
        );
        $this->enterProviderBoundaryWithoutCallingTransport($executor, $crashCreated->publicId);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $crashCreated->publicId,
            'state' => 'sending',
            'provider_attempts' => 1,
        ]);

        self::assertSame(TelegramDeliveryOperationState::Uncertain, $executor->recover($crashCreated->publicId));
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
        $edit = $this->queue()->queue(
            TelegramDeliveryAction::Edit,
            900020,
            801,
            NonRestrictedTelegramPresentation::plainText('edited'),
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
        $delete = $this->queue()->queue(
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
        $failed = $this->queue()->queue(
            TelegramDeliveryAction::Send,
            900022,
            null,
            NonRestrictedTelegramPresentation::plainText('blocked'),
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

    private function queue(): TelegramDeliveryQueueService
    {
        $database = app(DatabaseManager::class);

        return new TelegramDeliveryQueueService(
            $database,
            $this->clock,
            new DatabaseOutboxPublisher($database, $this->clock),
            $this->runtime,
        );
    }

    private function executor(RecordingTelegramMutationTransport $transport): TelegramDeliveryOperationExecutor
    {
        return new TelegramDeliveryOperationExecutor(
            app(DatabaseManager::class),
            $this->clock,
            $this->runtime,
            $transport,
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
