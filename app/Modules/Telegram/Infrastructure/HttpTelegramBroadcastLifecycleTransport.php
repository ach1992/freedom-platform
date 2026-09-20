<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramBroadcastLifecycleTransport;
use App\Modules\Telegram\Application\TelegramBroadcastLifecycleMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Domain\TelegramBroadcastLifecycleAction;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

final readonly class HttpTelegramBroadcastLifecycleTransport implements TelegramBroadcastLifecycleTransport
{
    public function __construct(
        private Factory $http,
        private TelegramRuntimeConfiguration $configuration,
    ) {}

    public function mutate(TelegramBroadcastLifecycleMutationRequest $request): TelegramMutationResult
    {
        [$method, $payload] = $this->providerRequest($request);

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
            return $this->uncertain('tg_broadcast_lifecycle_transport_uncertain');
        }

        return $this->result($response, $request);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function providerRequest(TelegramBroadcastLifecycleMutationRequest $request): array
    {
        $payload = [
            'chat_id' => $request->recipientChatId,
            'message_id' => $request->messageId,
        ];

        return match ($request->action) {
            TelegramBroadcastLifecycleAction::Edit => [
                'editMessageCaption',
                array_filter([
                    ...$payload,
                    'caption' => $request->caption,
                    'reply_markup' => $request->inlineKeyboard?->providerPayload(),
                ], static fn (mixed $value): bool => $value !== null),
            ],
            TelegramBroadcastLifecycleAction::Buttons => [
                'editMessageReplyMarkup',
                [
                    ...$payload,
                    'reply_markup' => $request->inlineKeyboard?->providerPayload()
                        ?? ['inline_keyboard' => []],
                ],
            ],
            TelegramBroadcastLifecycleAction::Pin => [
                'pinChatMessage',
                [...$payload, 'disable_notification' => true],
            ],
            TelegramBroadcastLifecycleAction::Unpin => [
                'unpinChatMessage',
                $payload,
            ],
            TelegramBroadcastLifecycleAction::Delete => throw new \LogicException(
                'Broadcast delete must use the durable Telegram delivery authority.',
            ),
        };
    }

    private function result(
        Response $response,
        TelegramBroadcastLifecycleMutationRequest $request,
    ): TelegramMutationResult {
        $decoded = $response->json();
        if (! is_array($decoded)) {
            return $this->uncertain('tg_broadcast_lifecycle_response_unparseable');
        }
        if ($response->status() >= 300 && $response->status() <= 399) {
            return $this->uncertain('tg_broadcast_lifecycle_redirect_ambiguous');
        }

        if (($decoded['ok'] ?? null) === true) {
            if (! $response->successful()) {
                return $this->uncertain('tg_broadcast_lifecycle_response_ambiguous');
            }

            return $this->success($decoded['result'] ?? null, $request);
        }

        if (($decoded['ok'] ?? null) !== false) {
            return $this->uncertain('tg_broadcast_lifecycle_response_ambiguous');
        }
        if ($response->serverError()) {
            return $this->uncertain('tg_broadcast_lifecycle_server_error_uncertain');
        }

        $parameters = $decoded['parameters'] ?? null;
        $retryAfterPresent = is_array($parameters) && array_key_exists('retry_after', $parameters);
        $retryAfter = $retryAfterPresent ? $parameters['retry_after'] : null;
        if (is_int($retryAfter) && $retryAfter >= 1) {
            if ($retryAfter <= 86_400) {
                return new TelegramMutationResult(
                    TelegramMutationOutcome::RetryAfter,
                    'tg_broadcast_lifecycle_retry_after',
                    retryAfterSeconds: $retryAfter,
                );
            }

            return $this->uncertain('tg_broadcast_lifecycle_retry_unrepresentable');
        }
        if ($retryAfterPresent) {
            return $this->uncertain('tg_broadcast_lifecycle_retry_malformed');
        }

        $errorCode = $decoded['error_code'] ?? null;
        if ($response->status() === 429 || $errorCode === 429 || $errorCode === '429') {
            return $this->uncertain('tg_broadcast_lifecycle_retry_missing');
        }
        if (! is_int($errorCode) || $errorCode < 100 || $errorCode > 599) {
            return $this->uncertain('tg_broadcast_lifecycle_error_malformed');
        }
        if ($errorCode >= 500) {
            return $this->uncertain('tg_broadcast_lifecycle_'.$errorCode.'_uncertain');
        }
        if (! $response->clientError()) {
            return $this->uncertain('tg_broadcast_lifecycle_status_ambiguous');
        }

        return new TelegramMutationResult(
            TelegramMutationOutcome::DefinitiveFailure,
            'tg_broadcast_lifecycle_api_'.$errorCode,
        );
    }

    private function success(
        mixed $result,
        TelegramBroadcastLifecycleMutationRequest $request,
    ): TelegramMutationResult {
        if (in_array($request->action, [
            TelegramBroadcastLifecycleAction::Pin,
            TelegramBroadcastLifecycleAction::Unpin,
        ], true)) {
            if ($result !== true) {
                return $this->uncertain('tg_broadcast_lifecycle_success_ambiguous');
            }

            return new TelegramMutationResult(
                TelegramMutationOutcome::Success,
                'tg_broadcast_lifecycle_success',
            );
        }

        if (! is_array($result)
            || ! is_int($result['message_id'] ?? null)
            || $result['message_id'] !== $request->messageId
        ) {
            return $this->uncertain('tg_broadcast_lifecycle_identity_mismatch');
        }

        return new TelegramMutationResult(
            TelegramMutationOutcome::Success,
            'tg_broadcast_lifecycle_success',
        );
    }

    private function uncertain(string $code): TelegramMutationResult
    {
        return new TelegramMutationResult(TelegramMutationOutcome::UncertainResult, $code);
    }
}
