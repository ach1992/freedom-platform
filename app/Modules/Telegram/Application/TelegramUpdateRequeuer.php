<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramRuntime;
use App\Modules\Telegram\Application\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

final readonly class TelegramUpdateRequeuer
{
    public function __construct(
        private DatabaseManager $database,
        private TelegramRuntime $configuration,
    ) {}

    /** @requirement ONB-001 PAY-003 OPS-003 SEC-009 */
    public function requeue(int $olderThanSeconds, int $limit, bool $includeFailed): TelegramUpdateRequeueResult
    {
        $states = $includeFailed ? ['accepted', 'queued', 'failed'] : ['accepted', 'queued'];
        $cutoff = now('UTC')->subSeconds($olderThanSeconds)->format('Y-m-d H:i:s.u');

        /** @var Collection<int, object{bot_id: string, update_id: int}> $rows */
        $rows = $this->database->connection()->table('processed_telegram_updates')
            ->whereIn('state', $states)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('bot_id')
            ->orderBy('update_id')
            ->limit($limit)
            ->get(['bot_id', 'update_id']);

        $requeued = 0;
        $queue = $this->configuration->queue();

        foreach ($rows as $candidate) {
            $didRequeue = $this->database->connection()->transaction(function () use (
                $candidate,
                $states,
                $queue,
            ): bool {
                $row = $this->database->connection()->table('processed_telegram_updates')
                    ->where('bot_id', $candidate->bot_id)
                    ->where('update_id', $candidate->update_id)
                    ->lockForUpdate()
                    ->first(['state']);

                if ($row === null || ! in_array((string) $row->state, $states, true)) {
                    return false;
                }

                ProcessTelegramUpdateJob::dispatch(
                    $candidate->bot_id,
                    $candidate->update_id,
                )->onQueue($queue)->afterCommit();

                $now = now('UTC')->format('Y-m-d H:i:s.u');
                $this->database->connection()->table('processed_telegram_updates')
                    ->where('bot_id', $candidate->bot_id)
                    ->where('update_id', $candidate->update_id)
                    ->update([
                        'state' => 'queued',
                        'queued_at' => $now,
                        'processing_started_at' => null,
                        'failed_at' => null,
                        'last_error_class' => null,
                        'last_error_code' => null,
                        'updated_at' => $now,
                    ]);

                return true;
            });

            if ($didRequeue) {
                $requeued++;
            }
        }

        return new TelegramUpdateRequeueResult($requeued, $queue);
    }
}
