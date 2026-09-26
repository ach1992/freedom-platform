<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation\Console;

use App\Modules\Customers\Application\CustomerTierMaintenanceService;
use Illuminate\Console\Command;
use Throwable;

final class RecalculateCustomerTiersCommand extends Command
{
    protected $signature = 'customers:recalculate-tiers
        {--batch=500 : Customer profiles to examine per database batch}
        {--json : Emit JSON only}';

    protected $description = 'Recalculate automatic customer tiers from current authoritative metrics';

    public function handle(CustomerTierMaintenanceService $maintenance): int
    {
        $batchSize = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batchSize === false || $batchSize < 1 || $batchSize > 1000) {
            return $this->invalid('Customer tier maintenance batch size must be between 1 and 1000.');
        }

        try {
            $result = $maintenance->process($batchSize);
        } catch (Throwable) {
            if ($this->option('json')) {
                $this->line('{"status":"failed","code":"customer_tier_maintenance_failed"}');
            } else {
                $this->error('Customer tier maintenance failed unexpectedly.');
            }

            return self::FAILURE;
        }

        $payload = ['status' => 'ok'] + $result;
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Customer tier maintenance complete: examined=%d changed=%d',
                $result['examined'],
                $result['changed'],
            ));
        }

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        if ($this->option('json')) {
            $this->line('{"status":"invalid","code":"customer_tier_maintenance_invalid_input"}');
        } else {
            $this->error($message);
        }

        return self::INVALID;
    }
}
