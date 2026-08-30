<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Infrastructure\DatabaseOutboxContractRetirementGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class OutboxContractVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_rows_without_explicit_version_default_to_v1(): void
    {
        $id = '0198a4c7-ff31-7bb9-8222-000000018401';
        $this->insertMessage($id, 'legacy.example', null);

        self::assertSame(1, (int) DB::table('outbox_messages')->where('id', $id)->value('contract_version'));
    }

    public function test_retirement_guard_blocks_until_matching_durable_messages_are_processed(): void
    {
        $id = '0198a4c7-ff31-7bb9-8222-000000018402';
        $this->insertMessage($id, 'legacy.example', 1);
        $guard = $this->app->make(DatabaseOutboxContractRetirementGuard::class);

        try {
            $guard->assertRetirable('legacy.example', 1);
            self::fail('An unprocessed durable contract must block handler retirement.');
        } catch (LogicException $exception) {
            self::assertSame('Outbox contract version still has unprocessed durable messages.', $exception->getMessage());
        }

        DB::table('outbox_messages')->where('id', $id)->update([
            'dispatch_state' => 'processed',
            'processed_at' => '2026-08-30 10:00:00.000000',
        ]);

        $guard->assertRetirable('legacy.example', 1);
    }

    public function test_schema_rollback_refuses_to_erase_non_v1_durable_history(): void
    {
        $this->insertMessage(
            '0198a4c7-ff31-7bb9-8222-000000018404',
            'evolved.example',
            2,
        );
        $migration = require database_path('migrations/2026_08_30_000100_add_outbox_contract_versions.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Outbox contract versioning cannot be removed while non-v1 durable history exists.');

        $migration->down();
    }

    public function test_operator_command_is_fail_closed_and_redacted(): void
    {
        $id = '0198a4c7-ff31-7bb9-8222-000000018403';
        $this->insertMessage($id, 'legacy.example', 1);

        $blocked = Artisan::call('operations:check-outbox-contract-retirement', [
            'event-type' => 'legacy.example',
            'version' => 1,
            '--json' => true,
        ]);
        self::assertSame(1, $blocked);
        self::assertSame(
            '{"status":"blocked","code":"outbox_contract_retirement_pending_messages"}',
            trim(Artisan::output()),
        );

        DB::table('outbox_messages')->where('id', $id)->update([
            'dispatch_state' => 'processed',
            'processed_at' => '2026-08-30 10:00:00.000000',
        ]);

        $safe = Artisan::call('operations:check-outbox-contract-retirement', [
            'event-type' => 'legacy.example',
            'version' => 1,
            '--json' => true,
        ]);
        self::assertSame(0, $safe);
        self::assertSame(
            '{"status":"safe","code":"outbox_contract_retirement_safe"}',
            trim(Artisan::output()),
        );
    }

    private function insertMessage(string $id, string $eventType, ?int $contractVersion): void
    {
        $payload = '{"public_id":"legacy-aggregate-1"}';
        $row = [
            'id' => $id,
            'event_key' => $eventType.':'.$id,
            'event_type' => $eventType,
            'aggregate_type' => 'legacy',
            'aggregate_id' => 'legacy-aggregate-1',
            'payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
            'correlation_id' => 'correlation-contract-version-test',
            'available_at' => '2026-08-30 10:00:00.000000',
            'dispatch_state' => 'pending',
            'attempts' => 0,
            'created_at' => '2026-08-30 10:00:00.000000',
            'updated_at' => '2026-08-30 10:00:00.000000',
        ];
        if ($contractVersion !== null) {
            $row['contract_version'] = $contractVersion;
        }

        DB::table('outbox_messages')->insert($row);
    }
}
