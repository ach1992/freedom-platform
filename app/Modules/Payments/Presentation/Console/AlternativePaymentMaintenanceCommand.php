<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Console;

use App\Modules\Payments\Application\AlternativePaymentRuntimeService;
use Illuminate\Console\Command;

final class AlternativePaymentMaintenanceCommand extends Command
{
    protected $signature = 'payments:alternative-maintenance {--limit=50} {--json}';

    protected $description = 'Poll and reconcile configured C2C/Gift Card alternative-payment providers.';

    public function handle(AlternativePaymentRuntimeService $runtime): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);
        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 500.');

            return self::INVALID;
        }

        $result = $runtime->run((int) $limit);
        $payload = [
            'c2c_providers_polled' => $result->c2cProvidersPolled,
            'c2c_transactions_ingested' => $result->c2cTransactionsIngested,
            'c2c_matches' => $result->c2cMatches,
            'c2c_captures' => $result->c2cCaptures,
            'c2c_reviews' => $result->c2cReviews,
            'gift_card_submissions_processed' => $result->giftCardSubmissionsProcessed,
            'gift_card_submissions_reconciled' => $result->giftCardSubmissionsReconciled,
            'failures' => $result->failures,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->table(array_keys($payload), [array_values($payload)]);
        }

        return $result->failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
