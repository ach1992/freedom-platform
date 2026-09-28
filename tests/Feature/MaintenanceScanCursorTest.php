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
