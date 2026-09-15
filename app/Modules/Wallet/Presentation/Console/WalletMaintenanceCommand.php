<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Presentation\Console;

use App\Modules\Wallet\Application\WalletMaintenanceService;
use DomainException;
use Illuminate\Console\Command;

/** @requirement WAL-002 QUA-001 */
final class WalletMaintenanceCommand extends Command
{
    protected $signature = 'wallet:maintenance
        {--hold-limit=100 : Maximum expired active holds to examine}
        {--wallet-limit=200 : Maximum active wallet accounts to reconcile}
        {--json : Emit JSON only}';

    protected $description = 'Release expired wallet holds and append authoritative wallet reconciliation snapshots';

    public function handle(WalletMaintenanceService $maintenance): int
    {
        try {
            $result = $maintenance->run(
                (int) $this->option('hold-limit'),
                (int) $this->option('wallet-limit'),
            );
        } catch (DomainException $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'status' => 'invalid',
                    'code' => 'wallet_maintenance_invalid_input',
                ], JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::INVALID;
        }

        $payload = [
            'status' => $result->requiresReview() ? 'review_required' : 'healthy',
            'expired_holds' => [
                'examined' => $result->cleanup->examined,
                'released' => $result->cleanup->released,
                'replayed' => $result->cleanup->replayed,
                'review_count' => count($result->cleanup->reviewHoldIds),
            ],
            'wallets' => [
                'examined' => $result->walletsExamined,
                'reconciled' => $result->walletsReconciled,
                'initial' => $result->initialSnapshots,
                'matched' => $result->matchedSnapshots,
                'refreshed' => $result->refreshedSnapshots,
                'review_count' => $result->walletReviewCount,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } elseif ($result->requiresReview()) {
            $this->error(sprintf(
                'Wallet maintenance requires review: %d hold row(s), %d wallet account(s).',
                count($result->cleanup->reviewHoldIds),
                $result->walletReviewCount,
            ));
        } else {
            $this->info(sprintf(
                'Wallet maintenance healthy: %d hold row(s) examined, %d wallet account(s) reconciled.',
                $result->cleanup->examined,
                $result->walletsReconciled,
            ));
        }

        return $result->requiresReview() ? self::FAILURE : self::SUCCESS;
    }
}
