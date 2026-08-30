<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

final readonly class HttpTelegramMutationTransport implements TelegramMutationTransport
{
    public function __construct(
        private Factory $http,
        private TelegramRuntimeConfiguration $configuration,
    ) {}

    /** @requirement ARCH-004 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
    public function mutate(TelegramMutationRequest $request): TelegramMutationResult
    {
        try {
            $response = $this->http
                ->asJson()
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout($this->configuration->apiTimeoutSeconds)
                ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
                ->post($this->url($request->action), $this->payload($request));
        } catch (Throwable) {
            return new TelegramMutationResult(
                TelegramMutationOutcome::UncertainResult,
                'telegram_transport_uncertain',
            );
        }

        return $this->result($request, $response);
    }

    private function url(TelegramDeliveryAction $action): string
    {
        $method = match ($action) {
            TelegramDeliveryAction::Send => 'sendMessage',
            TelegramDeliveryAction::Edit => 'editMessageText',
            TelegramDeliveryAction::Delete => 'deleteMessage',
        };

        return $this->configuration->apiBaseUrl.'/bot'.$this->configuration->botToken.'/'.$method;
    }

    /** @return array<string, mixed> */
    private function payload(TelegramMutationRequest $request): array
    {
        $payload = match ($request->action) {
            TelegramDeliveryAction::Send => [
                'chat_id' => $request->recipientChatId,
                'text' => $request->presentation?->text(),
                'link_preview_options' => ['is_disabled' => true],
            ],
            TelegramDeliveryAction::Edit => [
                'chat_id' => $request->recipientChatId,
                'message_id' => $request->targetMessageId,
                'text' => $request->presentation?->text(),
                'link_preview_options' => ['is_disabled' => true],
            ],
            TelegramDeliveryAction::Delete => [
                'chat_id' => $request->recipientChatId,
                'message_id' => $request->targetMessageId,
            ],
        };

        if ($request->inlineKeyboard !== null) {
            $payload['reply_markup'] = $request->inlineKeyboard->providerPayload();
        }

        return $payload;
    }

    private function result(TelegramMutationRequest $request, Response $response): TelegramMutationResult
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

            return $this->success($request, $decoded['result'] ?? null);
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

    private function success(TelegramMutationRequest $request, mixed $result): TelegramMutationResult
    {
        if ($request->action === TelegramDeliveryAction::Delete) {
            return $result === true
                ? new TelegramMutationResult(TelegramMutationOutcome::Success, 'telegram_success')
                : $this->uncertain('telegram_delete_success_ambiguous');
        }

        $messageId = is_array($result) ? ($result['message_id'] ?? null) : null;
        if (! is_int($messageId) || $messageId < 1) {
            return $this->uncertain('telegram_success_identity_missing');
        }
        $chat = is_array($result) ? ($result['chat'] ?? null) : null;
        $chatId = is_array($chat) ? ($chat['id'] ?? null) : null;
        if (! is_int($chatId) || $chatId === 0) {
            return $this->uncertain('telegram_success_recipient_identity_missing');
        }
        if ($chatId !== $request->recipientChatId) {
            return $this->uncertain('telegram_success_recipient_mismatch');
        }

        if ($request->action === TelegramDeliveryAction::Edit && $messageId !== $request->targetMessageId) {
            return $this->uncertain('telegram_edit_target_mismatch');
        }

        return new TelegramMutationResult(
            TelegramMutationOutcome::Success,
            'telegram_success',
            messageId: $messageId,
        );
    }

    private function uncertain(string $code): TelegramMutationResult
    {
        return new TelegramMutationResult(TelegramMutationOutcome::UncertainResult, $code);
    }
}
