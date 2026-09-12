<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Catalog\Application\PlanOfferingRouteSelector;
use App\Modules\Catalog\Application\RouteSelectionContext;
use App\Modules\Catalog\Application\RouteSelectionReceipt;
use App\Modules\Catalog\Application\RouteSelectionRequest;
use App\Modules\Catalog\Domain\RouteSelectionActor;
use App\Modules\Orders\Domain\OrderSourceType;
use App\Modules\Panels\Application\CapacityOperationContext;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\PanelCreateCoordinator;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Modules\Panels\Domain\CapacityReservationState;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Application\Clock;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type OperationRow object{
 *     id:int|string,
 *     public_id:string,
 *     operation_key:string,
 *     operation_type:string,
 *     order_id:int|string,
 *     order_item_id:int|string,
 *     service_subscription_id:int|string,
 *     user_id:int|string,
 *     state:string,
 *     state_version:int|string,
 *     correlation_id:string,
 *     effect_fence_key:?string,
 *     route_hold_expires_at:?string,
 *     route_selection_id:int|string|null,
 *     service_target_id:int|string|null,
 *     capacity_reservation_id:int|string|null,
 *     capacity_reservation_key:?string,
 *     remote_username:?string,
 *     target_reference:?string,
 *     attempt_count:int|string,
 *     last_result_code:?string,
 *     last_result_message:?string,
 *     remote_service_id:?string,
 *     remote_effect_started_at:?string,
 *     remote_effect_completed_at:?string,
 *     purchase_settlement_id:int|string|null,
 *     payment_intent_id:int|string|null,
 *     order_state:string,
 *     order_state_version:int|string,
 *     order_source_type:string,
 *     order_source_authorization_id:int|string|null,
 *     plan_offering_id:int|string,
 *     account_type_snapshot:string,
 *     service_public_id:string
 * }
 * @phpstan-type TrialProvisioningAuthority array{route:RouteSelectionReceipt,capacity_expires_at:string,data_bytes:int,duration_days:int}
 */
final readonly class InitialProvisioningExecutor
{
    private const OPERATION_TYPE = 'initial_provision';

    private const AUTHORITY = 'initial_remote_effect_v1';

    private const ROUTE_HOLD_INTERVAL = 'PT24H';

    private const CAPACITY_EFFECT_MINIMUM_REMAINING_INTERVAL = 'PT10M';

    public function __construct(
        private DatabaseManager $database,
        private InitialProvisioningAuthorityGuard $authority,
        private Clock $clock,
        private PlanOfferingRouteSelector $routes,
        private ProvisioningPanelAdapterResolver $adapters,
        private PanelCreateCoordinator $coordinator,
        private TargetCapacityAllocator $capacity,
    ) {}

    /** @requirement PAY-003 PRV-001 PRV-002 PRV-003 CAT-006 ADM-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-001 QUA-004 */
    public function execute(string $operationPublicId): InitialProvisioningExecutionReceipt
    {
        $operation = $this->operationByPublicId($operationPublicId);
        $state = $this->storedState($operation->state);
        if ($this->isReplayTerminal($state)) {
            return $this->receipt($operation, true);
        }

        if ($state !== ProvisioningState::Running) {
            if (! in_array($state, [ProvisioningState::Queued, ProvisioningState::RetryScheduled], true)) {
                throw new DomainException('Initial provisioning operation is not executable automatically.');
            }
            $operation = $this->claim($operation);
        }

        try {
            $route = $this->ensureRouteBinding($operation);
            $operation = $this->operationByPublicId($operationPublicId);
        } catch (Throwable $exception) {
            return $this->finalize(
                $operation,
                ProvisioningState::RetryScheduled,
                'route_resolution_failed',
                $this->safeMessage($exception->getMessage()),
                null,
            );
        }

        // Revalidate the exact purchase or zero-cost source authority in its own short transaction
        // immediately before the provider boundary. Purchase refunds remain fenced by the accepted
        // running-effect invalidation guard; zero-cost sources never fabricate payment evidence.
        try {
            $this->revalidateFinancialAuthority($operation);
        } catch (Throwable $exception) {
            return $this->finalize(
                $operation,
                ProvisioningState::FailedFinal,
                'financial_authority_lost',
                $this->safeMessage($exception->getMessage()),
                null,
            );
        }

        // Capacity is separately revalidated after Order authority and immediately before the
        // provider boundary. The running-capacity DB fence prevents release/expiry transitions
        // after this check while the remote effect remains in flight.
        try {
            $this->revalidateCapacityAuthority($operation);
        } catch (Throwable $exception) {
            return $this->finalize(
                $operation,
                ProvisioningState::NeedsReview,
                'capacity_authority_lost',
                $this->safeMessage($exception->getMessage()),
                null,
            );
        }

        try {
            $adapter = $this->adapters->resolve($route->serviceTargetId);
            $request = $this->remoteRequest($operation, $route);
        } catch (Throwable $exception) {
            return $this->finalize(
                $operation,
                ProvisioningState::RetryScheduled,
                'panel_runtime_unavailable',
                $this->safeMessage($exception->getMessage()),
                null,
            );
        }

        try {
            $result = $this->coordinator->createOrAdopt($adapter, $request);
        } catch (Throwable $exception) {
            // Once control enters the provider coordinator, an exception can be post-mutation.
            // Persist uncertainty rather than ever converting it into a blind create retry.
            return $this->finalize(
                $operation,
                ProvisioningState::UncertainRemoteResult,
                'remote_effect_exception',
                $this->safeMessage($exception->getMessage()),
                null,
            );
        }

        return $this->applyPanelResult($operation, $result);
    }

    /**
     * @param  OperationRow  $locator
     * @return OperationRow
     */
    private function claim(object $locator): object
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($locator): object {
            $this->authority->lockAndAssert($connection, $locator);
            $locked = $this->operationById($connection, (int) $locator->id, true);
            $state = $this->storedState($locked->state);
            if ($state === ProvisioningState::Running) {
                return $locked;
            }
            if (! in_array($state, [ProvisioningState::Queued, ProvisioningState::RetryScheduled], true)) {
                throw new DomainException('Initial provisioning operation cannot acquire remote-effect authority.');
            }

            $now = $this->nowString();
            if ($locked->route_selection_id !== null) {
                $holdExpiry = $locked->route_hold_expires_at;
            } elseif ($this->isTrialSource($locked)) {
                $trialAuthority = $this->trialProvisioningAuthority($locked, $connection, true);
                $holdExpiry = $trialAuthority['capacity_expires_at'];
            } else {
                $holdExpiry = $this->clock->now()->add(new DateInterval(self::ROUTE_HOLD_INTERVAL))->format('Y-m-d H:i:s.u');
            }
            if (! is_string($holdExpiry) || $holdExpiry === '') {
                throw new RuntimeException('Provisioning route hold expiry is unavailable.');
            }
            $fenceKey = $locked->effect_fence_key ?? $this->effectFenceKey($locked->public_id);

            $this->setAuthority($connection, $locked);
            try {
                $updated = $connection->table('provisioning_operations')
                    ->where('id', (int) $locked->id)
                    ->where('state', $state->value)
                    ->where('state_version', (int) $locked->state_version)
                    ->update([
                        'state' => ProvisioningState::Running->value,
                        'state_version' => (int) $locked->state_version + 1,
                        'effect_fence_key' => $fenceKey,
                        'route_hold_expires_at' => $holdExpiry,
                        'attempt_count' => (int) $locked->attempt_count + 1,
                        'remote_effect_started_at' => $locked->remote_effect_started_at ?? $now,
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Initial provisioning remote-effect claim lost its state.');
                }

                $next = $this->operationById($connection, (int) $locked->id, true);
                $this->recordEvent($connection, $next, 'claimed', null);

                return $next;
            } finally {
                $this->clearAuthority($connection);
            }
        }, 3);
    }

    /** @param OperationRow $operation */
    private function ensureRouteBinding(object $operation): RouteSelectionReceipt
    {
        if ($operation->route_selection_id !== null) {
            return $this->storedRouteSelection((int) $operation->route_selection_id, $operation);
        }

        $expiresAt = $this->storedDateTime($operation->route_hold_expires_at, 'Provisioning route hold expiry');
        if ($this->isTrialSource($operation)) {
            $trialAuthority = $this->trialProvisioningAuthority($operation);
            $trialCapacityExpiry = $this->storedDateTime(
                $trialAuthority['capacity_expires_at'],
                'Trial capacity reservation expiry',
            );
            if ($expiresAt->format('Y-m-d H:i:s.u') !== $trialCapacityExpiry->format('Y-m-d H:i:s.u')) {
                throw new DomainException('Trial provisioning route hold does not match the committed Trial capacity authority.');
            }

            return $this->bindRouteIntent($operation, $trialAuthority['route']);
        }
        $actor = $operation->account_type_snapshot === RouteSelectionActor::Agent->value
            ? RouteSelectionActor::Agent
            : RouteSelectionActor::Customer;
        $context = new RouteSelectionContext(
            $this->routeCommandKey($operation->public_id),
            $operation->correlation_id,
            'provisioning',
            'initial_remote_effect',
        );

        $route = $this->routes->select(
            new RouteSelectionRequest(
                (int) $operation->plan_offering_id,
                (int) $operation->user_id,
                $actor,
                null,
                null,
                1,
                $expiresAt,
            ),
            $context,
        );

        return $this->bindRouteIntent($operation, $route);
    }

    /** @param OperationRow $operation */
    private function bindRouteIntent(object $operation, RouteSelectionReceipt $route): RouteSelectionReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($operation, $route): RouteSelectionReceipt {
            $locked = $this->operationById($connection, (int) $operation->id, true);
            if ($this->storedState($locked->state) !== ProvisioningState::Running) {
                throw new RuntimeException('Provisioning route binding requires a running operation.');
            }
            if ($locked->route_selection_id !== null) {
                return $this->storedRouteSelection((int) $locked->route_selection_id, $locked, $connection);
            }

            /** @var object{code:string}|null $target */
            $target = $connection->table('panel_service_targets')->where('id', $route->serviceTargetId)->first(['code']);
            if ($target === null) {
                throw new RuntimeException('Selected provisioning service target disappeared.');
            }

            $remoteUsername = strtolower($locked->service_public_id);
            $this->setAuthority($connection, $locked);
            try {
                $updated = $connection->table('provisioning_operations')
                    ->where('id', (int) $locked->id)
                    ->where('state', ProvisioningState::Running->value)
                    ->whereNull('route_selection_id')
                    ->update([
                        'route_selection_id' => $route->selectionId,
                        'service_target_id' => $route->serviceTargetId,
                        'capacity_reservation_id' => $route->capacityReservationId,
                        'capacity_reservation_key' => $route->capacityReservationKey,
                        'remote_username' => $remoteUsername,
                        'target_reference' => $target->code,
                        'updated_at' => $this->nowString(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Provisioning route binding lost its running authority.');
                }

                $next = $this->operationById($connection, (int) $locked->id, true);
                $this->recordEvent($connection, $next, 'route_bound', null);
            } finally {
                $this->clearAuthority($connection);
            }

            return $route;
        }, 3);
    }

    /** @param OperationRow $operation */
    private function revalidateFinancialAuthority(object $operation): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($operation): void {
            $this->authority->lockAndAssert($connection, $operation);
            $locked = $this->operationById($connection, (int) $operation->id, true);
            if ($this->storedState($locked->state) !== ProvisioningState::Running) {
                throw new DomainException('Initial provisioning operation lost its remote-effect fence.');
            }
        }, 3);
    }

    /** @param OperationRow $operation */
    private function revalidateCapacityAuthority(object $operation): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($operation): void {
            $locked = $this->operationById($connection, (int) $operation->id, true);
            if ($this->storedState($locked->state) !== ProvisioningState::Running) {
                throw new DomainException('Initial provisioning operation lost its remote-effect fence.');
            }
            if ($locked->route_selection_id === null
                || $locked->service_target_id === null
                || $locked->capacity_reservation_id === null
                || $locked->capacity_reservation_key === null
                || $locked->route_hold_expires_at === null
            ) {
                throw new DomainException('Provisioning capacity authority is incomplete.');
            }

            if ($this->isTrialSource($locked)) {
                $trialAuthority = $this->trialProvisioningAuthority($locked, $connection, true);
                $selectionCapacityReservationId = $trialAuthority['route']->capacityReservationId;
                $selectionServiceTargetId = $trialAuthority['route']->serviceTargetId;
                $selectionUnits = $trialAuthority['route']->units;
            } else {
                /** @var object{capacity_reservation_id:int|string,selected_service_target_id:int|string,units:int|string}|null $selection */
                $selection = $connection->table('plan_offering_route_selections')
                    ->where('id', (int) $locked->route_selection_id)
                    ->where('command_key', $this->routeCommandKey($locked->public_id))
                    ->first(['capacity_reservation_id', 'selected_service_target_id', 'units']);
                if ($selection === null) {
                    throw new DomainException('Provisioning route capacity binding is inconsistent.');
                }
                $selectionCapacityReservationId = (int) $selection->capacity_reservation_id;
                $selectionServiceTargetId = (int) $selection->selected_service_target_id;
                $selectionUnits = (int) $selection->units;
            }
            if ($selectionCapacityReservationId !== (int) $locked->capacity_reservation_id
                || $selectionServiceTargetId !== (int) $locked->service_target_id
            ) {
                throw new DomainException('Provisioning route capacity binding is inconsistent.');
            }

            /** @var object{panel_target_capacity_id:int|string,reservation_key:string,units:int|string,state:string,expires_at:string}|null $reservation */
            $reservation = $connection->table('panel_capacity_reservations')
                ->where('id', (int) $locked->capacity_reservation_id)
                ->lockForUpdate()
                ->first(['panel_target_capacity_id', 'reservation_key', 'units', 'state', 'expires_at']);
            if ($reservation === null
                || ! hash_equals($reservation->reservation_key, $locked->capacity_reservation_key)
                || (int) $reservation->units !== $selectionUnits
            ) {
                throw new DomainException('Provisioning capacity reservation binding is no longer authoritative.');
            }

            $reservationState = CapacityReservationState::tryFrom($reservation->state)
                ?? throw new DomainException('Provisioning capacity reservation state is invalid.');
            if (! in_array($reservationState, [CapacityReservationState::Held, CapacityReservationState::Committed], true)) {
                throw new DomainException('Provisioning capacity reservation is no longer authoritative.');
            }

            /** @var object{panel_service_target_id:int|string,state:string}|null $capacity */
            $capacity = $connection->table('panel_target_capacities')
                ->where('id', (int) $reservation->panel_target_capacity_id)
                ->first(['panel_service_target_id', 'state']);
            if ($capacity === null
                || (int) $capacity->panel_service_target_id !== (int) $locked->service_target_id
            ) {
                throw new DomainException('Provisioning target capacity no longer matches the selected route.');
            }

            $reservationExpiresAt = $this->storedDateTime($reservation->expires_at, 'Provisioning capacity reservation expiry');
            $routeHoldExpiresAt = $this->storedDateTime($locked->route_hold_expires_at, 'Provisioning route hold expiry');
            if ($reservationExpiresAt->format('Y-m-d H:i:s.u') !== $routeHoldExpiresAt->format('Y-m-d H:i:s.u')) {
                throw new DomainException('Provisioning capacity expiry does not match the durable route hold.');
            }

            // A committed reservation is stronger evidence than a live hold: it can legitimately
            // survive a crash after provider success/capacity commit but before operation finalization.
            // The coordinator still enters lookup-first, so recovery cannot blindly create.
            if ($reservationState === CapacityReservationState::Committed) {
                return;
            }
            if ($capacity->state !== 'enabled') {
                throw new DomainException('Provisioning target capacity is no longer enabled for the selected route.');
            }

            $minimumValidUntil = $this->clock->now()
                ->setTimezone(new DateTimeZone('UTC'))
                ->add(new DateInterval(self::CAPACITY_EFFECT_MINIMUM_REMAINING_INTERVAL));
            if ($reservationExpiresAt <= $minimumValidUntil) {
                throw new DomainException('Provisioning capacity hold is too close to expiry for a remote effect.');
            }
        }, 3);
    }

    /** @param OperationRow $operation */
    private function applyPanelResult(object $operation, PanelOperationResult $result): InitialProvisioningExecutionReceipt
    {
        $code = $result->providerCode ?? match ($result->outcome) {
            PanelOperationOutcome::Success => 'remote_service_succeeded',
            PanelOperationOutcome::RetryableFailure => 'remote_retryable_failure',
            PanelOperationOutcome::DefinitiveFailure => 'remote_definitive_failure',
            PanelOperationOutcome::UncertainResult => 'remote_uncertain_result',
        };
        $remoteServiceId = $result->service?->remoteId;

        return match ($result->outcome) {
            PanelOperationOutcome::Success => $this->finalizeSuccess($operation, $code, $result->safeMessage, $remoteServiceId),
            PanelOperationOutcome::RetryableFailure => $this->finalize(
                $operation,
                ProvisioningState::RetryScheduled,
                $code,
                $result->safeMessage,
                $remoteServiceId,
            ),
            PanelOperationOutcome::UncertainResult => $this->finalize(
                $operation,
                ProvisioningState::UncertainRemoteResult,
                $code,
                $result->safeMessage,
                $remoteServiceId,
            ),
            PanelOperationOutcome::DefinitiveFailure => $this->finalizeDefinitive($operation, $code, $result),
        };
    }

    /** @param OperationRow $operation */
    private function finalizeSuccess(
        object $operation,
        string $code,
        ?string $message,
        ?string $remoteServiceId,
    ): InitialProvisioningExecutionReceipt {
        if ($remoteServiceId === null || trim($remoteServiceId) === '') {
            return $this->finalize(
                $operation,
                ProvisioningState::UncertainRemoteResult,
                'remote_success_missing_identity',
                'Remote success did not include an authoritative service identity.',
                null,
            );
        }

        $this->commitCapacity($operation);

        return $this->finalize(
            $operation,
            ProvisioningState::Succeeded,
            $code,
            $message,
            $remoteServiceId,
        );
    }

    /** @param OperationRow $operation */
    private function finalizeDefinitive(
        object $operation,
        string $code,
        PanelOperationResult $result,
    ): InitialProvisioningExecutionReceipt {
        $manualReview = $result->service !== null
            || str_contains($code, 'conflict')
            || str_contains($code, 'ambiguous')
            || str_contains($code, 'equivalence');

        return $this->finalize(
            $operation,
            $manualReview ? ProvisioningState::NeedsReview : ProvisioningState::FailedFinal,
            $code,
            $result->safeMessage,
            $result->service?->remoteId,
        );
    }

    /** @param OperationRow $operation */
    private function finalize(
        object $operation,
        ProvisioningState $targetState,
        string $resultCode,
        ?string $message,
        ?string $remoteServiceId,
    ): InitialProvisioningExecutionReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $operation,
            $targetState,
            $resultCode,
            $message,
            $remoteServiceId,
        ): InitialProvisioningExecutionReceipt {
            $locked = $this->operationById($connection, (int) $operation->id, true);
            $currentState = $this->storedState($locked->state);
            if ($currentState !== ProvisioningState::Running) {
                return $this->receipt($locked, true);
            }
            if ($targetState === ProvisioningState::Succeeded
                && ($locked->route_selection_id === null
                    || $locked->service_target_id === null
                    || $locked->capacity_reservation_id === null
                    || $locked->capacity_reservation_key === null
                    || $locked->remote_username === null
                    || $locked->target_reference === null)
            ) {
                throw new RuntimeException('Provisioning remote-effect intent is incomplete.');
            }

            $now = $this->nowString();
            $this->setAuthority($connection, $locked);
            try {
                $updated = $connection->table('provisioning_operations')
                    ->where('id', (int) $locked->id)
                    ->where('state', ProvisioningState::Running->value)
                    ->where('state_version', (int) $locked->state_version)
                    ->update([
                        'state' => $targetState->value,
                        'state_version' => (int) $locked->state_version + 1,
                        'last_result_code' => $this->resultCode($resultCode),
                        'last_result_message' => $this->safeMessage($message),
                        'remote_service_id' => $remoteServiceId,
                        'remote_effect_completed_at' => $now,
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Provisioning remote-effect finalization lost its state.');
                }

                if ($targetState === ProvisioningState::Succeeded) {
                    $serviceUpdated = $connection->table('service_subscriptions')
                        ->where('id', (int) $locked->service_subscription_id)
                        ->update([
                            'route_selection_id' => (int) $locked->route_selection_id,
                            'service_target_id' => (int) $locked->service_target_id,
                            'remote_service_id' => $remoteServiceId,
                            'provisioned_at' => $now,
                            'updated_at' => $now,
                        ]);
                    if ($serviceUpdated !== 1) {
                        throw new RuntimeException('Provisioned Service Subscription binding could not be persisted.');
                    }
                }

                $next = $this->operationById($connection, (int) $locked->id, true);
                $this->recordEvent($connection, $next, $targetState->value, $this->resultCode($resultCode));

                return $this->receipt($next, false);
            } finally {
                $this->clearAuthority($connection);
            }
        }, 3);
    }

    /** @param OperationRow $operation */
    private function commitCapacity(object $operation): void
    {
        if ($operation->capacity_reservation_id === null || $operation->capacity_reservation_key === null) {
            throw new RuntimeException('Provisioning capacity reservation is unavailable.');
        }

        /** @var object{state:string,version:int|string}|null $row */
        $row = $this->database->connection()->table('panel_capacity_reservations')
            ->where('id', (int) $operation->capacity_reservation_id)
            ->where('reservation_key', $operation->capacity_reservation_key)
            ->first(['state', 'version']);
        if ($row === null) {
            throw new RuntimeException('Provisioning capacity reservation disappeared.');
        }

        $state = CapacityReservationState::tryFrom($row->state)
            ?? throw new RuntimeException('Stored provisioning capacity reservation state is invalid.');
        if ($state === CapacityReservationState::Committed) {
            return;
        }
        if ($state !== CapacityReservationState::Held) {
            throw new RuntimeException('Provisioning capacity reservation cannot be committed from its current state.');
        }

        $this->capacity->commit(
            $operation->capacity_reservation_key,
            (int) $row->version,
            new CapacityOperationContext(
                'initial-provision-commit:'.$operation->public_id,
                $operation->correlation_id,
                'provisioning',
                'initial_provision',
                'remote_effect_succeeded',
            ),
        );
    }

    /** @param OperationRow $operation */
    private function remoteRequest(object $operation, RouteSelectionReceipt $route): PanelCreateServiceRequest
    {
        if ($operation->remote_username === null || $operation->target_reference === null) {
            throw new RuntimeException('Provisioning remote identity is unavailable.');
        }

        $dataLimitBytes = null;
        $expiresAt = null;
        if ($this->isTrialSource($operation)) {
            $trialAuthority = $this->trialProvisioningAuthority($operation);
            if ($trialAuthority['route']->selectionId !== $route->selectionId) {
                throw new DomainException('Trial provisioning route no longer matches the source authorization.');
            }
            $startedAt = $this->storedDateTime($operation->remote_effect_started_at, 'Provisioning remote-effect start');
            $dataLimitBytes = $trialAuthority['data_bytes'];
            $expiresAt = $startedAt->add(new DateInterval('P'.$trialAuthority['duration_days'].'D'));
        }

        return new PanelCreateServiceRequest(
            $operation->public_id,
            $operation->operation_key,
            $operation->remote_username,
            $operation->target_reference,
            $dataLimitBytes,
            $expiresAt,
            [
                'plan_offering_id' => (int) $operation->plan_offering_id,
                'route_selection_id' => $route->selectionId,
                'sales_server_id' => $route->salesServerId,
                'service_target_id' => $route->serviceTargetId,
                'protocol_profile_id' => $route->protocolProfileId,
                'service_subscription_public_id' => $operation->service_public_id,
            ],
        );
    }

    /** @param OperationRow $operation */
    private function storedRouteSelection(
        int $selectionId,
        object $operation,
        ?Connection $connection = null,
    ): RouteSelectionReceipt {
        if ($this->isTrialSource($operation)) {
            $trialAuthority = $this->trialProvisioningAuthority($operation, $connection);
            $route = $trialAuthority['route'];
            if ($route->selectionId !== $selectionId
                || $route->serviceTargetId !== (int) $operation->service_target_id
                || $route->capacityReservationId !== (int) $operation->capacity_reservation_id
                || ! hash_equals($route->capacityReservationKey, (string) $operation->capacity_reservation_key)
            ) {
                throw new RuntimeException('Stored Trial provisioning route selection is inconsistent.');
            }

            return $route;
        }
        $database = $connection ?? $this->database->connection();
        /** @var object{selection_id:int|string,offering_id:int|string,route_policy_id:int|string,route_id:int|string,sales_server_id:int|string,service_target_id:int|string,protocol_profile_id:int|string,capacity_reservation_id:int|string,capacity_reservation_key:string,units:int|string,fallback_used:int|bool,disclosure_fa:?string,capacity_available_units:int|string,capacity_version:int|string}|null $row */
        $row = $database->table('plan_offering_route_selections as selection')
            ->join('panel_capacity_reservations as reservation', 'reservation.id', '=', 'selection.capacity_reservation_id')
            ->where('selection.id', $selectionId)
            ->where('selection.command_key', $this->routeCommandKey($operation->public_id))
            ->first([
                'selection.id as selection_id',
                'selection.plan_offering_id as offering_id',
                'selection.plan_offering_route_policy_id as route_policy_id',
                'selection.plan_offering_route_id as route_id',
                'selection.selected_sales_server_id as sales_server_id',
                'selection.selected_service_target_id as service_target_id',
                'selection.panel_protocol_profile_id as protocol_profile_id',
                'selection.capacity_reservation_id',
                'reservation.reservation_key as capacity_reservation_key',
                'selection.units',
                'selection.fallback_used',
                'selection.disclosure_fa_snapshot as disclosure_fa',
                'selection.capacity_available_units',
                'selection.capacity_version',
            ]);
        if ($row === null
            || (int) $row->offering_id !== (int) $operation->plan_offering_id
            || (int) $row->service_target_id !== (int) $operation->service_target_id
            || (int) $row->capacity_reservation_id !== (int) $operation->capacity_reservation_id
            || ! hash_equals($row->capacity_reservation_key, (string) $operation->capacity_reservation_key)
        ) {
            throw new RuntimeException('Stored provisioning route selection is inconsistent.');
        }

        return new RouteSelectionReceipt(
            (int) $row->selection_id,
            (int) $row->offering_id,
            (int) $row->route_policy_id,
            (int) $row->route_id,
            (int) $row->sales_server_id,
            (int) $row->service_target_id,
            (int) $row->protocol_profile_id,
            (int) $row->capacity_reservation_id,
            $row->capacity_reservation_key,
            (int) $row->units,
            (bool) $row->fallback_used,
            $row->disclosure_fa,
            (int) $row->capacity_available_units,
            (int) $row->capacity_version,
            true,
        );
    }

    /**
     * @param  OperationRow  $operation
     * @return TrialProvisioningAuthority
     */
    private function trialProvisioningAuthority(
        object $operation,
        ?Connection $connection = null,
        bool $lock = false,
    ): array {
        if (! $this->isTrialSource($operation) || $operation->order_source_authorization_id === null) {
            throw new DomainException('Trial provisioning source authorization is unavailable.');
        }

        $database = $connection ?? $this->database->connection();
        $authorizationQuery = $database->table('order_source_authorizations')
            ->where('id', (int) $operation->order_source_authorization_id);
        if ($lock) {
            $authorizationQuery->lockForUpdate();
        }
        /** @var object{source_type:string,user_id:int|string,plan_offering_id:int|string,trial_reservation_id:int|string|null,trial_reservation_command_key:?string,configuration_snapshot:string,configuration_snapshot_hash:string}|null $authorization */
        $authorization = $authorizationQuery->first([
            'source_type', 'user_id', 'plan_offering_id', 'trial_reservation_id', 'trial_reservation_command_key',
            'configuration_snapshot', 'configuration_snapshot_hash',
        ]);
        if ($authorization === null
            || $authorization->source_type !== OrderSourceType::Trial->value
            || (int) $authorization->user_id !== (int) $operation->user_id
            || (int) $authorization->plan_offering_id !== (int) $operation->plan_offering_id
            || $authorization->trial_reservation_id === null
            || ! is_string($authorization->trial_reservation_command_key)
            || $authorization->trial_reservation_command_key === ''
            || ! hash_equals(strtolower($authorization->configuration_snapshot_hash), hash('sha256', $authorization->configuration_snapshot))
        ) {
            throw new DomainException('Trial provisioning source authorization is inconsistent.');
        }

        try {
            $configuration = json_decode($authorization->configuration_snapshot, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Trial provisioning configuration snapshot is invalid.', previous: $exception);
        }
        if (! is_array($configuration)) {
            throw new DomainException('Trial provisioning configuration snapshot is invalid.');
        }
        $selectionId = $this->positiveSnapshotInt($configuration['plan_offering_route_selection_id'] ?? null, 'Trial route selection ID');
        $dataBytes = $this->positiveSnapshotInt($configuration['data_bytes'] ?? null, 'Trial data allowance');
        $durationDays = $this->positiveSnapshotInt($configuration['duration_days'] ?? null, 'Trial duration');

        $reservationQuery = $database->table('trial_reservations')
            ->where('id', (int) $authorization->trial_reservation_id);
        if ($lock) {
            $reservationQuery->lockForUpdate();
        }
        /** @var object{command_key:string,plan_offering_id:int|string,user_id:int|string,plan_offering_route_selection_id:int|string,state:string,data_bytes:int|string,duration_days:int|string}|null $trial */
        $trial = $reservationQuery->first([
            'command_key', 'plan_offering_id', 'user_id', 'plan_offering_route_selection_id', 'state', 'data_bytes', 'duration_days',
        ]);
        if ($trial === null
            || $trial->state !== 'committed'
            || ! hash_equals($authorization->trial_reservation_command_key, $trial->command_key)
            || (int) $trial->plan_offering_id !== (int) $operation->plan_offering_id
            || (int) $trial->user_id !== (int) $operation->user_id
            || (int) $trial->plan_offering_route_selection_id !== $selectionId
            || (int) $trial->data_bytes !== $dataBytes
            || (int) $trial->duration_days !== $durationDays
        ) {
            throw new DomainException('Trial provisioning reservation authority is inconsistent.');
        }

        $selectionQuery = $database->table('plan_offering_route_selections as selection')
            ->where('selection.id', $selectionId);
        if ($lock) {
            $selectionQuery->lockForUpdate();
        }
        /** @var object{selection_id:int|string,offering_id:int|string,user_id:int|string,route_policy_id:int|string,route_id:int|string,sales_server_id:int|string,service_target_id:int|string,protocol_profile_id:int|string,capacity_reservation_id:int|string,units:int|string,fallback_used:int|bool,disclosure_fa:?string,capacity_available_units:int|string,capacity_version:int|string}|null $selection */
        $selection = $selectionQuery->first([
            'selection.id as selection_id', 'selection.plan_offering_id as offering_id', 'selection.user_id',
            'selection.plan_offering_route_policy_id as route_policy_id', 'selection.plan_offering_route_id as route_id',
            'selection.selected_sales_server_id as sales_server_id', 'selection.selected_service_target_id as service_target_id',
            'selection.panel_protocol_profile_id as protocol_profile_id', 'selection.capacity_reservation_id', 'selection.units',
            'selection.fallback_used', 'selection.disclosure_fa_snapshot as disclosure_fa',
            'selection.capacity_available_units', 'selection.capacity_version',
        ]);
        if ($selection === null
            || (int) $selection->offering_id !== (int) $operation->plan_offering_id
            || (int) $selection->user_id !== (int) $operation->user_id
            || (int) $selection->units !== 1
        ) {
            throw new DomainException('Trial provisioning route selection is inconsistent.');
        }

        $capacityReservationQuery = $database->table('panel_capacity_reservations as reservation')
            ->join('panel_target_capacities as capacity', 'capacity.id', '=', 'reservation.panel_target_capacity_id')
            ->where('reservation.id', (int) $selection->capacity_reservation_id);
        if ($lock) {
            $capacityReservationQuery->lockForUpdate();
        }
        /** @var object{reservation_key:string,units:int|string,state:string,expires_at:string,service_target_id:int|string}|null $capacityReservation */
        $capacityReservation = $capacityReservationQuery->first([
            'reservation.reservation_key', 'reservation.units', 'reservation.state', 'reservation.expires_at',
            'capacity.panel_service_target_id as service_target_id',
        ]);
        if ($capacityReservation === null
            || $capacityReservation->state !== CapacityReservationState::Committed->value
            || (int) $capacityReservation->units !== 1
            || (int) $capacityReservation->service_target_id !== (int) $selection->service_target_id
        ) {
            throw new DomainException('Trial provisioning committed capacity authority is inconsistent.');
        }

        return [
            'route' => new RouteSelectionReceipt(
                (int) $selection->selection_id,
                (int) $selection->offering_id,
                (int) $selection->route_policy_id,
                (int) $selection->route_id,
                (int) $selection->sales_server_id,
                (int) $selection->service_target_id,
                (int) $selection->protocol_profile_id,
                (int) $selection->capacity_reservation_id,
                $capacityReservation->reservation_key,
                (int) $selection->units,
                (bool) $selection->fallback_used,
                $selection->disclosure_fa,
                (int) $selection->capacity_available_units,
                (int) $selection->capacity_version,
                true,
            ),
            'capacity_expires_at' => $capacityReservation->expires_at,
            'data_bytes' => $dataBytes,
            'duration_days' => $durationDays,
        ];
    }

    /** @param OperationRow $operation */
    private function isTrialSource(object $operation): bool
    {
        return $operation->order_source_type === OrderSourceType::Trial->value;
    }

    private function positiveSnapshotInt(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 1) {
            throw new DomainException($field.' must be a positive integer in the Trial source snapshot.');
        }

        return $value;
    }

    /** @return OperationRow */
    private function operationByPublicId(string $publicId): object
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $publicId) !== 1) {
            throw new DomainException('Provisioning operation public ID is invalid.');
        }

        /** @var OperationRow|null $row */
        $row = $this->operationQuery($this->database->connection())
            ->where('operation.public_id', strtoupper($publicId))
            ->first($this->operationColumns());
        if ($row === null || $row->operation_type !== self::OPERATION_TYPE) {
            throw new DomainException('Initial provisioning operation does not exist.');
        }

        return $row;
    }

    /** @return OperationRow */
    private function operationById(Connection $connection, int $operationId, bool $lock = false): object
    {
        $query = $this->operationQuery($connection)->where('operation.id', $operationId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var OperationRow|null $row */
        $row = $query->first($this->operationColumns());
        if ($row === null || $row->operation_type !== self::OPERATION_TYPE) {
            throw new RuntimeException('Initial provisioning operation disappeared.');
        }

        return $row;
    }

    private function operationQuery(Connection $connection): Builder
    {
        return $connection->table('provisioning_operations as operation')
            ->join('orders as order_row', 'order_row.id', '=', 'operation.order_id')
            ->join('order_items as item', 'item.id', '=', 'operation.order_item_id')
            ->join('service_subscriptions as service', 'service.id', '=', 'operation.service_subscription_id');
    }

    /** @return list<string> */
    private function operationColumns(): array
    {
        return [
            'operation.id', 'operation.public_id', 'operation.operation_key', 'operation.operation_type',
            'operation.order_id', 'operation.order_item_id', 'operation.service_subscription_id', 'operation.user_id',
            'operation.state', 'operation.state_version', 'operation.correlation_id', 'operation.effect_fence_key',
            'operation.route_hold_expires_at', 'operation.route_selection_id', 'operation.service_target_id',
            'operation.capacity_reservation_id', 'operation.capacity_reservation_key', 'operation.remote_username',
            'operation.target_reference', 'operation.attempt_count', 'operation.last_result_code',
            'operation.last_result_message', 'operation.remote_service_id', 'operation.remote_effect_started_at',
            'operation.remote_effect_completed_at', 'order_row.purchase_settlement_id', 'order_row.payment_intent_id',
            'order_row.state as order_state', 'order_row.state_version as order_state_version',
            'order_row.source_type as order_source_type', 'order_row.order_source_authorization_id',
            'item.plan_offering_id', 'item.account_type_snapshot', 'service.public_id as service_public_id',
        ];
    }

    /** @param OperationRow $operation */
    private function recordEvent(Connection $connection, object $operation, string $eventType, ?string $resultCode): void
    {
        $connection->table('provisioning_remote_effect_events')->insert([
            'provisioning_operation_id' => (int) $operation->id,
            'event_type' => $eventType,
            'state_version' => (int) $operation->state_version,
            'route_selection_id' => $operation->route_selection_id === null ? null : (int) $operation->route_selection_id,
            'service_target_id' => $operation->service_target_id === null ? null : (int) $operation->service_target_id,
            'remote_service_id' => $operation->remote_service_id,
            'result_code' => $resultCode,
            'correlation_id' => $operation->correlation_id,
            'created_at' => $this->nowString(),
        ]);
    }

    /** @param OperationRow $operation */
    private function setAuthority(Connection $connection, object $operation): void
    {
        $connection->statement('SET @app_provisioning_authority = ?, @app_provisioning_operation_key = ?, @app_provisioning_correlation_id = ?', [
            self::AUTHORITY,
            $operation->operation_key,
            $operation->correlation_id,
        ]);
    }

    private function clearAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_provisioning_authority = NULL, @app_provisioning_operation_key = NULL, @app_provisioning_correlation_id = NULL');
    }

    private function storedState(string $value): ProvisioningState
    {
        return ProvisioningState::tryFrom($value)
            ?? throw new RuntimeException('Stored provisioning state is invalid.');
    }

    private function isReplayTerminal(ProvisioningState $state): bool
    {
        return in_array($state, [
            ProvisioningState::Succeeded,
            ProvisioningState::FailedFinal,
            ProvisioningState::UncertainRemoteResult,
            ProvisioningState::NeedsReview,
            ProvisioningState::Compensated,
        ], true);
    }

    /** @param OperationRow $operation */
    private function receipt(object $operation, bool $replayed): InitialProvisioningExecutionReceipt
    {
        return new InitialProvisioningExecutionReceipt(
            (int) $operation->id,
            $operation->public_id,
            $this->storedState($operation->state),
            (int) $operation->state_version,
            (int) $operation->attempt_count,
            $operation->route_selection_id === null ? null : (int) $operation->route_selection_id,
            $operation->service_target_id === null ? null : (int) $operation->service_target_id,
            $operation->remote_service_id,
            $operation->last_result_code,
            $replayed,
        );
    }

    private function routeCommandKey(string $operationPublicId): string
    {
        return 'initial-provision-route:'.strtolower($operationPublicId);
    }

    private function effectFenceKey(string $operationPublicId): string
    {
        return 'initial-effect:'.substr(hash('sha256', $operationPublicId), 0, 48);
    }

    private function resultCode(string $value): string
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z0-9_.:-]{1,64}\z/', $normalized) !== 1) {
            return 'remote_result_unclassified';
        }

        return $normalized;
    }

    private function safeMessage(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '');
        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, 512);
    }

    private function storedDateTime(mixed $value, string $label): DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException($label.' is unavailable.');
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException($label.' is invalid.', 0, $exception);
        }
    }

    private function nowString(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
