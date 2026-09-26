<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Execution-time customer policy/capability fence for an already locked Service.
 *
 * @phpstan-type PolicyServiceRow object{id:int|string,user_id:int|string,order_item_id:int|string,service_target_id:int|string|null}
 */
final readonly class ServiceCustomerOperationPolicyGuard
{
    /**
     * @param  PolicyServiceRow  $service
     * @param  list<string>  $intrinsicCapabilities
     */
    public function assertCurrentForUpdate(
        Connection $connection,
        object $service,
        int $actorUserId,
        string $operationCode,
        array $intrinsicCapabilities,
    ): void {
        if ($actorUserId < 1 || (int) $service->user_id !== $actorUserId) {
            throw new AuthorizationException('Service operation authorization failed.');
        }
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $operationCode) !== 1) {
            throw new RuntimeException('Service operation policy code is invalid.');
        }

        /** @var object{account_status:string,account_type:string}|null $user */
        $user = $connection->table('users')->where('id', $actorUserId)->lockForUpdate()->first(['account_status', 'account_type']);
        if ($user === null || $user->account_status !== 'active' || ! in_array($user->account_type, ['customer', 'agent'], true)) {
            throw new AuthorizationException('Service operation authorization failed.');
        }

        $offeringId = $connection->table('order_items')
            ->where('id', (int) $service->order_item_id)
            ->lockForUpdate()
            ->value('plan_offering_id');
        if (! is_int($offeringId) && ! is_string($offeringId)) {
            throw new AuthorizationException('Service operation is unavailable for this Service.');
        }

        /** @var object{customer_enabled:int|bool,required_capability_code:?string}|null $policy */
        $policy = $connection->table('plan_offering_operations')
            ->where('plan_offering_id', (int) $offeringId)
            ->where('operation_code', $operationCode)
            ->lockForUpdate()
            ->first(['customer_enabled', 'required_capability_code']);
        if ($policy === null || ! (bool) $policy->customer_enabled) {
            throw new AuthorizationException('Service operation is not enabled for this customer.');
        }

        $targetId = filter_var($service->service_target_id, FILTER_VALIDATE_INT);
        if ($targetId === false || $targetId < 1) {
            throw new AuthorizationException('Service operation requires a current Panel target.');
        }

        /** @var object{state:string,capability_status:string}|null $target */
        $target = $connection->table('panel_service_targets')
            ->where('id', $targetId)
            ->lockForUpdate()
            ->first(['state', 'capability_status']);
        if ($target === null || $target->state !== 'active' || $target->capability_status !== 'verified') {
            throw new AuthorizationException('Service operation requires a verified active Panel target.');
        }

        $required = [];
        foreach ($intrinsicCapabilities as $capability) {
            if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $capability) !== 1) {
                throw new RuntimeException('Service intrinsic capability code is invalid.');
            }
            $required[$capability] = true;
        }
        if ($policy->required_capability_code !== null) {
            $required[(string) $policy->required_capability_code] = true;
        }
        if ($required === []) {
            return;
        }

        $verified = $connection->table('panel_target_capabilities')
            ->where('panel_service_target_id', $targetId)
            ->where('verification_status', 'verified')
            ->whereIn('capability_code', array_keys($required))
            ->lockForUpdate()
            ->pluck('capability_code')
            ->all();
        $verifiedSet = array_fill_keys(array_map('strval', $verified), true);
        foreach (array_keys($required) as $capability) {
            if (! isset($verifiedSet[$capability])) {
                throw new AuthorizationException('Service operation is not supported by the current Panel target.');
            }
        }
    }
}
