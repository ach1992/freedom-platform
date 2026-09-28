<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\WalletHoldStatus;
use App\Shared\Application\Clock;
use App\Shared\Application\MaintenanceScanCursor;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final readonly class ExpiredWalletHoldCleanupService
{
    private const RELEASE_REASON = 'system expired hold cleanup';

    public function __construct(
        private Clock $clock,
        private WalletHoldService $holds,
        private MaintenanceScanCursor $scanCursor,
    ) {}

    /** @requirement WAL-002 DAT-003 DAT-004 QUA-001 */
    public function cleanup(int $limit = 100): ExpiredWalletHoldCleanupResult
    {
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Expired wallet hold cleanup limit must be between 1 and 500.');
        }

        $expiresAt = $this->clock->now()->format('Y-m-d H:i:s.u');
        /** @var list<object{id: int|string, hold_key: string}> $rows */
        $rows = $this->scanCursor->claim(
            'wallet.expired-holds',
            $limit,
            static fn (Connection $connection) => $connection->table('wallet_holds')
                ->where('status', WalletHoldStatus::Active->value)
                ->where('source_type', '<>', 'wallet_transfer')
                ->where('expires_at', '<=', $expiresAt),
            'wallet_holds.id',
            ['wallet_holds.id', 'wallet_holds.hold_key'],
        );

        $released = 0;
        $replayed = 0;
        $reviewHoldIds = [];

        foreach ($rows as $row) {
            $holdId = $this->positiveDatabaseInt($row->id, 'Expired wallet hold ID');
            try {
                $receipt = $this->holds->release($row->hold_key, self::RELEASE_REASON);
                if ($receipt->holdId !== $holdId) {
                    throw new RuntimeException('Expired wallet hold cleanup resolved a different hold.');
                }
                if ($receipt->replayed) {
                    $replayed++;
                } else {
                    $released++;
                }
            } catch (Throwable) {
                $reviewHoldIds[] = $holdId;
            }
        }

        return new ExpiredWalletHoldCleanupResult(
            count($rows),
            $released,
            $replayed,
            $reviewHoldIds,
        );
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new RuntimeException($label.' must be positive.');
            }

            return $value;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new RuntimeException($label.' is malformed.');
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new RuntimeException($label.' exceeds the supported integer range.');
        }

        return (int) $value;
    }
}
