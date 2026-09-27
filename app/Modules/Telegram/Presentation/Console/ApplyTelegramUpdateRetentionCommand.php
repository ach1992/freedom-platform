<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Presentation\Console;

use App\Modules\Telegram\Application\TelegramUpdatePayloadRetentionService;
use Illuminate\Console\Command;
use Throwable;

final class ApplyTelegramUpdateRetentionCommand extends Command
{
    protected $signature = 'telegram:updates:retention
        {--failed-older-than= : Required policy-supplied age in seconds before failed Update payloads become terminal}
        {--limit=100 : Maximum failed Update payloads to terminalize}
        {--json : Emit JSON only}';

    protected $description = 'Terminalize old failed Telegram Update payloads using an explicit external retention age';

    public function handle(TelegramUpdatePayloadRetentionService $retention): int
    {
        $olderThan = filter_var($this->option('failed-older-than'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($olderThan) || $olderThan < 1 || ! is_int($limit) || $limit < 1 || $limit > 1000) {
            return $this->invalidInput();
        }

        try {
            $terminalized = $retention->terminalizeFailedPayloads($olderThan, $limit);
        } catch (Throwable) {
            if ($this->option('json')) {
                $this->line('{"status":"failed","code":"telegram_update_retention_failed"}');
            } else {
                $this->error('Telegram Update retention failed unexpectedly.');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => 'ok',
                'terminalized' => $terminalized,
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->info('Telegram Update retention complete: terminalized='.$terminalized);
        }

        return self::SUCCESS;
    }

    private function invalidInput(): int
    {
        if ($this->option('json')) {
            $this->line('{"status":"invalid","code":"telegram_update_retention_invalid_input"}');
        } else {
            $this->error('Provide --failed-older-than as a positive integer and --limit between 1 and 1000.');
        }

        return self::INVALID;
    }
}
