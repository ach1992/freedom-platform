<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Presentation\Console;

use App\Modules\Provisioning\Application\ServiceAutoRenewalProcessor;
use DomainException;
use Illuminate\Console\Command;
use RuntimeException;

/** @requirement SVC-007 RUN-004 QUA-004 */
final class ProcessServiceAutoRenewalsCommand extends Command
{
    protected $signature = 'services:auto-renew {--limit= : Maximum enabled Services to process} {--json : Emit machine-readable output}';

    protected $description = 'Process due wallet-funded Service auto-renewals.';

    public function handle(ServiceAutoRenewalProcessor $processor): int
    {
        try {
            $limit = $this->limit();
            $receipt = $processor->processDue($limit);
        } catch (DomainException|RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        $payload = [
            'status' => $receipt->requiresAttention() ? 'attention_required' : 'ok',
            'candidates' => $receipt->candidates,
            'attempted' => $receipt->attempted,
            'queued' => $receipt->queued,
            'succeeded' => $receipt->succeeded,
            'blocked' => $receipt->blocked,
            'insufficient_wallet' => $receipt->insufficientWallet,
            'failed' => $receipt->failed,
        ];
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                'Auto-renew: candidates=%d attempted=%d queued=%d succeeded=%d blocked=%d insufficient_wallet=%d failed=%d',
                $receipt->candidates,
                $receipt->attempted,
                $receipt->queued,
                $receipt->succeeded,
                $receipt->blocked,
                $receipt->insufficientWallet,
                $receipt->failed,
            ));
        }

        return $receipt->requiresAttention() ? self::FAILURE : self::SUCCESS;
    }

    private function limit(): int
    {
        $raw = $this->option('limit');
        $value = $raw === null || $raw === '' ? config('auto_renew.batch_limit', 50) : $raw;
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($validated === false) {
            throw new DomainException('Auto-renew --limit must be an integer between 1 and 500.');
        }

        return (int) $validated;
    }

    private function failure(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(['status' => 'error', 'message' => $message], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
