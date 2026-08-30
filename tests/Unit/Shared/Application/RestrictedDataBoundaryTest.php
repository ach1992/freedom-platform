<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application;

use App\Shared\Application\RestrictedValue;
use App\Shared\Application\SafeLogContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RestrictedDataBoundaryTest extends TestCase
{
    public function test_restricted_value_requires_an_explicit_reveal(): void
    {
        $value = RestrictedValue::fromString('synthetic-sensitive-value');

        self::assertSame('synthetic-sensitive-value', $value->reveal());
        self::assertSame('{}', json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function test_safe_log_context_accepts_flat_metadata_and_explicit_restricted_markers(): void
    {
        $restricted = RestrictedValue::fromString('synthetic-sensitive-value');
        $context = SafeLogContext::from([
            'correlation_id' => 'corr-1',
            'attempt' => 2,
            'restricted_value' => $restricted,
        ])->values();

        self::assertSame('corr-1', $context['correlation_id']);
        self::assertSame(2, $context['attempt']);
        self::assertSame($restricted, $context['restricted_value']);
    }

    public function test_safe_log_context_rejects_arbitrary_nested_payloads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Log context values must be scalar, null, or explicitly classified RestrictedData.');

        SafeLogContext::from([
            'provider_payload' => ['status' => 'ok'],
        ]);
    }
}
