<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

use Closure;

interface UpdateMutationFence
{
    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     */
    public function run(Closure $operation): mixed;
}
