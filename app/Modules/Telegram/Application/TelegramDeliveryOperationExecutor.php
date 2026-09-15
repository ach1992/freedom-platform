<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type DeliveryOperationRow object{id:int|string,public_id:string,request_key_hash:string,request_fingerprint:string,correlation_id:string,action:string,bot_id:string,recipient_chat_id:int|string,target_message_id:int|string|null,presentation_text:?string,outbox_event_id:string,state:string,state_version:int|string,provider_attempts:int|string,provider_boundary_started_at:?string,completed_at:?string,telegram_message_id:int|string|null,result_code:?string,retry_after_seconds:int|string|null}
 */
final readonly class TelegramDeliveryOperationExecutor
{
    private const EFFECT_AUTHORITY = 'telegram_delivery_effect_v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private TelegramDeliveryRuntime $runtime,
        private TelegramMutationTransport $transport,
        private TelegramDeliveryDatabaseCapability $databaseCapability,
        private TelegramDeliveryInteractivePresentationService $interactivePresentations,
        private TelegramDeliveryConfidentialPresentationService $confidentialPresentations,
        private ?ProtectedTelegramMessageSender $protectedSender = null,
        private ?TelegramProtectedPresentationResolver $protectedPresentations = null,
    ) {}

    /** @requirement ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 QUA-007 */
    public function recover(
        string $publicId,
        string $expectedOutboxEventId,
        string $expectedCorrelationId,
    ): TelegramDeliveryOperationState {
        return $this->database->connection()->transaction(function (Connection $connection) use ($publicId, $expectedOutboxEventId, $expectedCorrelationId): TelegramDeliveryOperationState {
            $this->databaseCapability->acquireRuntimeLifecycleFence($connection);
            $row = $this->operation($connection, $publicId, true);
            $this->assertExpectedOutboxIdentity($row, $expectedOutboxEventId, $expectedCorrelationId);
            $this->assertRuntimeBot($row);
            $state = $this->state($row);

            if ($state !== TelegramDeliveryOperationState::Sending) {
                return $state;
            }

            $updated = $this->transition(
                $connection,
                $row,
                TelegramDeliveryOperationState::Uncertain,
                'telegram_boundary_recovery_uncertain',
                null,
                null,
            );

            return $this->state($updated);
        }, 3);
    }

    /** @requirement ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 QUA-007 */
    public function execute(
        string $publicId,
        string $expectedOutboxEventId,
        string $expectedCorrelationId,
        int $contractVersion = TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION,
    ): TelegramDeliveryOperationReceipt {
        /** @var array{row: DeliveryOperationRow, boundary_entered: bool, request:?TelegramMutationRequest, protected_presentation:?ProtectedTelegramPresentation} $boundary */
        $boundary = $this->database->connection()->transaction(function (Connection $connection) use ($publicId, $expectedOutboxEventId, $expectedCorrelationId, $contractVersion): array {
            $this->databaseCapability->acquireRuntimeLifecycleFence($connection);
            $row = $this->operation($connection, $publicId, true);
            $this->assertExpectedOutboxIdentity($row, $expectedOutboxEventId, $expectedCorrelationId);
            $this->assertRuntimeBot($row);
            $state = $this->state($row);

            if ($state === TelegramDeliveryOperationState::Sending) {
                return [
                    'row' => $this->transition(
                        $connection,
                        $row,
                        TelegramDeliveryOperationState::Uncertain,
                        'telegram_boundary_reentry_uncertain',
                        null,
                        null,
                    ),
                    'boundary_entered' => false,
                    'request' => null,
                    'protected_presentation' => null,
                ];
            }

            if (! in_array($state, [TelegramDeliveryOperationState::Prepared, TelegramDeliveryOperationState::Retryable], true)) {
                return ['row' => $row, 'boundary_entered' => false, 'request' => null, 'protected_presentation' => null];
            }

            // Interactive resolution and durable fingerprint verification must
            // never outrun the existing #179 semantic database attestation.
            $this->databaseCapability->assertRuntimeAuthorityReady($connection);

            $recipientChatId = $this->nonZeroInt($row->recipient_chat_id, 'Telegram recipient chat identity');
            $interactive = null;
            $confidential = null;
            $protectedReference = null;
            $protectedPresentation = null;
            if ($contractVersion === TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION) {
                // Historical v1: exact non-restricted text only.
            } elseif ($contractVersion === TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE) {
                $interactive = $this->interactivePresentations->resolve(
                    $connection,
                    (string) $row->public_id,
                    $recipientChatId,
                );
            } elseif ($contractVersion === TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL) {
                if (! hash_equals(
                    TelegramDeliveryConfidentialPresentationService::DURABLE_MARKER,
                    (string) ($row->presentation_text ?? ''),
                )) {
                    throw new DomainException('Telegram confidential delivery operation marker is invalid.');
                }
                $confidential = $this->confidentialPresentations->resolve(
                    $connection,
                    (string) $row->public_id,
                );
                $interactive = $this->interactivePresentations->resolveOptional(
                    $connection,
                    (string) $row->public_id,
                    $recipientChatId,
                );
            } elseif ($contractVersion === TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PROTECTED_REFERENCE) {
                if ($this->protectedSender === null || $this->protectedPresentations === null) {
                    throw new RuntimeException('Protected Telegram delivery dependencies are unavailable.');
                }
                $protectedReference = TelegramProtectedPresentationReference::restore(
                    (string) ($row->presentation_text ?? ''),
                );
                $userId = $this->protectedRecipientUserId(
                    $connection,
                    (string) $row->bot_id,
                    $recipientChatId,
                );
                $protectedPresentations = $this->protectedPresentations ?? throw new RuntimeException('Protected Telegram presentation resolver is unavailable.');
                $protectedPresentation = $protectedPresentations->resolveForSelf($userId, $protectedReference);
            } else {
                throw new DomainException('Telegram delivery Outbox contract version is unsupported.');
            }
            $request = $this->mutationRequest(
                $row,
                $interactive?->keyboard,
                $confidential?->presentation,
                $protectedReference,
            );
            $confidentialHashCandidates = $confidential === null
                ? null
                : $this->confidentialPresentations->fingerprintHashCandidates($confidential->presentation);
            $fingerprintCandidates = TelegramDeliveryRequestFingerprint::candidates(
                $request,
                (string) $row->bot_id,
                (string) $row->correlation_id,
                $interactive?->snapshotHash,
                $confidentialHashCandidates,
            );
            if (! TelegramDeliveryRequestFingerprint::matches(
                (string) $row->request_fingerprint,
                $fingerprintCandidates,
            )) {
                throw new DomainException('Telegram delivery request fingerprint no longer matches its durable presentation.');
            }

            return [
                'row' => $this->enterProviderBoundary($connection, $row),
                'boundary_entered' => true,
                'request' => $request,
                'protected_presentation' => $protectedPresentation,
            ];
        }, 3);

        $prepared = $boundary['row'];
        if (! $boundary['boundary_entered']) {
            return $this->receipt($prepared);
        }

        $request = $boundary['request']
            ?? throw new RuntimeException('Telegram delivery provider boundary is missing its prepared mutation request.');
        try {
            $protectedPresentation = $boundary['protected_presentation'];
            $result = $protectedPresentation === null
                ? $this->transport->mutate($request)
                : $this->protectedMutationResult(($this->protectedSender ?? throw new RuntimeException('Protected Telegram sender is unavailable.'))->send(
                    $request->recipientChatId,
                    $protectedPresentation,
                ));
        } catch (Throwable) {
            $result = new TelegramMutationResult(
                TelegramMutationOutcome::UncertainResult,
                'telegram_transport_exception',
            );
        }

        $result = $this->normalizeResult($request, $result);

        return $this->database->connection()->transaction(function (Connection $connection) use ($publicId, $result, $expectedOutboxEventId, $expectedCorrelationId): TelegramDeliveryOperationReceipt {
            $this->databaseCapability->acquireRuntimeLifecycleFence($connection);
            $row = $this->operation($connection, $publicId, true);
            $this->assertExpectedOutboxIdentity($row, $expectedOutboxEventId, $expectedCorrelationId);
            $this->assertRuntimeBot($row);

            if ($this->state($row) !== TelegramDeliveryOperationState::Sending) {
                return $this->receipt($row);
            }

            [$state, $completed] = match ($result->outcome) {
                TelegramMutationOutcome::Success => [TelegramDeliveryOperationState::Succeeded, true],
                TelegramMutationOutcome::DefinitiveNoEffectRetryable => [TelegramDeliveryOperationState::Retryable, false],
                TelegramMutationOutcome::DefinitiveFailure => [TelegramDeliveryOperationState::FailedFinal, true],
                TelegramMutationOutcome::RetryAfter => [TelegramDeliveryOperationState::ReviewRequired, true],
                TelegramMutationOutcome::UncertainResult => [TelegramDeliveryOperationState::Uncertain, true],
            };

            $updated = $this->transition(
                $connection,
                $row,
                $state,
                $result->resultCode,
                $result->messageId,
                $result->retryAfterSeconds,
                $completed,
            );

            return $this->receipt($updated);
        }, 3);
    }

    /**
     * @param  DeliveryOperationRow  $row
     * @return DeliveryOperationRow
     */
    private function enterProviderBoundary(Connection $connection, object $row): object
    {
        $version = $this->positiveInt($row->state_version, 'Telegram delivery state version');
        $attempts = $this->nonNegativeInt($row->provider_attempts, 'Telegram provider attempt count');
        $timestamp = $this->timestamp();

        $updated = $this->databaseCapability->runEffect(
            $connection,
            self::EFFECT_AUTHORITY,
            (string) $row->public_id,
            $version,
            fn (): int => $connection->table('telegram_delivery_operations')
                ->where('id', (int) $row->id)
                ->where('state_version', $version)
                ->whereIn('state', [
                    TelegramDeliveryOperationState::Prepared->value,
                    TelegramDeliveryOperationState::Retryable->value,
                ])
                ->update([
                    'state' => TelegramDeliveryOperationState::Sending->value,
                    'state_version' => $version + 1,
                    'provider_attempts' => $attempts + 1,
                    'provider_boundary_started_at' => $timestamp,
                    'completed_at' => null,
                    'telegram_message_id' => null,
                    'result_code' => null,
                    'retry_after_seconds' => null,
                    'updated_at' => $timestamp,
                ]),
        );
        if ($updated !== 1) {
            throw new RuntimeException('Telegram delivery operation lost provider-boundary authority.');
        }

        return $this->operation($connection, (string) $row->public_id, false);
    }

    /**
     * @param  DeliveryOperationRow  $row
     * @return DeliveryOperationRow
     */
    private function transition(
        Connection $connection,
        object $row,
        TelegramDeliveryOperationState $state,
        string $resultCode,
        ?int $messageId,
        ?int $retryAfterSeconds,
        bool $completed = true,
    ): object {
        $version = $this->positiveInt($row->state_version, 'Telegram delivery state version');
        $timestamp = $this->timestamp();

        $updated = $this->databaseCapability->runEffect(
            $connection,
            self::EFFECT_AUTHORITY,
            (string) $row->public_id,
            $version,
            fn (): int => $connection->table('telegram_delivery_operations')
                ->where('id', (int) $row->id)
                ->where('state_version', $version)
                ->where('state', (string) $row->state)
                ->update([
                    'state' => $state->value,
                    'state_version' => $version + 1,
                    'completed_at' => $completed ? $timestamp : null,
                    'telegram_message_id' => $messageId,
                    'result_code' => $resultCode,
                    'retry_after_seconds' => $retryAfterSeconds,
                    'updated_at' => $timestamp,
                ]),
        );
        if ($updated !== 1) {
            throw new RuntimeException('Telegram delivery operation lost result-transition authority.');
        }

        return $this->operation($connection, (string) $row->public_id, false);
    }

    /** @param DeliveryOperationRow $row */
    private function mutationRequest(
        object $row,
        ?TelegramResolvedInlineKeyboardMarkup $inlineKeyboard,
        ?ConfidentialTelegramPresentation $confidentialPresentation = null,
        ?TelegramProtectedPresentationReference $protectedReference = null,
    ): TelegramMutationRequest {
        $action = TelegramDeliveryAction::tryFrom((string) $row->action)
            ?? throw new RuntimeException('Stored Telegram delivery action is invalid.');
        if ($confidentialPresentation !== null && $protectedReference !== null) {
            throw new RuntimeException('Telegram delivery cannot be confidential and protected-reference simultaneously.');
        }
        $presentation = $confidentialPresentation ?? $protectedReference;
        if ($presentation === null && $row->presentation_text !== null) {
            $presentation = NonRestrictedTelegramPresentation::restorePersisted((string) $row->presentation_text);
        }

        return new TelegramMutationRequest(
            $action,
            $this->nonZeroInt($row->recipient_chat_id, 'Telegram recipient chat identity'),
            $row->target_message_id === null ? null : $this->positiveInt($row->target_message_id, 'Telegram target message identity'),
            $presentation,
            $inlineKeyboard,
        );
    }

    private function protectedRecipientUserId(Connection $connection, string $botId, int $telegramUserId): int
    {
        if (preg_match('/\A[1-9][0-9]{5,19}\z/', $botId) !== 1 || $telegramUserId < 1) {
            throw new DomainException('Protected Telegram recipient identity is invalid.');
        }
        $userId = $connection->table('telegram_accounts')
            ->where('bot_id', $botId)
            ->where('telegram_user_id', $telegramUserId)
            ->where('is_bot', false)
            ->value('user_id');
        $userId = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false) {
            throw new DomainException('Protected Telegram recipient account is unavailable.');
        }

        return $userId;
    }

    private function protectedMutationResult(ProtectedTelegramSendResult $result): TelegramMutationResult
    {
        return match ($result->outcome) {
            ProtectedTelegramSendOutcome::Success => new TelegramMutationResult(
                TelegramMutationOutcome::Success,
                $result->resultCode,
                $result->messageId,
            ),
            ProtectedTelegramSendOutcome::RetryAfter => new TelegramMutationResult(
                TelegramMutationOutcome::RetryAfter,
                $result->resultCode,
                retryAfterSeconds: $result->retryAfterSeconds,
            ),
            ProtectedTelegramSendOutcome::DefinitiveFailure => new TelegramMutationResult(
                TelegramMutationOutcome::DefinitiveFailure,
                $result->resultCode,
            ),
            ProtectedTelegramSendOutcome::UncertainResult => new TelegramMutationResult(
                TelegramMutationOutcome::UncertainResult,
                $result->resultCode,
            ),
        };
    }

    private function normalizeResult(TelegramMutationRequest $request, TelegramMutationResult $result): TelegramMutationResult
    {
        if ($result->outcome !== TelegramMutationOutcome::Success) {
            return $result;
        }

        if ($request->action === TelegramDeliveryAction::Delete) {
            if ($result->messageId !== null) {
                return new TelegramMutationResult(
                    TelegramMutationOutcome::UncertainResult,
                    'telegram_delete_success_identity_ambiguous',
                );
            }

            return $result;
        }

        if ($result->messageId === null) {
            return new TelegramMutationResult(
                TelegramMutationOutcome::UncertainResult,
                'telegram_success_identity_missing',
            );
        }

        if ($request->action === TelegramDeliveryAction::Edit
            && $result->messageId !== $request->targetMessageId) {
            return new TelegramMutationResult(
                TelegramMutationOutcome::UncertainResult,
                'telegram_edit_target_mismatch',
            );
        }

        return $result;
    }

    /** @return DeliveryOperationRow */
    private function operation(Connection $connection, string $publicId, bool $lock): object
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new DomainException('Telegram delivery operation identity is invalid.');
        }

        $query = $connection->table('telegram_delivery_operations')->where('public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DeliveryOperationRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'request_key_hash', 'request_fingerprint', 'correlation_id', 'action', 'bot_id',
            'recipient_chat_id', 'target_message_id', 'presentation_text', 'outbox_event_id', 'state', 'state_version',
            'provider_attempts', 'provider_boundary_started_at', 'completed_at', 'telegram_message_id', 'result_code',
            'retry_after_seconds',
        ]);
        if ($row === null) {
            throw new DomainException('Telegram delivery operation was not found.');
        }

        return $row;
    }

    /** @param DeliveryOperationRow $row */
    private function assertExpectedOutboxIdentity(
        object $row,
        string $expectedOutboxEventId,
        string $expectedCorrelationId,
    ): void {
        if (! hash_equals((string) $row->outbox_event_id, $expectedOutboxEventId)
            || ! hash_equals((string) $row->correlation_id, $expectedCorrelationId)) {
            throw new DomainException('Telegram delivery Outbox identity does not match the durable operation authority.');
        }
    }

    /** @param DeliveryOperationRow $row */
    private function assertRuntimeBot(object $row): void
    {
        $currentBotId = $this->runtime->botId();
        if (! hash_equals((string) $row->bot_id, $currentBotId)) {
            throw new DomainException('Telegram delivery operation no longer matches the active bot identity.');
        }
    }

    /** @param DeliveryOperationRow $row */
    private function state(object $row): TelegramDeliveryOperationState
    {
        return TelegramDeliveryOperationState::tryFrom((string) $row->state)
            ?? throw new RuntimeException('Stored Telegram delivery state is invalid.');
    }

    /** @param DeliveryOperationRow $row */
    private function receipt(object $row): TelegramDeliveryOperationReceipt
    {
        $action = TelegramDeliveryAction::tryFrom((string) $row->action)
            ?? throw new RuntimeException('Stored Telegram delivery action is invalid.');

        return new TelegramDeliveryOperationReceipt(
            (string) $row->public_id,
            $action,
            $this->state($row),
            (string) $row->outbox_event_id,
            true,
            $row->telegram_message_id === null ? null : (int) $row->telegram_message_id,
            $row->result_code === null ? null : (string) $row->result_code,
            $row->retry_after_seconds === null ? null : (int) $row->retry_after_seconds,
        );
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($integer === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $integer;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($integer === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $integer;
    }

    private function nonZeroInt(mixed $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || (int) $integer === 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $integer;
    }
}
