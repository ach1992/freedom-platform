<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramRuntime;
use InvalidArgumentException;

final readonly class TelegramRuntimeConfiguration implements ProtectedTelegramDeliveryRuntime, TelegramDeliveryRuntime, TelegramRuntime
{
    /** @requirement ONB-001 SEC-001 SEC-009 OPS-003 */
    public function __construct(
        public string $botToken,
        public string $botId,
        public string $webhookSecret,
        public string $webhookUrl,
        public int $maximumBodyBytes,
        public string $queue,
        public int $processingLeaseSeconds,
        public string $apiBaseUrl,
        public int $apiTimeoutSeconds,
    ) {
        if (preg_match('/\A([1-9][0-9]{5,19}):[A-Za-z0-9_-]{20,}\z/', $botToken, $matches) !== 1) {
            throw new InvalidArgumentException('Telegram bot token is not configured correctly.');
        }

        if (! hash_equals($matches[1], $botId)) {
            throw new InvalidArgumentException('Telegram bot identifier does not match the bot token.');
        }

        if (preg_match('/\A[A-Za-z0-9_-]{32,256}\z/', $webhookSecret) !== 1) {
            throw new InvalidArgumentException('Telegram webhook secret must contain 32-256 safe characters.');
        }

        if (filter_var($webhookUrl, FILTER_VALIDATE_URL) === false || ! str_starts_with($webhookUrl, 'https://')) {
            throw new InvalidArgumentException('Telegram webhook URL must be a valid HTTPS URL.');
        }

        if ($maximumBodyBytes < 1 || $maximumBodyBytes > 4_194_304) {
            throw new InvalidArgumentException('Telegram webhook body limit must be between 1 byte and 4 MiB.');
        }

        if (preg_match('/\A[a-zA-Z0-9._-]{1,64}\z/', $queue) !== 1) {
            throw new InvalidArgumentException('Telegram queue name is invalid.');
        }

        if ($processingLeaseSeconds < 30 || $processingLeaseSeconds > 3_600) {
            throw new InvalidArgumentException('Telegram processing lease must be between 30 and 3600 seconds.');
        }

        if ($apiBaseUrl !== 'https://api.telegram.org') {
            throw new InvalidArgumentException('Telegram API base URL is fixed to the official HTTPS endpoint.');
        }

        if ($apiTimeoutSeconds < 1 || $apiTimeoutSeconds > 60) {
            throw new InvalidArgumentException('Telegram API timeout must be between 1 and 60 seconds.');
        }
    }

    public function botId(): string
    {
        return $this->botId;
    }

    public function webhookUrl(): string
    {
        return $this->webhookUrl;
    }

    public function webhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function maximumBodyBytes(): int
    {
        return $this->maximumBodyBytes;
    }

    public function queue(): string
    {
        return $this->queue;
    }

    public function processingLeaseSeconds(): int
    {
        return $this->processingLeaseSeconds;
    }

    /** @param array<string, mixed> $configuration */
    public static function fromArray(array $configuration, string $applicationUrl): self
    {
        $token = self::requiredString($configuration['bot_token'] ?? null, 'Telegram bot token');
        $separator = strpos($token, ':');
        $botId = $separator === false ? '' : substr($token, 0, $separator);
        $path = trim(self::requiredString($configuration['webhook_path'] ?? null, 'Telegram webhook path'), '/');

        return new self(
            botToken: $token,
            botId: $botId,
            webhookSecret: self::requiredString($configuration['webhook_secret'] ?? null, 'Telegram webhook secret'),
            webhookUrl: rtrim($applicationUrl, '/').'/'.$path,
            maximumBodyBytes: self::integer($configuration['max_body_bytes'] ?? null, 'Telegram maximum body bytes'),
            queue: self::requiredString($configuration['queue'] ?? null, 'Telegram queue'),
            processingLeaseSeconds: self::integer($configuration['processing_lease_seconds'] ?? null, 'Telegram processing lease'),
            apiBaseUrl: self::requiredString($configuration['api_base_url'] ?? null, 'Telegram API base URL'),
            apiTimeoutSeconds: self::integer($configuration['api_timeout_seconds'] ?? null, 'Telegram API timeout'),
        );
    }

    private static function requiredString(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($label.' is required.');
        }

        return trim($value);
    }

    private static function integer(mixed $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException($label.' must be an integer.');
        }

        return (int) $value;
    }
}
