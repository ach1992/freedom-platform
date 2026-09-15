<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Console;

use App\Modules\Payments\Application\PurchasePaymentMaintenanceService;
use Illuminate\Console\Command;

final class PurchasePaymentMaintenanceCommand extends Command
{
    protected $signature = 'payments:purchase-maintenance {--limit=100} {--json}';

    protected $description = 'Expire abandoned purchase wallet/card-to-card intents and release eligible promotion usage.';

    public function handle(PurchasePaymentMaintenanceService $maintenance): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 500.');

            return self::INVALID;
        }
        $result = $maintenance->run($limit);
        $payload = [
            'wallet_intents_examined' => $result->walletIntentsExamined,
            'expired_wallet_intents' => $result->expiredWalletIntents,
            'c2c_intents_examined' => $result->c2cIntentsExamined,
            'expired_c2c_intents' => $result->expiredC2cIntents,
            'promotion_reservations_examined' => $result->promotionReservationsExamined,
            'released_promotion_reservations' => $result->releasedPromotionReservations,
            'failures' => $result->failures,
        ];
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->table(array_keys($payload), [array_values($payload)]);
        }

        return $result->failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
