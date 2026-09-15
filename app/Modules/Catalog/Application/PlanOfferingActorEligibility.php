<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\RouteSelectionActor;
use DomainException;
use Illuminate\Database\Connection;

final class PlanOfferingActorEligibility
{
    public function authoritativeActor(
        Connection $connection,
        int $userId,
        RouteSelectionActor $actor,
    ): PlanOfferingActorEligibilitySnapshot {
        /** @var object{account_status: string}|null $user */
        $user = $connection->table('users')->where('id', $userId)->lockForUpdate()->first(['account_status']);
        if ($user === null || $user->account_status !== 'active') {
            throw new DomainException('Route selection actor is unavailable.');
        }
        if ($actor === RouteSelectionActor::Agent
            && ! $connection->table('agent_profiles')->where('user_id', $userId)->where('status', 'active')->exists()
        ) {
            throw new DomainException('Route selection requires an active agent profile.');
        }

        /** @var object{tier_code: ?string}|null $profile */
        $profile = $connection->table('customer_profiles as profile')
            ->leftJoin('customer_tiers as tier', 'tier.id', '=', 'profile.current_tier_id')
            ->where('profile.user_id', $userId)
            ->first(['tier.code as tier_code']);
        if ($profile === null) {
            throw new DomainException('Route selection requires a customer profile.');
        }

        /** @var list<int|string> $tagRows */
        $tagRows = $connection->table('customer_tag_assignments')
            ->where('user_id', $userId)
            ->whereNull('removed_at')
            ->pluck('tag_id')
            ->all();

        return new PlanOfferingActorEligibilitySnapshot(
            $profile->tier_code,
            array_map(static fn (int|string $id): int => (int) $id, $tagRows),
        );
    }

    public function assertAudience(string $audience, RouteSelectionActor $actor): void
    {
        $allowed = $audience === 'both'
            || ($audience === 'customers' && $actor === RouteSelectionActor::Customer)
            || ($audience === 'agents' && $actor === RouteSelectionActor::Agent);
        if (! $allowed) {
            throw new DomainException('Offering audience does not allow this route selection actor.');
        }
    }

    public function assertOfferingEligibility(
        Connection $connection,
        int $offeringId,
        string $tagMatchMode,
        PlanOfferingActorEligibilitySnapshot $actor,
    ): void {
        /** @var list<int|string> $tierRows */
        $tierRows = $connection->table('plan_offering_tiers')
            ->where('plan_offering_id', $offeringId)
            ->pluck('tier_code')
            ->all();
        $tiers = array_map(static fn (int|string $code): string => (string) $code, $tierRows);
        if ($tiers !== [] && ($actor->tierCode === null || ! in_array($actor->tierCode, $tiers, true))) {
            throw new DomainException('Route selection actor tier is not eligible.');
        }

        /** @var list<int|string> $tagRows */
        $tagRows = $connection->table('plan_offering_tags')
            ->where('plan_offering_id', $offeringId)
            ->pluck('customer_tag_id')
            ->all();
        $requiredTags = array_map(static fn (int|string $id): int => (int) $id, $tagRows);
        if ($requiredTags === []) {
            return;
        }
        $matching = array_intersect($requiredTags, $actor->tagIds);
        $eligible = $tagMatchMode === 'any'
            ? $matching !== []
            : count($matching) === count($requiredTags);
        if (! $eligible) {
            throw new DomainException('Route selection actor tags are not eligible.');
        }
    }
}
