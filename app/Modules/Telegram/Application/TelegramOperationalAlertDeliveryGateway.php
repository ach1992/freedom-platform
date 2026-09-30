<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Operations\Application\Contracts\OperationalAlertDeliveryGateway;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Reviewed confidential delivery seam for bounded operational alert metadata.
 */
final readonly class TelegramOperationalAlertDeliveryGateway implements OperationalAlertDeliveryGateway
{
    public function __construct(
        private DatabaseManager $database,
        private TelegramDeliveryRuntime $runtime,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
    ) {}

    /** @requirement OPS-001 OPS-003 DAT-003 SEC-002 */
    public function queue(
        string $audience,
        string $severity,
        string $eventName,
        int $occurrenceCount,
        bool $resolved,
        string $trackingCode,
        string $requestKey,
        string $correlationId,
    ): string {
        if (! in_array($audience, ['report_channel', 'owner'], true)
            || ! in_array($severity, ['warning', 'critical', 'security'], true)
            || preg_match('/\A[a-z][a-z0-9_.-]{2,190}\z/', $eventName) !== 1
            || $occurrenceCount < 1
            || preg_match('/\A[a-f0-9]{12}\z/', $trackingCode) !== 1
        ) {
            throw new DomainException('Operational alert Telegram delivery metadata is invalid.');
        }

        $recipient = $audience === 'owner'
            ? $this->ownerTelegramUserId()
            : $this->reportChannelChatId();

        $presentation = $this->presentations->fromSource(
            new readonly class($severity, $eventName, $occurrenceCount, $resolved, $trackingCode) implements ConfidentialTelegramPresentationSource
            {
                public function __construct(
                    private string $severity,
                    private string $eventName,
                    private int $occurrenceCount,
                    private bool $resolved,
                    private string $trackingCode,
                ) {}

                public function confidentialTelegramText(): string
                {
                    return implode("\n", [
                        'Operational alert',
                        'Severity: '.$this->severity,
                        'Event: '.$this->eventName,
                        'Status: '.($this->resolved ? 'resolved' : 'active'),
                        'Occurrences: '.$this->occurrenceCount,
                        'Tracking: '.$this->trackingCode,
                    ]);
                }
            },
        );

        return $this->delivery->send(
            $recipient,
            $presentation,
            $requestKey,
            $correlationId,
        )->publicId;
    }

    private function reportChannelChatId(): int
    {
        $chatId = config('reporting.telegram.report_channel_chat_id');
        if (! is_int($chatId) || $chatId === 0) {
            throw new RuntimeException('Operational alert report-channel destination is not configured.');
        }

        return $chatId;
    }

    private function ownerTelegramUserId(): int
    {
        $botId = $this->runtime->botId();

        $rows = $this->database->connection()
            ->table('administrators as administrator')
            ->join('telegram_accounts as telegram', function ($join) use ($botId): void {
                $join->on('telegram.user_id', '=', 'administrator.user_id')
                    ->where('telegram.bot_id', '=', $botId)
                    ->where('telegram.is_bot', '=', false);
            })
            ->where('administrator.status', 'active')
            ->where('administrator.is_owner', true)
            ->get(['telegram.telegram_user_id'])
            ->all();

        if (count($rows) !== 1) {
            throw new RuntimeException('Operational alert delivery requires exactly one active Owner Telegram account.');
        }

        $telegramUserId = (int) ($rows[0]->telegram_user_id ?? 0);
        if ($telegramUserId < 1) {
            throw new RuntimeException('Operational alert Owner Telegram identity is invalid.');
        }

        return $telegramUserId;
    }
}
