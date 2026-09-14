<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerTrialProvisioningStatus;
use App\Modules\Telegram\Application\TelegramCustomerTrialProvisioningStatusSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

final readonly class TelegramCustomerTrialProvisioningStatusService implements TelegramCustomerTrialProvisioningStatus
{
    public function __construct(private DatabaseManager $database) {}

    public function statusForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $provisioningPublicId,
        string $servicePublicId,
    ): TelegramCustomerTrialProvisioningStatusSnapshot {
        if ($actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram Trial provisioning status is unavailable for this actor.');
        }
        if (! $this->validUlid($provisioningPublicId) || ! $this->validUlid($servicePublicId)) {
            return new TelegramCustomerTrialProvisioningStatusSnapshot(
                TelegramCustomerTrialProvisioningStatusSnapshot::UNAVAILABLE,
            );
        }

        $row = $this->database->connection()
            ->table('provisioning_operations as operation')
            ->join('service_subscriptions as service', 'service.id', '=', 'operation.service_subscription_id')
            ->where('operation.operation_type', 'initial_provision')
            ->where('operation.user_id', $subjectUserId)
            ->where('service.user_id', $subjectUserId)
            ->whereRaw('BINARY operation.public_id = ?', [$provisioningPublicId])
            ->whereRaw('BINARY service.public_id = ?', [$servicePublicId])
            ->whereColumn('operation.order_id', 'service.order_id')
            ->whereColumn('operation.order_item_id', 'service.order_item_id')
            ->first(['operation.state', 'service.provisioned_at']);
        if ($row === null || ! is_string($row->state ?? null)) {
            return new TelegramCustomerTrialProvisioningStatusSnapshot(
                TelegramCustomerTrialProvisioningStatusSnapshot::UNAVAILABLE,
            );
        }

        $state = ProvisioningState::tryFrom($row->state);
        if ($state === null) {
            return new TelegramCustomerTrialProvisioningStatusSnapshot(
                TelegramCustomerTrialProvisioningStatusSnapshot::UNAVAILABLE,
            );
        }
        $provisioned = is_string($row->provisioned_at ?? null) && $row->provisioned_at !== '';

        $status = match ($state) {
            ProvisioningState::Queued,
            ProvisioningState::Running,
            ProvisioningState::RetryScheduled => $provisioned
                ? TelegramCustomerTrialProvisioningStatusSnapshot::NEEDS_REVIEW
                : TelegramCustomerTrialProvisioningStatusSnapshot::PENDING,
            ProvisioningState::Succeeded => $provisioned
                ? TelegramCustomerTrialProvisioningStatusSnapshot::SUCCEEDED
                : TelegramCustomerTrialProvisioningStatusSnapshot::NEEDS_REVIEW,
            ProvisioningState::FailedFinal => $provisioned
                ? TelegramCustomerTrialProvisioningStatusSnapshot::NEEDS_REVIEW
                : TelegramCustomerTrialProvisioningStatusSnapshot::FAILED_FINAL,
            ProvisioningState::UncertainRemoteResult,
            ProvisioningState::NeedsReview,
            ProvisioningState::Compensating,
            ProvisioningState::Compensated => TelegramCustomerTrialProvisioningStatusSnapshot::NEEDS_REVIEW,
        };

        return new TelegramCustomerTrialProvisioningStatusSnapshot($status);
    }

    private function validUlid(string $value): bool
    {
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) === 1;
    }
}
