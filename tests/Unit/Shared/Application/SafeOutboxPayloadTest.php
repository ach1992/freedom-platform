<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application;

use App\Shared\Application\SafeOutboxPayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SafeOutboxPayloadTest extends TestCase
{
    public function test_equivalent_maps_have_a_stable_hash(): void
    {
        $first = new SafeOutboxPayload(['order_id' => '1', 'amount_irr' => 1000]);
        $second = new SafeOutboxPayload(['amount_irr' => 1000, 'order_id' => '1']);

        self::assertSame($first->hash(), $second->hash());
    }

    public function test_sensitive_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SafeOutboxPayload(['provider' => ['access_token' => 'do-not-queue']]);
    }
}
