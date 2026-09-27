<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class ReferralRewardMaintenanceService
{
    public function __construct(
        private DatabaseManager $database,
        private ReferralRewardLifecycleService $lifecycle,
        private Clock $clock,
    ) {}

    /** @return array{examined:int,changed:int} */
    public function process(int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Referral reward maintenance limit must be between 1 and 1000.');
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $rewardIds = $this->database->connection()->table('referral_rewards')
            ->where('state', 'pending')
            ->where('release_at', '<=', $now)
            ->orderBy('release_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('public_id')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        $changed = 0;
        foreach ($rewardIds as $rewardPublicId) {
            $receipt = $this->lifecycle->process(
                $rewardPublicId,
                'referral-maintenance-'.substr(hash('sha256', $rewardPublicId), 0, 32),
            );
            if ($receipt->changed) {
                $changed++;
            }
        }

        return ['examined' => count($rewardIds), 'changed' => $changed];
    }
}
