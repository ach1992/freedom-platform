<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Orders\Domain\QuoteAction;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * @phpstan-type ServicePackageFacts array{
 *   action: QuoteAction,
 *   agent_action: AgentPricingAction,
 *   service_subscription_id:int,
 *   service_subscription_public_id:string,
 *   service_target_id:int,
 *   remote_identity_generation:int,
 *   lifecycle_version:int,
 *   package_id:int,
 *   package_code:string,
 *   package_type:string,
 *   duration_days:?int,
 *   data_bytes:?int,
 *   price_irr:int,
 *   discount_eligible:bool,
 *   required_capability_code:?string
 * }
 */
trait QuoteServiceServicePackages
{
    /** @return ServicePackageFacts */
    private function servicePackageFacts(
        Connection $connection,
        int $userId,
        int $planOfferingId,
        ServicePackageQuoteContext $context,
    ): array {
        /** @var object{id:int|string,public_id:string,user_id:int|string,order_item_id:int|string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,remote_deleted_at:?string,plan_offering_id:int|string}|null $service */
        $service = $connection->table('service_subscriptions as service')
            ->join('order_items as item', 'item.id', '=', 'service.order_item_id')
            ->where('service.public_id', $context->servicePublicId)
            ->lockForUpdate()
            ->first([
                'service.id',
                'service.public_id',
                'service.user_id',
                'service.order_item_id',
                'service.service_target_id',
                'service.remote_service_id',
                'service.provisioned_at',
                'service.lifecycle_state',
                'service.lifecycle_version',
                'service.remote_identity_generation',
                'service.remote_deleted_at',
                'item.plan_offering_id',
            ]);
        if ($service === null) {
            throw new DomainException('Service package Quote Service does not exist.');
        }
        if ((int) $service->user_id !== $userId
            || (int) $service->plan_offering_id !== $planOfferingId
            || $service->remote_deleted_at !== null
            || ! in_array($service->lifecycle_state, ['active', 'suspended'], true)
            || $service->provisioned_at === null
            || $service->service_target_id === null
            || ! is_string($service->remote_service_id) || $service->remote_service_id === '') {
            throw new DomainException('Service package Quote requires a current owned provisioned Service on the same offering.');
        }

        $serviceTargetId = $this->positiveDatabaseInt($service->service_target_id, 'Service target ID');
        $remoteGeneration = $this->positiveDatabaseInt($service->remote_identity_generation, 'Service remote identity generation');
        $lifecycleVersion = $this->nonNegativeDatabaseInt($service->lifecycle_version, 'Service lifecycle version');

        /** @var object{id:int|string,code:string,package_type:string,price_irr:int|string,duration_days:int|string|null,data_bytes:int|string|null,discount_eligible:int|bool}|null $package */
        $package = $connection->table('plan_offering_packages')
            ->where('plan_offering_id', $planOfferingId)
            ->where('code', $context->packageCode)
            ->lockForUpdate()
            ->first(['id', 'code', 'package_type', 'price_irr', 'duration_days', 'data_bytes', 'discount_eligible']);
        if ($package === null) {
            throw new DomainException('Service package Quote package does not exist for this offering.');
        }

        [$action, $agentAction] = match ($package->package_type) {
            'renewal' => [QuoteAction::Renew, AgentPricingAction::Renew],
            'add_data' => [QuoteAction::AddData, AgentPricingAction::AddData],
            'add_days' => [QuoteAction::AddDays, AgentPricingAction::AddDays],
            'add_data_days' => [QuoteAction::AddDataDays, AgentPricingAction::AddDataDays],
            default => throw new RuntimeException('Stored Service package type is invalid.'),
        };

        /** @var object{customer_enabled:int|bool,discount_eligible:int|bool,required_capability_code:?string}|null $policy */
        $policy = $connection->table('plan_offering_operations')
            ->where('plan_offering_id', $planOfferingId)
            ->where('operation_code', $action->value)
            ->lockForUpdate()
            ->first(['customer_enabled', 'discount_eligible', 'required_capability_code']);
        if ($policy === null || ! (bool) $policy->customer_enabled) {
            throw new DomainException('Service package operation is not enabled for customer/agent execution.');
        }

        $requiredCapabilities = match ($action) {
            QuoteAction::Renew, QuoteAction::AddDays => ['update_expiry'],
            QuoteAction::AddData => ['add_data_allowance'],
            QuoteAction::AddDataDays => ['update_expiry', 'add_data_allowance'],
            QuoteAction::Purchase => throw new RuntimeException('Purchase is not a Service package action.'),
        };
        if (is_string($policy->required_capability_code) && $policy->required_capability_code !== '') {
            $requiredCapabilities[] = $policy->required_capability_code;
        }
        $requiredCapabilities = array_values(array_unique($requiredCapabilities));
        sort($requiredCapabilities, SORT_STRING);

        foreach ($requiredCapabilities as $capability) {
            $verified = $connection->table('panel_target_capabilities')
                ->where('panel_service_target_id', $serviceTargetId)
                ->where('capability_code', $capability)
                ->where('verification_status', 'verified')
                ->exists();
            if (! $verified) {
                throw new DomainException('Service target does not have the verified capability required by this package.');
            }
        }

        $durationDays = $package->duration_days === null ? null : $this->positiveDatabaseInt($package->duration_days, 'Service package duration days');
        $dataBytes = $package->data_bytes === null ? null : $this->positiveDatabaseInt($package->data_bytes, 'Service package data bytes');
        $priceIrr = $this->nonNegativeDatabaseInt($package->price_irr, 'Service package price');

        if (($action === QuoteAction::Renew || $action === QuoteAction::AddDays) && ($durationDays === null || $dataBytes !== null)) {
            throw new RuntimeException('Stored Service duration package shape is invalid.');
        }
        if ($action === QuoteAction::AddData && ($durationDays !== null || $dataBytes === null)) {
            throw new RuntimeException('Stored Service data package shape is invalid.');
        }
        if ($action === QuoteAction::AddDataDays && ($durationDays === null || $dataBytes === null)) {
            throw new RuntimeException('Stored combined Service package shape is invalid.');
        }

        return [
            'action' => $action,
            'agent_action' => $agentAction,
            'service_subscription_id' => $this->positiveDatabaseInt($service->id, 'Service Subscription ID'),
            'service_subscription_public_id' => $service->public_id,
            'service_target_id' => $serviceTargetId,
            'remote_identity_generation' => $remoteGeneration,
            'lifecycle_version' => $lifecycleVersion,
            'package_id' => $this->positiveDatabaseInt($package->id, 'Service package ID'),
            'package_code' => $package->code,
            'package_type' => $package->package_type,
            'duration_days' => $durationDays,
            'data_bytes' => $dataBytes,
            'price_irr' => $priceIrr,
            'discount_eligible' => (bool) $package->discount_eligible && (bool) $policy->discount_eligible,
            'required_capability_code' => $policy->required_capability_code,
        ];
    }
}
