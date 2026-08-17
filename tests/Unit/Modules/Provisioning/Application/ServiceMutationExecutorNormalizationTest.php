<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Provisioning\Application;

use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

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

    public function test_provider_boundary_preflight_happens_before_remote_effect_claim(): void
    {
        $source = $this->classSource(ServiceMutationExecutor::class);

        $preflight = strpos($source, '$connection = $this->database->connection();');
        $claim = strpos($source, '$operation = $this->claim($operation);');
        self::assertIsInt($preflight);
        self::assertIsInt($claim);
        self::assertLessThan($claim, $preflight);
    }

    public function test_stale_provider_boundary_is_fail_closed_without_entering_remote_effect(): void
    {
        $source = $this->classSource(ServiceMutationExecutor::class);

        self::assertStringContainsString('private function markProviderBoundary(object $locator, ServiceMutationType $type): ?object', $source);
        self::assertStringContainsString("'stale_service_at_provider_boundary'", $source);
        self::assertStringContainsString('if ($boundaryOperation === null)', $source);
        self::assertStringContainsString('if ($state !== ProvisioningState::NeedsReview)', $source);
    }

    public function test_provider_outcome_mapping_is_explicit_for_terminal_and_uncertain_failures(): void
    {
        $source = $this->classSource(ServiceMutationExecutor::class);

        self::assertStringContainsString('PanelOperationOutcome::Success => ProvisioningState::Succeeded', $source);
        self::assertStringContainsString('PanelOperationOutcome::DefinitiveFailure => ProvisioningState::FailedFinal', $source);
        self::assertStringContainsString('PanelOperationOutcome::RetryableFailure, PanelOperationOutcome::UncertainResult => ProvisioningState::UncertainRemoteResult', $source);
    }

    public function test_uncertain_and_review_states_remain_unresolved_queue_blockers(): void
    {
        $reflection = new ReflectionClass(ServiceMutationQueueService::class);
        self::assertSame(['succeeded', 'failed_final', 'compensated'], $reflection->getConstant('TERMINAL_STATES'));

        $source = $this->classSource(ServiceMutationQueueService::class);
        self::assertStringContainsString("->whereNotIn('state', self::TERMINAL_STATES)", $source);
    }

    /** @param class-string $class */
    private function classSource(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        if (! is_string($file)) {
            throw new RuntimeException('Reflected class source file is unavailable.');
        }

        $source = file_get_contents($file);
        if (! is_string($source)) {
            throw new RuntimeException('Reflected class source cannot be read.');
        }

        return $source;
    }
}
