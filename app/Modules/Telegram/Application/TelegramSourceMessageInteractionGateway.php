<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramBroadcastNavigationResolver;

final readonly class TelegramSourceMessageInteractionGateway
{
    private const ADMIN_DIRECT_MESSAGE_SOURCE_STATE = 'admin_customer_message_source';

    private const BROADCAST_SOURCE_STATE = 'admin_broadcast_source_wait';

    public function __construct(
        private TelegramInteractionSessionService $sessions,
        private TelegramAdminCustomerNavigationHandler $adminCustomers,
        private TelegramBroadcastNavigationResolver $broadcastResolver,
    ) {}

    public function awaitingSource(int $telegramAccountId): bool
    {
        if ($telegramAccountId < 1) {
            return false;
        }

        $session = $this->sessions->activeForAccount($telegramAccountId);

        return $session !== null
            && $session->flow === TelegramNavigationEntryGateway::FLOW
            && in_array($session->state, [
                self::ADMIN_DIRECT_MESSAGE_SOURCE_STATE,
                self::BROADCAST_SOURCE_STATE,
            ], true);
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function handle(TelegramSourceMessageInteraction $interaction): bool
    {
        if ($interaction->flow !== TelegramNavigationEntryGateway::FLOW) {
            return false;
        }
        if ($interaction->sessionState === self::ADMIN_DIRECT_MESSAGE_SOURCE_STATE) {
            return $this->adminCustomers->handleSourceMessage($interaction);
        }
        if ($interaction->sessionState === self::BROADCAST_SOURCE_STATE) {
            return $this->broadcastResolver->resolve()->handleSourceMessage($interaction);
        }

        return false;
    }
}
