<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramSourceMessageInteractionGateway
{
    private const ADMIN_DIRECT_MESSAGE_SOURCE_STATE = 'admin_customer_message_source';

    public function __construct(
        private TelegramInteractionSessionService $sessions,
        private TelegramAdminCustomerNavigationHandler $adminCustomers,
    ) {}

    public function awaitingSource(int $telegramAccountId): bool
    {
        if ($telegramAccountId < 1) {
            return false;
        }

        $session = $this->sessions->activeForAccount($telegramAccountId);

        return $session !== null
            && $session->flow === TelegramNavigationEntryGateway::FLOW
            && $session->state === self::ADMIN_DIRECT_MESSAGE_SOURCE_STATE;
    }

    /** @requirement COM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
    public function handle(TelegramSourceMessageInteraction $interaction): bool
    {
        if ($interaction->flow !== TelegramNavigationEntryGateway::FLOW
            || $interaction->sessionState !== self::ADMIN_DIRECT_MESSAGE_SOURCE_STATE) {
            return false;
        }

        return $this->adminCustomers->handleSourceMessage($interaction);
    }
}
