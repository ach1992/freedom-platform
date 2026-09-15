<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Presentation\Console;

use App\Modules\Telegram\Application\TelegramUpdateRequeuer;
use Illuminate\Console\Command;
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
    public function handle(TelegramUpdateRequeuer $requeuer): int
    {
        $olderThan = $this->integerOption('older-than', 0, 86_400);
        $limit = $this->integerOption('limit', 1, 1_000);
        $includeFailed = $this->option('include-failed') === true;
        $outcome = $requeuer->requeue($olderThan, $limit, $includeFailed);

        $result = [
            'requeued' => $outcome->requeued,
            'queue' => $outcome->queue,
            'included_failed' => $includeFailed,
        ];

        if ($this->option('json') === true) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->components->info(sprintf('Requeued %d Telegram update(s) on %s.', $outcome->requeued, $outcome->queue));
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
