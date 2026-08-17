<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Provisioning\Application;

use App\Modules\Provisioning\Application\ServiceMutationOutboxHandler;
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Application\ServiceMutationRecoveryService;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Providers\FoundationServiceProvider;
use App\Shared\Application\OutboxDispatchOutcome;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class ServiceMutationOutboxContractTest extends TestCase
{
    public function test_queue_publishes_one_safe_transactional_outbox_event_after_replay_gate(): void
    {
        self::assertSame('provisioning.service_mutation.requested', ServiceMutationQueueService::OUTBOX_EVENT_TYPE);
        self::assertSame('provisioning_operation', ServiceMutationQueueService::OUTBOX_AGGREGATE_TYPE);

        $source = $this->classSource(ServiceMutationQueueService::class);
        $replayLookup = strpos($source, '$replayed = $this->operationByRequestHash');
        $replayReturn = strpos($source, 'return $this->receipt($service, $replayed, true);');
        $publish = strpos($source, '$this->outbox->publish(');
        $transaction = strpos($source, '->transaction(function (Connection $connection)');
        self::assertIsInt($replayLookup);
        self::assertIsInt($replayReturn);
        self::assertIsInt($publish);
        self::assertIsInt($transaction);
        self::assertLessThan($publish, $replayLookup);
        self::assertLessThan($publish, $replayReturn);
        self::assertLessThan($publish, $transaction);
        self::assertSame(1, substr_count($source, '$this->outbox->publish('));
        self::assertStringContainsString("'provisioning_operation_public_id' => \$operation->public_id", $source);
    }

    public function test_handler_maps_uncertain_provider_state_to_non_retrying_outbox_outcome(): void
    {
        $reflection = new ReflectionClass(ServiceMutationOutboxHandler::class);
        $handler = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('outcomeForState');

        self::assertSame(
            OutboxDispatchOutcome::UncertainResult,
            $method->invoke($handler, ProvisioningState::UncertainRemoteResult),
        );
        self::assertSame(
            OutboxDispatchOutcome::Success,
            $method->invoke($handler, ProvisioningState::Succeeded),
        );
        self::assertSame(
            OutboxDispatchOutcome::DefinitiveFailure,
            $method->invoke($handler, ProvisioningState::NeedsReview),
        );
        self::assertSame(
            OutboxDispatchOutcome::RetryableFailure,
            $method->invoke($handler, ProvisioningState::RetryScheduled),
        );
    }

    public function test_running_recovery_distinguishes_pre_boundary_retry_from_post_boundary_uncertainty(): void
    {
        $source = $this->classSource(ServiceMutationRecoveryService::class);

        self::assertStringContainsString('private const RUNNING_STALE_AFTER_SECONDS = 120;', $source);
        self::assertStringContainsString('if ($operation->remote_effect_started_at === null)', $source);
        self::assertStringContainsString('ProvisioningState::RetryScheduled', $source);
        self::assertStringContainsString('interrupted_pre_boundary_recovery', $source);
        self::assertStringContainsString('ProvisioningState::UncertainRemoteResult', $source);
        self::assertStringContainsString('interrupted_remote_effect_recovery', $source);
        self::assertStringNotContainsString('UncertainRemoteResult->value => ProvisioningState::RetryScheduled', $source);
    }

    public function test_stale_queued_snapshot_is_rejected_to_review_before_claim(): void
    {
        $source = $this->classSource(ServiceMutationRecoveryService::class);

        self::assertStringContainsString('transitionStaleQueued', $source);
        self::assertStringContainsString('stale_service_before_claim', $source);
        self::assertStringContainsString('ProvisioningState::NeedsReview', $source);
        self::assertStringContainsString('$this->serviceMatchesOperation($service, $operation, $type)', $source);
    }

    public function test_foundation_router_registers_service_mutation_handler(): void
    {
        $source = $this->classSource(FoundationServiceProvider::class);

        self::assertStringContainsString('ServiceMutationOutboxHandler::class', $source);
        self::assertStringContainsString(
            '[InitialProvisioningOutboxHandler::class, ServiceMutationOutboxHandler::class]',
            $source,
        );
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
