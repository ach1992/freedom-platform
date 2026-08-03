<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain;

use App\Shared\Domain\IdempotencyKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyTest extends TestCase
{
    /** @requirement PAY-003 ARCH-004 QUA-003 */
    public function test_same_parts_produce_same_key_and_order_matters(): void
    {
        $first = (string) IdempotencyKey::fromParts('payment.capture', 'provider-a', 'txn-42');
        $duplicate = (string) IdempotencyKey::fromParts('payment.capture', 'provider-a', 'txn-42');
        $reordered = (string) IdempotencyKey::fromParts('payment.capture', 'txn-42', 'provider-a');

        self::assertSame($first, $duplicate);
        self::assertNotSame($first, $reordered);
    }

    public function test_empty_parts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdempotencyKey::fromParts('payment.capture', '');
    }

    public function test_length_prefixing_prevents_delimiter_collisions(): void
    {
        $first = (string) IdempotencyKey::fromParts('payment.capture', "a\x1Fb", 'c');
        $second = (string) IdempotencyKey::fromParts('payment.capture', 'a', "b\x1Fc");

        self::assertNotSame($first, $second);
    }
}
