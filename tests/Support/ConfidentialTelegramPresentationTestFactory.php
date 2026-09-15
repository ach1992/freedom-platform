<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Telegram\Application\ConfidentialTelegramPresentation;
use App\Modules\Telegram\Application\TelegramConfidentialPresentationHasher;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationService;
use App\Modules\Telegram\Application\TelegramDeliveryDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryOperationReceipt;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramDeliveryRequestFingerprint;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use App\Shared\Application\SafeOutboxPayload;
use App\Shared\Infrastructure\DatabaseOutboxPublisher;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use RuntimeException;

final class ConfidentialTelegramPresentationTestFactory
{
    public static function plainText(string $text): ConfidentialTelegramPresentation
    {
        $reflection = new ReflectionClass(ConfidentialTelegramPresentation::class);
        $validated = $reflection->getMethod('validated')->invoke(null, $text);
        if (! $validated instanceof ConfidentialTelegramPresentation) {
            throw new RuntimeException('Unable to create confidential Telegram presentation test fixture.');
        }

        return $validated;
    }

    public static function rawValue(ConfidentialTelegramPresentation $presentation): string
    {
        $reflection = new ReflectionClass(ConfidentialTelegramPresentation::class);
        $plaintext = $reflection->getMethod('plaintext')->invoke($presentation);
        if (! is_string($plaintext)) {
            throw new RuntimeException('Unable to reveal confidential Telegram presentation test fixture.');
        }

        return $plaintext;
    }

    public static function service(
        Clock $clock,
        ?StringEncrypter $encrypter = null,
        ?TelegramConfidentialPresentationHasher $hasher = null,
    ): TelegramDeliveryConfidentialPresentationService {
        return new TelegramDeliveryConfidentialPresentationService(
            $clock,
            $encrypter ?? app(StringEncrypter::class),
            new TelegramDeliveryConfidentialPresentationDatabaseCapability,
            $hasher ?? app(TelegramConfidentialPresentationHasher::class),
        );
    }

    /**
     * Prepare exact v3 operation/Outbox authority without inserting the companion.
     * Used only by direct-trigger rollback concurrency tests.
     *
     * @return array{public_id:string,outbox_event_id:string,presentation_ciphertext:string,presentation_hash:string,ciphertext_hash:string,request_fingerprint:string}
     */
    public static function prepareV3OperationWithoutCompanion(
        DatabaseManager $database,
        Clock $clock,
        int $recipientChatId,
        string $requestKey,
        string $correlationId,
        string $botId = '123456',
    ): array {
        $presentation = self::plainText('confidential trigger-only rollback fixture');
        $hasher = app(TelegramConfidentialPresentationHasher::class);
        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            null,
            $presentation,
        );
        $publicId = (string) Str::ulid();
        $outboxEventId = (string) Str::uuid();
        $requestKeyHash = hash('sha256', $requestKey);
        $fingerprint = TelegramDeliveryRequestFingerprint::make($request, $botId, $correlationId, null, $hasher->fingerprintHash($presentation));
        $timestamp = $clock->now()->format('Y-m-d H:i:s.u');
        $ciphertext = app(StringEncrypter::class)->encryptString(self::rawValue($presentation));
        $presentationHash = $hasher->integrityHash($presentation, $publicId);
        $ciphertextHash = hash('sha256', $ciphertext);
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
            $ciphertext,
            $presentationHash,
            $ciphertextHash,
        ): array {
            return $capability->runQueue(
                $connection,
                'telegram_delivery_queue_v1',
                $publicId,
                $requestKeyHash,
                $fingerprint,
                $correlationId,
                $request,
                TelegramDeliveryConfidentialPresentationService::DURABLE_MARKER,
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
                    $ciphertext,
                    $presentationHash,
                    $ciphertextHash,
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
                        TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
                    );
                    if (! hash_equals($outboxEventId, $published)) {
                        throw new LogicException('Confidential trigger fixture Outbox identity was unexpectedly replayed.');
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
                        'presentation_text' => TelegramDeliveryConfidentialPresentationService::DURABLE_MARKER,
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
                        'presentation_ciphertext' => $ciphertext,
                        'presentation_hash' => $presentationHash,
                        'ciphertext_hash' => $ciphertextHash,
                        'request_fingerprint' => $fingerprint,
                    ];
                },
            );
        }, 3);
    }

    public static function queue(
        TelegramDeliveryQueueService $queue,
        TelegramDeliveryAction $action,
        int $recipientChatId,
        ?int $targetMessageId,
        ConfidentialTelegramPresentation $presentation,
        string $requestKey,
        string $correlationId,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): TelegramDeliveryOperationReceipt {
        return $queue->queueConfidential(
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
