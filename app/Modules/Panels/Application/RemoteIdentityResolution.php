<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Domain\RemoteIdentityDisposition;

final readonly class RemoteIdentityResolution
{
    public function __construct(
        public RemoteIdentityDisposition $disposition,
        public ?RemoteServiceSnapshot $service,
        public string $reasonCode,
    ) {}
}
