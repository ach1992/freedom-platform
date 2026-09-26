<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Telegram\Application\Contracts\TelegramServiceNotificationPreferenceManager;
use App\Modules\Telegram\Application\TelegramServiceNotificationPreferenceOption;
use App\Modules\Telegram\Application\TelegramServiceNotificationPreferenceResult;
use App\Modules\Telegram\Application\TelegramServiceNotificationPreferenceSnapshot;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/** @requirement SVC-013 SEC-002 QUA-001 QUA-004 */
final readonly class TelegramServiceNotificationPreferenceService implements TelegramServiceNotificationPreferenceManager
{
    /** @var list<array{string,string}> */
    private const OPTIONS = [
        ['expiry', '*'],
        ['expiry', 'expiry_7d'],
        ['expiry', 'expiry_3d'],
        ['expiry', 'expiry_1d'],
        ['expiry', 'expiry_due'],
        ['usage', '*'],
        ['usage', 'usage_20pct'],
        ['usage', 'usage_10pct'],
        ['usage', 'usage_exhausted'],
        ['low_balance', '*'],
        ['low_balance', 'low_balance'],
        ['renewal_failure', '*'],
        ['renewal_failure', 'renewal_insufficient_wallet'],
        ['renewal_failure', 'renewal_price_change_blocked'],
        ['renewal_failure', 'renewal_failure'],
        ['service_state', '*'],
        ['service_state', 'state_suspended'],
        ['service_state', 'state_deleted'],
        ['sync_issue', '*'],
        ['sync_issue', 'sync_issue'],
    ];

    public function __construct(
        private DatabaseManager $database,
        private ServiceNotificationPreferenceResolver $resolver,
        private ServiceNotificationPreferenceService $preferences,
    ) {}

    public function snapshotForSelf(int $actorUserId, ?string $servicePublicId): TelegramServiceNotificationPreferenceSnapshot
    {
        [$serviceId, $normalizedPublicId] = $this->scope($actorUserId, $servicePublicId);
        $options = [];
        foreach (self::OPTIONS as [$type, $threshold]) {
            $options[] = new TelegramServiceNotificationPreferenceOption(
                $type,
                $threshold,
                $serviceId === null
                    ? $this->globalAllows($actorUserId, $type, $threshold)
                    : $this->resolver->allows($actorUserId, $serviceId, $type, $threshold),
            );
        }

        return new TelegramServiceNotificationPreferenceSnapshot($normalizedPublicId, $options);
    }

    public function configureForSelf(
        int $actorUserId,
        ?string $servicePublicId,
        string $notificationType,
        string $thresholdCode,
        bool $enabled,
        string $requestKey,
        string $correlationId,
    ): TelegramServiceNotificationPreferenceResult {
        $snapshot = $this->snapshotForSelf($actorUserId, $servicePublicId);
        if ($snapshot->option($notificationType, $thresholdCode) === null) {
            throw new DomainException('Telegram Service notification preference option is unsupported.');
        }

        $receipt = $this->preferences->configureForSelf(
            $requestKey,
            $actorUserId,
            $servicePublicId,
            $notificationType,
            $thresholdCode,
            $enabled,
            $correlationId,
        );

        return new TelegramServiceNotificationPreferenceResult(
            $receipt->notificationType,
            $receipt->thresholdCode,
            $receipt->enabled,
            $receipt->version,
            $receipt->replayed,
        );
    }

    /** @return array{?int,?string} */
    private function scope(int $actorUserId, ?string $servicePublicId): array
    {
        if ($actorUserId < 1) {
            throw new DomainException('Telegram Service notification preference actor is invalid.');
        }
        /** @var object{id:int|string,account_status:string,account_type:string}|null $user */
        $user = $this->database->connection()->table('users')
            ->where('id', $actorUserId)
            ->first(['id', 'account_status', 'account_type']);
        if ($user === null || $user->account_status !== 'active' || ! in_array($user->account_type, ['customer', 'agent'], true)) {
            throw new DomainException('Telegram Service notification preferences require an active customer or agent account.');
        }
        if ($servicePublicId === null) {
            return [null, null];
        }
        if (! Str::isUlid($servicePublicId)) {
            throw new DomainException('Telegram Service notification preference Service public ID is invalid.');
        }
        /** @var object{id:int|string,user_id:int|string}|null $service */
        $service = $this->database->connection()->table('service_subscriptions')
            ->where('public_id', $servicePublicId)
            ->first(['id', 'user_id']);
        if ($service === null || (int) $service->user_id !== $actorUserId) {
            throw new DomainException('Telegram Service notification preferences are owner-only.');
        }

        return [(int) $service->id, $servicePublicId];
    }

    private function globalAllows(int $ownerUserId, string $notificationType, string $thresholdCode): bool
    {
        $scope = ServiceNotificationPreferenceResolver::globalScopeHash($ownerUserId);
        foreach ([$thresholdCode, '*'] as $threshold) {
            $enabled = $this->database->connection()->table('service_notification_preferences')
                ->where('owner_user_id', $ownerUserId)
                ->whereNull('service_subscription_id')
                ->where('scope_key_hash', $scope)
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
}
