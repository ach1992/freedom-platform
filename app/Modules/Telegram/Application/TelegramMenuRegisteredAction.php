<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

enum TelegramMenuRegisteredAction: string
{
    case MyAccount = 'my_account';
    case WalletTransfer = 'wallet_transfer';
    case Purchase = 'purchase';
    case Trial = 'trial';
    case MyServices = 'my_services';
    case NotificationPreferences = 'notification_preferences';
    case ClientGuides = 'client_guides';
    case Support = 'support';
    case ExternalSupport = 'external_support';
    case Agent = 'agent';
    case Admin = 'admin';
    case Referral = 'referral';
}
