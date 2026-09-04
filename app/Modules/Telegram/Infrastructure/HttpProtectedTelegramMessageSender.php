<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

final readonly class HttpProtectedTelegramMessageSender implements ProtectedTelegramMessageSender
{
    /** @requirement SVC-002 SVC-014 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
    public function __construct(
        private Factory $http,
        private TelegramRuntimeConfiguration $configuration,
    ) {}

    public function send(int $telegramUserId, ProtectedTelegramPresentation $presentation): ProtectedTelegramSendResult
    {
        if ($telegramUserId < 1) {
            throw new InvalidArgumentException('Protected Telegram recipient identity is invalid.');
        }

        try {
            $response = $presentation->isText()
                ? $this->sendText($telegramUserId, $presentation)
                : $this->sendDocument($telegramUserId, $presentation);
        } catch (Throwable) {
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_transport_uncertain',
            );
        }

        return $this->resultFromResponse($response);
    }

    private function sendText(int $telegramUserId, ProtectedTelegramPresentation $presentation): Response
    {
        $text = $presentation->text();
        if ($text === '' || mb_strlen($text) > 4096) {
            throw new InvalidArgumentException('Protected Telegram message must contain 1-4096 characters.');
        }

        $payload = [
            'chat_id' => $telegramUserId,
            'text' => $text,
            'protect_content' => true,
            'link_preview_options' => ['is_disabled' => true],
        ];
        if ($presentation->hasCopyText()) {
            $payload['reply_markup'] = [
                'inline_keyboard' => [[[
                    'text' => $presentation->copyButtonText(),
                    'copy_text' => ['text' => $presentation->copyText()->reveal()],
                ]]],
            ];
        }

        return $this->http
            ->asJson()
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout($this->configuration->apiTimeoutSeconds)
            ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
            ->post(
                $this->configuration->apiBaseUrl.'/bot'.$this->configuration->botToken.'/sendMessage',
                $payload,
            );
    }

    private function sendDocument(int $telegramUserId, ProtectedTelegramPresentation $presentation): Response
    {
        $document = $presentation->documentContents();
        if ($document === '' || strlen($document) > 1_048_576) {
            throw new InvalidArgumentException('Protected Telegram document exceeds the local safety bound.');
        }

        return $this->http
            ->acceptJson()
            ->withoutRedirecting()
            ->timeout($this->configuration->apiTimeoutSeconds)
            ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
            ->attach('document', $document, $presentation->documentFilename())
            ->post(
                $this->configuration->apiBaseUrl.'/bot'.$this->configuration->botToken.'/sendDocument',
                [
                    'chat_id' => $telegramUserId,
                    'caption' => $presentation->caption(),
                    // Multipart scalar parts are strings; use the Bot API's explicit boolean literal.
                    'protect_content' => 'true',
                    'disable_content_type_detection' => 'true',
                ],
            );
    }

    private function resultFromResponse(Response $response): ProtectedTelegramSendResult
    {
        $decoded = $response->json();
        if (! is_array($decoded)) {
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_response_unparseable',
            );
        }

        if ($response->status() >= 300 && $response->status() <= 399) {
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_redirect_ambiguous',
            );
        }

        if (($decoded['ok'] ?? null) === true) {
            if (! $response->successful()) {
                return new ProtectedTelegramSendResult(
                    ProtectedTelegramSendOutcome::UncertainResult,
                    'telegram_response_ambiguous',
                );
            }

            $result = $decoded['result'] ?? null;
            $messageId = is_array($result) ? ($result['message_id'] ?? null) : null;
            if (! is_int($messageId) || $messageId < 1) {
                return new ProtectedTelegramSendResult(
                    ProtectedTelegramSendOutcome::UncertainResult,
                    'telegram_success_identity_missing',
                );
            }

            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: $messageId,
            );
        }

        if (($decoded['ok'] ?? null) !== false) {
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_response_ambiguous',
            );
        }

        $parameters = $decoded['parameters'] ?? null;
        $retryAfterPresent = is_array($parameters) && array_key_exists('retry_after', $parameters);
        $retryAfter = $retryAfterPresent ? $parameters['retry_after'] : null;
        if (is_int($retryAfter) && $retryAfter >= 1) {
            if ($retryAfter <= 86_400) {
                return new ProtectedTelegramSendResult(
                    ProtectedTelegramSendOutcome::RetryAfter,
                    'telegram_retry_after',
                    retryAfterSeconds: $retryAfter,
                );
            }

            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_retry_after_unrepresentable',
            );
        }

        if ($retryAfterPresent) {
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_retry_after_malformed',
            );
        }

        $errorCode = $decoded['error_code'] ?? null;
        if ($response->status() === 429 || $errorCode === 429 || $errorCode === '429') {
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_retry_after_missing',
            );
        }

        if (! is_int($errorCode) || $errorCode < 100 || $errorCode > 599) {
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_error_code_malformed',
            );
        }

        return new ProtectedTelegramSendResult(
            ProtectedTelegramSendOutcome::DefinitiveFailure,
            'telegram_api_error_'.$errorCode,
        );
    }
}
