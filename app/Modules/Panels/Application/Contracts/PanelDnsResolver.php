<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

interface PanelDnsResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
