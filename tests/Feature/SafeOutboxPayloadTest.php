<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Application\SafeOutboxPayload;
use InvalidArgumentException;
use Tests\TestCase;

final class SafeOutboxPayloadTest extends TestCase
{
    /** @requirement PAY-003 OPS-001 SEC-008 */
    public function test_payload_larger_than_64_kib_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Outbox payload exceeds the 64 KiB safety limit.');

        new SafeOutboxPayload(['body' => str_repeat('a', 65_536)]);
    }
}
