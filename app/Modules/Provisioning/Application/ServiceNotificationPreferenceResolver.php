<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Database\DatabaseManager;

/** @requirement SVC-013 DAT-003 */
final readonly class ServiceNotificationPreferenceResolver
{
    public function __construct(private DatabaseManager $database) {}

    public function allows(
        int $ownerUserId,
        int $serviceSubscriptionId,
        string $notificationType,
        string $thresholdCode,
    ): bool {
        $connection = $this->database->connection();
        $serviceScope = self::serviceScopeHash($ownerUserId, $serviceSubscriptionId);
        $globalScope = self::globalScopeHash($ownerUserId);

        foreach ([
            [$serviceScope, $thresholdCode],
            [$serviceScope, '*'],
            [$globalScope, $thresholdCode],
            [$globalScope, '*'],
        ] as [$scopeHash, $threshold]) {
            $enabled = $connection->table('service_notification_preferences')
                ->where('owner_user_id', $ownerUserId)
                ->where('scope_key_hash', $scopeHash)
                ->where('notification_type', $notificationType)
                ->where('threshold_code', $threshold)
                ->where('audience', 'customer')
                ->where('destination', 'telegram')
                ->value('enabled');
            if ($enabled !== null) {
                return (bool) $enabled;
            }
        }

        return true;
    }

    public static function globalScopeHash(int $ownerUserId): string
    {
        return hash('sha256', 'service-notification-preference-global-v1|'.$ownerUserId);
    }

    public static function serviceScopeHash(int $ownerUserId, int $serviceSubscriptionId): string
    {
        return hash('sha256', 'service-notification-preference-service-v1|'.$ownerUserId.'|'.$serviceSubscriptionId);
    }
}
