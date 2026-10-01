<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

use Closure;

interface UpdateOperationLock
{
    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     */
    public function synchronized(Closure $operation): mixed;
}
