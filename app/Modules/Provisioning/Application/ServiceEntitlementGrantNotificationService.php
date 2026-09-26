<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type GrantNotificationRow object{item_id:int|string,item_public_id:string,service_public_id:string,data_bytes:int|string|null,duration_days:int|string|null,notify_customers:int|string|bool,operation_state:string}
 * @phpstan-type GrantNotificationBindingRow object{retry_ordinal:int|string,effect_state:?string,retry_after_seconds:int|string|null}
 */
final readonly class ServiceEntitlementGrantNotificationService
{
    public const OUTBOX_EVENT_TYPE = 'provisioning.service_entitlement_grant.notification_requested';

    public const OUTBOX_CONTRACT_VERSION = 1;

    public const OUTBOX_AGGREGATE_TYPE = 'service_entitlement_grant_item';

    public const OUTBOX_EVENT_KEY_PREFIX = 'service-entitlement-grant-notification:';

    private const MAX_RETRIES = 2;

    public function __construct(
        private DatabaseManager $database,
        private ServiceDeliveryAttemptQueueService $deliveries,
    ) {}

    /** @requirement SVC-012 SVC-014 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function ensureInitial(string $itemPublicId): ?ServiceDeliveryAttemptReceipt
    {
        return $this->ensure($itemPublicId, false);
    }

    /** @requirement SVC-012 SVC-014 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function retryFailedForBatch(string $batchPublicId): int
    {
        if (! Str::isUlid($batchPublicId)) {
            throw new DomainException('Service entitlement grant batch public ID is invalid.');
        }

        /** @var list<string> $items */
        $items = $this->database->connection()->table('service_entitlement_grant_items as item')
            ->join('service_entitlement_grant_batches as batch', 'batch.id', '=', 'item.service_entitlement_grant_batch_id')
            ->join('provisioning_operations as operation', 'operation.id', '=', 'item.provisioning_operation_id')
            ->where('batch.public_id', $batchPublicId)
            ->where('batch.notify_customers', true)
            ->where('operation.state', 'succeeded')
            ->whereIn('item.state', ['queued', 'succeeded'])
            ->orderBy('item.position')
            ->pluck('item.public_id')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        $queued = 0;
        foreach ($items as $itemPublicId) {
            if ($this->ensure($itemPublicId, true) !== null) {
                $queued++;
            }
        }

        return $queued;
    }

    private function ensure(string $itemPublicId, bool $allowRetry): ?ServiceDeliveryAttemptReceipt
    {
        if (! Str::isUlid($itemPublicId)) {
            throw new DomainException('Service entitlement grant item public ID is invalid.');
        }

        $row = $this->grantRow($itemPublicId);
        if (! (bool) $row->notify_customers) {
            return null;
        }
        if ($row->operation_state !== 'succeeded') {
            throw new DomainException('Service entitlement grant notification requires a successful grant Operation.');
        }

        $latest = $this->latestBinding((int) $row->item_id);
        $retryOrdinal = 0;
        if ($latest !== null) {
            if ($latest->effect_state === null) {
                return null;
            }
            $effectState = ServiceDeliveryEffectState::tryFrom($latest->effect_state)
                ?? throw new RuntimeException('Stored Service entitlement grant notification effect state is invalid.');
            if ($effectState !== ServiceDeliveryEffectState::FailedFinal) {
                return null;
            }
            if (! $allowRetry || $latest->retry_after_seconds !== null) {
                return null;
            }
            $retryOrdinal = (int) $latest->retry_ordinal + 1;
            if ($retryOrdinal > self::MAX_RETRIES) {
                return null;
            }
        }

        $presentation = $this->presentation($row->data_bytes, $row->duration_days);
        $requestKey = 'grant-notification:'.$itemPublicId.':'.$retryOrdinal;
        $correlationId = 'svc-grant-notify:'.substr(hash('sha256', $itemPublicId.'|'.$retryOrdinal), 0, 40);

        return $this->deliveries->queueEntitlementGrantNotification(
            $itemPublicId,
            $row->service_public_id,
            $retryOrdinal,
            $requestKey,
            $correlationId,
            $presentation,
        );
    }

    /** @return GrantNotificationRow */
    private function grantRow(string $itemPublicId): object
    {
        /** @var GrantNotificationRow|null $row */
        $row = $this->database->connection()->table('service_entitlement_grant_items as item')
            ->join('service_entitlement_grant_batches as batch', 'batch.id', '=', 'item.service_entitlement_grant_batch_id')
            ->join('service_subscriptions as service', 'service.id', '=', 'item.service_subscription_id')
            ->join('provisioning_operations as operation', 'operation.id', '=', 'item.provisioning_operation_id')
            ->where('item.public_id', $itemPublicId)
            ->whereIn('operation.operation_type', ['grant_data', 'grant_days', 'grant_data_days'])
            ->first([
                'item.id as item_id',
                'item.public_id as item_public_id',
                'service.public_id as service_public_id',
                'batch.data_bytes',
                'batch.duration_days',
                'batch.notify_customers',
                'operation.state as operation_state',
            ]);
        if ($row === null) {
            throw new DomainException('Service entitlement grant notification source does not exist.');
        }

        return $row;
    }

    /** @return GrantNotificationBindingRow|null */
    private function latestBinding(int $itemId): ?object
    {
        /** @var GrantNotificationBindingRow|null $row */
        $row = $this->database->connection()->table('service_entitlement_grant_notification_bindings as binding')
            ->leftJoin('service_delivery_effects as effect', 'effect.service_delivery_attempt_id', '=', 'binding.service_delivery_attempt_id')
            ->where('binding.service_entitlement_grant_item_id', $itemId)
            ->orderByDesc('binding.retry_ordinal')
            ->first([
                'binding.retry_ordinal',
                'effect.state as effect_state',
                'effect.retry_after_seconds',
            ]);

        return $row;
    }

    private function presentation(int|string|null $dataBytes, int|string|null $durationDays): string
    {
        $lines = ['🎁 هدیه سرویس شما با موفقیت اعمال شد.'];
        if ($dataBytes !== null && (int) $dataBytes > 0) {
            $lines[] = 'حجم افزوده‌شده: '.$this->formatBytes((int) $dataBytes);
        }
        if ($durationDays !== null && (int) $durationDays > 0) {
            $lines[] = 'مدت افزوده‌شده: '.(int) $durationDays.' روز';
        }
        if (count($lines) === 1) {
            throw new RuntimeException('Stored Service entitlement grant notification package is invalid.');
        }

        return implode("\n", $lines);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1) {
            throw new RuntimeException('Stored Service entitlement grant data amount is invalid.');
        }
        $gib = $bytes / 1_073_741_824;
        if ($gib >= 0.1) {
            return rtrim(rtrim(number_format($gib, 2, '.', ''), '0'), '.').' GB';
        }

        $mib = $bytes / 1_048_576;

        return rtrim(rtrim(number_format($mib, 2, '.', ''), '0'), '.').' MB';
    }
}
