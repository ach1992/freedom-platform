<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Presentation\Console;

use App\Modules\Telegram\Application\Jobs\ProcessTelegramUpdateJob;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use RuntimeException;

final class RequeueTelegramUpdatesCommand extends Command
{
    protected $signature = 'telegram:updates:requeue
        {--older-than=30 : Only requeue rows not updated during this many seconds}
        {--limit=100 : Maximum rows to requeue}
        {--include-failed : Include failed rows in addition to accepted and queued rows}
        {--json : Emit a machine-readable secret-free result}';

    protected $description = 'Requeue stranded Telegram updates using the configured ingress queue.';

    /** @requirement ONB-001 PAY-003 OPS-003 SEC-009 */
    public function handle(
        DatabaseManager $database,
        TelegramRuntimeConfiguration $configuration,
    ): int {
        $olderThan = $this->integerOption('older-than', 0, 86_400);
        $limit = $this->integerOption('limit', 1, 1_000);
        $includeFailed = $this->option('include-failed') === true;
        $states = $includeFailed ? ['accepted', 'queued', 'failed'] : ['accepted', 'queued'];
        $cutoff = now('UTC')->subSeconds($olderThan)->format('Y-m-d H:i:s.u');

        /** @var Collection<int, object{bot_id: string, update_id: int}> $rows */
        $rows = $database->connection()->table('processed_telegram_updates')
            ->whereIn('state', $states)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get(['bot_id', 'update_id']);

        $requeued = 0;

        foreach ($rows as $candidate) {
            $didRequeue = $database->connection()->transaction(function () use (
                $database,
                $configuration,
                $candidate,
                $states,
            ): bool {
                $row = $database->connection()->table('processed_telegram_updates')
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
                )->onQueue($configuration->queue)->afterCommit();

                $now = now('UTC')->format('Y-m-d H:i:s.u');
                $database->connection()->table('processed_telegram_updates')
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

        $result = [
            'requeued' => $requeued,
            'queue' => $configuration->queue,
            'included_failed' => $includeFailed,
        ];

        if ($this->option('json') === true) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->components->info(sprintf('Requeued %d Telegram update(s) on %s.', $requeued, $configuration->queue));
        }

        return self::SUCCESS;
    }

    private function integerOption(string $name, int $minimum, int $maximum): int
    {
        $value = $this->option($name);

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException(sprintf('The --%s option must be an integer.', $name));
        }

        $integer = (int) $value;

        if ($integer < $minimum || $integer > $maximum) {
            throw new RuntimeException(sprintf('The --%s option is outside the allowed range.', $name));
        }

        return $integer;
    }
}
