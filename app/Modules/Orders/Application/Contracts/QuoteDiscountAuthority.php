<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Contracts;

use App\Modules\Orders\Application\QuoteDiscountAuthorization;
use App\Modules\Orders\Application\QuoteDiscountAuthorizationRequest;
use App\Modules\Orders\Application\QuoteDiscountConsumptionReceipt;
use App\Modules\Orders\Application\QuoteDiscountConsumptionRequest;

interface QuoteDiscountAuthority
{
    public function authorize(QuoteDiscountAuthorizationRequest $request): QuoteDiscountAuthorization;

    public function consume(QuoteDiscountConsumptionRequest $request): QuoteDiscountConsumptionReceipt;
}
