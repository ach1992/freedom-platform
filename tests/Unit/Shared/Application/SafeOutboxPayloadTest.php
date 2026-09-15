<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application;

use App\Shared\Application\RestrictedValue;
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

    public function test_restricted_marker_is_rejected_even_under_generic_nested_keys(): void
    {
        $restricted = RestrictedValue::fromString('synthetic-sensitive-value');

        try {
            new SafeOutboxPayload([
                'data' => [
                    'value' => $restricted,
                ],
            ]);
            self::fail('Restricted data unexpectedly crossed the durable Outbox boundary.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Restricted data is forbidden in outbox payloads.', $exception->getMessage());
            self::assertStringNotContainsString('synthetic-sensitive-value', $exception->getMessage());
        }
    }

    public function test_sensitive_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SafeOutboxPayload(['provider' => ['access_token' => 'do-not-queue']]);
    }
}
