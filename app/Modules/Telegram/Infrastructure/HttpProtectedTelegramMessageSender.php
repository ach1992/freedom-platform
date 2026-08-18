<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use Throwable;

final readonly class HttpProtectedTelegramMessageSender implements ProtectedTelegramMessageSender
{
    /** @requirement SVC-002 SVC-014 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
    public function __construct(
        private Factory $http,
        private TelegramRuntimeConfiguration $configuration,
    ) {}

    public function send(int $telegramUserId, string $text): ProtectedTelegramSendResult
    {
        if ($telegramUserId < 1) {
            throw new InvalidArgumentException('Protected Telegram recipient identity is invalid.');
        }
        if ($text === '' || mb_strlen($text) > 4096) {
            throw new InvalidArgumentException('Protected Telegram message must contain 1-4096 characters.');
        }

        try {
            // Deliberately one network attempt: no retry and no redirect follow after the
            // provider boundary, because either can duplicate or disclose restricted material.
            $response = $this->http
                ->asJson()
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout($this->configuration->apiTimeoutSeconds)
                ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
                ->post(
                    $this->configuration->apiBaseUrl.'/bot'.$this->configuration->botToken.'/sendMessage',
                    [
                        'chat_id' => $telegramUserId,
                        'text' => $text,
                        'protect_content' => true,
                        'link_preview_options' => ['is_disabled' => true],
                    ],
                );
        } catch (Throwable) {
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_transport_uncertain',
            );
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_response_unparseable',
            );
        }

        if (($decoded['ok'] ?? null) === true) {
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
        $retryAfter = is_array($parameters) ? ($parameters['retry_after'] ?? null) : null;
        if (is_int($retryAfter) && $retryAfter >= 1) {
            if ($retryAfter <= 86_400) {
                return new ProtectedTelegramSendResult(
                    ProtectedTelegramSendOutcome::RetryAfter,
                    'telegram_retry_after',
                    retryAfterSeconds: $retryAfter,
                );
            }

            // The bounded durable representation cannot encode this exact provider delay.
            // Quarantine instead of silently converting it into a non-blocking rejection.
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_retry_after_unrepresentable',
            );
        }

        $errorCode = $decoded['error_code'] ?? null;
        if ($errorCode === 429) {
            // Flood-control rejection without a usable exact delay is not safe to treat as
            // definitive: doing so could admit another restricted delivery too early.
            return new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_retry_after_missing',
            );
        }

        $resultCode = is_int($errorCode) && $errorCode >= 100 && $errorCode <= 599
            ? 'telegram_api_error_'.$errorCode
            : 'telegram_api_rejected';

        return new ProtectedTelegramSendResult(
            ProtectedTelegramSendOutcome::DefinitiveFailure,
            $resultCode,
        );
    }
}
