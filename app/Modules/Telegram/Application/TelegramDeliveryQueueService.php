<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * @phpstan-type DeliveryOperationRow object{id:int|string,public_id:string,request_key_hash:string,request_fingerprint:string,correlation_id:string,action:string,bot_id:string,recipient_chat_id:int|string,target_message_id:int|string|null,presentation_text:?string,outbox_event_id:string,state:string,state_version:int|string,provider_attempts:int|string,provider_boundary_started_at:?string,completed_at:?string,telegram_message_id:int|string|null,result_code:?string,retry_after_seconds:int|string|null}
 */
final readonly class TelegramDeliveryQueueService
{
    private const QUEUE_AUTHORITY = 'telegram_delivery_queue_v1';

    public const OUTBOX_EVENT_TYPE = 'telegram.delivery.requested';

    public const OUTBOX_AGGREGATE_TYPE = 'telegram_delivery_operation';

    public const OUTBOX_EVENT_KEY_PREFIX = 'telegram-delivery-requested:';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private OutboxPublisher $outbox,
        private TelegramDeliveryRuntime $runtime,
        private TelegramDeliveryDatabaseCapability $databaseCapability,
    ) {}

    /** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 QUA-007 */
    public function queue(
        TelegramDeliveryAction $action,
        int $recipientChatId,
        ?int $targetMessageId,
        ?NonRestrictedTelegramPresentation $presentation,
        string $requestKey,
        string $correlationId,
    ): TelegramDeliveryOperationReceipt {
        $request = new TelegramMutationRequest($action, $recipientChatId, $targetMessageId, $presentation);
        $requestKeyHash = $this->requestKeyHash($requestKey);
        $this->assertToken($correlationId, 'Telegram delivery correlation ID', 8, 64);
        $botId = $this->runtime->botId();
        $this->assertBotId($botId);
        $fingerprint = $this->fingerprint($request, $botId, $correlationId);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $request,
                $requestKeyHash,
                $correlationId,
                $botId,
                $fingerprint,
            ): TelegramDeliveryOperationReceipt {
                $existing = $this->operationByRequestHash($connection, $requestKeyHash, true);
                if ($existing !== null) {
                    return $this->replayReceipt($existing, $fingerprint);
                }

                return $this->createOperation(
                    $connection,
                    $request,
                    $requestKeyHash,
                    $fingerprint,
                    $correlationId,
                    $botId,
                );
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            // A concurrent transaction may have won the unique request hash. Its
            // commit is visible after the duplicate-key wait completes; our failed
            // transaction (including its quarantined Outbox row) has rolled back.
            $existing = $this->operationByRequestHash($this->database->connection(), $requestKeyHash, false);
            if ($existing === null) {
                throw $exception;
            }

            return $this->replayReceipt($existing, $fingerprint);
        }
    }

    private function createOperation(
        Connection $connection,
        TelegramMutationRequest $request,
        string $requestKeyHash,
        string $fingerprint,
        string $correlationId,
        string $botId,
    ): TelegramDeliveryOperationReceipt {
        $publicId = (string) Str::ulid();
        $outboxEventId = (string) Str::uuid();
        $timestamp = $this->timestamp();

        return $this->databaseCapability->runQueue(
            $connection,
            self::QUEUE_AUTHORITY,
            $publicId,
            $requestKeyHash,
            $fingerprint,
            $correlationId,
            $request,
            $botId,
            $outboxEventId,
            function () use (
                $connection,
                $publicId,
                $requestKeyHash,
                $fingerprint,
                $correlationId,
                $request,
                $botId,
                $outboxEventId,
                $timestamp,
            ): TelegramDeliveryOperationReceipt {
                $publishedEventId = $this->outbox->publish(
                    $outboxEventId,
                    self::OUTBOX_EVENT_KEY_PREFIX.$publicId,
                    self::OUTBOX_EVENT_TYPE,
                    self::OUTBOX_AGGREGATE_TYPE,
                    $publicId,
                    new SafeOutboxPayload([
                        'telegram_delivery_operation_public_id' => $publicId,
                    ]),
                    $correlationId,
                );
                if (! hash_equals($outboxEventId, $publishedEventId)) {
                    throw new RuntimeException('Telegram delivery Outbox event identity was unexpectedly replayed.');
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

                $released = $connection->table('outbox_messages')
                    ->where('id', $outboxEventId)
                    ->where('dispatch_state', 'authority_pending')
                    ->update([
                        'dispatch_state' => 'pending',
                        'updated_at' => $timestamp,
                    ]);
                if ($released !== 1) {
                    throw new RuntimeException('Telegram delivery Outbox command did not release from queue authority.');
                }

                $created = $this->operationByPublicId($connection, $publicId, false)
                    ?? throw new RuntimeException('Telegram delivery operation was not persisted.');

                return $this->receipt($created, false);
            },
        );
    }

    /** @param DeliveryOperationRow $row */
    private function replayReceipt(object $row, string $fingerprint): TelegramDeliveryOperationReceipt
    {
        if (! hash_equals((string) $row->request_fingerprint, $fingerprint)) {
            throw new DomainException('Telegram delivery request key was reused with conflicting semantics.');
        }

        return $this->receipt($row, true);
    }

    /** @param DeliveryOperationRow $row */
    private function receipt(object $row, bool $replayed): TelegramDeliveryOperationReceipt
    {
        $action = TelegramDeliveryAction::tryFrom((string) $row->action)
            ?? throw new RuntimeException('Stored Telegram delivery action is invalid.');
        $state = TelegramDeliveryOperationState::tryFrom((string) $row->state)
            ?? throw new RuntimeException('Stored Telegram delivery state is invalid.');

        return new TelegramDeliveryOperationReceipt(
            (string) $row->public_id,
            $action,
            $state,
            (string) $row->outbox_event_id,
            $replayed,
            $row->telegram_message_id === null ? null : (int) $row->telegram_message_id,
            $row->result_code === null ? null : (string) $row->result_code,
            $row->retry_after_seconds === null ? null : (int) $row->retry_after_seconds,
        );
    }

    /** @return DeliveryOperationRow|null */
    private function operationByRequestHash(Connection $connection, string $requestKeyHash, bool $lock): ?object
    {
        $query = $connection->table('telegram_delivery_operations')->where('request_key_hash', $requestKeyHash);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DeliveryOperationRow|null $row */
        $row = $query->first($this->columns());

        return $row;
    }

    /** @return DeliveryOperationRow|null */
    private function operationByPublicId(Connection $connection, string $publicId, bool $lock): ?object
    {
        $query = $connection->table('telegram_delivery_operations')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DeliveryOperationRow|null $row */
        $row = $query->first($this->columns());

        return $row;
    }

    /** @return list<string> */
    private function columns(): array
    {
        return [
            'id', 'public_id', 'request_key_hash', 'request_fingerprint', 'correlation_id', 'action', 'bot_id',
            'recipient_chat_id', 'target_message_id', 'presentation_text', 'outbox_event_id', 'state', 'state_version',
            'provider_attempts', 'provider_boundary_started_at', 'completed_at', 'telegram_message_id', 'result_code',
            'retry_after_seconds',
        ];
    }

    private function requestKeyHash(string $requestKey): string
    {
        if ($requestKey === '' || strlen($requestKey) > 191 || preg_match('/[\x00-\x1F\x7F]/', $requestKey) === 1) {
            throw new DomainException('Telegram delivery request key is invalid.');
        }

        return hash('sha256', $requestKey);
    }

    /** @throws JsonException */
    private function fingerprint(TelegramMutationRequest $request, string $botId, string $correlationId): string
    {
        $encoded = json_encode([
            'action' => $request->action->value,
            'bot_id' => $botId,
            'correlation_id' => $correlationId,
            'presentation_text' => $request->presentation?->text(),
            'recipient_chat_id' => $request->recipientChatId,
            'target_message_id' => $request->targetMessageId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $encoded);
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertBotId(string $botId): void
    {
        if (preg_match('/\A[1-9][0-9]{5,19}\z/', $botId) !== 1) {
            throw new RuntimeException('Telegram runtime bot identity is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }
}
