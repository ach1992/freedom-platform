<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

interface SupportCustomerDeliveryAlertScanner
{
    public function scan(int $limit): int;
}
