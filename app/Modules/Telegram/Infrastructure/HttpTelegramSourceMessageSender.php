<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramSourceMessageSender;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Application\TelegramResolvedInlineKeyboardMarkup;
use App\Modules\Telegram\Application\TelegramResolvedSourceMessagePresentation;
use App\Modules\Telegram\Application\TelegramSourceMessageMode;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

final readonly class HttpTelegramSourceMessageSender implements TelegramSourceMessageSender
{
    public function __construct(
        private Factory $http,
        private TelegramRuntimeConfiguration $configuration,
    ) {}

    public function send(
        int $recipientChatId,
        TelegramResolvedSourceMessagePresentation $source,
        ?TelegramResolvedInlineKeyboardMarkup $inlineKeyboard = null,
    ): TelegramMutationResult {
        if ($recipientChatId < 1) {
            throw new InvalidArgumentException('Telegram source-message recipient chat identity must be positive.');
        }
        if ($source->mode === TelegramSourceMessageMode::Forward && $inlineKeyboard !== null) {
            throw new InvalidArgumentException('Telegram forwardMessage does not support authored reply markup.');
        }

        $method = $source->mode === TelegramSourceMessageMode::Forward
            ? 'forwardMessage'
            : 'copyMessage';
        $payload = [
            'chat_id' => $recipientChatId,
            'from_chat_id' => $source->sourceChatId,
            'message_id' => $source->sourceMessageId,
        ];
        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = $inlineKeyboard->providerPayload();
        }

        try {
            $response = $this->http
                ->asJson()
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout($this->configuration->apiTimeoutSeconds)
                ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
                ->post(
                    $this->configuration->apiBaseUrl.'/bot'.$this->configuration->botToken.'/'.$method,
                    $payload,
                );
        } catch (Throwable) {
            return new TelegramMutationResult(
                TelegramMutationOutcome::UncertainResult,
                'telegram_source_message_transport_uncertain',
            );
        }

        return $this->result($response, $recipientChatId, $source->mode);
    }

    private function result(
        Response $response,
        int $recipientChatId,
        TelegramSourceMessageMode $mode,
    ): TelegramMutationResult {
        $decoded = $response->json();
        if (! is_array($decoded)) {
            return $this->uncertain('telegram_source_message_response_unparseable');
        }
        if ($response->status() >= 300 && $response->status() <= 399) {
            return $this->uncertain('telegram_source_message_redirect_ambiguous');
        }

        if (($decoded['ok'] ?? null) === true) {
            if (! $response->successful()) {
                return $this->uncertain('telegram_source_message_response_ambiguous');
            }

            return $this->success($decoded['result'] ?? null, $recipientChatId, $mode);
        }

        if (($decoded['ok'] ?? null) !== false) {
            return $this->uncertain('telegram_source_message_response_ambiguous');
        }
        if ($response->serverError()) {
            return $this->uncertain('telegram_source_message_http_server_error_uncertain');
        }

        $parameters = $decoded['parameters'] ?? null;
        $retryAfterPresent = is_array($parameters) && array_key_exists('retry_after', $parameters);
        $retryAfter = $retryAfterPresent ? $parameters['retry_after'] : null;
        if (is_int($retryAfter) && $retryAfter >= 1) {
            if ($retryAfter <= 86_400) {
                return new TelegramMutationResult(
                    TelegramMutationOutcome::RetryAfter,
                    'telegram_source_message_retry_after',
                    retryAfterSeconds: $retryAfter,
                );
            }

            return $this->uncertain('telegram_source_message_retry_after_unrepresentable');
        }
        if ($retryAfterPresent) {
            return $this->uncertain('telegram_source_message_retry_after_malformed');
        }

        $errorCode = $decoded['error_code'] ?? null;
        if ($response->status() === 429 || $errorCode === 429 || $errorCode === '429') {
            return $this->uncertain('telegram_source_message_retry_after_missing');
        }
        if (! is_int($errorCode) || $errorCode < 100 || $errorCode > 599) {
            return $this->uncertain('telegram_source_message_error_code_malformed');
        }
        if ($errorCode >= 500) {
            return $this->uncertain('telegram_source_message_api_error_'.$errorCode.'_uncertain');
        }
        if (! $response->clientError()) {
            return $this->uncertain('telegram_source_message_error_status_ambiguous');
        }

        return new TelegramMutationResult(
            TelegramMutationOutcome::DefinitiveFailure,
            'telegram_source_message_api_error_'.$errorCode,
        );
    }

    private function success(
        mixed $result,
        int $recipientChatId,
        TelegramSourceMessageMode $mode,
    ): TelegramMutationResult {
        if (! is_array($result)) {
            return $this->uncertain('telegram_source_message_success_identity_missing');
        }

        $messageId = $result['message_id'] ?? null;
        if (! is_int($messageId) || $messageId < 1) {
            return $this->uncertain('telegram_source_message_success_identity_missing');
        }

        if ($mode === TelegramSourceMessageMode::Copy) {
            return new TelegramMutationResult(
                TelegramMutationOutcome::Success,
                'telegram_source_message_success',
                messageId: $messageId,
            );
        }

        $chat = $result['chat'] ?? null;
        $chatId = is_array($chat) ? ($chat['id'] ?? null) : null;
        if (! is_int($chatId) || $chatId !== $recipientChatId) {
            return $this->uncertain('telegram_source_message_success_recipient_mismatch');
        }

        return new TelegramMutationResult(
            TelegramMutationOutcome::Success,
            'telegram_source_message_success',
            messageId: $messageId,
        );
    }

    private function uncertain(string $code): TelegramMutationResult
    {
        return new TelegramMutationResult(TelegramMutationOutcome::UncertainResult, $code);
    }
}
