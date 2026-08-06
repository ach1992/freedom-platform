<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CustomPlanActorType;
use DomainException;
use Illuminate\Database\Connection;

final class CustomPlanEligibility
{
    public function actor(Connection $connection, int $userId, CustomPlanActorType $actorType): CustomPlanActorSnapshot
    {
        /** @var object{account_status: string}|null $user */
        $user = $connection->table('users')->where('id', $userId)->lockForUpdate()->first(['account_status']);
        if ($user === null || $user->account_status !== 'active') {
            throw new DomainException('Custom-plan actor is unavailable.');
        }
        if ($actorType === CustomPlanActorType::Agent
            && ! $connection->table('agent_profiles')->where('user_id', $userId)->where('status', 'active')->exists()
        ) {
            throw new DomainException('Custom-plan agent profile is unavailable.');
        }

        /** @var object{tier_code: ?string}|null $profile */
        $profile = $connection->table('customer_profiles as profile')
            ->leftJoin('customer_tiers as tier', 'tier.id', '=', 'profile.current_tier_id')
            ->where('profile.user_id', $userId)
            ->first(['tier.code as tier_code']);
        if ($profile === null) {
            throw new DomainException('Custom-plan customer profile is unavailable.');
        }

        /** @var object{telegram_user_id: int|string}|null $telegram */
        $telegram = $connection->table('telegram_accounts')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['telegram_user_id']);

        /** @var list<int|string> $tagRows */
        $tagRows = $connection->table('customer_tag_assignments as assignment')
            ->join('customer_tags as tag', 'tag.id', '=', 'assignment.tag_id')
            ->where('assignment.user_id', $userId)
            ->whereNull('assignment.removed_at')
            ->where('tag.is_active', true)
            ->orderBy('assignment.tag_id')
            ->pluck('assignment.tag_id')
            ->all();
        $tagIds = array_map(static fn (int|string $id): int => (int) $id, $tagRows);
        $eligibilityHash = CatalogPayloadHash::make([
            'user_id' => $userId,
            'actor_type' => $actorType->value,
            'tier_code' => $profile->tier_code,
            'tag_ids' => $tagIds,
        ]);

        return new CustomPlanActorSnapshot(
            $userId,
            $actorType,
            $telegram === null ? null : (int) $telegram->telegram_user_id,
            $profile->tier_code,
            $tagIds,
            $eligibilityHash,
        );
    }

    public function assertAudience(string $audience, CustomPlanActorType $actorType): void
    {
        $allowed = $audience === 'both'
            || ($audience === 'customers' && $actorType === CustomPlanActorType::Customer)
            || ($audience === 'agents' && $actorType === CustomPlanActorType::Agent);
        if (! $allowed) {
            throw new DomainException('Offering audience does not allow this custom-plan actor.');
        }
    }

    public function assertOfferingEligibility(Connection $connection, int $offeringId, string $tagMatchMode, CustomPlanActorSnapshot $actor): void
    {
        /** @var list<int|string> $tierRows */
        $tierRows = $connection->table('plan_offering_tiers')->where('plan_offering_id', $offeringId)->pluck('tier_code')->all();
        /** @var list<int|string> $tagRows */
        $tagRows = $connection->table('plan_offering_tags')->where('plan_offering_id', $offeringId)->pluck('customer_tag_id')->all();
        $this->assertRule(
            array_map(static fn (int|string $value): string => (string) $value, $tierRows),
            array_map(static fn (int|string $value): int => (int) $value, $tagRows),
            $tagMatchMode,
            $actor,
            'Offering',
        );
    }

    public function assertPolicyEligibility(Connection $connection, int $policyId, string $tagMatchMode, CustomPlanActorSnapshot $actor): void
    {
        /** @var list<int|string> $tierRows */
        $tierRows = $connection->table('custom_plan_policy_tiers')->where('custom_plan_policy_id', $policyId)->pluck('tier_code')->all();
        /** @var list<int|string> $tagRows */
        $tagRows = $connection->table('custom_plan_policy_tags')->where('custom_plan_policy_id', $policyId)->pluck('customer_tag_id')->all();
        $this->assertRule(
            array_map(static fn (int|string $value): string => (string) $value, $tierRows),
            array_map(static fn (int|string $value): int => (int) $value, $tagRows),
            $tagMatchMode,
            $actor,
            'Custom-plan policy',
        );
    }

    /**
     * @param  list<string>  $tierCodes
     * @param  list<int>  $tagIds
     */
    private function assertRule(array $tierCodes, array $tagIds, string $tagMatchMode, CustomPlanActorSnapshot $actor, string $label): void
    {
        if ($tierCodes !== [] && ($actor->tierCode === null || ! in_array($actor->tierCode, $tierCodes, true))) {
            throw new DomainException($label.' tier eligibility failed.');
        }
        if ($tagIds === []) {
            return;
        }
        $matching = array_intersect($tagIds, $actor->tagIds);
        $eligible = $tagMatchMode === 'any' ? $matching !== [] : count($matching) === count($tagIds);
        if (! $eligible) {
            throw new DomainException($label.' tag eligibility failed.');
        }
    }
}
