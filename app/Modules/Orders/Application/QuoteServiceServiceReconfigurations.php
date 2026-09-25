<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Orders\Domain\QuoteAction;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;

/**
 * @phpstan-type ServiceReconfigurationFacts array{
 *   action:QuoteAction,
 *   agent_action:AgentPricingAction,
 *   preview_id:int,
 *   preview_public_id:string,
 *   service_subscription_id:int,
 *   service_subscription_public_id:string,
 *   source_service_target_id:int,
 *   source_remote_identity_generation:int,
 *   source_lifecycle_version:int,
 *   source_mutation_generation:int,
 *   source_route_selection_id:?int,
 *   target_route_selection_id:int,
 *   target_service_target_id:int,
 *   target_protocol_profile_id:int,
 *   price_difference_irr:int,
 *   operation_fee_irr:int,
 *   total_price_irr:int,
 *   discount_eligible:bool,
 *   changes_plan:bool,
 *   changes_target:bool,
 *   changes_protocol:bool
 * }
 */
trait QuoteServiceServiceReconfigurations
{
    /** @return ServiceReconfigurationFacts */
    private function serviceReconfigurationFacts(
        Connection $connection,
        int $userId,
        int $planOfferingId,
        ServiceReconfigurationQuoteContext $context,
        DateTimeImmutable $quoteExpiresAt,
    ): array {
        /** @var object{id:int|string,public_id:string,actor_user_id:int|string,service_subscription_id:int|string,source_route_selection_id:int|string|null,source_service_target_id:int|string,source_remote_identity_generation:int|string,source_lifecycle_version:int|string,source_mutation_generation:int|string,target_plan_offering_id:int|string,target_route_selection_id:int|string,target_service_target_id:int|string,target_protocol_profile_id:int|string,target_capacity_reservation_id:int|string,changes_plan:int|bool,changes_target:int|bool,changes_protocol:int|bool,price_difference_irr:int|string,operation_fee_irr:int|string,total_price_irr:int|string,discount_eligible:int|bool,state:string,expires_at:string,service_public_id:string,service_user_id:int|string,service_route_selection_id:int|string|null,service_target_id:int|string|null,service_remote_identity_generation:int|string,service_lifecycle_version:int|string,service_mutation_generation:int|string,service_lifecycle_state:string,service_remote_deleted_at:?string,service_provisioned_at:?string,reservation_state:string,reservation_expires_at:string}|null $preview */
        $preview = $connection->table('service_reconfiguration_previews as preview')
            ->join('service_subscriptions as service', 'service.id', '=', 'preview.service_subscription_id')
            ->join('panel_capacity_reservations as reservation', 'reservation.id', '=', 'preview.target_capacity_reservation_id')
            ->where('preview.public_id', $context->previewPublicId)
            ->lockForUpdate()
            ->first([
                'preview.id', 'preview.public_id', 'preview.actor_user_id', 'preview.service_subscription_id',
                'preview.source_route_selection_id', 'preview.source_service_target_id',
                'preview.source_remote_identity_generation', 'preview.source_lifecycle_version', 'preview.source_mutation_generation',
                'preview.target_plan_offering_id', 'preview.target_route_selection_id', 'preview.target_service_target_id',
                'preview.target_protocol_profile_id', 'preview.target_capacity_reservation_id',
                'preview.changes_plan', 'preview.changes_target', 'preview.changes_protocol',
                'preview.price_difference_irr', 'preview.operation_fee_irr', 'preview.total_price_irr',
                'preview.discount_eligible', 'preview.state', 'preview.expires_at',
                'service.public_id as service_public_id', 'service.user_id as service_user_id',
                'service.route_selection_id as service_route_selection_id', 'service.service_target_id',
                'service.remote_identity_generation as service_remote_identity_generation',
                'service.lifecycle_version as service_lifecycle_version', 'service.mutation_generation as service_mutation_generation',
                'service.lifecycle_state as service_lifecycle_state', 'service.remote_deleted_at as service_remote_deleted_at',
                'service.provisioned_at as service_provisioned_at',
                'reservation.state as reservation_state', 'reservation.expires_at as reservation_expires_at',
            ]);
        if ($preview === null) {
            throw new DomainException('Service reconfiguration Quote preview does not exist.');
        }
        $previewExpiresAt = $this->databaseDateTimeFromString($preview->expires_at, 'Service reconfiguration preview expiry');
        $reservationExpiresAt = $this->databaseDateTimeFromString($preview->reservation_expires_at, 'Service reconfiguration capacity expiry');
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        if ((int) $preview->actor_user_id !== $userId
            || (int) $preview->service_user_id !== $userId
            || (int) $preview->target_plan_offering_id !== $planOfferingId
            || $preview->state !== 'previewed'
            || $previewExpiresAt <= $now
            || $quoteExpiresAt > $previewExpiresAt
            || $preview->reservation_state !== 'held'
            || $reservationExpiresAt < $quoteExpiresAt
            || $preview->service_provisioned_at === null
            || $preview->service_remote_deleted_at !== null
            || ! in_array($preview->service_lifecycle_state, ['active', 'suspended'], true)
            || $preview->service_target_id === null
            || (int) $preview->service_target_id !== (int) $preview->source_service_target_id
            || ! ($preview->service_route_selection_id === null && $preview->source_route_selection_id === null)
                && (int) $preview->service_route_selection_id !== (int) $preview->source_route_selection_id
            || (int) $preview->service_remote_identity_generation !== (int) $preview->source_remote_identity_generation
            || (int) $preview->service_lifecycle_version !== (int) $preview->source_lifecycle_version
            || (int) $preview->service_mutation_generation !== (int) $preview->source_mutation_generation) {
            throw new DomainException('Service reconfiguration Quote preview is stale or unauthorized.');
        }

        $totalPrice = $this->nonNegativeDatabaseInt($preview->total_price_irr, 'Service reconfiguration total price');

        return [
            'action' => QuoteAction::Reconfigure,
            'agent_action' => AgentPricingAction::Reconfigure,
            'preview_id' => $this->positiveDatabaseInt($preview->id, 'Service reconfiguration preview ID'),
            'preview_public_id' => $preview->public_id,
            'service_subscription_id' => $this->positiveDatabaseInt($preview->service_subscription_id, 'Service Subscription ID'),
            'service_subscription_public_id' => $preview->service_public_id,
            'source_service_target_id' => $this->positiveDatabaseInt($preview->source_service_target_id, 'Service reconfiguration source target ID'),
            'source_remote_identity_generation' => $this->positiveDatabaseInt($preview->source_remote_identity_generation, 'Service reconfiguration remote identity generation'),
            'source_lifecycle_version' => $this->nonNegativeDatabaseInt($preview->source_lifecycle_version, 'Service reconfiguration lifecycle version'),
            'source_mutation_generation' => $this->nonNegativeDatabaseInt($preview->source_mutation_generation, 'Service reconfiguration mutation generation'),
            'source_route_selection_id' => $preview->source_route_selection_id === null ? null : $this->positiveDatabaseInt($preview->source_route_selection_id, 'Service reconfiguration source route selection ID'),
            'target_route_selection_id' => $this->positiveDatabaseInt($preview->target_route_selection_id, 'Service reconfiguration target route selection ID'),
            'target_service_target_id' => $this->positiveDatabaseInt($preview->target_service_target_id, 'Service reconfiguration target service target ID'),
            'target_protocol_profile_id' => $this->positiveDatabaseInt($preview->target_protocol_profile_id, 'Service reconfiguration target protocol profile ID'),
            'price_difference_irr' => $this->nonNegativeDatabaseInt($preview->price_difference_irr, 'Service reconfiguration price difference'),
            'operation_fee_irr' => $this->nonNegativeDatabaseInt($preview->operation_fee_irr, 'Service reconfiguration operation fee'),
            'total_price_irr' => $totalPrice,
            'discount_eligible' => (bool) $preview->discount_eligible,
            'changes_plan' => (bool) $preview->changes_plan,
            'changes_target' => (bool) $preview->changes_target,
            'changes_protocol' => (bool) $preview->changes_protocol,
        ];
    }
}
