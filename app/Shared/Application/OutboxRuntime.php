<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface OutboxRuntime
{
    public function dispatchBatch(int $limit): OutboxRuntimeResult;
}
