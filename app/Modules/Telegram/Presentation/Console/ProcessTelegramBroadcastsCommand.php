<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Presentation\Console;

use App\Modules\Telegram\Application\TelegramBroadcastCampaignService;
use App\Modules\Telegram\Application\TelegramBroadcastDeliveryRunner;
use App\Modules\Telegram\Application\TelegramBroadcastLifecycleRunner;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

/** @requirement COM-002 COM-003 OPS-003 QUA-004 */
final class ProcessTelegramBroadcastsCommand extends Command
{
    private const MAX_BATCH_SIZE = 100;

    protected $signature = 'telegram:process-broadcasts
        {--recipient-limit=10 : Maximum initial/retry recipients to process}
        {--lifecycle-limit=10 : Maximum post-send lifecycle operations to process}
        {--activation-limit=10 : Maximum due scheduled campaigns to activate}
        {--json : Emit machine-readable output}';

    protected $description = 'Process bounded Telegram broadcast activation, delivery, retry and lifecycle work.';

    public function handle(
        TelegramBroadcastCampaignService $campaigns,
        TelegramBroadcastDeliveryRunner $delivery,
        TelegramBroadcastLifecycleRunner $lifecycle,
    ): int {
        try {
            $activationLimit = $this->limit($this->option('activation-limit'), 'activation');
            $recipientLimit = $this->limit($this->option('recipient-limit'), 'recipient');
            $lifecycleLimit = $this->limit($this->option('lifecycle-limit'), 'lifecycle');

            $activated = $campaigns->activateDueCampaigns($activationLimit);
            $delivered = $delivery->processBatch($recipientLimit);
            $lifecycleProcessed = $lifecycle->processBatch($lifecycleLimit);
        } catch (DomainException $exception) {
            return $this->failure('invalid', $exception->getMessage());
        } catch (Throwable) {
            return $this->failure('failed', 'Telegram broadcast processing failed unexpectedly.');
        }

        $payload = [
            'status' => 'ok',
            'activated_campaigns' => $activated,
            'delivery_operations_processed' => $delivered,
            'lifecycle_operations_processed' => $lifecycleProcessed,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Telegram broadcasts: activated=%d delivery=%d lifecycle=%d',
                $activated,
                $delivered,
                $lifecycleProcessed,
            ));
        }

        return self::SUCCESS;
    }

    private function limit(mixed $raw, string $name): int
    {
        $validated = filter_var(
            $raw,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => self::MAX_BATCH_SIZE]],
        );
        if ($validated === false) {
            throw new DomainException(
                'Telegram broadcast '.$name.' limit must be an integer between 1 and '.self::MAX_BATCH_SIZE.'.',
            );
        }

        return (int) $validated;
    }

    private function failure(string $status, string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => $status,
                'code' => 'telegram_broadcast_processing_'.$status,
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
