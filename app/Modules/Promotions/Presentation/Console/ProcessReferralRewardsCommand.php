<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Presentation\Console;

use App\Modules\Promotions\Application\ReferralRewardMaintenanceService;
use Illuminate\Console\Command;
use Throwable;

final class ProcessReferralRewardsCommand extends Command
{
    protected $signature = 'referrals:process-rewards
        {--limit=250 : Maximum due referral rewards to process}
        {--json : Emit JSON only}';

    protected $description = 'Release or reconcile due referral rewards through the canonical lifecycle authority';

    public function handle(ReferralRewardMaintenanceService $maintenance): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            return $this->invalid('Referral reward maintenance limit must be between 1 and 1000.');
        }

        try {
            $result = $maintenance->process($limit);
        } catch (Throwable) {
            if ($this->option('json')) {
                $this->line('{"status":"failed","code":"referral_reward_maintenance_failed"}');
            } else {
                $this->error('Referral reward maintenance failed unexpectedly.');
            }

            return self::FAILURE;
        }

        $payload = ['status' => 'ok'] + $result;
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Referral reward maintenance complete: examined=%d changed=%d',
                $result['examined'],
                $result['changed'],
            ));
        }

        return self::SUCCESS;
    }

    private function invalid(string $message): int
    {
        if ($this->option('json')) {
            $this->line('{"status":"invalid","code":"referral_reward_maintenance_invalid_input"}');
        } else {
            $this->error($message);
        }

        return self::INVALID;
    }
}
