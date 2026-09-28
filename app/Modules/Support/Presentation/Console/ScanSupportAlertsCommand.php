<?php

declare(strict_types=1);

namespace App\Modules\Support\Presentation\Console;

use App\Modules\Support\Application\SupportAlertService;
use App\Modules\Support\Application\SupportCustomerDeliveryAlertScanner;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

final class ScanSupportAlertsCommand extends Command
{
    protected $signature = 'support:alerts:scan
        {--limit=100 : Maximum tickets and delivery failures to inspect per category}
        {--json : Emit JSON only}';

    protected $description = 'Scan bounded Support SLA and material customer-delivery alert conditions';

    public function handle(
        SupportAlertService $supportAlerts,
        SupportCustomerDeliveryAlertScanner $deliveryAlerts,
    ): int {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000]],
        );
        if ($limit === false) {
            $this->error('Support alert scan limit must be between 1 and 1000.');

            return self::INVALID;
        }

        try {
            $slaAlerts = $supportAlerts->scanSla($limit);
            $deliveryAlertsRecorded = $deliveryAlerts->scan($limit);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $result = [
            'status' => 'ok',
            'sla_alerts' => $slaAlerts,
            'delivery_alerts' => $deliveryAlertsRecorded,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Support alerts scanned: SLA=%d delivery=%d',
                $slaAlerts,
                $deliveryAlertsRecorded,
            ));
        }

        return self::SUCCESS;
    }
}
