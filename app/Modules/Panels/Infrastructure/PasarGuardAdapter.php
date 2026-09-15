<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PasarGuardGateway;

final class PasarGuardAdapter extends DelegatingPanelAdapter
{
    public function __construct(PasarGuardGateway $gateway)
    {
        parent::__construct($gateway);
    }
}
