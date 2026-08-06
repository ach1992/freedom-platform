<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CustomPlanActorType;
use App\Modules\Catalog\Domain\CustomPlanPricing;
use App\Modules\Catalog\Domain\CustomPlanUsernameMode;
use App\Modules\Catalog\Domain\CustomPlanValidationStage;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class CustomPlanCalculator
{
    public function __construct(
        private DatabaseManager $database,
        private CustomPlanEligibility $eligibility,
        private CustomPlanUsernameNormalizer $usernameNormalizer,
        private ServiceUsernameAvailability $usernameAvailability,
        private CustomPlanArithmetic $arithmetic,
        private CustomPlanOperationalVerifier $operationalVerifier,
        private Clock $clock,
    ) {}

    /** @requirement CAT-005 SEC-002 DAT-002 DAT-003 QUA-001 */
    public function calculate(CustomPlanRequest $request, CustomPlanContext $context): CustomPlanReceipt
    {
        $payloadHash = CatalogPayloadHash::make($request->payload());
        $existing = $this->existingCalculation($context->commandKey, $payloadHash);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $request,
                $context,
                $payloadHash,
            ): CustomPlanReceipt {
                $replay = $this->existingCalculation($context->commandKey, $payloadHash, $connection, true);
                if ($replay !== null) {
                    return $replay;
                }

                $offering = $this->lockedOffering($connection, $request->offeringId);
                $policy = $this->lockedPolicy($connection, $request->offeringId);
                $actor = $this->eligibility->actor($connection, $request->userId, $request->actorType);
                $this->eligibility->assertAudience($offering->audience, $request->actorType);
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
                $this->assertRange($request->dataGb, $policy->minimum_data_gb, $policy->maximum_data_gb, $policy->data_step_gb, 'data');
                $this->assertRange($request->days, $policy->minimum_days, $policy->maximum_days, $policy->day_step, 'days');

                $separators = $this->policyStrings($connection, 'custom_plan_policy_separators', 'separator', $policy->id);
                $reservedWords = $this->policyStrings($connection, 'custom_plan_policy_reserved_words', 'normalized_word', $policy->id);
                $username = $this->usernameNormalizer->normalize(
                    CustomPlanUsernameMode::from($policy->username_mode),
                    $actor->telegramUserId,
                    $request->requestedUsername,
                    $context->commandKey,
                    $policy->username_minimum_length,
                    $policy->username_maximum_length,
                    $separators,
                    $reservedWords,
                );
                $this->usernameAvailability->assertAvailable($connection, $username);

                $pricing = $this->pricing($policy, $request->actorType);
                $components = $this->arithmetic->calculate(
                    $pricing->basePriceIrr,
                    $pricing->pricePerGbIrr,
                    $pricing->pricePerDayIrr,
                    $pricing->minimumOrderAmountIrr,
                    $request->dataGb,
                    $request->days,
                );
                $calculationId = (int) $connection->table('custom_plan_calculations')->insertGetId([
                    'command_key' => $context->commandKey,
                    'payload_hash' => $payloadHash,
                    'plan_offering_id' => $request->offeringId,
                    'custom_plan_policy_id' => $policy->id,
                    'custom_plan_policy_version' => $policy->version,
                    'policy_configuration_hash' => $policy->configuration_hash,
                    'user_id' => $request->userId,
                    'actor_type' => $request->actorType->value,
                    'tier_code_snapshot' => $actor->tierCode,
                    'eligibility_snapshot_hash' => $actor->eligibilityHash,
                    'data_gb' => $request->dataGb,
                    'days' => $request->days,
                    'username_mode' => $policy->username_mode,
                    'normalized_username' => $username,
                    'base_price_irr' => $pricing->basePriceIrr,
                    'price_per_gb_irr' => $pricing->pricePerGbIrr,
                    'price_per_day_irr' => $pricing->pricePerDayIrr,
                    'data_price_irr' => $components['data_price_irr'],
                    'day_price_irr' => $components['day_price_irr'],
                    'subtotal_irr' => $components['subtotal_irr'],
                    'minimum_order_amount_irr' => $pricing->minimumOrderAmountIrr,
                    'minimum_adjustment_irr' => $components['minimum_adjustment_irr'],
                    'final_price_irr' => $components['final_price_irr'],
                    'discount_eligible' => $policy->discount_eligible,
                    'correlation_id' => $context->correlationId,
                    'source_code' => $context->sourceCode,
                    'reason_code' => $context->reasonCode,
                    'created_at' => $this->timestamp(),
                ]);

                return new CustomPlanReceipt(
                    $calculationId,
                    $request->offeringId,
                    $policy->id,
                    $policy->version,
                    $policy->configuration_hash,
                    $request->actorType->value,
                    $request->dataGb,
                    $request->days,
                    $username,
                    $pricing->basePriceIrr,
                    $pricing->pricePerGbIrr,
                    $pricing->pricePerDayIrr,
                    $components['data_price_irr'],
                    $components['day_price_irr'],
                    $components['subtotal_irr'],
                    $pricing->minimumOrderAmountIrr,
                    $components['minimum_adjustment_irr'],
                    $components['final_price_irr'],
                    (bool) $policy->discount_eligible,
                );
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existingCalculation($context->commandKey, $payloadHash);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** @requirement CAT-005 SEC-002 QUA-001 */
    public function revalidate(
        int $calculationId,
        CustomPlanValidationStage $stage,
        CustomPlanContext $context,
    ): CustomPlanValidationReceipt {
        if ($calculationId < 1) {
            throw new RuntimeException('Custom-plan calculation ID must be positive.');
        }
        $payloadHash = CatalogPayloadHash::make([
            'calculation_id' => $calculationId,
            'stage' => $stage->value,
        ]);
        $existing = $this->existingValidation($context->commandKey, $payloadHash);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $calculationId,
                $stage,
                $context,
                $payloadHash,
            ): CustomPlanValidationReceipt {
                $replay = $this->existingValidation($context->commandKey, $payloadHash, $connection, true);
                if ($replay !== null) {
                    return $replay;
                }
                $calculation = $this->lockedCalculation($connection, $calculationId);
                $offering = $this->lockedOffering($connection, $calculation->plan_offering_id);
                $policy = $this->lockedPolicy($connection, $calculation->plan_offering_id);
                if ($policy->id !== $calculation->custom_plan_policy_id
                    || $policy->version !== $calculation->custom_plan_policy_version
                    || ! hash_equals($policy->configuration_hash, $calculation->policy_configuration_hash)
                ) {
                    throw new DomainException('Custom-plan policy changed; a new calculation is required.');
                }

                $actorType = CustomPlanActorType::from($calculation->actor_type);
                $actor = $this->eligibility->actor($connection, $calculation->user_id, $actorType);
                $this->eligibility->assertAudience($offering->audience, $actorType);
                $this->eligibility->assertOfferingEligibility(
                    $connection,
                    $calculation->plan_offering_id,
                    $offering->tag_match_mode,
                    $actor,
                );
                $this->eligibility->assertPolicyEligibility(
                    $connection,
                    $policy->id,
                    $policy->tag_match_mode,
                    $actor,
                );
                $this->usernameAvailability->assertAvailable(
                    $connection,
                    $calculation->normalized_username,
                    $calculationId,
                );
                $this->assertStoredArithmetic($policy, $calculation, $actorType);

                $validationId = (int) $connection->table('custom_plan_calculation_validations')->insertGetId([
                    'custom_plan_calculation_id' => $calculationId,
                    'command_key' => $context->commandKey,
                    'payload_hash' => $payloadHash,
                    'stage' => $stage->value,
                    'policy_configuration_hash' => $policy->configuration_hash,
                    'eligibility_snapshot_hash' => $actor->eligibilityHash,
                    'correlation_id' => $context->correlationId,
                    'source_code' => $context->sourceCode,
                    'reason_code' => $context->reasonCode,
                    'created_at' => $this->timestamp(),
                ]);

                return new CustomPlanValidationReceipt(
                    $validationId,
                    $calculationId,
                    $stage->value,
                    $policy->configuration_hash,
                    $actor->eligibilityHash,
                );
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existingValidation($context->commandKey, $payloadHash);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** @return object{id: int, audience: string, tag_match_mode: string} */
    private function lockedOffering(Connection $connection, int $offeringId): object
    {
        /** @var object{id: int|string, state: string, visibility: string, custom_plan_allowed: bool|int, audience: string, tag_match_mode: string}|null $row */
        $row = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->lockForUpdate()
            ->first(['id', 'state', 'visibility', 'custom_plan_allowed', 'audience', 'tag_match_mode']);
        if ($row === null || ! (bool) $row->custom_plan_allowed) {
            throw new DomainException('Custom-plan Offering is unavailable.');
        }
        $this->operationalVerifier->assertOperational($connection, $offeringId);

        return (object) [
            'id' => (int) $row->id,
            'audience' => $row->audience,
            'tag_match_mode' => $row->tag_match_mode,
        ];
    }

    /**
     * @return object{
     *  id: int, version: int, configuration_hash: string, enabled: bool,
     *  minimum_data_gb: int, maximum_data_gb: int, data_step_gb: int,
     *  minimum_days: int, maximum_days: int, day_step: int,
     *  customer_base_price_irr: int, customer_price_per_gb_irr: int, customer_price_per_day_irr: int, customer_minimum_order_amount_irr: int,
     *  agent_base_price_irr: int, agent_price_per_gb_irr: int, agent_price_per_day_irr: int, agent_minimum_order_amount_irr: int,
     *  discount_eligible: bool, tag_match_mode: string, username_mode: string,
     *  username_minimum_length: int, username_maximum_length: int
     * }
     */
    private function lockedPolicy(Connection $connection, int $offeringId): object
    {
        /** @var object{id: int|string, version: int|string, configuration_hash: string, enabled: bool|int, minimum_data_gb: int|string, maximum_data_gb: int|string, data_step_gb: int|string, minimum_days: int|string, maximum_days: int|string, day_step: int|string, customer_base_price_irr: int|string, customer_price_per_gb_irr: int|string, customer_price_per_day_irr: int|string, customer_minimum_order_amount_irr: int|string, agent_base_price_irr: int|string, agent_price_per_gb_irr: int|string, agent_price_per_day_irr: int|string, agent_minimum_order_amount_irr: int|string, discount_eligible: bool|int, tag_match_mode: string, username_mode: string, username_minimum_length: int|string, username_maximum_length: int|string}|null $row */
        $row = $connection->table('custom_plan_policies')
            ->where('plan_offering_id', $offeringId)
            ->lockForUpdate()
            ->first();
        if ($row === null || ! (bool) $row->enabled) {
            throw new DomainException('Custom-plan policy is unavailable.');
        }

        return (object) [
            'id' => (int) $row->id,
            'version' => (int) $row->version,
            'configuration_hash' => $row->configuration_hash,
            'enabled' => (bool) $row->enabled,
            'minimum_data_gb' => (int) $row->minimum_data_gb,
            'maximum_data_gb' => (int) $row->maximum_data_gb,
            'data_step_gb' => (int) $row->data_step_gb,
            'minimum_days' => (int) $row->minimum_days,
            'maximum_days' => (int) $row->maximum_days,
            'day_step' => (int) $row->day_step,
            'customer_base_price_irr' => (int) $row->customer_base_price_irr,
            'customer_price_per_gb_irr' => (int) $row->customer_price_per_gb_irr,
            'customer_price_per_day_irr' => (int) $row->customer_price_per_day_irr,
            'customer_minimum_order_amount_irr' => (int) $row->customer_minimum_order_amount_irr,
            'agent_base_price_irr' => (int) $row->agent_base_price_irr,
            'agent_price_per_gb_irr' => (int) $row->agent_price_per_gb_irr,
            'agent_price_per_day_irr' => (int) $row->agent_price_per_day_irr,
            'agent_minimum_order_amount_irr' => (int) $row->agent_minimum_order_amount_irr,
            'discount_eligible' => (bool) $row->discount_eligible,
            'tag_match_mode' => $row->tag_match_mode,
            'username_mode' => $row->username_mode,
            'username_minimum_length' => (int) $row->username_minimum_length,
            'username_maximum_length' => (int) $row->username_maximum_length,
        ];
    }

    /**
     * @param object{
     *  customer_base_price_irr: int, customer_price_per_gb_irr: int, customer_price_per_day_irr: int, customer_minimum_order_amount_irr: int,
     *  agent_base_price_irr: int, agent_price_per_gb_irr: int, agent_price_per_day_irr: int, agent_minimum_order_amount_irr: int
     * } $policy
     */
    private function pricing(object $policy, CustomPlanActorType $actorType): CustomPlanPricing
    {
        return $actorType === CustomPlanActorType::Agent
            ? new CustomPlanPricing(
                $policy->agent_base_price_irr,
                $policy->agent_price_per_gb_irr,
                $policy->agent_price_per_day_irr,
                $policy->agent_minimum_order_amount_irr,
            )
            : new CustomPlanPricing(
                $policy->customer_base_price_irr,
                $policy->customer_price_per_gb_irr,
                $policy->customer_price_per_day_irr,
                $policy->customer_minimum_order_amount_irr,
            );
    }

    private function assertRange(int $value, int $minimum, int $maximum, int $step, string $label): void
    {
        if ($value < $minimum || $value > $maximum || (($value - $minimum) % $step) !== 0) {
            throw new DomainException('Custom-plan '.$label.' does not satisfy range and step policy.');
        }
    }

    /** @return list<string> */
    private function policyStrings(Connection $connection, string $table, string $column, int $policyId): array
    {
        /** @var list<int|string> $rows */
        $rows = $connection->table($table)
            ->where('custom_plan_policy_id', $policyId)
            ->orderBy($column)
            ->pluck($column)
            ->all();

        return array_map(static fn (int|string $value): string => (string) $value, $rows);
    }

    /** @return object{plan_offering_id: int, custom_plan_policy_id: int, custom_plan_policy_version: int, policy_configuration_hash: string, user_id: int, actor_type: string, data_gb: int, days: int, normalized_username: string, base_price_irr: int, price_per_gb_irr: int, price_per_day_irr: int, data_price_irr: int, day_price_irr: int, subtotal_irr: int, minimum_order_amount_irr: int, minimum_adjustment_irr: int, final_price_irr: int} */
    private function lockedCalculation(Connection $connection, int $calculationId): object
    {
        /** @var object{plan_offering_id: int|string, custom_plan_policy_id: int|string, custom_plan_policy_version: int|string, policy_configuration_hash: string, user_id: int|string, actor_type: string, data_gb: int|string, days: int|string, normalized_username: string, base_price_irr: int|string, price_per_gb_irr: int|string, price_per_day_irr: int|string, data_price_irr: int|string, day_price_irr: int|string, subtotal_irr: int|string, minimum_order_amount_irr: int|string, minimum_adjustment_irr: int|string, final_price_irr: int|string}|null $row */
        $row = $connection->table('custom_plan_calculations')
            ->where('id', $calculationId)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw new RuntimeException('Custom-plan calculation does not exist.');
        }

        return (object) [
            'plan_offering_id' => (int) $row->plan_offering_id,
            'custom_plan_policy_id' => (int) $row->custom_plan_policy_id,
            'custom_plan_policy_version' => (int) $row->custom_plan_policy_version,
            'policy_configuration_hash' => $row->policy_configuration_hash,
            'user_id' => (int) $row->user_id,
            'actor_type' => $row->actor_type,
            'data_gb' => (int) $row->data_gb,
            'days' => (int) $row->days,
            'normalized_username' => $row->normalized_username,
            'base_price_irr' => (int) $row->base_price_irr,
            'price_per_gb_irr' => (int) $row->price_per_gb_irr,
            'price_per_day_irr' => (int) $row->price_per_day_irr,
            'data_price_irr' => (int) $row->data_price_irr,
            'day_price_irr' => (int) $row->day_price_irr,
            'subtotal_irr' => (int) $row->subtotal_irr,
            'minimum_order_amount_irr' => (int) $row->minimum_order_amount_irr,
            'minimum_adjustment_irr' => (int) $row->minimum_adjustment_irr,
            'final_price_irr' => (int) $row->final_price_irr,
        ];
    }

    /**
     * @param object{
     *  customer_base_price_irr: int, customer_price_per_gb_irr: int, customer_price_per_day_irr: int, customer_minimum_order_amount_irr: int,
     *  agent_base_price_irr: int, agent_price_per_gb_irr: int, agent_price_per_day_irr: int, agent_minimum_order_amount_irr: int
     * } $policy
     * @param  object{data_gb: int, days: int, base_price_irr: int, price_per_gb_irr: int, price_per_day_irr: int, data_price_irr: int, day_price_irr: int, subtotal_irr: int, minimum_order_amount_irr: int, minimum_adjustment_irr: int, final_price_irr: int}  $calculation
     */
    private function assertStoredArithmetic(object $policy, object $calculation, CustomPlanActorType $actorType): void
    {
        $pricing = $this->pricing($policy, $actorType);
        $components = $this->arithmetic->calculate(
            $pricing->basePriceIrr,
            $pricing->pricePerGbIrr,
            $pricing->pricePerDayIrr,
            $pricing->minimumOrderAmountIrr,
            $calculation->data_gb,
            $calculation->days,
        );
        $expected = [
            $pricing->basePriceIrr,
            $pricing->pricePerGbIrr,
            $pricing->pricePerDayIrr,
            $components['data_price_irr'],
            $components['day_price_irr'],
            $components['subtotal_irr'],
            $pricing->minimumOrderAmountIrr,
            $components['minimum_adjustment_irr'],
            $components['final_price_irr'],
        ];
        $stored = [
            $calculation->base_price_irr,
            $calculation->price_per_gb_irr,
            $calculation->price_per_day_irr,
            $calculation->data_price_irr,
            $calculation->day_price_irr,
            $calculation->subtotal_irr,
            $calculation->minimum_order_amount_irr,
            $calculation->minimum_adjustment_irr,
            $calculation->final_price_irr,
        ];
        if ($stored !== $expected) {
            throw new DomainException('Custom-plan calculation snapshot is inconsistent.');
        }
    }

    private function existingCalculation(
        string $commandKey,
        string $payloadHash,
        ?Connection $connection = null,
        bool $lock = false,
    ): ?CustomPlanReceipt {
        $database = $connection ?? $this->database->connection();
        $query = $database->table('custom_plan_calculations')->where('command_key', $commandKey);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id: int|string, payload_hash: string, plan_offering_id: int|string, custom_plan_policy_id: int|string, custom_plan_policy_version: int|string, policy_configuration_hash: string, actor_type: string, data_gb: int|string, days: int|string, normalized_username: string, base_price_irr: int|string, price_per_gb_irr: int|string, price_per_day_irr: int|string, data_price_irr: int|string, day_price_irr: int|string, subtotal_irr: int|string, minimum_order_amount_irr: int|string, minimum_adjustment_irr: int|string, final_price_irr: int|string, discount_eligible: bool|int}|null $row */
        $row = $query->first();
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row->payload_hash, $payloadHash)) {
            throw new RuntimeException('Custom-plan command key conflict.');
        }

        return new CustomPlanReceipt(
            (int) $row->id,
            (int) $row->plan_offering_id,
            (int) $row->custom_plan_policy_id,
            (int) $row->custom_plan_policy_version,
            $row->policy_configuration_hash,
            $row->actor_type,
            (int) $row->data_gb,
            (int) $row->days,
            $row->normalized_username,
            (int) $row->base_price_irr,
            (int) $row->price_per_gb_irr,
            (int) $row->price_per_day_irr,
            (int) $row->data_price_irr,
            (int) $row->day_price_irr,
            (int) $row->subtotal_irr,
            (int) $row->minimum_order_amount_irr,
            (int) $row->minimum_adjustment_irr,
            (int) $row->final_price_irr,
            (bool) $row->discount_eligible,
            true,
        );
    }

    private function existingValidation(
        string $commandKey,
        string $payloadHash,
        ?Connection $connection = null,
        bool $lock = false,
    ): ?CustomPlanValidationReceipt {
        $database = $connection ?? $this->database->connection();
        $query = $database->table('custom_plan_calculation_validations')->where('command_key', $commandKey);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id: int|string, custom_plan_calculation_id: int|string, payload_hash: string, stage: string, policy_configuration_hash: string, eligibility_snapshot_hash: string}|null $row */
        $row = $query->first([
            'id', 'custom_plan_calculation_id', 'payload_hash', 'stage',
            'policy_configuration_hash', 'eligibility_snapshot_hash',
        ]);
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row->payload_hash, $payloadHash)) {
            throw new RuntimeException('Custom-plan validation command key conflict.');
        }

        return new CustomPlanValidationReceipt(
            (int) $row->id,
            (int) $row->custom_plan_calculation_id,
            $row->stage,
            $row->policy_configuration_hash,
            $row->eligibility_snapshot_hash,
            true,
        );
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
