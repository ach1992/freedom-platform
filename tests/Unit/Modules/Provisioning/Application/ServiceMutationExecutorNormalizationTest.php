<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Provisioning\Application;

use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ServiceMutationExecutorNormalizationTest extends TestCase
{
    public function test_safe_message_is_sanitized_and_bounded_to_persisted_schema_limit(): void
    {
        $reflection = new ReflectionClass(ServiceMutationExecutor::class);
        $executor = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('safeMessage');

        $bounded = $method->invoke($executor, str_repeat('x', 600));
        self::assertIsString($bounded);
        self::assertSame(512, mb_strlen($bounded));
        self::assertSame(str_repeat('x', 512), $bounded);

        $sanitized = $method->invoke($executor, "  hello\nworld\x7F  ");
        self::assertSame('hello world', $sanitized);

        $fallback = $method->invoke($executor, "\n\t\x7F");
        self::assertSame('Service mutation completed without a provider message.', $fallback);
    }

    public function test_result_code_is_normalized_to_persisted_safe_shape(): void
    {
        $reflection = new ReflectionClass(ServiceMutationExecutor::class);
        $executor = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resultCode');

        self::assertSame('panel.ok-1', $method->invoke($executor, '  PANEL.OK-1  '));
        self::assertSame('normalized_panel_result', $method->invoke($executor, 'contains unsafe spaces'));
        self::assertSame('normalized_panel_result', $method->invoke($executor, str_repeat('x', 65)));
    }
}
