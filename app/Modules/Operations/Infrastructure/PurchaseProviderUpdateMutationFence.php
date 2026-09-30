<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\UpdateMutationFence;
use App\Modules\Payments\Application\PurchaseProviderMutationBarrier;
use Closure;

final readonly class PurchaseProviderUpdateMutationFence implements UpdateMutationFence
{
    public function __construct(private PurchaseProviderMutationBarrier $barrier) {}

    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     *
     * @requirement UPD-001 PAY-003 QUA-001
     */
    public function run(Closure $operation): mixed
    {
        return $this->barrier->blockAll($operation);
    }
}
