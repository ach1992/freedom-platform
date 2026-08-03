<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Application\Clock;
use App\Shared\Application\SafeOutboxPayload;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class DatabaseOutboxPublisherTest extends TestCase
{
    /** @requirement PAY-003 ARCH-004 OPS-003 QUA-004 */
    use RefreshDatabase;

    private const EVENT_ID = '0198a4c7-ff31-7bb9-8222-000000000001';

    public function test_publish_requires_an_active_transaction(): void
    {
        $this->expectException(LogicException::class);

        $this->publisher()->publish(
            self::EVENT_ID,
            'order:1:paid:v1',
            'order.paid',
            'order',
            '1',
            new SafeOutboxPayload(['order_id' => '1']),
            '0198a4c7-ff31-7bb9-8222-000000000002',
        );
    }

    public function test_duplicate_event_key_returns_the_recorded_result_without_a_second_row(): void
    {
        $database = app(DatabaseManager::class);
        $publisher = $this->publisher();
        $payload = new SafeOutboxPayload(['order_id' => '1']);

        $database->transaction(function () use ($publisher, $payload): void {
            $first = $publisher->publish(
                self::EVENT_ID,
                'order:1:paid:v1',
                'order.paid',
                'order',
                '1',
                $payload,
                '0198a4c7-ff31-7bb9-8222-000000000002',
            );
            $duplicate = $publisher->publish(
                '0198a4c7-ff31-7bb9-8222-000000000003',
                'order:1:paid:v1',
                'order.paid',
                'order',
                '1',
                $payload,
                '0198a4c7-ff31-7bb9-8222-000000000002',
            );

            self::assertSame($first, $duplicate);
        });

        self::assertDatabaseCount('outbox_messages', 1);
    }

    private function publisher(): DatabaseOutboxPublisher
    {
        return new DatabaseOutboxPublisher(
            app(DatabaseManager::class),
            new class implements Clock {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-08-03T00:00:00+00:00');
                }
            },
        );
    }
}
