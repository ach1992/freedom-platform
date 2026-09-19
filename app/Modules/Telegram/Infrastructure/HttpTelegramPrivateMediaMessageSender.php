<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaMessageSender;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Application\TelegramResolvedInlineKeyboardMarkup;
use App\Modules\Telegram\Application\TelegramResolvedPrivateMediaPresentation;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

final readonly class HttpTelegramPrivateMediaMessageSender implements TelegramPrivateMediaMessageSender
{
    public function __construct(
        private Factory $http,
        private TelegramRuntimeConfiguration $configuration,
    ) {}

    /** @requirement COM-001 ARCH-004 SEC-002 SEC-003 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
    public function send(
        int $recipientChatId,
        TelegramResolvedPrivateMediaPresentation $presentation,
        ?TelegramResolvedInlineKeyboardMarkup $inlineKeyboard = null,
    ): TelegramMutationResult {
        if ($recipientChatId < 1) {
            throw new InvalidArgumentException('Private Telegram media recipient identity is invalid.');
        }

        try {
            $response = $this->request($recipientChatId, $presentation, $inlineKeyboard);
        } catch (Throwable) {
            return $this->uncertain('telegram_transport_uncertain');
        }

        return $this->result($recipientChatId, $response);
    }

    private function request(
        int $recipientChatId,
        TelegramResolvedPrivateMediaPresentation $presentation,
        ?TelegramResolvedInlineKeyboardMarkup $inlineKeyboard,
    ): Response {
        $bytes = $presentation->revealBytesForProvider();
        $contentType = $presentation->contentType();
        $field = $contentType;
        $method = match ($contentType) {
            'photo' => 'sendPhoto',
            'video' => 'sendVideo',
            'document' => 'sendDocument',
            default => throw new InvalidArgumentException('Private Telegram media type is unsupported.'),
        };

        $payload = ['chat_id' => $recipientChatId];
        $caption = $presentation->captionForProvider();
        if ($caption !== '') {
            $payload['caption'] = $caption;
        }
        if ($contentType === 'document') {
            // Multipart scalar parts are strings; use the Bot API's explicit boolean literal.
            $payload['disable_content_type_detection'] = 'true';
        }
        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = json_encode(
                $inlineKeyboard->providerPayload(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return $this->http
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout($this->configuration->apiTimeoutSeconds)
            ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
            ->attach($field, $bytes, $presentation->filename())
            ->post(
                $this->configuration->apiBaseUrl.'/bot'.$this->configuration->botToken.'/'.$method,
                $payload,
            );
    }

    private function result(int $recipientChatId, Response $response): TelegramMutationResult
    {
        $decoded = $response->json();
        if (! is_array($decoded)) {
            return $this->uncertain('telegram_response_unparseable');
        }
        if ($response->status() >= 300 && $response->status() <= 399) {
            return $this->uncertain('telegram_redirect_ambiguous');
        }

        if (($decoded['ok'] ?? null) === true) {
            if (! $response->successful()) {
                return $this->uncertain('telegram_response_ambiguous');
            }

            $result = $decoded['result'] ?? null;
            $messageId = is_array($result) ? ($result['message_id'] ?? null) : null;
            $chat = is_array($result) ? ($result['chat'] ?? null) : null;
            $chatId = is_array($chat) ? ($chat['id'] ?? null) : null;
            if (! is_int($messageId) || $messageId < 1) {
                return $this->uncertain('telegram_success_identity_missing');
            }
            if (! is_int($chatId) || $chatId === 0) {
                return $this->uncertain('telegram_success_recipient_identity_missing');
            }
            if ($chatId !== $recipientChatId) {
                return $this->uncertain('telegram_success_recipient_mismatch');
            }

            return new TelegramMutationResult(
                TelegramMutationOutcome::Success,
                'telegram_success',
                messageId: $messageId,
            );
        }

        if (($decoded['ok'] ?? null) !== false) {
            return $this->uncertain('telegram_response_ambiguous');
        }
        if ($response->serverError()) {
            return $this->uncertain('telegram_http_server_error_uncertain');
        }

        $parameters = $decoded['parameters'] ?? null;
        $retryAfterPresent = is_array($parameters) && array_key_exists('retry_after', $parameters);
        $retryAfter = $retryAfterPresent ? $parameters['retry_after'] : null;
        if (is_int($retryAfter) && $retryAfter >= 1) {
            if ($retryAfter <= 86_400) {
                return new TelegramMutationResult(
                    TelegramMutationOutcome::RetryAfter,
                    'telegram_retry_after',
                    retryAfterSeconds: $retryAfter,
                );
            }

            return $this->uncertain('telegram_retry_after_unrepresentable');
        }
        if ($retryAfterPresent) {
            return $this->uncertain('telegram_retry_after_malformed');
        }

        $errorCode = $decoded['error_code'] ?? null;
        if ($response->status() === 429 || $errorCode === 429 || $errorCode === '429') {
            return $this->uncertain('telegram_retry_after_missing');
        }
        if (! is_int($errorCode) || $errorCode < 100 || $errorCode > 599) {
            return $this->uncertain('telegram_error_code_malformed');
        }
        if ($errorCode >= 500) {
            return $this->uncertain('telegram_api_error_'.$errorCode.'_uncertain');
        }
        if (! $response->clientError()) {
            return $this->uncertain('telegram_error_status_ambiguous');
        }

        return new TelegramMutationResult(
            TelegramMutationOutcome::DefinitiveFailure,
            'telegram_api_error_'.$errorCode,
        );
    }

    private function uncertain(string $code): TelegramMutationResult
    {
        return new TelegramMutationResult(TelegramMutationOutcome::UncertainResult, $code);
    }
}
