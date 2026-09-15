<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use App\Shared\Application\OutboxMessageHandler;
use App\Shared\Application\SafeOutboxPayload;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class DatabaseOutboxDispatcherTest extends TestCase
{
    /** @requirement PAY-003 ARCH-004 OPS-003 QUA-004 */
    use RefreshDatabase;

    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MutableClock(new DateTimeImmutable('2026-08-12T00:00:00+00:00'));
    }

    public function test_successful_dispatch_claims_then_completes_without_a_database_transaction_in_the_handler(): void
    {
        $id = $this->publish('order:1:paid:v1');
        $database = app(DatabaseManager::class);
        $baselineTransactionLevel = $database->connection()->transactionLevel();
        $handler = new RecordingOutboxHandler(OutboxDispatchOutcome::Success, $database);

        $result = $this->dispatcher()->dispatchOne($handler);

        self::assertNotNull($result);
        self::assertSame($id, $result->messageId);
        self::assertSame(OutboxDispatchOutcome::Success, $result->outcome);
        self::assertSame($baselineTransactionLevel, $handler->transactionLevelAtHandle);
        self::assertSame(['order_id' => '1'], $handler->messages[0]->payload);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $id,
            'dispatch_state' => 'processed',
            'attempts' => 1,
            'lease_token' => null,
            'review_reason' => null,
        ]);
        self::assertNotNull($database->connection()->table('outbox_messages')->where('id', $id)->value('processed_at'));
    }

    public function test_retryable_failure_schedules_bounded_retry_and_releases_the_lease(): void
    {
        $id = $this->publish('order:2:paid:v1');

        $result = $this->dispatcher()->dispatchOne(new RecordingOutboxHandler(OutboxDispatchOutcome::RetryableFailure));

        self::assertNotNull($result);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $id,
            'dispatch_state' => 'retry',
            'attempts' => 1,
            'lease_token' => null,
            'review_reason' => null,
            'last_error_code' => 'retryable_failure',
        ]);
        $availableAt = app(DatabaseManager::class)->connection()->table('outbox_messages')->where('id', $id)->value('available_at');
        self::assertIsString($availableAt);
        self::assertSame('2026-08-12T00:00:05+00:00', (new DateTimeImmutable($availableAt))->format(DateTimeImmutable::ATOM));
    }

    public function test_retryable_failure_requires_review_after_the_bounded_attempt_limit(): void
    {
        $id = $this->publish('order:8:paid:v1');
        app(DatabaseManager::class)->connection()->table('outbox_messages')->where('id', $id)->update(['attempts' => 4]);

        $this->dispatcher()->dispatchOne(new RecordingOutboxHandler(OutboxDispatchOutcome::RetryableFailure));

        $this->assertDatabaseHas('outbox_messages', [
            'id' => $id,
            'dispatch_state' => 'review_required',
            'attempts' => 5,
            'review_reason' => 'retry_exhausted',
            'last_error_code' => 'retryable_failure',
        ]);
    }

    public function test_definitive_and_uncertain_outcomes_require_review_without_automatic_redelivery(): void
    {
        foreach ([OutboxDispatchOutcome::DefinitiveFailure, OutboxDispatchOutcome::UncertainResult] as $outcome) {
            $id = $this->publish('order:'.($outcome === OutboxDispatchOutcome::DefinitiveFailure ? '3' : '4').':paid:v1');

            $this->dispatcher()->dispatchOne(new RecordingOutboxHandler($outcome));

            $this->assertDatabaseHas('outbox_messages', [
                'id' => $id,
                'dispatch_state' => 'review_required',
                'attempts' => 1,
                'lease_token' => null,
                'review_reason' => $outcome->value,
                'last_error_code' => $outcome->value,
            ]);
        }

        self::assertNull($this->dispatcher()->dispatchOne(new RecordingOutboxHandler(OutboxDispatchOutcome::Success)));
    }

    public function test_an_active_lease_is_not_claimed_by_a_second_dispatcher(): void
    {
        $id = $this->publish('order:5:paid:v1');
        app(DatabaseManager::class)->connection()->table('outbox_messages')->where('id', $id)->update([
            'dispatch_state' => 'leased',
            'lease_token' => 'active-lease-token',
            'leased_until' => '2026-08-12 00:01:00.000000',
        ]);

        $handler = new RecordingOutboxHandler(OutboxDispatchOutcome::Success);

        self::assertNull($this->dispatcher()->dispatchOne($handler));
        self::assertSame([], $handler->messages);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $id,
            'dispatch_state' => 'leased',
            'lease_token' => 'active-lease-token',
            'attempts' => 0,
        ]);
    }

    public function test_a_stale_lease_is_reclaimed_and_dispatched_once(): void
    {
        $id = $this->publish('order:6:paid:v1');
        app(DatabaseManager::class)->connection()->table('outbox_messages')->where('id', $id)->update([
            'dispatch_state' => 'leased',
            'lease_token' => 'expired-lease-token',
            'leased_until' => '2026-08-11 23:59:59.000000',
        ]);

        $result = $this->dispatcher()->dispatchOne(new RecordingOutboxHandler(OutboxDispatchOutcome::Success));

        self::assertNotNull($result);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $id,
            'dispatch_state' => 'processed',
            'attempts' => 1,
            'lease_token' => null,
        ]);
    }

    public function test_unexpected_handler_exception_is_persisted_for_review_and_not_swallowed(): void
    {
        $id = $this->publish('order:7:paid:v1');

        $this->expectException(RuntimeException::class);

        try {
            $this->dispatcher()->dispatchOne(new ThrowingOutboxHandler);
        } finally {
            $this->assertDatabaseHas('outbox_messages', [
                'id' => $id,
                'dispatch_state' => 'review_required',
                'review_reason' => 'unexpected_exception',
                'last_error_class' => RuntimeException::class,
                'lease_token' => null,
            ]);
        }
    }

    private function publish(string $eventKey): string
    {
        $database = app(DatabaseManager::class);
        $publisher = new DatabaseOutboxPublisher($database, $this->clock);
        $id = sprintf('0198a4c7-ff31-7bb9-8222-%012d', (int) substr($eventKey, 6, 1));

        $database->transaction(function () use ($publisher, $id, $eventKey): void {
            $publisher->publish(
                $id,
                $eventKey,
                'order.paid',
                'order',
                '1',
                new SafeOutboxPayload(['order_id' => '1']),
                '0198a4c7-ff31-7bb9-8222-000000000099',
                1,
            );
        });

        return $id;
    }

    private function dispatcher(): DatabaseOutboxDispatcher
    {
        return new DatabaseOutboxDispatcher(app(DatabaseManager::class), $this->clock, 60);
    }
}

final class MutableClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class RecordingOutboxHandler implements OutboxMessageHandler
{
    /** @var list<OutboxMessage> */
    public array $messages = [];

    public ?int $transactionLevelAtHandle = null;

    public function __construct(
        private readonly OutboxDispatchOutcome $outcome,
        private readonly ?DatabaseManager $database = null,
    ) {}

    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $this->messages[] = $message;
        $this->transactionLevelAtHandle = $this->database?->connection()->transactionLevel();

        return $this->outcome;
    }
}

final class ThrowingOutboxHandler implements OutboxMessageHandler
{
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        throw new RuntimeException('Expected handler failure.');
    }
}
