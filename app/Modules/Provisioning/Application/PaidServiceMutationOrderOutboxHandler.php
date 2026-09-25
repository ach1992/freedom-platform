<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Orders\Application\PaidServiceMutationOrderOutboxPublisher;
use App\Modules\Orders\Domain\QuoteAction;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use DomainException;
use Illuminate\Support\Str;
use Throwable;

final readonly class PaidServiceMutationOrderOutboxHandler implements OutboxEventHandler
{
    public function __construct(private ServicePurchaseMutationQueueService $mutations) {}

    public function eventType(): string
    {
        return PaidServiceMutationOrderOutboxPublisher::OUTBOX_EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return PaidServiceMutationOrderOutboxPublisher::OUTBOX_CONTRACT_VERSION;
    }

    /** @requirement BUY-002 PAY-002 SVC-003 SVC-004 SVC-005 ARCH-004 SEC-002 QUA-004 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $orderPublicId = $message->payload['order_public_id'] ?? null;
        $settlementPublicId = $message->payload['purchase_settlement_public_id'] ?? null;
        $quotePublicId = $message->payload['quote_public_id'] ?? null;
        $actionValue = $message->payload['action'] ?? null;
        $action = is_string($actionValue) ? QuoteAction::tryFrom($actionValue) : null;
        if ($message->eventType !== PaidServiceMutationOrderOutboxPublisher::OUTBOX_EVENT_TYPE
            || $message->contractVersion !== PaidServiceMutationOrderOutboxPublisher::OUTBOX_CONTRACT_VERSION
            || $message->aggregateType !== PaidServiceMutationOrderOutboxPublisher::OUTBOX_AGGREGATE_TYPE
            || ! is_string($orderPublicId) || ! Str::isUlid($orderPublicId)
            || ! hash_equals($message->aggregateId, $orderPublicId)
            || ! hash_equals(PaidServiceMutationOrderOutboxPublisher::OUTBOX_EVENT_KEY_PREFIX.$orderPublicId, $message->eventKey)
            || ! is_string($settlementPublicId) || ! Str::isUlid($settlementPublicId)
            || ! is_string($quotePublicId) || ! Str::isUlid($quotePublicId)
            || $action === null || $action === QuoteAction::Purchase) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        try {
            $this->mutations->queueFromSettlement(
                $settlementPublicId,
                ServicePurchaseMutationQueueService::canonicalRequestKeyForSettlement($settlementPublicId),
                $message->correlationId,
            );
        } catch (DomainException) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            return OutboxDispatchOutcome::RetryableFailure;
        }

        return OutboxDispatchOutcome::Success;
    }
}
