<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryOperationReceipt;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramDeliveryRequestFingerprint;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use App\Shared\Application\SafeOutboxPayload;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;

final class NonRestrictedTelegramPresentationTestFactory
{
    public static function plainText(string $text): NonRestrictedTelegramPresentation
    {
        $reflection = new ReflectionClass(NonRestrictedTelegramPresentation::class);
        $validated = $reflection->getMethod('validated')->invoke(null, $text);
        if (! $validated instanceof NonRestrictedTelegramPresentation) {
            throw new LogicException('Unable to create Telegram presentation test fixture.');
        }

        return $validated;
    }

    public static function queue(
        TelegramDeliveryQueueService $queue,
        TelegramDeliveryAction $action,
        int $recipientChatId,
        ?int $targetMessageId,
        ?NonRestrictedTelegramPresentation $presentation,
        string $requestKey,
        string $correlationId,
    ): TelegramDeliveryOperationReceipt {
        return $queue->queue(
            $action,
            $recipientChatId,
            $targetMessageId,
            $presentation,
            $requestKey,
            $correlationId,
        );
    }

    public static function queueProtectedReference(
        TelegramDeliveryQueueService $queue,
        TelegramDeliveryAction $action,
        int $recipientChatId,
        TelegramProtectedPresentationReference $reference,
        string $requestKey,
        string $correlationId,
    ): TelegramDeliveryOperationReceipt {
        return $queue->queueProtectedReference(
            $action,
            $recipientChatId,
            $reference,
            $requestKey,
            $correlationId,
        );
    }

    /**
     * Create the exact prepared v2 delivery envelope without its interactive
     * snapshot. This deliberately test-only seam lets MariaDB concurrency tests
     * exercise the interactive INSERT trigger itself instead of taking the
     * application lifecycle fence first.
     *
     * @return array{public_id:string,outbox_event_id:string}
     */
    public static function prepareInteractiveV2OperationWithoutSnapshot(
        DatabaseManager $database,
        Clock $clock,
        int $recipientChatId,
        string $requestKey,
        string $correlationId,
        TelegramInlineKeyboardSnapshot $inlineKeyboard,
        string $botId = '123456',
    ): array {
        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            null,
            self::plainText('interactive trigger-only rollback fixture'),
        );
        $publicId = (string) Str::ulid();
        $outboxEventId = (string) Str::uuid();
        $requestKeyHash = hash('sha256', $requestKey);
        $fingerprint = TelegramDeliveryRequestFingerprint::make(
            $request,
            $botId,
            $correlationId,
            $inlineKeyboard->hash(),
        );
        $timestamp = $clock->now()->format('Y-m-d H:i:s.u');
        $capability = new TelegramDeliveryDatabaseCapability;
        $outbox = new DatabaseOutboxPublisher($database, $clock);

        return $database->connection()->transaction(function (Connection $connection) use (
            $capability,
            $outbox,
            $publicId,
            $outboxEventId,
            $requestKeyHash,
            $fingerprint,
            $correlationId,
            $request,
            $botId,
            $timestamp,
        ): array {
            return $capability->runQueue(
                $connection,
                'telegram_delivery_queue_v1',
                $publicId,
                $requestKeyHash,
                $fingerprint,
                $correlationId,
                $request,
                $request->presentation?->text(),
                $botId,
                $outboxEventId,
                function () use (
                    $connection,
                    $outbox,
                    $publicId,
                    $outboxEventId,
                    $requestKeyHash,
                    $fingerprint,
                    $correlationId,
                    $request,
                    $botId,
                    $timestamp,
                ): array {
                    $payload = new SafeOutboxPayload([
                        'telegram_delivery_operation_public_id' => $publicId,
                    ]);
                    $published = $outbox->publish(
                        $outboxEventId,
                        TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$publicId,
                        TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE,
                        TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE,
                        $publicId,
                        $payload,
                        $correlationId,
                        TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE,
                    );
                    if (! hash_equals($outboxEventId, $published)) {
                        throw new LogicException('Interactive trigger fixture Outbox identity was unexpectedly replayed.');
                    }

                    $connection->table('telegram_delivery_operations')->insert([
                        'public_id' => $publicId,
                        'request_key_hash' => $requestKeyHash,
                        'request_fingerprint' => $fingerprint,
                        'correlation_id' => $correlationId,
                        'action' => $request->action->value,
                        'bot_id' => $botId,
                        'recipient_chat_id' => $request->recipientChatId,
                        'target_message_id' => $request->targetMessageId,
                        'presentation_text' => $request->presentation?->text(),
                        'outbox_event_id' => $outboxEventId,
                        'state' => TelegramDeliveryOperationState::Prepared->value,
                        'state_version' => 1,
                        'provider_attempts' => 0,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                    return [
                        'public_id' => $publicId,
                        'outbox_event_id' => $outboxEventId,
                    ];
                },
            );
        }, 3);
    }

    public static function queueInteractive(
        TelegramDeliveryQueueService $queue,
        TelegramDeliveryAction $action,
        int $recipientChatId,
        ?int $targetMessageId,
        ?NonRestrictedTelegramPresentation $presentation,
        string $requestKey,
        string $correlationId,
        TelegramInlineKeyboardSnapshot $inlineKeyboard,
    ): TelegramDeliveryOperationReceipt {
        return $queue->queue(
            $action,
            $recipientChatId,
            $targetMessageId,
            $presentation,
            $requestKey,
            $correlationId,
            $inlineKeyboard,
        );
    }
}
