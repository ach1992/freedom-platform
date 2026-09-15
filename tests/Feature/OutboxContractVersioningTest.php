<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryDatabaseAuthoritySurfaceV1;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use App\Shared\Application\OutboxMessageRouter;
use App\Shared\Infrastructure\DatabaseOutboxContractRetirementGuard;
use App\Shared\Infrastructure\DatabaseOutboxDispatcher;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
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

    public function test_durable_contract_version_cannot_be_mutated_after_persistence(): void
    {
        $id = '0198a4c7-ff31-7bb9-8222-000000018405';
        $this->insertMessage($id, 'immutable.example', 1);

        try {
            DB::table('outbox_messages')->where('id', $id)->update(['contract_version' => 2]);
            self::fail('A persisted Outbox contract version must be immutable.');
        } catch (QueryException) {
            self::assertSame(1, (int) DB::table('outbox_messages')->where('id', $id)->value('contract_version'));
        }
    }

    public function test_version_guard_preserves_telegram_outbox_terminal_authority(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            self::markTestSkipped('Telegram delivery semantic attestation requires MariaDB/MySQL.');
        }

        $surface = new TelegramDeliveryDatabaseAuthoritySurfaceV1;
        self::assertSame(
            [],
            $surface->semanticAttestationFailures($connection),
            'Outbox contract versioning must remain an explicitly attested extension of Telegram delivery authority.',
        );
        self::assertTrue($surface->semanticsMatchExpected($connection));

        $orders = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $connection->getDatabaseName())
            ->whereIn('TRIGGER_NAME', [
                'outbox_contract_version_update_guard',
                'outbox_telegram_delivery_envelope_update_guard',
            ])
            ->pluck('ACTION_ORDER', 'TRIGGER_NAME');

        self::assertTrue(
            (int) $orders->get('outbox_contract_version_update_guard')
                < (int) $orders->get('outbox_telegram_delivery_envelope_update_guard'),
            'The shared version guard must execute before the Telegram terminal mutation guard.',
        );
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

    public function test_persisted_v1_message_survives_release_evolution_and_unsupported_version_fails_closed(): void
    {
        $eventType = 'release.evolved.example';
        $v1Id = '0198a4c7-ff31-7bb9-8222-000000018406';
        $unsupportedId = '0198a4c7-ff31-7bb9-8222-000000018407';
        $this->insertMessage($v1Id, $eventType, null);

        $v1 = new PersistedReleaseOutboxHandler($eventType, 1);
        $v2 = new PersistedReleaseOutboxHandler($eventType, 2);
        $router = new OutboxMessageRouter([$v1, $v2]);
        $dispatcher = new DatabaseOutboxDispatcher(
            $this->app->make(DatabaseManager::class),
            new FixedOutboxVersioningClock,
        );

        $v1Result = $dispatcher->dispatchOne($router);

        self::assertNotNull($v1Result);
        self::assertSame($v1Id, $v1Result->messageId);
        self::assertSame(OutboxDispatchOutcome::Success, $v1Result->outcome);
        self::assertSame([1], $v1->handledVersions);
        self::assertSame([], $v2->handledVersions);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $v1Id,
            'contract_version' => 1,
            'dispatch_state' => 'processed',
            'attempts' => 1,
        ]);

        $this->insertMessage($unsupportedId, $eventType, 3);

        $unsupportedResult = $dispatcher->dispatchOne($router);

        self::assertNotNull($unsupportedResult);
        self::assertSame($unsupportedId, $unsupportedResult->messageId);
        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $unsupportedResult->outcome);
        self::assertSame([1], $v1->handledVersions);
        self::assertSame([], $v2->handledVersions);
        $this->assertDatabaseHas('outbox_messages', [
            'id' => $unsupportedId,
            'contract_version' => 3,
            'dispatch_state' => 'review_required',
            'attempts' => 1,
            'review_reason' => OutboxDispatchOutcome::DefinitiveFailure->value,
        ]);
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

final class PersistedReleaseOutboxHandler implements OutboxEventHandler
{
    /** @var list<int> */
    public array $handledVersions = [];

    public function __construct(
        private readonly string $type,
        private readonly int $version,
    ) {}

    public function eventType(): string
    {
        return $this->type;
    }

    public function contractVersion(): int
    {
        return $this->version;
    }

    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $this->handledVersions[] = $message->contractVersion;

        return OutboxDispatchOutcome::Success;
    }
}

final class FixedOutboxVersioningClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-30T10:00:00+00:00');
    }
}
