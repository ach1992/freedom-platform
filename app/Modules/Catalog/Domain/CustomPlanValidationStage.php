<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

enum CustomPlanValidationStage: string
{
    case PrePayment = 'pre_payment';
    case PreProvisioning = 'pre_provisioning';
}
