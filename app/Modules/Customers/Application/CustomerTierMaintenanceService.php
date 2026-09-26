<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Shared\Application\Clock;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class CustomerTierMaintenanceService
{
    public function __construct(
        private DatabaseManager $database,
        private CustomerTierAutomaticRecalculationService $tiers,
        private Clock $clock,
    ) {}

    /** @return array{examined:int,changed:int} */
    public function process(int $batchSize): array
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('Customer tier maintenance batch size must be between 1 and 1000.');
        }

        $dateKey = $this->clock->now()->format('Ymd');
        $examined = 0;
        $changed = 0;
        $lastUserId = 0;

        do {
            $userIds = $this->database->connection()->table('customer_profiles as profile')
                ->join('users as user', 'user.id', '=', 'profile.user_id')
                ->where('user.account_type', 'customer')
                ->where('user.account_status', 'active')
                ->where('profile.user_id', '>', $lastUserId)
                ->orderBy('profile.user_id')
                ->limit($batchSize)
                ->pluck('profile.user_id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->all();

            foreach ($userIds as $userId) {
                $receipt = $this->tiers->daily($userId, $dateKey);
                $examined++;
                if ($receipt?->changed === true) {
                    $changed++;
                }
                $lastUserId = $userId;
            }
        } while (count($userIds) === $batchSize);

        return ['examined' => $examined, 'changed' => $changed];
    }
}
