<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

final class QueueSafetyConfigurationTest extends TestCase
{
    public function test_redis_retry_after_exceeds_every_supervisor_worker_timeout(): void
    {
        $template = file_get_contents(base_path('deploy/supervisor/freedom-platform.conf'));

        if (! is_string($template) || preg_match_all('/--timeout=(\d+)/', $template, $matches) < 1) {
            throw new RuntimeException('Supervisor worker timeouts could not be inspected.');
        }

        $workerTimeouts = array_map('intval', $matches[1]);
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        self::assertGreaterThan(max($workerTimeouts), $retryAfter);
    }
}
