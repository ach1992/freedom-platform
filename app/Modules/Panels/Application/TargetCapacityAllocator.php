<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

use App\Modules\Panels\Domain\CapacityReservationState;
use App\Modules\Panels\Domain\TargetCapacityState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class TargetCapacityAllocator
{
    public function __construct(
        private DatabaseManager $database,
        private PanelPayloadHasher $hasher,
        private Clock $clock,
    ) {}

    /** @requirement CAT-008 SEC-002 DAT-003 QUA-001 */
    public function reserve(
        int $serviceTargetId,
        int $units,
        DateTimeImmutable $expiresAt,
        CapacityOperationContext $context,
    ): CapacityReservationReceipt {
        PanelInput::positiveId($serviceTargetId, 'Panel service target ID');
        $normalizedUnits = CapacityInput::units($units);
        if ($expiresAt <= $this->clock->now()) {
            throw new DomainException('Capacity reservation expiry must be in the future.');
        }

        $payloadHmac = $this->hasher->mutation([
            'service_target_id' => $serviceTargetId,
            'units' => $normalizedUnits,
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'purpose_code' => $context->purposeCode,
        ]);
        $existing = $this->existingCommand($context->commandKey, 'capacity.reserve', $payloadHmac);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $serviceTargetId,
                $normalizedUnits,
                $expiresAt,
                $context,
                $payloadHmac,
            ): CapacityReservationReceipt {
                $capacity = $this->lockedCapacityForTarget($connection, $serviceTargetId);
                if ($capacity->state !== TargetCapacityState::Enabled->value) {
                    throw new DomainException('Target capacity is not accepting reservations.');
                }

                $replay = $this->existingCommand($context->commandKey, 'capacity.reserve', $payloadHmac, $connection);
                if ($replay !== null) {
                    return $replay;
                }

                $reservationId = (int) $connection->table('panel_capacity_reservations')->insertGetId([
                    'panel_target_capacity_id' => $capacity->id,
                    'reservation_key' => $context->commandKey,
                    'purpose_code' => $context->purposeCode,
                    'units' => $normalizedUnits,
                    'state' => CapacityReservationState::Held->value,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
                    'version' => 1,
                    ...$this->commandColumns($context, $payloadHmac),
                    'created_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);

                return $this->currentReceipt($connection, $reservationId, false);
            }, 3);
        } catch (QueryException $exception) {
            $replay = $this->existingCommand($context->commandKey, 'capacity.reserve', $payloadHmac);
            if ($replay !== null) {
                return $replay;
            }
            if (str_contains($exception->getMessage(), 'Insufficient target capacity')) {
                throw new DomainException('Insufficient target capacity.', previous: $exception);
            }

            throw $exception;
        }
    }

    public function commit(string $reservationKey, int $expectedVersion, CapacityOperationContext $context): CapacityReservationReceipt
    {
        return $this->transition($reservationKey, $expectedVersion, CapacityReservationState::Committed, 'capacity.commit', $context);
    }

    public function release(string $reservationKey, int $expectedVersion, CapacityOperationContext $context): CapacityReservationReceipt
    {
        return $this->transition($reservationKey, $expectedVersion, CapacityReservationState::Released, 'capacity.release', $context);
    }

    public function expire(string $reservationKey, int $expectedVersion, CapacityOperationContext $context): CapacityReservationReceipt
    {
        return $this->transition($reservationKey, $expectedVersion, CapacityReservationState::Expired, 'capacity.expire', $context);
    }

    public function availability(int $serviceTargetId): CapacityAvailability
    {
        PanelInput::positiveId($serviceTargetId, 'Panel service target ID');
        /** @var object{id: int|string, panel_service_target_id: int|string, hard_limit: int|string, held_units: int|string, committed_units: int|string, state: string, version: int|string}|null $row */
        $row = $this->database->connection()->table('panel_target_capacities')
            ->where('panel_service_target_id', $serviceTargetId)
            ->first(['id', 'panel_service_target_id', 'hard_limit', 'held_units', 'committed_units', 'state', 'version']);
        if ($row === null) {
            throw new RuntimeException('Target capacity does not exist.');
        }

        return $this->availabilityFromRow($row);
    }

    private function transition(
        string $reservationKey,
        int $expectedVersion,
        CapacityReservationState $targetState,
        string $action,
        CapacityOperationContext $context,
    ): CapacityReservationReceipt {
        $key = CapacityInput::commandKey($reservationKey);
        $version = PanelInput::expectedVersion($expectedVersion);
        $payloadHmac = $this->hasher->mutation([
            'reservation_key' => $key,
            'expected_version' => $version,
            'target_state' => $targetState->value,
        ]);
        $existing = $this->existingCommand($context->commandKey, $action, $payloadHmac);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $key,
                $version,
                $targetState,
                $action,
                $context,
                $payloadHmac,
            ): CapacityReservationReceipt {
                $replay = $this->existingCommand($context->commandKey, $action, $payloadHmac, $connection);
                if ($replay !== null) {
                    return $replay;
                }

                /** @var object{id: int|string, state: string, version: int|string, expires_at: string}|null $reservation */
                $reservation = $connection->table('panel_capacity_reservations')
                    ->where('reservation_key', $key)
                    ->lockForUpdate()
                    ->first(['id', 'state', 'version', 'expires_at']);
                if ($reservation === null) {
                    throw new RuntimeException('Capacity reservation does not exist.');
                }
                if ((int) $reservation->version !== $version) {
                    throw new RuntimeException('Capacity reservation version conflict.');
                }

                $state = CapacityReservationState::tryFrom($reservation->state)
                    ?? throw new RuntimeException('Stored capacity reservation state is invalid.');
                $state->assertCanTransitionTo($targetState);
                $expiresAt = new DateTimeImmutable($reservation->expires_at);
                if ($targetState === CapacityReservationState::Committed && $expiresAt <= $this->clock->now()) {
                    throw new DomainException('Expired capacity hold cannot be committed.');
                }
                if ($targetState === CapacityReservationState::Expired && $expiresAt > $this->clock->now()) {
                    throw new DomainException('Capacity hold is not expired yet.');
                }

                $connection->table('panel_capacity_reservations')->where('id', (int) $reservation->id)->update([
                    'state' => $targetState->value,
                    'version' => $version + 1,
                    ...$this->commandColumns($context, $payloadHmac),
                    'updated_at' => $this->timestamp(),
                ]);

                return $this->currentReceipt($connection, (int) $reservation->id, false);
            }, 3);
        } catch (QueryException $exception) {
            $replay = $this->existingCommand($context->commandKey, $action, $payloadHmac);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    /** @return array{last_command_key: string, last_payload_hmac: string, last_correlation_id: string, last_source_code: string, last_reason_code: string} */
    private function commandColumns(CapacityOperationContext $context, string $payloadHmac): array
    {
        return [
            'last_command_key' => $context->commandKey,
            'last_payload_hmac' => $payloadHmac,
            'last_correlation_id' => $context->correlationId,
            'last_source_code' => $context->sourceCode,
            'last_reason_code' => $context->reasonCode,
        ];
    }

    private function existingCommand(
        string $commandKey,
        string $action,
        string $payloadHmac,
        ?Connection $connection = null,
    ): ?CapacityReservationReceipt {
        $database = $connection ?? $this->database->connection();
        /** @var object{reservation_id: int|string, reservation_key: string, service_target_id: int|string, units: int|string, action: string, payload_hmac: string, to_state: string, reservation_version: int|string, capacity_id: int|string, capacity_hard_limit: int|string, capacity_held_units: int|string, capacity_committed_units: int|string, capacity_state: string, capacity_version: int|string}|null $event */
        $event = $database->table('panel_capacity_reservation_events as event')
            ->join('panel_capacity_reservations as reservation', 'reservation.id', '=', 'event.panel_capacity_reservation_id')
            ->join('panel_target_capacities as capacity', 'capacity.id', '=', 'reservation.panel_target_capacity_id')
            ->where('event.command_key', $commandKey)
            ->first([
                'event.panel_capacity_reservation_id as reservation_id', 'reservation.reservation_key',
                'capacity.panel_service_target_id as service_target_id', 'event.units', 'event.action',
                'event.payload_hmac', 'event.to_state', 'event.reservation_version', 'capacity.id as capacity_id',
                'event.capacity_hard_limit', 'event.capacity_held_units', 'event.capacity_committed_units',
                'event.capacity_state', 'event.capacity_version',
            ]);
        if ($event === null) {
            return null;
        }
        if ($event->action !== $action || ! hash_equals($event->payload_hmac, $payloadHmac)) {
            throw new RuntimeException('Capacity command key conflict.');
        }

        return new CapacityReservationReceipt(
            (int) $event->reservation_id,
            $event->reservation_key,
            $event->to_state,
            (int) $event->units,
            (int) $event->reservation_version,
            new CapacityAvailability(
                (int) $event->capacity_id,
                (int) $event->service_target_id,
                (int) $event->capacity_hard_limit,
                (int) $event->capacity_held_units,
                (int) $event->capacity_committed_units,
                max(0, (int) $event->capacity_hard_limit - (int) $event->capacity_held_units - (int) $event->capacity_committed_units),
                $event->capacity_state === TargetCapacityState::Enabled->value,
                (int) $event->capacity_version,
            ),
            true,
        );
    }

    private function currentReceipt(Connection $connection, int $reservationId, bool $replayed): CapacityReservationReceipt
    {
        /** @var object{id: int|string, reservation_key: string, state: string, units: int|string, version: int|string, panel_target_capacity_id: int|string}|null $reservation */
        $reservation = $connection->table('panel_capacity_reservations')->where('id', $reservationId)->first([
            'id', 'reservation_key', 'state', 'units', 'version', 'panel_target_capacity_id',
        ]);
        if ($reservation === null) {
            throw new RuntimeException('Capacity reservation receipt is unavailable.');
        }
        /** @var object{id: int|string, panel_service_target_id: int|string, hard_limit: int|string, held_units: int|string, committed_units: int|string, state: string, version: int|string}|null $capacity */
        $capacity = $connection->table('panel_target_capacities')->where('id', (int) $reservation->panel_target_capacity_id)->first([
            'id', 'panel_service_target_id', 'hard_limit', 'held_units', 'committed_units', 'state', 'version',
        ]);
        if ($capacity === null) {
            throw new RuntimeException('Target capacity receipt is unavailable.');
        }

        return new CapacityReservationReceipt(
            (int) $reservation->id,
            $reservation->reservation_key,
            $reservation->state,
            (int) $reservation->units,
            (int) $reservation->version,
            $this->availabilityFromRow($capacity),
            $replayed,
        );
    }

    private function lockedCapacityForTarget(Connection $connection, int $serviceTargetId): TargetCapacityRecord
    {
        /** @var object{id: int|string, panel_service_target_id: int|string, hard_limit: int|string, held_units: int|string, committed_units: int|string, state: string, version: int|string}|null $row */
        $row = $connection->table('panel_target_capacities')
            ->where('panel_service_target_id', $serviceTargetId)
            ->lockForUpdate()
            ->first(['id', 'panel_service_target_id', 'hard_limit', 'held_units', 'committed_units', 'state', 'version']);
        if ($row === null) {
            throw new RuntimeException('Target capacity does not exist.');
        }

        return new TargetCapacityRecord(
            (int) $row->id,
            (int) $row->panel_service_target_id,
            (int) $row->hard_limit,
            (int) $row->held_units,
            (int) $row->committed_units,
            $row->state,
            (int) $row->version,
        );
    }

    /** @param object{id: int|string, panel_service_target_id: int|string, hard_limit: int|string, held_units: int|string, committed_units: int|string, state: string, version: int|string} $row */
    private function availabilityFromRow(object $row): CapacityAvailability
    {
        $hardLimit = (int) $row->hard_limit;
        $held = (int) $row->held_units;
        $committed = (int) $row->committed_units;

        return new CapacityAvailability(
            (int) $row->id,
            (int) $row->panel_service_target_id,
            $hardLimit,
            $held,
            $committed,
            max(0, $hardLimit - $held - $committed),
            $row->state === TargetCapacityState::Enabled->value,
            (int) $row->version,
        );
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
