<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramBotApi;
use App\Modules\Telegram\Application\TelegramWebhookInfo;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

final readonly class HttpTelegramBotApi implements TelegramBotApi
{
    /** @requirement INS-001 SEC-001 SEC-005 SEC-008 SEC-009 INT-001 */
    public function __construct(
        private Factory $http,
        private TelegramRuntimeConfiguration $configuration,
    ) {
    }

    public function configureWebhook(string $url, string $secretToken, bool $dropPendingUpdates): TelegramWebhookInfo
    {
        if (! hash_equals($this->configuration->webhookUrl, $url)
            || ! hash_equals($this->configuration->webhookSecret, $secretToken)
        ) {
            throw new RuntimeException('Telegram webhook configuration does not match runtime policy.');
        }

        $configured = $this->request('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'drop_pending_updates' => $dropPendingUpdates,
            'allowed_updates' => [
                'message',
                'edited_message',
                'callback_query',
                'inline_query',
                'chosen_inline_result',
                'my_chat_member',
                'chat_member',
                'chat_join_request',
            ],
        ]);

        if ($configured !== true) {
            throw new RuntimeException('Telegram Bot API did not accept the webhook.');
        }

        return $this->webhookInfo();
    }

    public function webhookInfo(): TelegramWebhookInfo
    {
        $result = $this->request('getWebhookInfo');

        if (! is_array($result)) {
            throw new RuntimeException('Telegram Bot API returned invalid webhook information.');
        }

        $url = $result['url'] ?? null;
        $pending = $result['pending_update_count'] ?? 0;

        return new TelegramWebhookInfo(
            configured: is_string($url) && $url !== '',
            targetsExpectedUrl: is_string($url) && hash_equals($this->configuration->webhookUrl, $url),
            pendingUpdateCount: is_int($pending) && $pending >= 0 ? $pending : 0,
            lastErrorPresent: isset($result['last_error_date']) || isset($result['last_error_message']),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return mixed
     */
    private function request(string $method, array $payload = []): mixed
    {
        try {
            $response = $this->http
                ->asJson()
                ->acceptJson()
                ->timeout($this->configuration->apiTimeoutSeconds)
                ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
                ->retry(2, 200, throw: false)
                ->post($this->configuration->apiBaseUrl.'/bot'.$this->configuration->botToken.'/'.$method, $payload);

            if (! $response->successful()) {
                throw new RuntimeException('Telegram Bot API returned an unsuccessful response.');
            }

            $decoded = $response->json();

            if (! is_array($decoded) || ($decoded['ok'] ?? null) !== true || ! array_key_exists('result', $decoded)) {
                throw new RuntimeException('Telegram Bot API returned an invalid response.');
            }

            return $decoded['result'];
        } catch (Throwable) {
            throw new RuntimeException('Telegram Bot API request failed.');
        }
    }
}
