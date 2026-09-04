<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Modules\Telegram\Domain\TelegramInteractionDispatchStatus;
use App\Shared\Application\RestrictedValue;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramInteractionDispatcher
{
    /** @requirement ARCH-003 DAT-003 SEC-002 SEC-003 QUA-001 */
    public function __construct(
        private DatabaseManager $database,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramInteractionUpdateBindingService $updateBindings,
        private TelegramInteractionHandlerRegistry $handlers,
        private TelegramNavigationEntryGateway $navigationEntry,
        private TelegramPrivateMediaInteractionGateway $privateMediaGateway,
    ) {}

    /** @param array<string, mixed> $update */
    public function dispatch(string $botId, int $updateId, ?int $userId, array $update): TelegramInteractionDispatchResult
    {
        if ($userId === null) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Ignored);
        }

        $account = $this->database->connection()->table('telegram_accounts')
            ->where('bot_id', $botId)
            ->where('user_id', $userId)
            ->first(['id', 'telegram_user_id']);
        if ($account === null) {
            throw new RuntimeException('Telegram interaction identity was not synchronized.');
        }

        $callbackQuery = $update['callback_query'] ?? null;
        if (is_array($callbackQuery) && ! array_is_list($callbackQuery)) {
            return $this->dispatchCallback(
                $botId,
                $updateId,
                (int) $account->telegram_user_id,
                $callbackQuery,
            );
        }

        $message = $update['message'] ?? null;
        if (! is_array($message) || array_is_list($message)) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Ignored);
        }

        try {
            $privateMedia = $this->privateMediaFromMessage($message);
        } catch (TelegramPrivateMediaRejected) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Rejected);
        }
        $text = $message['text'] ?? null;
        if ($privateMedia !== null) {
            if (is_string($text) || ! $this->isPrivateActorChat($message, (int) $account->telegram_user_id)) {
                return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Rejected);
            }

            return $this->dispatchPrivateMedia(
                $botId,
                $updateId,
                $privateMedia,
                $message,
                (int) $account->id,
                (int) $account->telegram_user_id,
            );
        }

        if (! is_string($text)) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Ignored);
        }
        if (mb_strlen($text) > 4096) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Rejected);
        }

        $trimmed = trim($text);
        $this->navigationEntry->startIfEligible(
            $botId,
            $updateId,
            (int) $account->id,
            (int) $account->telegram_user_id,
            $message,
            $trimmed,
        );
        $isCancel = preg_match('/\A\/cancel(?:@[A-Za-z0-9_]+)?\z/u', $trimmed) === 1;
        $isBack = preg_match('/\A\/back(?:@[A-Za-z0-9_]+)?\z/u', $trimmed) === 1;
        $kind = $isCancel ? 'cancel' : ($isBack ? 'back' : 'message');
        $requestKey = $this->updateRequestKey($botId, $updateId, $kind);
        $binding = $this->updateBindings->bind(
            $botId,
            $updateId,
            (int) $account->id,
            $kind,
            $requestKey,
        );

        if ($isCancel) {
            if ($binding->sessionPublicId === null || $binding->sessionVersion === null) {
                return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Ignored);
            }

            $session = $this->sessions->cancel(
                $binding->sessionPublicId,
                $binding->sessionVersion,
                $requestKey,
            );

            return new TelegramInteractionDispatchResult(
                TelegramInteractionDispatchStatus::Cancelled,
                $session->publicId,
            );
        }

        if ($binding->sessionPublicId === null
            || $binding->flow === null
            || $binding->sessionState === null
            || $binding->sessionVersion === null) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Ignored);
        }

        $handler = $this->handlers->forFlow($binding->flow);
        if ($handler === null) {
            throw new RuntimeException('Telegram interaction session has no registered handler.');
        }

        $handler->handle(new TelegramInteractionAction(
            $isBack ? TelegramInteractionActionKind::Back : TelegramInteractionActionKind::Message,
            $requestKey,
            $botId,
            $updateId,
            $binding->telegramAccountId,
            $binding->userId,
            $binding->telegramUserId,
            $binding->sessionPublicId,
            $binding->flow,
            $binding->sessionState,
            $binding->sessionVersion,
            $binding->sessionPayload,
            $isBack ? null : $text,
            null,
            null,
            [],
            $binding->replayed,
            null,
            $binding->acceptedAt,
        ));

        return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Handled, $binding->sessionPublicId);
    }

    /**
     * @param  array<string,mixed>  $message
     */
    private function dispatchPrivateMedia(
        string $botId,
        int $updateId,
        TelegramPrivateMediaInput $media,
        array $message,
        int $telegramAccountId,
        int $telegramUserId,
    ): TelegramInteractionDispatchResult {
        $messageTimestamp = $message['date'] ?? null;
        if (! is_int($messageTimestamp) || $messageTimestamp < 1) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Rejected);
        }

        $requestKey = $this->updateRequestKey($botId, $updateId, 'message');
        $binding = $this->updateBindings->bind(
            $botId,
            $updateId,
            $telegramAccountId,
            'message',
            $requestKey,
        );
        if ($binding->sessionPublicId === null
            || $binding->flow === null
            || $binding->sessionState === null
            || $binding->sessionVersion === null) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Ignored);
        }

        $handled = $this->privateMediaGateway->handle(new TelegramPrivateMediaInteraction(
            $requestKey,
            $botId,
            $updateId,
            $binding->telegramAccountId,
            $binding->userId,
            $binding->telegramUserId,
            $binding->sessionPublicId,
            $binding->flow,
            $binding->sessionState,
            $binding->sessionVersion,
            $binding->sessionPayload,
            $media,
            new DateTimeImmutable('@'.$messageTimestamp),
            $binding->replayed,
        ));

        return new TelegramInteractionDispatchResult(
            $handled ? TelegramInteractionDispatchStatus::Handled : TelegramInteractionDispatchStatus::Rejected,
            $binding->sessionPublicId,
        );
    }

    /** @param array<string, mixed> $callbackQuery */
    private function dispatchCallback(
        string $botId,
        int $updateId,
        int $telegramUserId,
        array $callbackQuery,
    ): TelegramInteractionDispatchResult {
        $data = $callbackQuery['data'] ?? null;
        if (! is_string($data) || ! str_starts_with($data, 'i_')) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Ignored);
        }

        try {
            $callback = $this->callbacks->accept($botId, $telegramUserId, $data, $updateId);
        } catch (TelegramInteractionRejected) {
            return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Rejected);
        }

        if ($callback->completed) {
            return new TelegramInteractionDispatchResult(
                TelegramInteractionDispatchStatus::Replayed,
                $callback->sessionPublicId,
                $callback->publicId,
            );
        }

        if ($callback->replayed
            && $callback->acceptedUpdateId !== null
            && $callback->acceptedUpdateId !== $updateId) {
            return new TelegramInteractionDispatchResult(
                TelegramInteractionDispatchStatus::Replayed,
                $callback->sessionPublicId,
                $callback->publicId,
            );
        }

        $handler = $this->handlers->forFlow($callback->flow);
        if ($handler === null) {
            throw new RuntimeException('Telegram callback has no registered interaction handler.');
        }

        $handler->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Callback,
            $callback->requestKey,
            $botId,
            $updateId,
            $callback->telegramAccountId,
            $callback->userId,
            $telegramUserId,
            $callback->sessionPublicId,
            $callback->flow,
            $callback->sessionState,
            $callback->sessionVersion,
            $callback->sessionPayload,
            null,
            $callback->publicId,
            $callback->action,
            $callback->payload,
            $callback->replayed,
            $callback->acceptedAt,
        ));
        $this->callbacks->complete($callback->publicId);

        return new TelegramInteractionDispatchResult(
            TelegramInteractionDispatchStatus::Handled,
            $callback->sessionPublicId,
            $callback->publicId,
        );
    }

    /** @param array<string,mixed> $message */
    private function privateMediaFromMessage(array $message): ?TelegramPrivateMediaInput
    {
        $photoPresent = array_key_exists('photo', $message);
        $documentPresent = array_key_exists('document', $message);
        if ($photoPresent && $documentPresent) {
            throw new TelegramPrivateMediaRejected('ambiguous_media');
        }

        if ($photoPresent) {
            $photos = $message['photo'];
            if (! is_array($photos) || ! array_is_list($photos) || $photos === []) {
                throw new TelegramPrivateMediaRejected('malformed_photo');
            }

            $selected = null;
            $selectedArea = -1;
            foreach ($photos as $photo) {
                if (! is_array($photo) || array_is_list($photo)) {
                    throw new TelegramPrivateMediaRejected('malformed_photo');
                }
                $width = $photo['width'] ?? null;
                $height = $photo['height'] ?? null;
                if (! is_int($width)
                    || ! is_int($height)
                    || $width < 1
                    || $height < 1
                    || $width > 50_000
                    || $height > 50_000) {
                    throw new TelegramPrivateMediaRejected('malformed_photo');
                }
                $area = $width * $height;
                if ($area > $selectedArea) {
                    $selected = $photo;
                    $selectedArea = $area;
                }
            }
            if (! is_array($selected)) {
                throw new TelegramPrivateMediaRejected('malformed_photo');
            }

            return $this->mediaInput('photo', $selected);
        }

        if ($documentPresent) {
            $document = $message['document'];
            if (! is_array($document) || array_is_list($document)) {
                throw new TelegramPrivateMediaRejected('malformed_document');
            }

            return $this->mediaInput('document', $document);
        }

        return null;
    }

    /** @param array<string,mixed> $file */
    private function mediaInput(string $sourceKind, array $file): TelegramPrivateMediaInput
    {
        $fileId = $file['file_id'] ?? null;
        $fileUniqueId = $file['file_unique_id'] ?? null;
        if (! $this->safeProviderFileIdentity($fileId) || ! $this->safeProviderFileIdentity($fileUniqueId)) {
            throw new TelegramPrivateMediaRejected('malformed_media_identity');
        }

        $reportedFileSize = $file['file_size'] ?? null;
        if ($reportedFileSize !== null && (! is_int($reportedFileSize) || $reportedFileSize < 1)) {
            throw new TelegramPrivateMediaRejected('malformed_media_size');
        }

        return new TelegramPrivateMediaInput(
            $sourceKind,
            RestrictedValue::fromString($fileId),
            RestrictedValue::fromString($fileUniqueId),
            $reportedFileSize,
        );
    }

    private function safeProviderFileIdentity(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 2048
            && preg_match('/[\x00-\x20\x7F]/', $value) !== 1;
    }

    /** @param array<string,mixed> $message */
    private function isPrivateActorChat(array $message, int $telegramUserId): bool
    {
        $chat = $message['chat'] ?? null;
        $from = $message['from'] ?? null;
        if (! is_array($chat)
            || array_is_list($chat)
            || ($chat['type'] ?? null) !== 'private'
            || ! is_array($from)
            || array_is_list($from)) {
            return false;
        }
        $chatId = $chat['id'] ?? null;
        $fromId = $from['id'] ?? null;

        return is_int($chatId)
            && is_int($fromId)
            && $chatId > 0
            && $fromId > 0
            && $chatId === $telegramUserId
            && $fromId === $telegramUserId;
    }

    private function updateRequestKey(string $botId, int $updateId, string $kind): string
    {
        return "telegram-update:{$botId}:{$updateId}:{$kind}";
    }
}
