<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use App\Shared\Application\OutboxMessageRouter;
use App\Shared\Application\OutboxRuntime;
use App\Shared\Application\OutboxRuntimeResult;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** @requirement ARCH-004 OPS-003 QUA-004 SEC-008 */
final class OutboxRuntimeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('outbox_messages')->delete();
    }

    public function test_registered_handler_dispatches_outside_the_claim_transaction_and_reports_counts_only(): void
    {
        $clock = $this->useClock(new DateTimeImmutable('2026-08-13T00:00:00+00:00'));
        $database = app(DatabaseManager::class);
        $handler = new RuntimeSuccessOutboxHandler($database);
        $this->app->instance(OutboxMessageRouter::class, new OutboxMessageRouter([$handler]));
        $id = $this->insertOutboxMessage(
            1,
            'runtime:test:success:1',
            'test.success',
            $clock->now()->modify('-30 seconds'),
            ['secret' => 'must-not-appear', 'order_id' => '1'],
        );

        $exitCode = Artisan::call('operations:dispatch-outbox', [
            '--limit' => 10,
            '--json' => true,
        ]);
        $output = trim(Artisan::output());

        self::assertSame(0, $exitCode);
        self::assertJsonStringEqualsJsonString(json_encode([
            'status' => 'healthy',
            'dispatch' => [
                'examined' => 1,
                'success' => 1,
                'retryable_failure' => 0,
                'definitive_failure' => 0,
                'uncertain_result' => 0,
            ],
            'backlog' => [
                'due' => 0,
                'oldest_due_age_seconds' => null,
                'review_required' => 0,
            ],
        ], JSON_THROW_ON_ERROR), $output);
        self::assertStringNotContainsString('must-not-appear', $output);
        self::assertSame($database->connection()->transactionLevel(), $handler->transactionLevelAtHandle);
        self::assertCount(1, $handler->messages);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $id,
            'dispatch_state' => 'processed',
            'attempts' => 1,
        ]);
    }

    public function test_limit_is_bounded_and_unsupported_events_become_review_with_backlog_age_visibility(): void
    {
        $clock = $this->useClock(new DateTimeImmutable('2026-08-13T00:00:00+00:00'));
        $firstId = $this->insertOutboxMessage(
            2,
            'runtime:test:unsupported:1',
            'test.unsupported.first',
            $clock->now()->modify('-120 seconds'),
        );
        $secondId = $this->insertOutboxMessage(
            3,
            'runtime:test:unsupported:2',
            'test.unsupported.second',
            $clock->now()->modify('-60 seconds'),
        );

        $exitCode = Artisan::call('operations:dispatch-outbox', [
            '--limit' => 1,
            '--json' => true,
        ]);
        $output = trim(Artisan::output());

        self::assertSame(0, $exitCode);
        self::assertJsonStringEqualsJsonString(json_encode([
            'status' => 'review_required',
            'dispatch' => [
                'examined' => 1,
                'success' => 0,
                'retryable_failure' => 0,
                'definitive_failure' => 1,
                'uncertain_result' => 0,
            ],
            'backlog' => [
                'due' => 1,
                'oldest_due_age_seconds' => 60,
                'review_required' => 1,
            ],
        ], JSON_THROW_ON_ERROR), $output);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $firstId,
            'dispatch_state' => 'review_required',
            'review_reason' => 'definitive_failure',
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $secondId,
            'dispatch_state' => 'pending',
            'attempts' => 0,
        ]);

        self::assertSame(0, Artisan::call('operations:dispatch-outbox', [
            '--limit' => 1,
            '--json' => true,
        ]));
        self::assertJsonStringEqualsJsonString(json_encode([
            'status' => 'review_required',
            'dispatch' => [
                'examined' => 1,
                'success' => 0,
                'retryable_failure' => 0,
                'definitive_failure' => 1,
                'uncertain_result' => 0,
            ],
            'backlog' => [
                'due' => 0,
                'oldest_due_age_seconds' => null,
                'review_required' => 2,
            ],
        ], JSON_THROW_ON_ERROR), trim(Artisan::output()));
    }

    public function test_invalid_limit_and_unexpected_runtime_failure_have_deterministic_redacted_exit_behavior(): void
    {
        self::assertSame(2, Artisan::call('operations:dispatch-outbox', [
            '--limit' => 0,
            '--json' => true,
        ]));
        self::assertSame(
            '{"status":"invalid","code":"outbox_dispatch_invalid_input"}',
            trim(Artisan::output()),
        );

        $this->app->instance(OutboxRuntime::class, new ThrowingOutboxRuntime);

        self::assertSame(1, Artisan::call('operations:dispatch-outbox', [
            '--limit' => 1,
            '--json' => true,
        ]));
        self::assertSame(
            '{"status":"failed","code":"outbox_dispatch_failed"}',
            trim(Artisan::output()),
        );
        self::assertStringNotContainsString('sensitive-runtime-detail', Artisan::output());
    }

    public function test_outbox_dispatch_is_registered_in_the_single_scheduler(): void
    {
        self::assertSame(0, Artisan::call('schedule:list'));
        self::assertStringContainsString('operations:dispatch-outbox', Artisan::output());
    }

    private function useClock(DateTimeImmutable $now): RuntimeClock
    {
        $clock = new RuntimeClock($now);
        $this->app->instance(Clock::class, $clock);

        return $clock;
    }

    /** @param array<string, mixed> $payload */
    private function insertOutboxMessage(
        int $suffix,
        string $eventKey,
        string $eventType,
        DateTimeImmutable $availableAt,
        array $payload = ['kind' => 'runtime-test'],
    ): string {
        $id = sprintf('0198a4c7-ff31-7bb9-8222-%012d', $suffix);
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $now = '2026-08-13 00:00:00.000000';

        DB::table('outbox_messages')->insert([
            'id' => $id,
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'aggregate_type' => 'runtime_test',
            'aggregate_id' => (string) $suffix,
            'payload' => $payloadJson,
            'payload_hash' => hash('sha256', $payloadJson),
            'correlation_id' => sprintf('runtime-test-%02d', $suffix),
            'available_at' => $availableAt->format('Y-m-d H:i:s.u'),
            'processed_at' => null,
            'dispatch_state' => 'pending',
            'lease_token' => null,
            'leased_until' => null,
            'review_reason' => null,
            'attempts' => 0,
            'last_error_class' => null,
            'last_error_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}

final readonly class RuntimeClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class RuntimeSuccessOutboxHandler implements OutboxEventHandler
{
    /** @var list<OutboxMessage> */
    public array $messages = [];

    public ?int $transactionLevelAtHandle = null;

    public function __construct(private readonly DatabaseManager $database) {}

    public function eventType(): string
    {
        return 'test.success';
    }

    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $this->messages[] = $message;
        $this->transactionLevelAtHandle = $this->database->connection()->transactionLevel();

        return OutboxDispatchOutcome::Success;
    }
}

final class ThrowingOutboxRuntime implements OutboxRuntime
{
    public function dispatchBatch(int $limit): OutboxRuntimeResult
    {
        throw new RuntimeException('sensitive-runtime-detail');
    }
}
