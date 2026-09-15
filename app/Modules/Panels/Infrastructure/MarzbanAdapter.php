<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\MarzbanGateway;

final class MarzbanAdapter extends DelegatingPanelAdapter
{
    public function __construct(MarzbanGateway $gateway)
    {
        parent::__construct($gateway);
    }
}
