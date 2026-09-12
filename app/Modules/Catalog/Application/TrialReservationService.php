<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Catalog\Domain\TrialReservationState;
use App\Modules\Panels\Application\CapacityOperationContext;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class TrialReservationService
{
    private const ADMIN_PERMISSION = 'catalog.manage';

    public function __construct(
        private DatabaseManager $database,
        private TrialEligibility $eligibility,
        private TrialMembershipVerifier $membershipVerifier,
        private TrialRouteSelector $routeSelector,
        private TargetCapacityAllocator $targetCapacity,
        private AdministratorPermissionAuthorizer $authorizer,
        private Clock $clock,
    ) {}

    /** @requirement CAT-006 CAT-008 SEC-002 DAT-003 QUA-001 */
    public function reserve(TrialReservationRequest $request, TrialContext $context): TrialReservationReceipt
    {
        $payloadHash = CatalogPayloadHash::make($request->payload());
        $existing = $this->existingReservation($context->commandKey, $payloadHash);
        if ($existing !== null) {
            return $existing;
        }
        if ($request->expiresAt <= $this->clock->now()) {
            throw new DomainException('Trial reservation expiry must be in the future.');
        }

        $membershipPolicy = $this->membershipAuthorizationPreflight($request);
        if ($membershipPolicy->membership_required) {
            $this->membershipVerifier->assertSatisfied(
                $this->database->connection(),
                $request->userId,
                $request->offeringId,
                $membershipPolicy->id,
            );
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $request,
                $context,
                $payloadHash,
                $membershipPolicy,
            ): TrialReservationReceipt {
                $replay = $this->existingReservation($context->commandKey, $payloadHash, $connection, true);
                if ($replay !== null) {
                    return $replay;
                }

                $offering = $this->lockedOffering($connection, $request->offeringId);
                $policy = $this->lockedPolicy($connection, $request->offeringId);
                $this->assertMembershipPolicyUnchanged($policy, $membershipPolicy);
                $actor = $this->eligibility->actor($connection, $request->userId);
                $this->eligibility->assertOfferingAudience($offering->audience);
                $this->eligibility->assertOfferingEligibility(
                    $connection,
                    $request->offeringId,
                    $offering->tag_match_mode,
                    $actor,
                );
                $this->eligibility->assertPolicyEligibility(
                    $connection,
                    $policy->id,
                    $policy->tag_match_mode,
                    $actor,
                );
                $this->eligibility->assertPhonePolicy($policy->phone_verification_policy, $actor);
                $this->assertNoPriorClaim($connection, $policy, $actor);

                $capacityDate = $this->businessDate();
                $counter = $this->reserveDailyCapacity(
                    $connection,
                    $policy->id,
                    $capacityDate,
                    $policy->daily_capacity,
                );

                $route = $this->routeSelector->select(
                    $connection,
                    $request,
                    $policy->fallback_allowed,
                    $context,
                );

                $reservationId = (int) $connection->table('trial_reservations')->insertGetId([
                    'command_key' => $context->commandKey,
                    'payload_hash' => $payloadHash,
                    'trial_policy_id' => $policy->id,
                    'trial_policy_version' => $policy->version,
                    'policy_configuration_hash' => $policy->configuration_hash,
                    'plan_offering_id' => $request->offeringId,
                    'user_id' => $request->userId,
                    'phone_number_id' => $actor->phoneNumberId,
                    'active_user_id' => $policy->one_per_user ? $request->userId : null,
                    'active_phone_number_id' => $policy->one_per_phone ? $actor->phoneNumberId : null,
                    'trial_daily_capacity_counter_id' => $counter->id,
                    'plan_offering_route_selection_id' => $route->selectionId,
                    'state' => TrialReservationState::Reserved->value,
                    'version' => 1,
                    'capacity_date' => $capacityDate,
                    'data_bytes' => $policy->data_bytes,
                    'duration_days' => $policy->duration_days,
                    'daily_capacity_snapshot' => $policy->daily_capacity,
                    'phone_verification_policy_snapshot' => $policy->phone_verification_policy,
                    'membership_required_snapshot' => $policy->membership_required,
                    'one_per_user_snapshot' => $policy->one_per_user,
                    'one_per_phone_snapshot' => $policy->one_per_phone,
                    'fallback_allowed_snapshot' => $policy->fallback_allowed,
                    'fallback_used_snapshot' => $route->fallbackUsed,
                    'disclosure_fa_snapshot' => $route->disclosureFa,
                    'delivery_template_key_snapshot' => $policy->delivery_template_key,
                    'eligibility_snapshot_hash' => $actor->eligibilityHash,
                    'expires_at' => $request->expiresAt->format('Y-m-d H:i:s.u'),
                    'committed_at' => null,
                    'released_at' => null,
                    'expired_at' => null,
                    'eligibility_reset_at' => null,
                    'eligibility_reset_by_administrator_id' => null,
                    'correlation_id' => $context->correlationId,
                    'source_code' => $context->sourceCode,
                    'reason_code' => $context->reasonCode,
                    'created_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
                $this->recordEvent(
                    $connection,
                    $reservationId,
                    $context->commandKey,
                    $payloadHash,
                    'trial.reserve',
                    null,
                    TrialReservationState::Reserved->value,
                    1,
                    null,
                    $context->correlationId,
                    $context->sourceCode,
                    $context->reasonCode,
                    null,
                );

                return $this->currentReceipt($connection, $reservationId, false);
            }, 3);
        } catch (QueryException $exception) {
            $replay = $this->existingReservation($context->commandKey, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            if (str_contains($exception->getMessage(), 'trial_reservation_active_user_unique')) {
                throw new DomainException('Trial one-per-user policy is already consumed.', previous: $exception);
            }
            if (str_contains($exception->getMessage(), 'trial_reservation_active_phone_unique')) {
                throw new DomainException('Trial one-per-phone policy is already consumed.', previous: $exception);
            }

            throw $exception;
        }
    }

    public function commit(
        int $reservationId,
        int $expectedVersion,
        TrialContext $context,
    ): TrialReservationReceipt {
        return $this->transition(
            $reservationId,
            $expectedVersion,
            TrialReservationState::Committed,
            'trial.commit',
            $context,
        );
    }

    public function release(
        int $reservationId,
        int $expectedVersion,
        TrialContext $context,
    ): TrialReservationReceipt {
        return $this->transition(
            $reservationId,
            $expectedVersion,
            TrialReservationState::Released,
            'trial.release',
            $context,
        );
    }

    public function expire(
        int $reservationId,
        int $expectedVersion,
        TrialContext $context,
    ): TrialReservationReceipt {
        return $this->transition(
            $reservationId,
            $expectedVersion,
            TrialReservationState::Expired,
            'trial.expire',
            $context,
        );
    }

    /** @requirement CAT-006 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function resetEligibility(
        int $reservationId,
        int $expectedVersion,
        CatalogChangeContext $context,
    ): TrialReservationReceipt {
        if ($reservationId < 1 || $expectedVersion < 1) {
            throw new RuntimeException('Trial reservation ID and version must be positive.');
        }
        $reason = $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::ADMIN_PERMISSION);
        $payloadHash = CatalogPayloadHash::make([
            'reservation_id' => $reservationId,
            'expected_version' => $expectedVersion,
            'action' => 'eligibility_reset',
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $reason,
        ]);
        $existing = $this->existingEvent(
            $context->requestFingerprint,
            'trial.eligibility_reset',
            $payloadHash,
        );
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $reservationId,
                $expectedVersion,
                $context,
                $reason,
                $payloadHash,
            ): TrialReservationReceipt {
                $this->authorizeAdministratorInsideTransaction($connection, $context->actorAdministratorId);
                $replay = $this->existingEvent(
                    $context->requestFingerprint,
                    'trial.eligibility_reset',
                    $payloadHash,
                    $connection,
                    true,
                );
                if ($replay !== null) {
                    return $replay;
                }

                /** @var object{id: int|string, trial_policy_id: int|string, state: string, version: int|string, eligibility_reset_at: ?string}|null $reservation */
                $reservation = $connection->table('trial_reservations')
                    ->where('id', $reservationId)
                    ->lockForUpdate()
                    ->first(['id', 'trial_policy_id', 'state', 'version', 'eligibility_reset_at']);
                if ($reservation === null) {
                    throw new RuntimeException('Trial reservation does not exist.');
                }
                if ((int) $reservation->version !== $expectedVersion) {
                    throw new RuntimeException('Trial reservation version conflict.');
                }
                if ($reservation->state !== TrialReservationState::Committed->value) {
                    throw new DomainException('Only a committed trial may be regranted.');
                }
                if ($reservation->eligibility_reset_at !== null) {
                    throw new DomainException('Trial eligibility was already reset.');
                }
                if (! (bool) $connection->table('trial_policies')
                    ->where('id', (int) $reservation->trial_policy_id)
                    ->value('administrator_regrant_allowed')
                ) {
                    throw new DomainException('Trial administrator regrant is disabled.');
                }

                $newVersion = $expectedVersion + 1;
                $connection->table('trial_reservations')->where('id', $reservationId)->update([
                    'active_user_id' => null,
                    'active_phone_number_id' => null,
                    'eligibility_reset_at' => $this->timestamp(),
                    'eligibility_reset_by_administrator_id' => $context->actorAdministratorId,
                    'version' => $newVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $this->recordEvent(
                    $connection,
                    $reservationId,
                    $context->requestFingerprint,
                    $payloadHash,
                    'trial.eligibility_reset',
                    TrialReservationState::Committed->value,
                    TrialReservationState::Committed->value,
                    $newVersion,
                    $context->actorAdministratorId,
                    $context->correlationId,
                    'administrator',
                    $context->reasonCode,
                    $reason,
                );

                return $this->currentReceipt($connection, $reservationId, false);
            }, 3);
        } catch (QueryException $exception) {
            $replay = $this->existingEvent(
                $context->requestFingerprint,
                'trial.eligibility_reset',
                $payloadHash,
            );
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    private function transition(
        int $reservationId,
        int $expectedVersion,
        TrialReservationState $targetState,
        string $action,
        TrialContext $context,
    ): TrialReservationReceipt {
        if ($reservationId < 1 || $expectedVersion < 1) {
            throw new RuntimeException('Trial reservation ID and version must be positive.');
        }
        $payloadHash = CatalogPayloadHash::make([
            'reservation_id' => $reservationId,
            'expected_version' => $expectedVersion,
            'target_state' => $targetState->value,
        ]);
        $existing = $this->existingEvent($context->commandKey, $action, $payloadHash);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $reservationId,
                $expectedVersion,
                $targetState,
                $action,
                $context,
                $payloadHash,
            ): TrialReservationReceipt {
                $replay = $this->existingEvent($context->commandKey, $action, $payloadHash, $connection, true);
                if ($replay !== null) {
                    return $replay;
                }

                /** @var object{id: int|string, state: string, version: int|string, expires_at: string, trial_daily_capacity_counter_id: int|string, plan_offering_route_selection_id: int|string}|null $reservation */
                $reservation = $connection->table('trial_reservations')
                    ->where('id', $reservationId)
                    ->lockForUpdate()
                    ->first([
                        'id', 'state', 'version', 'expires_at',
                        'trial_daily_capacity_counter_id', 'plan_offering_route_selection_id',
                    ]);
                if ($reservation === null) {
                    throw new RuntimeException('Trial reservation does not exist.');
                }
                if ((int) $reservation->version !== $expectedVersion) {
                    throw new RuntimeException('Trial reservation version conflict.');
                }

                $state = TrialReservationState::tryFrom($reservation->state)
                    ?? throw new RuntimeException('Stored trial reservation state is invalid.');
                $state->assertCanTransitionTo($targetState);
                $expiresAt = new DateTimeImmutable($reservation->expires_at);
                if ($targetState === TrialReservationState::Committed && $expiresAt <= $this->clock->now()) {
                    throw new DomainException('Expired trial reservation cannot be committed.');
                }
                if ($targetState === TrialReservationState::Expired && $expiresAt > $this->clock->now()) {
                    throw new DomainException('Trial reservation is not expired yet.');
                }

                /** @var object{capacity_reservation_id: int|string}|null $route */
                $route = $connection->table('plan_offering_route_selections')
                    ->where('id', (int) $reservation->plan_offering_route_selection_id)
                    ->lockForUpdate()
                    ->first(['capacity_reservation_id']);
                if ($route === null) {
                    throw new RuntimeException('Trial route selection does not exist.');
                }
                /** @var object{reservation_key: string, version: int|string}|null $capacityReservation */
                $capacityReservation = $connection->table('panel_capacity_reservations')
                    ->where('id', (int) $route->capacity_reservation_id)
                    ->lockForUpdate()
                    ->first(['reservation_key', 'version']);
                if ($capacityReservation === null) {
                    throw new RuntimeException('Trial target-capacity reservation does not exist.');
                }

                $capacityContext = new CapacityOperationContext(
                    'trial-target-capacity:'.hash('sha256', $context->commandKey.':'.$targetState->value),
                    $context->correlationId,
                    'trial',
                    'trial_reservation',
                    'trial_'.$targetState->value,
                );
                match ($targetState) {
                    TrialReservationState::Committed => $this->targetCapacity->commit(
                        $capacityReservation->reservation_key,
                        (int) $capacityReservation->version,
                        $capacityContext,
                    ),
                    TrialReservationState::Released => $this->targetCapacity->release(
                        $capacityReservation->reservation_key,
                        (int) $capacityReservation->version,
                        $capacityContext,
                    ),
                    TrialReservationState::Expired => $this->targetCapacity->expire(
                        $capacityReservation->reservation_key,
                        (int) $capacityReservation->version,
                        $capacityContext,
                    ),
                    default => throw new RuntimeException('Unsupported trial transition.'),
                };

                $this->transitionDailyCapacity(
                    $connection,
                    (int) $reservation->trial_daily_capacity_counter_id,
                    $targetState,
                );
                $newVersion = $expectedVersion + 1;
                $updates = [
                    'state' => $targetState->value,
                    'version' => $newVersion,
                    'updated_at' => $this->timestamp(),
                ];
                if ($targetState === TrialReservationState::Committed) {
                    $updates['committed_at'] = $this->timestamp();
                } else {
                    $updates['active_user_id'] = null;
                    $updates['active_phone_number_id'] = null;
                    $updates[$targetState === TrialReservationState::Released ? 'released_at' : 'expired_at'] = $this->timestamp();
                }
                $connection->table('trial_reservations')->where('id', $reservationId)->update($updates);
                $this->recordEvent(
                    $connection,
                    $reservationId,
                    $context->commandKey,
                    $payloadHash,
                    $action,
                    $state->value,
                    $targetState->value,
                    $newVersion,
                    null,
                    $context->correlationId,
                    $context->sourceCode,
                    $context->reasonCode,
                    null,
                );

                return $this->currentReceipt($connection, $reservationId, false);
            }, 3);
        } catch (QueryException $exception) {
            $replay = $this->existingEvent($context->commandKey, $action, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @return object{id: int, audience: string, tag_match_mode: string} */
    private function lockedOffering(Connection $connection, int $offeringId): object
    {
        /** @var object{id: int|string, state: string, trial_allowed: bool|int, audience: string, tag_match_mode: string}|null $row */
        $row = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->lockForUpdate()
            ->first(['id', 'state', 'trial_allowed', 'audience', 'tag_match_mode']);
        if ($row === null) {
            throw new RuntimeException('Plan offering does not exist.');
        }
        if (! (bool) $row->trial_allowed || ! in_array($row->state, ['draft', 'active'], true)) {
            throw new DomainException('Trial offering is unavailable.');
        }

        return (object) [
            'id' => (int) $row->id,
            'audience' => $row->audience,
            'tag_match_mode' => $row->tag_match_mode,
        ];
    }

    /** @return object{id:int,version:int,configuration_hash:string,membership_required:bool} */
    private function membershipAuthorizationPreflight(TrialReservationRequest $request): object
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($request): object {
            $offering = $this->lockedOffering($connection, $request->offeringId);
            $policy = $this->lockedPolicy($connection, $request->offeringId);
            $actor = $this->eligibility->actor($connection, $request->userId);
            $this->eligibility->assertOfferingAudience($offering->audience);
            $this->eligibility->assertOfferingEligibility(
                $connection,
                $request->offeringId,
                $offering->tag_match_mode,
                $actor,
            );
            $this->eligibility->assertPolicyEligibility(
                $connection,
                $policy->id,
                $policy->tag_match_mode,
                $actor,
            );
            $this->eligibility->assertPhonePolicy($policy->phone_verification_policy, $actor);

            return (object) [
                'id' => $policy->id,
                'version' => $policy->version,
                'configuration_hash' => $policy->configuration_hash,
                'membership_required' => $policy->membership_required,
            ];
        }, 1);
    }

    /**
     * @param  object{id:int,version:int,configuration_hash:string,membership_required:bool}  $policy
     * @param  object{id:int,version:int,configuration_hash:string,membership_required:bool}  $preflight
     */
    private function assertMembershipPolicyUnchanged(object $policy, object $preflight): void
    {
        if (! $preflight->membership_required) {
            if ($policy->membership_required) {
                throw new DomainException('Trial membership policy changed after verification.');
            }

            return;
        }

        if ($policy->id !== $preflight->id
            || $policy->version !== $preflight->version
            || ! $policy->membership_required
            || ! hash_equals($policy->configuration_hash, $preflight->configuration_hash)
        ) {
            throw new DomainException('Trial membership policy changed after verification.');
        }
    }

    /** @return object{id: int, version: int, configuration_hash: string, data_bytes: int, duration_days: int, daily_capacity: int, phone_verification_policy: string, membership_required: bool, one_per_user: bool, one_per_phone: bool, fallback_allowed: bool, tag_match_mode: string, delivery_template_key: string} */
    private function lockedPolicy(Connection $connection, int $offeringId): object
    {
        /** @var object{id: int|string, version: int|string, configuration_hash: string, enabled: bool|int, data_bytes: int|string, duration_days: int|string, daily_capacity: int|string, phone_verification_policy: string, membership_required: bool|int, one_per_user: bool|int, one_per_phone: bool|int, fallback_allowed: bool|int, tag_match_mode: string, delivery_template_key: string}|null $row */
        $row = $connection->table('trial_policies')
            ->where('plan_offering_id', $offeringId)
            ->lockForUpdate()
            ->first([
                'id', 'version', 'configuration_hash', 'enabled', 'data_bytes', 'duration_days',
                'daily_capacity', 'phone_verification_policy', 'membership_required',
                'one_per_user', 'one_per_phone', 'fallback_allowed', 'tag_match_mode',
                'delivery_template_key',
            ]);
        if ($row === null || ! (bool) $row->enabled) {
            throw new DomainException('Trial policy is unavailable.');
        }

        return (object) [
            'id' => (int) $row->id,
            'version' => (int) $row->version,
            'configuration_hash' => $row->configuration_hash,
            'data_bytes' => (int) $row->data_bytes,
            'duration_days' => (int) $row->duration_days,
            'daily_capacity' => (int) $row->daily_capacity,
            'phone_verification_policy' => $row->phone_verification_policy,
            'membership_required' => (bool) $row->membership_required,
            'one_per_user' => (bool) $row->one_per_user,
            'one_per_phone' => (bool) $row->one_per_phone,
            'fallback_allowed' => (bool) $row->fallback_allowed,
            'tag_match_mode' => $row->tag_match_mode,
            'delivery_template_key' => $row->delivery_template_key,
        ];
    }

    /**
     * @param  object{id: int, one_per_user: bool, one_per_phone: bool}  $policy
     */
    private function assertNoPriorClaim(Connection $connection, object $policy, TrialActorSnapshot $actor): void
    {
        if ($policy->one_per_user
            && $connection->table('trial_reservations')
                ->where('trial_policy_id', $policy->id)
                ->where('active_user_id', $actor->userId)
                ->exists()
        ) {
            throw new DomainException('Trial one-per-user policy is already consumed.');
        }
        if ($policy->one_per_phone
            && $actor->phoneNumberId !== null
            && $connection->table('trial_reservations')
                ->where('trial_policy_id', $policy->id)
                ->where('active_phone_number_id', $actor->phoneNumberId)
                ->exists()
        ) {
            throw new DomainException('Trial one-per-phone policy is already consumed.');
        }
    }

    /** @return object{id: int, available: int} */
    private function reserveDailyCapacity(
        Connection $connection,
        int $policyId,
        string $capacityDate,
        int $dailyCapacity,
    ): object {
        $connection->table('trial_daily_capacity_counters')->insertOrIgnore([
            'trial_policy_id' => $policyId,
            'capacity_date' => $capacityDate,
            'hard_limit_snapshot' => $dailyCapacity,
            'reserved_count' => 0,
            'committed_count' => 0,
            'released_count' => 0,
            'expired_count' => 0,
            'version' => 1,
            'created_at' => $this->timestamp(),
            'updated_at' => $this->timestamp(),
        ]);
        /** @var object{id: int|string, hard_limit_snapshot: int|string, reserved_count: int|string, committed_count: int|string, version: int|string}|null $counter */
        $counter = $connection->table('trial_daily_capacity_counters')
            ->where('trial_policy_id', $policyId)
            ->where('capacity_date', $capacityDate)
            ->lockForUpdate()
            ->first(['id', 'hard_limit_snapshot', 'reserved_count', 'committed_count', 'version']);
        if ($counter === null) {
            throw new RuntimeException('Trial daily capacity counter is unavailable.');
        }
        if ((int) $counter->hard_limit_snapshot !== $dailyCapacity) {
            throw new RuntimeException('Trial daily capacity snapshot conflict.');
        }
        if ((int) $counter->reserved_count + (int) $counter->committed_count >= $dailyCapacity) {
            throw new DomainException('Trial daily capacity is exhausted.');
        }

        $connection->table('trial_daily_capacity_counters')->where('id', (int) $counter->id)->update([
            'reserved_count' => (int) $counter->reserved_count + 1,
            'version' => (int) $counter->version + 1,
            'updated_at' => $this->timestamp(),
        ]);

        return (object) [
            'id' => (int) $counter->id,
            'available' => $dailyCapacity - ((int) $counter->reserved_count + (int) $counter->committed_count + 1),
        ];
    }

    private function transitionDailyCapacity(
        Connection $connection,
        int $counterId,
        TrialReservationState $targetState,
    ): void {
        /** @var object{reserved_count: int|string, committed_count: int|string, released_count: int|string, expired_count: int|string, version: int|string}|null $counter */
        $counter = $connection->table('trial_daily_capacity_counters')
            ->where('id', $counterId)
            ->lockForUpdate()
            ->first(['reserved_count', 'committed_count', 'released_count', 'expired_count', 'version']);
        if ($counter === null || (int) $counter->reserved_count < 1) {
            throw new RuntimeException('Trial daily capacity reservation is inconsistent.');
        }

        $updates = [
            'reserved_count' => (int) $counter->reserved_count - 1,
            'committed_count' => (int) $counter->committed_count,
            'released_count' => (int) $counter->released_count,
            'expired_count' => (int) $counter->expired_count,
            'version' => (int) $counter->version + 1,
            'updated_at' => $this->timestamp(),
        ];
        if ($targetState === TrialReservationState::Committed) {
            $updates['committed_count']++;
        } elseif ($targetState === TrialReservationState::Released) {
            $updates['released_count']++;
        } elseif ($targetState === TrialReservationState::Expired) {
            $updates['expired_count']++;
        } else {
            throw new RuntimeException('Unsupported trial daily-capacity transition.');
        }
        $connection->table('trial_daily_capacity_counters')->where('id', $counterId)->update($updates);
    }

    private function existingReservation(
        string $commandKey,
        string $payloadHash,
        ?Connection $connection = null,
        bool $lock = false,
    ): ?TrialReservationReceipt {
        $database = $connection ?? $this->database->connection();
        $query = $database->table('trial_reservations')->where('command_key', $commandKey);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id: int|string, payload_hash: string}|null $row */
        $row = $query->first(['id', 'payload_hash']);
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row->payload_hash, $payloadHash)) {
            throw new RuntimeException('Trial command key conflict.');
        }

        return $this->currentReceipt($database, (int) $row->id, true);
    }

    private function existingEvent(
        string $commandKey,
        string $action,
        string $payloadHash,
        ?Connection $connection = null,
        bool $lock = false,
    ): ?TrialReservationReceipt {
        $database = $connection ?? $this->database->connection();
        $query = $database->table('trial_reservation_events')->where('command_key', $commandKey);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{trial_reservation_id: int|string, action: string, payload_hash: string}|null $event */
        $event = $query->first(['trial_reservation_id', 'action', 'payload_hash']);
        if ($event === null) {
            return null;
        }
        if ($event->action !== $action || ! hash_equals($event->payload_hash, $payloadHash)) {
            throw new RuntimeException('Trial transition command key conflict.');
        }

        return $this->currentReceipt($database, (int) $event->trial_reservation_id, true);
    }

    private function currentReceipt(
        Connection $connection,
        int $reservationId,
        bool $replayed,
    ): TrialReservationReceipt {
        /** @var object{id: int|string, plan_offering_id: int|string, trial_policy_id: int|string, trial_policy_version: int|string, user_id: int|string, phone_number_id: int|string|null, state: string, version: int|string, capacity_date: string, data_bytes: int|string, duration_days: int|string, delivery_template_key_snapshot: string, trial_daily_capacity_counter_id: int|string, plan_offering_route_selection_id: int|string}|null $reservation */
        $reservation = $connection->table('trial_reservations')->where('id', $reservationId)->first([
            'id', 'plan_offering_id', 'trial_policy_id', 'trial_policy_version', 'user_id',
            'phone_number_id', 'state', 'version', 'capacity_date', 'data_bytes', 'duration_days',
            'delivery_template_key_snapshot', 'trial_daily_capacity_counter_id',
            'plan_offering_route_selection_id',
        ]);
        if ($reservation === null) {
            throw new RuntimeException('Trial reservation receipt is unavailable.');
        }
        /** @var object{hard_limit_snapshot: int|string, reserved_count: int|string, committed_count: int|string}|null $counter */
        $counter = $connection->table('trial_daily_capacity_counters')
            ->where('id', (int) $reservation->trial_daily_capacity_counter_id)
            ->first(['hard_limit_snapshot', 'reserved_count', 'committed_count']);
        if ($counter === null) {
            throw new RuntimeException('Trial daily capacity receipt is unavailable.');
        }
        /** @var object{id: int|string, plan_offering_route_id: int|string, selected_sales_server_id: int|string, selected_service_target_id: int|string, panel_protocol_profile_id: int|string, capacity_reservation_id: int|string, fallback_used: bool|int, disclosure_fa_snapshot: ?string}|null $route */
        $route = $connection->table('plan_offering_route_selections')
            ->where('id', (int) $reservation->plan_offering_route_selection_id)
            ->first([
                'id', 'plan_offering_route_id', 'selected_sales_server_id', 'selected_service_target_id',
                'panel_protocol_profile_id', 'capacity_reservation_id', 'fallback_used',
                'disclosure_fa_snapshot',
            ]);
        if ($route === null) {
            throw new RuntimeException('Trial route receipt is unavailable.');
        }
        /** @var object{reservation_key: string}|null $capacity */
        $capacity = $connection->table('panel_capacity_reservations')
            ->where('id', (int) $route->capacity_reservation_id)
            ->first(['reservation_key']);
        if ($capacity === null) {
            throw new RuntimeException('Trial target-capacity receipt is unavailable.');
        }

        return new TrialReservationReceipt(
            (int) $reservation->id,
            (int) $reservation->plan_offering_id,
            (int) $reservation->trial_policy_id,
            (int) $reservation->trial_policy_version,
            (int) $reservation->user_id,
            $reservation->phone_number_id === null ? null : (int) $reservation->phone_number_id,
            $reservation->state,
            (int) $reservation->version,
            $reservation->capacity_date,
            max(0, (int) $counter->hard_limit_snapshot - (int) $counter->reserved_count - (int) $counter->committed_count),
            (int) $route->id,
            (int) $route->plan_offering_route_id,
            (int) $route->selected_sales_server_id,
            (int) $route->selected_service_target_id,
            (int) $route->panel_protocol_profile_id,
            (int) $route->capacity_reservation_id,
            $capacity->reservation_key,
            (bool) $route->fallback_used,
            $route->disclosure_fa_snapshot,
            (int) $reservation->data_bytes,
            (int) $reservation->duration_days,
            $reservation->delivery_template_key_snapshot,
            $replayed,
        );
    }

    private function recordEvent(
        Connection $connection,
        int $reservationId,
        string $commandKey,
        string $payloadHash,
        string $action,
        ?string $fromState,
        string $toState,
        int $reservationVersion,
        ?int $actorAdministratorId,
        string $correlationId,
        string $sourceCode,
        string $reasonCode,
        ?string $reason,
    ): void {
        $connection->table('trial_reservation_events')->insert([
            'trial_reservation_id' => $reservationId,
            'command_key' => $commandKey,
            'payload_hash' => $payloadHash,
            'action' => $action,
            'from_state' => $fromState,
            'to_state' => $toState,
            'reservation_version' => $reservationVersion,
            'actor_administrator_id' => $actorAdministratorId,
            'correlation_id' => $correlationId,
            'source_code' => $sourceCode,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function authorizeAdministratorInsideTransaction(Connection $connection, int $administratorId): void
    {
        /** @var object{status: string}|null $administrator */
        $administrator = $connection->table('administrators')
            ->where('id', $administratorId)
            ->lockForUpdate()
            ->first(['status']);
        if ($administrator === null || $administrator->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }
        $this->authorizer->authorize($administratorId, self::ADMIN_PERMISSION);
    }

    private function businessDate(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
