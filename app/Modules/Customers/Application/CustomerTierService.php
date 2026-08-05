<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\CustomerTierCode;
use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class CustomerTierService
{
    private const MANUAL_ACTION = 'customer.tier.assign';

    private const AUTOMATIC_ACTION = 'customer.tier.recalculate';

    public function __construct(
        private DatabaseManager $database,
        private CustomerMutationAudit $audit,
        private CustomerTierCalculator $calculator,
        private Clock $clock,
        private bool $automaticDowngradeEnabled = false,
    ) {}

    /** @requirement USR-002 USR-003 SEC-002 QUA-001 */
    public function assignManual(
        int $userId,
        CustomerTierCode $targetTier,
        bool $lockTier,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        if ($userId < 1) {
            throw new RuntimeException('Customer user ID must be positive.');
        }

        $context->requireAdministrator();

        return $this->executeIdempotently(
            self::MANUAL_ACTION,
            $userId,
            $context,
            fn (Connection $connection): CustomerMutationReceipt => $this->assignManualWithinTransaction(
                $connection,
                $userId,
                $targetTier,
                $lockTier,
                $context,
            ),
        );
    }

    /** @requirement USR-002 SEC-002 QUA-001 */
    public function recalculate(
        int $userId,
        CustomerTierMetrics $metrics,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        if ($userId < 1 || $context->actorAdministratorId !== null) {
            throw new RuntimeException('Automatic tier recalculation requires a valid customer and system actor.');
        }

        return $this->executeIdempotently(
            self::AUTOMATIC_ACTION,
            $userId,
            $context,
            fn (Connection $connection): CustomerMutationReceipt => $this->recalculateWithinTransaction(
                $connection,
                $userId,
                $metrics,
                $context,
            ),
        );
    }

    /**
     * @param  callable(Connection): CustomerMutationReceipt  $operation
     */
    private function executeIdempotently(
        string $action,
        int $userId,
        CustomerChangeContext $context,
        callable $operation,
    ): CustomerMutationReceipt {
        $existing = $this->audit->existing($action, $userId, $context->requestFingerprint);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(
                fn (): CustomerMutationReceipt => $operation($this->database->connection()),
            );
        } catch (QueryException $exception) {
            $existing = $this->audit->existing($action, $userId, $context->requestFingerprint);

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function assignManualWithinTransaction(
        Connection $connection,
        int $userId,
        CustomerTierCode $targetTier,
        bool $lockTier,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        $existing = $this->audit->existing(self::MANUAL_ACTION, $userId, $context->requestFingerprint, true);

        if ($existing !== null) {
            return $existing;
        }

        $this->assertActiveAdministrator($connection, $context->requireAdministrator());
        $profile = $this->lockedProfile($connection, $userId);
        $target = $this->tierByCode($connection, $targetTier, true);
        $current = $this->tierById($connection, $profile->current_tier_id);
        $before = [
            'tier_code' => $current?->code,
            'tier_locked' => (bool) $profile->tier_locked,
            'tier_lock_reason_code' => $profile->tier_lock_reason_code,
        ];
        $after = [
            'tier_code' => $target->code,
            'tier_locked' => $lockTier,
            'tier_lock_reason_code' => $lockTier ? $context->reasonCode : null,
        ];
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        if ($before !== $after) {
            $connection->table('customer_profiles')->where('user_id', $userId)->update([
                'current_tier_id' => $target->id,
                'tier_locked' => $lockTier,
                'tier_lock_reason_code' => $lockTier ? $context->reasonCode : null,
                'updated_at' => $now,
            ]);

            if ($current?->id !== $target->id) {
                $this->recordTierHistory(
                    $connection,
                    $userId,
                    $current?->id,
                    $target->id,
                    $context,
                    $now,
                );
            }
        }

        return $this->audit->record(
            $connection,
            self::MANUAL_ACTION,
            $userId,
            $context,
            $before,
            $after,
        );
    }

    private function recalculateWithinTransaction(
        Connection $connection,
        int $userId,
        CustomerTierMetrics $metrics,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        $existing = $this->audit->existing(self::AUTOMATIC_ACTION, $userId, $context->requestFingerprint, true);

        if ($existing !== null) {
            return $existing;
        }

        $profile = $this->lockedProfile($connection, $userId);
        $current = $this->tierById($connection, $profile->current_tier_id);
        $before = [
            'tier_code' => $current?->code,
            'tier_locked' => (bool) $profile->tier_locked,
        ];

        if ((bool) $profile->tier_locked) {
            return $this->audit->record(
                $connection,
                self::AUTOMATIC_ACTION,
                $userId,
                $context,
                $before,
                $before,
            );
        }

        $candidate = $this->calculator->determine($this->automaticPolicies($connection), $metrics);
        $selected = $candidate;

        if (! $this->automaticDowngradeEnabled
            && $current !== null
            && $candidate->sortOrder < $current->sort_order
        ) {
            $selected = CustomerTierPolicy::fromDatabase(
                $current->id,
                $current->code,
                $current->sort_order,
                $this->decodePolicy($current->policy),
            );
        }

        $after = [
            'tier_code' => $selected->code->value,
            'tier_locked' => false,
        ];
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        if ($current?->id !== $selected->id) {
            $connection->table('customer_profiles')->where('user_id', $userId)->update([
                'current_tier_id' => $selected->id,
                'updated_at' => $now,
            ]);

            $this->recordTierHistory(
                $connection,
                $userId,
                $current?->id,
                $selected->id,
                $context,
                $now,
            );
        }

        return $this->audit->record(
            $connection,
            self::AUTOMATIC_ACTION,
            $userId,
            $context,
            $before,
            $after,
        );
    }

    /** @return object{current_tier_id: int|null, tier_locked: int|bool, tier_lock_reason_code: ?string} */
    private function lockedProfile(Connection $connection, int $userId): object
    {
        /** @var object{current_tier_id: int|null, tier_locked: int|bool, tier_lock_reason_code: ?string}|null $profile */
        $profile = $connection->table('customer_profiles')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first(['current_tier_id', 'tier_locked', 'tier_lock_reason_code']);

        if ($profile === null) {
            throw new RuntimeException('Customer profile was not found.');
        }

        return $profile;
    }

    /** @return object{id: int, code: string, sort_order: int, policy: ?string}|null */
    private function tierById(Connection $connection, ?int $tierId): ?object
    {
        if ($tierId === null) {
            return null;
        }

        /** @var object{id: int, code: string, sort_order: int, policy: ?string}|null $tier */
        $tier = $connection->table('customer_tiers')
            ->where('id', $tierId)
            ->first(['id', 'code', 'sort_order', 'policy']);

        if ($tier === null) {
            throw new RuntimeException('Current customer tier was not found.');
        }

        return $tier;
    }

    /** @return object{id: int, code: string, sort_order: int, policy: ?string} */
    private function tierByCode(
        Connection $connection,
        CustomerTierCode $code,
        bool $activeOnly,
    ): object {
        $query = $connection->table('customer_tiers')->where('code', $code->value);

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        /** @var object{id: int, code: string, sort_order: int, policy: ?string}|null $tier */
        $tier = $query->lockForUpdate()->first(['id', 'code', 'sort_order', 'policy']);

        if ($tier === null) {
            throw new RuntimeException('Requested customer tier is unavailable.');
        }

        return $tier;
    }

    /** @return list<CustomerTierPolicy> */
    private function automaticPolicies(Connection $connection): array
    {
        /** @var list<object{id: int, code: string, sort_order: int, policy: ?string}> $rows */
        $rows = $connection->table('customer_tiers')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->lockForUpdate()
            ->get(['id', 'code', 'sort_order', 'policy'])
            ->all();

        $policies = [];

        foreach ($rows as $row) {
            $policies[] = CustomerTierPolicy::fromDatabase(
                $row->id,
                $row->code,
                $row->sort_order,
                $this->decodePolicy($row->policy),
            );
        }

        return $policies;
    }

    /** @return array<string, mixed> */
    private function decodePolicy(?string $policy): array
    {
        if ($policy === null) {
            return [];
        }

        $decoded = json_decode($policy, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Customer tier policy is invalid.');
        }

        return $decoded;
    }

    private function recordTierHistory(
        Connection $connection,
        int $userId,
        ?int $fromTierId,
        int $toTierId,
        CustomerChangeContext $context,
        string $now,
    ): void {
        $connection->table('customer_tier_histories')->insert([
            'user_id' => $userId,
            'from_tier_id' => $fromTierId,
            'to_tier_id' => $toTierId,
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->reason,
            'created_at' => $now,
        ]);
    }

    private function assertActiveAdministrator(Connection $connection, int $administratorId): void
    {
        $active = $connection->table('administrators')
            ->where('id', $administratorId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->exists();

        if (! $active) {
            throw new RuntimeException('An active administrator is required.');
        }
    }
}
