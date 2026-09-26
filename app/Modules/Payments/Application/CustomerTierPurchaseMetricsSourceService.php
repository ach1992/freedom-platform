<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Modules\Customers\Application\Contracts\CustomerTierPurchaseMetricsSource;
use App\Modules\Customers\Application\CustomerTierPurchaseMetrics;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class CustomerTierPurchaseMetricsSourceService implements CustomerTierPurchaseMetricsSource
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement USR-002 PAY-002 DAT-002 DAT-003 */
    public function metricsFor(int $userId): CustomerTierPurchaseMetrics
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Customer tier metrics user ID must be positive.');
        }

        $row = $this->database->connection()->table('purchase_settlements as settlement')
            ->where('settlement.user_id', $userId)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('purchase_refunds as refund')
                    ->whereColumn('refund.purchase_settlement_id', 'settlement.id');
            })
            ->selectRaw('COUNT(*) AS successful_purchase_count, COALESCE(SUM(settlement.amount_irr), 0) AS total_spend_irr')
            ->first();

        if ($row === null) {
            throw new RuntimeException('Customer tier purchase metrics query failed.');
        }

        return new CustomerTierPurchaseMetrics(
            $this->nonNegativeInt($row->successful_purchase_count, 'Successful purchase count'),
            $this->nonNegativeInt($row->total_spend_irr, 'Total purchase spend'),
        );
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new RuntimeException($label.' is invalid.');
        }
        if (! is_numeric($value) || (int) $value < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $value;
    }
}
