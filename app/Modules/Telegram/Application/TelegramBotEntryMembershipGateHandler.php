<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;

final readonly class TelegramBotEntryMembershipGateHandler implements TelegramInteractionHandler
{
    public function __construct(private TelegramNavigationEntryGateway $entry) {}

    public function flow(): string
    {
        return TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        $this->entry->handleMembershipGateAction($action);
    }
}
