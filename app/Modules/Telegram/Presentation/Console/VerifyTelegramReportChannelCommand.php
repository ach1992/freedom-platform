<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Presentation\Console;

use App\Modules\Telegram\Application\Contracts\TelegramReportChannelVerifier;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class VerifyTelegramReportChannelCommand extends Command
{
    protected $signature = 'telegram:report-channel:verify {--json : Emit a machine-readable secret-free result}';

    protected $description = 'Verify the configured Telegram report channel/chat by sending an installation test message';

    public function handle(TelegramReportChannelVerifier $verifier): int
    {
        try {
            $chatId = config('reporting.telegram.report_channel_chat_id');

            if (! is_int($chatId) || $chatId === 0) {
                throw new RuntimeException('Telegram report channel/chat ID is not configured.');
            }

            $verifier->verifyReportChannel($chatId);
        } catch (Throwable) {
            $this->components->error('Telegram report channel verification failed.');

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode(['status' => 'verified'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Telegram report channel verified.');

        return self::SUCCESS;
    }
}
