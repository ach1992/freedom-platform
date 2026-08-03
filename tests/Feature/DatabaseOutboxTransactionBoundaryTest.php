<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Application\Clock;
use App\Shared\Application\SafeOutboxPayload;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Tests\TestCase;

final class DatabaseOutboxTransactionBoundaryTest extends TestCase
{
    /** @requirement PAY-003 ARCH-004 OPS-003 QUA-004 */
    public function test_publish_requires_an_active_transaction(): void
    {
        $publisher = new DatabaseOutboxPublisher(
            app(DatabaseManager::class),
            new class implements Clock
            {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-08-03T00:00:00+00:00');
                }
            },
        );

        $this->expectException(LogicException::class);

        $publisher->publish(
            '0198a4c7-ff31-7bb9-8222-000000000001',
            'order:1:paid:v1',
            'order.paid',
            'order',
            '1',
            new SafeOutboxPayload(['order_id' => '1']),
            '0198a4c7-ff31-7bb9-8222-000000000002',
        );
    }
}
