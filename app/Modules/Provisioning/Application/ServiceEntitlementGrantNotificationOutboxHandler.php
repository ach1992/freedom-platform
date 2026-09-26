<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use DomainException;
use Illuminate\Support\Str;
use Throwable;

final readonly class ServiceEntitlementGrantNotificationOutboxHandler implements OutboxEventHandler
{
    public function __construct(private ServiceEntitlementGrantNotificationService $notifications) {}

    public function eventType(): string
    {
        return ServiceEntitlementGrantNotificationService::OUTBOX_EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return ServiceEntitlementGrantNotificationService::OUTBOX_CONTRACT_VERSION;
    }

    /** @requirement SVC-012 SVC-014 ARCH-004 SEC-002 QUA-004 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $itemPublicId = $message->payload['service_entitlement_grant_item_public_id'] ?? null;
        if ($message->eventType !== ServiceEntitlementGrantNotificationService::OUTBOX_EVENT_TYPE
            || $message->contractVersion !== ServiceEntitlementGrantNotificationService::OUTBOX_CONTRACT_VERSION
            || $message->aggregateType !== ServiceEntitlementGrantNotificationService::OUTBOX_AGGREGATE_TYPE
            || ! is_string($itemPublicId)
            || ! Str::isUlid($itemPublicId)
            || ! hash_equals($message->aggregateId, $itemPublicId)
            || ! hash_equals(
                ServiceEntitlementGrantNotificationService::OUTBOX_EVENT_KEY_PREFIX.$itemPublicId,
                $message->eventKey,
            )) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        try {
            $this->notifications->ensureInitial($itemPublicId);
        } catch (ServiceDeliveryTemporarilyBlockedException) {
            return OutboxDispatchOutcome::RetryableFailure;
        } catch (DomainException) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            return OutboxDispatchOutcome::RetryableFailure;
        }

        return OutboxDispatchOutcome::Success;
    }
}
