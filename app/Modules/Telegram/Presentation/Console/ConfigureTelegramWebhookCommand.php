<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Presentation\Console;

use App\Modules\Telegram\Application\Contracts\TelegramBotApi;
use App\Modules\Telegram\Application\TelegramWebhookInfo;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Console\Command;
use RuntimeException;

final class ConfigureTelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook:configure
        {--status-only : Read current webhook status without changing it}
        {--drop-pending-updates : Ask Telegram to discard pending test updates}
        {--json : Emit a machine-readable secret-free result}';

    protected $description = 'Configure or inspect the secret-protected Telegram webhook.';

    /** @requirement INS-001 SEC-008 SEC-009 QUA-013 */
    public function handle(
        TelegramBotApi $api,
        TelegramRuntimeConfiguration $configuration,
    ): int {
        try {
            $info = $this->option('status-only') === true
                ? $api->webhookInfo()
                : $api->configureWebhook(
                    $configuration->webhookUrl,
                    $configuration->webhookSecret,
                    $this->option('drop-pending-updates') === true,
                );
        } catch (RuntimeException) {
            $this->components->error('Telegram webhook operation failed.');

            return self::FAILURE;
        }

        $this->renderResult($configuration->botId, $info);

        return $info->configured && $info->targetsExpectedUrl ? self::SUCCESS : self::FAILURE;
    }

    private function renderResult(string $botId, TelegramWebhookInfo $info): void
    {
        $result = [
            'bot_id' => $botId,
            'configured' => $info->configured,
            'targets_expected_url' => $info->targetsExpectedUrl,
            'pending_update_count' => $info->pendingUpdateCount,
            'last_error_present' => $info->lastErrorPresent,
        ];

        if ($this->option('json') === true) {
            $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->line($encoded);

            return;
        }

        $this->table(['Fact', 'Value'], array_map(
            static fn (string $key, bool|int|string $value): array => [
                $key,
                is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value,
            ],
            array_keys($result),
            array_values($result),
        ));
    }
}
