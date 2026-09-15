<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use DomainException;

final class CardToCardSettlementUnavailable extends DomainException {}
