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
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;
use Tests\TestCase;

/** @requirement ARCH-004 DAT-003 OPS-003 QUA-004 QUA-007 */
final class TelegramOutboundDeliveryStaleLeaseTest extends TestCase
{
    use DatabaseTruncation;

    private TelegramStaleLeaseClock $clock;

    private TelegramStaleLeaseRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram outbound stale-lease verification requires MariaDB/MySQL.');
        }

        $migration = require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php');
        $migration->up();

        $this->clock = new TelegramStaleLeaseClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));
        $this->runtime = new TelegramStaleLeaseRuntime('123456');
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_stale_outbox_lease_after_provider_boundary_crash_becomes_uncertain_without_second_mutation(): void
    {
        $database = app(DatabaseManager::class);
        $transport = new TelegramStaleLeaseTransport;
        $queue = new TelegramDeliveryQueueService(
            $database,
            $this->clock,
            new DatabaseOutboxPublisher($database, $this->clock),
            $this->runtime,
            new TelegramDeliveryDatabaseCapability,
        );
        $executor = new TelegramDeliveryOperationExecutor(
            $database,
            $this->clock,
            $this->runtime,
            $transport,
            new TelegramDeliveryDatabaseCapability,
        );
        $handler = new TelegramDeliveryOutboxHandler(
            static fn (): TelegramDeliveryOperationExecutor => $executor,
        );
        $dispatcher = new DatabaseOutboxDispatcher($database, $this->clock, 60);

        $created = NonRestrictedTelegramPresentationTestFactory::queue($queue,
            TelegramDeliveryAction::Send,
            900040,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('stale lease recovery'),
            'telegram-stale-lease-request-179',
            'correlation-stale-lease-179',
        );

        $this->enterProviderBoundaryWithoutCallingTransport($executor, $created->publicId);

        DB::table('outbox_messages')
            ->where('id', $created->outboxEventId)
            ->update([
                'dispatch_state' => 'leased',
                'lease_token' => 'expired-telegram-delivery-lease',
                'leased_until' => '2026-08-25 11:59:00.000000',
                'attempts' => 1,
            ]);

        $result = $dispatcher->dispatchOne($handler);

        self::assertNotNull($result);
        self::assertSame(OutboxDispatchOutcome::UncertainResult, $result->outcome);
        self::assertSame(0, $transport->attempts);
        $this->assertDatabaseHas('telegram_delivery_operations', [
            'public_id' => $created->publicId,
            'state' => 'uncertain',
            'provider_attempts' => 1,
            'result_code' => 'telegram_boundary_recovery_uncertain',
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $created->outboxEventId,
            'dispatch_state' => 'review_required',
            'attempts' => 2,
            'lease_token' => null,
            'review_reason' => 'uncertain_result',
        ]);
    }

    private function enterProviderBoundaryWithoutCallingTransport(
        TelegramDeliveryOperationExecutor $executor,
        string $publicId,
    ): void {
        $reflection = new ReflectionClass($executor);
        $operationMethod = $reflection->getMethod('operation');
        $boundaryMethod = $reflection->getMethod('enterProviderBoundary');
        $database = app(DatabaseManager::class);

        $database->connection()->transaction(
            function ($connection) use ($executor, $publicId, $operationMethod, $boundaryMethod): void {
                $row = $operationMethod->invoke($executor, $connection, $publicId, true);
                $boundaryMethod->invoke($executor, $connection, $row);
            },
        );
    }
}

final class TelegramStaleLeaseClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final readonly class TelegramStaleLeaseRuntime implements TelegramDeliveryRuntime
{
    public function __construct(private string $botId) {}

    public function botId(): string
    {
        return $this->botId;
    }
}

final class TelegramStaleLeaseTransport implements TelegramMutationTransport
{
    public int $attempts = 0;

    public function mutate(TelegramMutationRequest $request): TelegramMutationResult
    {
        $this->attempts++;

        return new TelegramMutationResult(
            TelegramMutationOutcome::Success,
            'unexpected_transport_call',
            messageId: 999,
        );
    }
}
