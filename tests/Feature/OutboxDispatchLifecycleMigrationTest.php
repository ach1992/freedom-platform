<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @requirement PAY-003 ARCH-004 OPS-003 QUA-004 */
final class OutboxDispatchLifecycleMigrationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_legacy_processed_messages_are_backfilled_and_pending_messages_remain_pending(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_12_000200_add_dispatch_lifecycle_to_outbox_messages.php');
        $migration->down();

        self::assertFalse(Schema::hasColumn('outbox_messages', 'dispatch_state'));

        $this->insertLegacyMessage('0198a4c7-ff31-7bb9-8222-000000000301', 'legacy:processed:v1', '2026-08-12 00:00:00.000000');
        $this->insertLegacyMessage('0198a4c7-ff31-7bb9-8222-000000000302', 'legacy:pending:v1', null);

        $migration->up();

        self::assertSame('processed', DB::table('outbox_messages')->where('event_key', 'legacy:processed:v1')->value('dispatch_state'));
        self::assertSame('pending', DB::table('outbox_messages')->where('event_key', 'legacy:pending:v1')->value('dispatch_state'));
    }

    private function insertLegacyMessage(string $id, string $eventKey, ?string $processedAt): void
    {
        DB::table('outbox_messages')->insert([
            'id' => $id,
            'event_key' => $eventKey,
            'event_type' => 'order.paid',
            'aggregate_type' => 'order',
            'aggregate_id' => '1',
            'payload' => json_encode(['order_id' => '1'], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', '{"order_id":"1"}'),
            'correlation_id' => '0198a4c7-ff31-7bb9-8222-000000000399',
            'available_at' => '2026-08-12 00:00:00.000000',
            'processed_at' => $processedAt,
            'attempts' => 0,
            'created_at' => '2026-08-12 00:00:00.000000',
            'updated_at' => '2026-08-12 00:00:00.000000',
        ]);
    }
}
