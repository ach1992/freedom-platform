<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use DomainException;

final class QuoteDiscountSourceQuoteUnavailable extends DomainException {}
