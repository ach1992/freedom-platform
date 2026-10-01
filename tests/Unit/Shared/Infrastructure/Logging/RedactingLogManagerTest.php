<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\RedactingLogManager;
use Illuminate\Log\LogManager;
use Tests\TestCase;

final class RedactingLogManagerTest extends TestCase
{
    public function test_emergency_fallback_redacts_sensitive_message_and_context_values(): void
    {
        $path = storage_path('logs/emergency-redaction-test.log');
        @unlink($path);

        $this->app['config']->set('logging.channels.emergency', ['path' => $path]);
        $this->app['config']->set('logging.channels.forced_failure', [
            'driver' => 'monolog',
            'handler' => 'Tests\\MissingEmergencyLogHandler',
        ]);

        $manager = $this->app->make('log');

        self::assertInstanceOf(RedactingLogManager::class, $manager);
        self::assertInstanceOf(LogManager::class, $manager);

        try {
            $manager->channel('forced_failure')->error(
                'synthetic token=message-secret',
                ['api_token' => 'context-secret'],
            );

            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringContainsString('[REDACTED]', $contents);
            self::assertStringNotContainsString('message-secret', $contents);
            self::assertStringNotContainsString('context-secret', $contents);
        } finally {
            $manager->forgetChannel('forced_failure');
            @unlink($path);
        }
    }
}
