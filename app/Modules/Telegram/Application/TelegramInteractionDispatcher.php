<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Modules\Telegram\Domain\TelegramInteractionDispatchStatus;
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
        $text = $message['text'] ?? null;
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
        ));

        return new TelegramInteractionDispatchResult(TelegramInteractionDispatchStatus::Handled, $binding->sessionPublicId);
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
        ));
        $this->callbacks->complete($callback->publicId);

        return new TelegramInteractionDispatchResult(
            TelegramInteractionDispatchStatus::Handled,
            $callback->sessionPublicId,
            $callback->publicId,
        );
    }

    private function updateRequestKey(string $botId, int $updateId, string $kind): string
    {
        return "telegram-update:{$botId}:{$updateId}:{$kind}";
    }
}
