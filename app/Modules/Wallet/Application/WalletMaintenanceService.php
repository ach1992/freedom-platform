<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\WalletReconciliationStatus;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class WalletMaintenanceService
{
    public function __construct(
        private DatabaseManager $database,
        private ExpiredWalletHoldCleanupService $cleanup,
        private WalletReconciliationService $reconciliation,
    ) {}

    /** @requirement WAL-002 DAT-003 DAT-004 QUA-001 */
    public function run(int $holdLimit = 100, int $walletLimit = 200): WalletMaintenanceResult
    {
        $this->assertLimit($holdLimit, 'Wallet maintenance hold limit');
        $this->assertLimit($walletLimit, 'Wallet maintenance account limit');

        $cleanup = $this->cleanup->cleanup($holdLimit);

        /** @var list<object{id: int|string, owner_user_id: int|string}> $accounts */
        $accounts = $this->database->connection()->table('ledger_accounts')
            ->whereNotNull('owner_user_id')
            ->whereIn('wallet_bucket', ['cash', 'promotional'])
            ->where('currency', 'IRR')
            ->where('is_active', true)
            ->orderBy('id')
            ->limit($walletLimit)
            ->get(['id', 'owner_user_id'])
            ->all();

        $reconciled = 0;
        $initial = 0;
        $matched = 0;
        $refreshed = 0;
        $reviewCount = 0;

        foreach ($accounts as $account) {
            try {
                $accountId = $this->positiveDatabaseInt($account->id, 'Wallet maintenance account ID');
                $ownerUserId = $this->positiveDatabaseInt($account->owner_user_id, 'Wallet maintenance owner user ID');
                $result = $this->reconciliation->reconcile($ownerUserId, $accountId);
                $reconciled++;

                match ($result->status) {
                    WalletReconciliationStatus::Initial => $initial++,
                    WalletReconciliationStatus::Matched => $matched++,
                    WalletReconciliationStatus::Refreshed => $refreshed++,
                };
            } catch (Throwable) {
                $reviewCount++;
            }
        }

        if ($initial + $matched + $refreshed !== $reconciled) {
            throw new RuntimeException('Wallet maintenance reconciliation counters are inconsistent.');
        }

        return new WalletMaintenanceResult(
            $cleanup,
            count($accounts),
            $reconciled,
            $initial,
            $matched,
            $refreshed,
            $reviewCount,
        );
    }

    private function assertLimit(int $value, string $label): void
    {
        if ($value < 1 || $value > 500) {
            throw new DomainException($label.' must be between 1 and 500.');
        }
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
