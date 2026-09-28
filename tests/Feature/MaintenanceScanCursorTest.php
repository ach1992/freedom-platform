<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Application\MaintenanceScanCursor;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement OPS-003 WAL-002 PAY-002 DAT-003 QUA-004 */
final class MaintenanceScanCursorTest extends TestCase
{
    use DatabaseTruncation;

    public function test_bounded_cursor_advances_before_processing_and_wraps_after_persistent_front_failure(): void
    {
        $walletIds = [];
        for ($index = 1; $index <= 3; $index++) {
            $userId = $this->user();
            $walletIds[] = $this->account('wallet.cash.cursor.'.$index, $userId);
        }

        /** @var \Closure(): list<object{id:int|string}> $claim */
        $claim = function (): array {
            return $this->app->make(MaintenanceScanCursor::class)->claim(
                'test.wallet.failure',
                1,
                static fn (Connection $connection) => $connection->table('ledger_accounts')
                    ->whereNotNull('owner_user_id')
                    ->where('wallet_bucket', 'cash')
                    ->where('currency', 'IRR')
                    ->where('is_active', true),
                'ledger_accounts.id',
                ['ledger_accounts.id'],
            );
        };

        // Simulate a permanent processing failure by deliberately leaving the first claimed
        // wallet untouched. Cursor progress must not depend on downstream success.
        $first = $claim();
        $second = $claim();
        $third = $claim();
        $wrapped = $claim();

        self::assertSame($walletIds[0], (int) $first[0]->id);
        self::assertSame($walletIds[1], (int) $second[0]->id);
        self::assertSame($walletIds[2], (int) $third[0]->id);
        self::assertSame($walletIds[0], (int) $wrapped[0]->id);
        self::assertSame(
            $walletIds[0],
            (int) DB::table('maintenance_scan_cursors')
                ->where('cursor_name', 'test.wallet.failure')
                ->value('last_scanned_id'),
        );
    }

    public function test_cycle_high_water_forces_wrap_under_continuous_equal_capacity_arrivals(): void
    {
        $firstId = $this->account('wallet.cash.cursor.arrival.1', $this->user());
        $secondId = $this->account('wallet.cash.cursor.arrival.2', $this->user());

        /** @var \Closure(): list<object{id:int|string}> $claim */
        $claim = function (): array {
            return $this->app->make(MaintenanceScanCursor::class)->claim(
                'test.wallet.continuous-arrival',
                1,
                static fn (Connection $connection) => $connection->table('ledger_accounts')
                    ->whereNotNull('owner_user_id')
                    ->where('wallet_bucket', 'cash')
                    ->where('currency', 'IRR')
                    ->where('is_active', true),
                'ledger_accounts.id',
                ['ledger_accounts.id'],
            );
        };

        $first = $claim();
        self::assertSame($firstId, (int) $first[0]->id);

        $thirdId = $this->account('wallet.cash.cursor.arrival.3', $this->user());
        $second = $claim();
        self::assertSame($secondId, (int) $second[0]->id);
        self::assertSame(
            $secondId,
            (int) DB::table('maintenance_scan_cursors')
                ->where('cursor_name', 'test.wallet.continuous-arrival')
                ->value('cycle_max_id'),
        );

        $fourthId = $this->account('wallet.cash.cursor.arrival.4', $this->user());
        $wrapped = $claim();

        self::assertSame($firstId, (int) $wrapped[0]->id);
        self::assertSame(
            $fourthId,
            (int) DB::table('maintenance_scan_cursors')
                ->where('cursor_name', 'test.wallet.continuous-arrival')
                ->value('cycle_max_id'),
        );
        self::assertGreaterThan($secondId, $thirdId);
        self::assertGreaterThan($thirdId, $fourthId);
    }

    public function test_older_row_becoming_eligible_after_cursor_passes_is_reclaimed_under_new_arrivals(): void
    {
        $olderId = $this->account('wallet.cash.cursor.late.1', $this->user());
        DB::table('ledger_accounts')->where('id', $olderId)->update([
            'is_active' => false,
            'updated_at' => now('UTC'),
        ]);
        $secondId = $this->account('wallet.cash.cursor.late.2', $this->user());
        $thirdId = $this->account('wallet.cash.cursor.late.3', $this->user());

        /** @var \Closure(): list<object{id:int|string}> $claim */
        $claim = function (): array {
            return $this->app->make(MaintenanceScanCursor::class)->claim(
                'test.wallet.late-eligibility',
                1,
                static fn (Connection $connection) => $connection->table('ledger_accounts')
                    ->whereNotNull('owner_user_id')
                    ->where('wallet_bucket', 'cash')
                    ->where('currency', 'IRR')
                    ->where('is_active', true),
                'ledger_accounts.id',
                ['ledger_accounts.id'],
            );
        };

        self::assertSame($secondId, (int) $claim()[0]->id);

        DB::table('ledger_accounts')->where('id', $olderId)->update([
            'is_active' => true,
            'updated_at' => now('UTC'),
        ]);
        $fourthId = $this->account('wallet.cash.cursor.late.4', $this->user());
        self::assertSame($thirdId, (int) $claim()[0]->id);

        $fifthId = $this->account('wallet.cash.cursor.late.5', $this->user());
        self::assertSame($olderId, (int) $claim()[0]->id);
        self::assertSame(
            $fifthId,
            (int) DB::table('maintenance_scan_cursors')
                ->where('cursor_name', 'test.wallet.late-eligibility')
                ->value('cycle_max_id'),
        );
        self::assertGreaterThan($thirdId, $fourthId);
        self::assertGreaterThan($fourthId, $fifthId);
    }

    public function test_cursor_persists_across_service_resolution_and_skips_ineligible_ids(): void
    {
        $firstUser = $this->user();
        $secondUser = $this->user();
        $thirdUser = $this->user();
        $firstId = $this->account('wallet.cash.cursor.persist.1', $firstUser);
        $secondId = $this->account('wallet.cash.cursor.persist.2', $secondUser);
        $thirdId = $this->account('wallet.cash.cursor.persist.3', $thirdUser);

        /** @var \Closure(MaintenanceScanCursor): list<object{id:int|string}> $claim */
        $claim = static function (MaintenanceScanCursor $cursor): array {
            return $cursor->claim(
                'test.wallet.restart',
                1,
                static fn (Connection $connection) => $connection->table('ledger_accounts')
                    ->whereNotNull('owner_user_id')
                    ->where('wallet_bucket', 'cash')
                    ->where('currency', 'IRR')
                    ->where('is_active', true),
                'ledger_accounts.id',
                ['ledger_accounts.id'],
            );
        };

        self::assertSame($firstId, (int) $claim($this->app->make(MaintenanceScanCursor::class))[0]->id);
        DB::table('ledger_accounts')->where('id', $secondId)->update([
            'is_active' => false,
            'updated_at' => now('UTC'),
        ]);

        self::assertSame($thirdId, (int) $claim($this->app->make(MaintenanceScanCursor::class))[0]->id);
        self::assertSame($firstId, (int) $claim($this->app->make(MaintenanceScanCursor::class))[0]->id);
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function account(string $code, int $userId): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
