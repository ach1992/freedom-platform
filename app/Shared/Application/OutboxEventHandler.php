<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface OutboxEventHandler extends OutboxMessageHandler
{
    public function eventType(): string;
}
