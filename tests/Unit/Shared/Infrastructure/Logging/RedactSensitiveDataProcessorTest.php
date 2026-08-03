<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\RedactSensitiveDataProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RedactSensitiveDataProcessorTest extends TestCase
{
    public function test_it_recursively_redacts_sensitive_keys(): void
    {
        $result = (new RedactSensitiveDataProcessor)->sanitize([
            'order_id' => 'order-1',
            'provider' => [
                'api_token' => 'do-not-log',
                'status' => 'healthy',
            ],
            'subscription_link' => 'https://secret.example/token',
        ]);

        self::assertSame('order-1', $result['order_id']);
        self::assertSame('[REDACTED]', $result['provider']['api_token']);
        self::assertSame('healthy', $result['provider']['status']);
        self::assertSame('[REDACTED]', $result['subscription_link']);
    }

    public function test_it_handles_numeric_keys_and_sanitizes_messages_and_throwables(): void
    {
        $processor = new RedactSensitiveDataProcessor;
        $result = $processor->sanitize([
            0 => ['api_token' => 'secret-value'],
            'exception' => new RuntimeException('password=do-not-log', 17),
        ]);

        self::assertSame('[REDACTED]', $result[0]['api_token']);
        self::assertSame(RuntimeException::class, $result['exception']['class']);
        self::assertSame('17', $result['exception']['code']);
        self::assertSame(
            'request token=[REDACTED] Bearer [REDACTED]',
            $processor->sanitizeMessage('request token=abc Bearer xyz'),
        );
    }
}
