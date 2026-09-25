<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceLifecycleExecutor;
use App\Modules\Telegram\Application\TelegramOwnedServiceAction;
use App\Modules\Telegram\Application\TelegramOwnedServiceLifecycleResult;
use App\Modules\Telegram\Application\TelegramOwnedServiceLifecycleStatus;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/** @requirement SVC-004 SVC-006 ARCH-002 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-003 QUA-004 */
final readonly class TelegramOwnedServiceLifecycleService implements TelegramOwnedServiceLifecycleExecutor
{
    public function __construct(
        private DatabaseManager $database,
        private ServiceLifecycleCommandService $lifecycle,
        private ServiceSynchronizationService $synchronization,
        private ServiceCustomerOperationPolicyGuard $customerPolicies,
    ) {}

    public function executeForSelf(
        int $actorUserId,
        string $servicePublicId,
        TelegramOwnedServiceAction $action,
        string $requestKey,
        string $correlationId,
    ): TelegramOwnedServiceLifecycleResult {
        if ($actorUserId < 1 || ! Str::isUlid($servicePublicId) || ! $action->isLifecycleAction()) {
            throw new DomainException('Telegram Service lifecycle request is invalid.');
        }

        if ($action === TelegramOwnedServiceAction::RefreshDetails) {
            $this->assertRefreshAllowed($actorUserId, $servicePublicId);
            $run = $this->synchronization->syncOne($servicePublicId);

            return new TelegramOwnedServiceLifecycleResult(
                $action,
                $run->requiresAttention()
                    ? TelegramOwnedServiceLifecycleStatus::RefreshAttention
                    : TelegramOwnedServiceLifecycleStatus::Refreshed,
            );
        }

        $context = new ServiceLifecycleCommandContext(
            $requestKey,
            $correlationId,
            'telegram_my_services',
            'Customer confirmed a Telegram My Services lifecycle action.',
            actorUserId: $actorUserId,
        );

        match ($action) {
            TelegramOwnedServiceAction::ResetUsage => $this->lifecycle->resetUsage($servicePublicId, $context),
            TelegramOwnedServiceAction::Suspend => $this->lifecycle->suspend($servicePublicId, $context),
            TelegramOwnedServiceAction::Activate => $this->lifecycle->activate($servicePublicId, $context),
            TelegramOwnedServiceAction::RotateSubscriptionLink => $this->lifecycle->rotateSubscriptionLink($servicePublicId, $context),
            TelegramOwnedServiceAction::Delete => $this->lifecycle->retire($servicePublicId, $context),
            default => throw new DomainException('Telegram Service lifecycle action is unsupported.'),
        };

        return new TelegramOwnedServiceLifecycleResult($action, TelegramOwnedServiceLifecycleStatus::Queued);
    }

    private function assertRefreshAllowed(int $actorUserId, string $servicePublicId): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($actorUserId, $servicePublicId): void {
            /** @var object{id:int|string,user_id:int|string,order_item_id:int|string,service_target_id:int|string|null}|null $service */
            $service = $connection->table('service_subscriptions')
                ->where('public_id', $servicePublicId)
                ->lockForUpdate()
                ->first(['id', 'user_id', 'order_item_id', 'service_target_id']);
            if ($service === null) {
                throw new DomainException('Service Subscription does not exist.');
            }

            $this->customerPolicies->assertCurrentForUpdate(
                $connection,
                $service,
                $actorUserId,
                'refresh_details',
                ['fetch_status'],
            );
        }, 3);
    }
}
