<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Catalog\Application\PlanOfferingRoutePolicyService;
use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Provisioning\Application\InitialProvisioningExecutor;
use App\Modules\Provisioning\Application\InitialProvisioningOutboxHandler;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Provisioning\Application\ServiceDeliveryAttemptQueueService;
use App\Modules\Provisioning\Application\ServiceDeliveryEffectExecutor;
use App\Modules\Provisioning\Application\ServiceDeliveryResendAudit;
use App\Modules\Provisioning\Application\ServiceDeliveryResendContext;
use App\Modules\Provisioning\Application\ServiceDeliveryResendService;
use App\Modules\Provisioning\Application\ServiceDeliveryOutboxHandler;
use App\Modules\Provisioning\Application\ServiceMutationExecutor;
use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Application\ProtectedTelegramSendResult;
use App\Modules\Telegram\Infrastructure\HttpProtectedTelegramMessageSender;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/** @requirement SVC-002 SVC-014 PRV-002 PRV-003 ARCH-004 DAT-003 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 QUA-007 QUA-010 */
final class ServiceDeliveryEffectAuthorityTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    private const BOT_ID = 123456;

    private const TELEGRAM_USER_ID = 99887766;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Migration $mutationMigration */
        $mutationMigration = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');
        $mutationMigration->up();

        /** @var Migration $deliveryAttemptMigration */
        $deliveryAttemptMigration = require database_path('migrations/2026_08_18_000100_create_service_delivery_attempt_authority.php');
        $deliveryAttemptMigration->up();

        /** @var Migration $deliveryEffectMigration */
        $deliveryEffectMigration = require database_path('migrations/2026_08_18_000200_enable_service_delivery_effect_authority.php');
        $deliveryEffectMigration->up();

        /** @var Migration $resendAuditMigration */
        $resendAuditMigration = require database_path('migrations/2026_08_21_000100_enable_service_delivery_resend_audit_authority.php');
        $resendAuditMigration->up();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_protected_delivery_success_replays_without_second_send_and_never_persists_secret(): void
    {
        $secretLink = 'https://subscription.example.test/'.str_repeat('s', 48);
        $scenario = $this->provisionedScenario(
            'effect-success',
            $secretLink,
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 4242,
            ),
        );
        $this->insertTelegramAccount($scenario['user_id']);

        $attempt = $this->deliveryQueue()->queue(
            $scenario['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-success-0001',
            'correlation-effect-success-0001',
        );
        $message = $this->outboxMessage($attempt->outboxEventId);
        $handler = $this->deliveryHandler();

        self::assertSame(OutboxDispatchOutcome::Success, $handler->handle($message));
        self::assertSame(['delivery_artifacts'], $scenario['doubles']->panelCalls);
        self::assertCount(1, $scenario['doubles']->sendCalls);
        self::assertSame(self::TELEGRAM_USER_ID, $scenario['doubles']->sendCalls[0]['telegram_user_id']);
        self::assertStringContainsString($secretLink, $scenario['doubles']->sendCalls[0]['text']);

        $effect = DB::table('service_delivery_effects')
            ->where('service_delivery_attempt_id', (int) DB::table('service_delivery_attempts')
                ->where('public_id', $attempt->attemptPublicId)->value('id'))
            ->first();
        self::assertNotNull($effect);
        self::assertSame(ServiceDeliveryEffectState::Succeeded->value, $effect->state);
        self::assertSame(4242, (int) $effect->telegram_message_id);
        self::assertNotNull($effect->provider_boundary_started_at);
        self::assertNotNull($effect->completed_at);

        try {
            DB::table('service_delivery_effects')->where('id', (int) $effect->id)->update([
                'telegram_user_id' => self::TELEGRAM_USER_ID + 1,
            ]);
            self::fail('Direct DB Service delivery effect retargeting must fail closed.');
        } catch (QueryException) {
            // Expected.
        }
        try {
            DB::table('service_delivery_effects')->where('id', (int) $effect->id)->delete();
            self::fail('Direct DB Service delivery effect deletion must fail closed.');
        } catch (QueryException) {
            // Expected.
        }

        self::assertSame(OutboxDispatchOutcome::Success, $handler->handle($message));
        self::assertSame(['delivery_artifacts'], $scenario['doubles']->panelCalls);
        self::assertCount(1, $scenario['doubles']->sendCalls);

        $effectEvidence = json_encode((array) $effect, JSON_THROW_ON_ERROR);
        $outboxEvidence = json_encode(DB::table('outbox_messages')->where('id', $attempt->outboxEventId)->first(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secretLink, $effectEvidence);
        self::assertStringNotContainsString($secretLink, $outboxEvidence);
    }

    public function test_explicit_owner_resend_is_audited_and_replays_without_second_delivery_attempt_or_outbox(): void
    {
        $secretLink = 'https://subscription.example.test/'.str_repeat('a', 48);
        $scenario = $this->provisionedScenario(
            'explicit-resend-replay',
            $secretLink,
            new ProtectedTelegramSendResult(ProtectedTelegramSendOutcome::Success, 'telegram_success', messageId: 4401),
        );
        $context = $this->resendContext($scenario['user_id'], 'explicit-resend-replay');

        $first = $this->resends()->resend($scenario['service_public_id'], $context);
        self::assertFalse($first->attempt->replayed);
        self::assertFalse($first->auditReplayed);
        self::assertSame(ServiceDeliveryPurpose::Resend, $first->attempt->purpose);

        $audit = DB::table('audit_logs')->where('id', $first->auditLogId)->first([
            'actor_type', 'actor_id', 'action', 'target_type', 'target_id', 'before_safe_data', 'after_safe_data',
            'reason_code', 'reason', 'correlation_id', 'request_fingerprint',
        ]);
        self::assertNotNull($audit);
        self::assertSame('user', $audit->actor_type);
        self::assertSame((string) $scenario['user_id'], $audit->actor_id);
        self::assertSame(ServiceDeliveryResendAudit::ACTION, $audit->action);
        self::assertSame('service_subscription', $audit->target_type);
        self::assertSame($scenario['service_public_id'], $audit->target_id);
        self::assertSame($context->reasonCode, $audit->reason_code);
        self::assertSame($context->reason, $audit->reason);
        self::assertSame($context->correlationId, $audit->correlation_id);
        self::assertSame($context->requestHash(), $audit->request_fingerprint);
        self::assertStringNotContainsString($secretLink, (string) $audit->before_safe_data);
        self::assertStringNotContainsString($secretLink, (string) $audit->after_safe_data);

        $replay = $this->resends()->resend($scenario['service_public_id'], $context);
        self::assertTrue($replay->attempt->replayed);
        self::assertTrue($replay->auditReplayed);
        self::assertSame($first->attempt->attemptPublicId, $replay->attempt->attemptPublicId);
        self::assertSame($first->auditLogId, $replay->auditLogId);
        self::assertSame(1, DB::table('service_delivery_attempts')->where('service_subscription_id', $scenario['service_id'])->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', ServiceDeliveryResendAudit::ACTION)->count());

        try {
            DB::table('audit_logs')->where('id', $first->auditLogId)->update(['reason' => 'tampered']);
            self::fail('Service delivery resend audit must be immutable.');
        } catch (QueryException) {
            // Expected.
        }
        try {
            DB::table('audit_logs')->where('id', $first->auditLogId)->delete();
            self::fail('Service delivery resend audit must be non-deletable.');
        } catch (QueryException) {
            // Expected.
        }
    }

    public function test_direct_resend_audit_forgery_without_database_authority_fails_closed(): void
    {
        $scenario = $this->provisionedScenario(
            'explicit-resend-forged-audit',
            'https://subscription.example.test/forged-audit',
            new ProtectedTelegramSendResult(ProtectedTelegramSendOutcome::Success, 'telegram_success', messageId: 4404),
        );

        try {
            DB::table('audit_logs')->insert([
                'actor_type' => 'user',
                'actor_id' => (string) $scenario['user_id'],
                'action' => ServiceDeliveryResendAudit::ACTION,
                'target_type' => 'service_subscription',
                'target_id' => $scenario['service_public_id'],
                'before_safe_data' => json_encode([
                    'lifecycle_state' => 'active',
                    'lifecycle_version' => 0,
                    'remote_identity_generation' => 1,
                ], JSON_THROW_ON_ERROR),
                'after_safe_data' => json_encode([
                    'delivery_attempt_public_id' => (string) Str::ulid(),
                    'delivery_purpose' => 'resend',
                    'target_remote_identity_generation' => 1,
                    'target_lifecycle_version' => 0,
                    'outbox_event_id' => '00000000-0000-4000-8000-000000000000',
                ], JSON_THROW_ON_ERROR),
                'reason_code' => 'forged',
                'reason' => 'forged',
                'correlation_id' => 'correlation-forged-audit-0001',
                'request_fingerprint' => hash('sha256', 'request-forged-audit-0001'),
                'created_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Direct Service delivery resend audit insertion must fail closed.');
        } catch (QueryException) {
            // Expected.
        }

        self::assertSame(0, DB::table('audit_logs')->where('action', ServiceDeliveryResendAudit::ACTION)->count());
    }

    public function test_cross_user_resend_is_rejected_before_delivery_attempt_outbox_or_audit(): void
    {
        $scenario = $this->provisionedScenario(
            'explicit-resend-cross-user',
            'https://subscription.example.test/cross-user',
            new ProtectedTelegramSendResult(ProtectedTelegramSendOutcome::Success, 'telegram_success', messageId: 4402),
        );

        try {
            $this->resends()->resend(
                $scenario['service_public_id'],
                $this->resendContext($scenario['user_id'] + 1000000, 'explicit-resend-cross-user'),
            );
            self::fail('A user must not resend restricted Service details for another user Service.');
        } catch (AuthorizationException) {
            // Expected.
        }

        self::assertSame(0, DB::table('service_delivery_attempts')->where('service_subscription_id', $scenario['service_id'])->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', ServiceDeliveryResendAudit::ACTION)->count());
    }

    public function test_replay_of_low_level_resend_without_caller_audit_fails_closed(): void
    {
        $scenario = $this->provisionedScenario(
            'explicit-resend-missing-audit',
            'https://subscription.example.test/missing-audit',
            new ProtectedTelegramSendResult(ProtectedTelegramSendOutcome::Success, 'telegram_success', messageId: 4403),
        );
        $context = $this->resendContext($scenario['user_id'], 'explicit-resend-missing-audit');

        $lowLevel = $this->deliveryQueue()->queue(
            $scenario['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            $context->requestKey,
            $context->correlationId,
        );
        self::assertFalse($lowLevel->replayed);

        try {
            $this->resends()->resend($scenario['service_public_id'], $context);
            self::fail('A low-level resend replay without caller audit must not be retroactively attributed.');
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertSame(1, DB::table('service_delivery_attempts')->where('service_subscription_id', $scenario['service_id'])->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', ServiceDeliveryResendAudit::ACTION)->count());
    }

    public function test_missing_recipient_rejects_before_restricted_artifact_retrieval_and_direct_db_forgery_fails(): void
    {
        $scenario = $this->provisionedScenario(
            'effect-missing-recipient',
            'https://subscription.example.test/missing-recipient',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 5001,
            ),
        );
        $attempt = $this->deliveryQueue()->queue(
            $scenario['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-missing-recipient-0001',
            'correlation-effect-missing-recipient-0001',
        );

        self::assertSame(
            OutboxDispatchOutcome::DefinitiveFailure,
            $this->deliveryHandler()->handle($this->outboxMessage($attempt->outboxEventId)),
        );
        self::assertSame([], $scenario['doubles']->panelCalls);
        self::assertSame([], $scenario['doubles']->sendCalls);
        self::assertSame(0, DB::table('service_delivery_effects')->count());

        $telegramAccountId = $this->insertTelegramAccount($scenario['user_id']);
        $attemptId = (int) DB::table('service_delivery_attempts')->where('public_id', $attempt->attemptPublicId)->value('id');
        try {
            DB::table('service_delivery_effects')->insert([
                'public_id' => (string) Str::ulid(),
                'service_delivery_attempt_id' => $attemptId,
                'service_subscription_id' => $scenario['service_id'],
                'telegram_account_id' => $telegramAccountId,
                'telegram_bot_id' => self::BOT_ID,
                'telegram_user_id' => self::TELEGRAM_USER_ID,
                'state' => ServiceDeliveryEffectState::Prepared->value,
                'state_version' => 1,
                'created_at' => $this->purchaseOrderTimestamp(),
                'updated_at' => $this->purchaseOrderTimestamp(),
            ]);
            self::fail('Direct DB Service delivery effect insertion must fail closed.');
        } catch (QueryException) {
            // Expected.
        }
        self::assertSame(0, DB::table('service_delivery_effects')->count());
    }

    public function test_mutation_created_during_artifact_retrieval_is_revalidated_before_telegram_boundary(): void
    {
        $scenario = $this->provisionedScenario(
            'effect-mutation-race',
            'https://subscription.example.test/mutation-race',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 5002,
            ),
        );
        $this->insertTelegramAccount($scenario['user_id']);
        $scenario['doubles']->beforeDeliveryArtifacts = function () use ($scenario): void {
            $this->app->make(ServiceMutationQueueService::class)->queue(
                $scenario['service_public_id'],
                ServiceMutationType::Suspend,
                'request-effect-mutation-race-0001',
                'correlation-effect-mutation-race-0001',
            );
        };

        $attempt = $this->deliveryQueue()->queue(
            $scenario['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-mutation-race-delivery-0001',
            'correlation-effect-mutation-race-delivery-0001',
        );

        self::assertSame(
            OutboxDispatchOutcome::DefinitiveFailure,
            $this->deliveryHandler()->handle($this->outboxMessage($attempt->outboxEventId)),
        );
        self::assertSame(['delivery_artifacts'], $scenario['doubles']->panelCalls);
        self::assertSame([], $scenario['doubles']->sendCalls);
        self::assertSame('failed_final', DB::table('service_delivery_effects')->value('state'));
        self::assertSame('delivery_authority_stale', DB::table('service_delivery_effects')->value('result_code'));
    }

    public function test_stale_lifecycle_and_recipient_mismatch_fail_before_send(): void
    {
        $stale = $this->provisionedScenario(
            'effect-stale-lifecycle',
            'https://subscription.example.test/stale-lifecycle',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 5101,
            ),
        );
        $this->insertTelegramAccount($stale['user_id']);
        $staleAttempt = $this->deliveryQueue()->queue(
            $stale['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-stale-lifecycle-0001',
            'correlation-effect-stale-lifecycle-0001',
        );
        $suspend = $this->app->make(ServiceMutationQueueService::class)->queue(
            $stale['service_public_id'],
            ServiceMutationType::Suspend,
            'request-effect-stale-lifecycle-suspend-0001',
            'correlation-effect-stale-lifecycle-suspend-0001',
        );
        self::assertSame(
            ProvisioningState::Succeeded,
            $this->app->make(ServiceMutationExecutor::class)->execute($suspend->operationPublicId)->state,
        );
        self::assertSame(
            OutboxDispatchOutcome::DefinitiveFailure,
            $this->deliveryHandler()->handle($this->outboxMessage($staleAttempt->outboxEventId)),
        );
        self::assertSame([], $stale['doubles']->panelCalls);
        self::assertSame([], $stale['doubles']->sendCalls);

        $this->truncateTablesForAllConnections();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);

        $mismatch = $this->provisionedScenario(
            'effect-recipient-mismatch',
            'https://subscription.example.test/recipient-mismatch',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 5102,
            ),
        );
        $accountId = $this->insertTelegramAccount($mismatch['user_id']);
        $mismatch['doubles']->beforeDeliveryArtifacts = static function () use ($accountId): void {
            DB::table('telegram_accounts')->where('id', $accountId)->update([
                'telegram_user_id' => self::TELEGRAM_USER_ID + 1,
            ]);
        };
        $mismatchAttempt = $this->deliveryQueue()->queue(
            $mismatch['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-recipient-mismatch-0001',
            'correlation-effect-recipient-mismatch-0001',
        );
        self::assertSame(
            OutboxDispatchOutcome::DefinitiveFailure,
            $this->deliveryHandler()->handle($this->outboxMessage($mismatchAttempt->outboxEventId)),
        );
        self::assertSame(['delivery_artifacts'], $mismatch['doubles']->panelCalls);
        self::assertSame([], $mismatch['doubles']->sendCalls);
        self::assertSame('failed_final', DB::table('service_delivery_effects')->value('state'));
        self::assertSame('delivery_authority_stale', DB::table('service_delivery_effects')->value('result_code'));
    }

    public function test_panel_failure_before_boundary_is_retryable_and_permanent_rejection_is_not_resent(): void
    {
        $panelFailure = $this->provisionedScenario(
            'effect-panel-failure',
            'https://subscription.example.test/panel-failure',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 5201,
            ),
        );
        $this->insertTelegramAccount($panelFailure['user_id']);
        $panelFailure['doubles']->throwOnDeliveryArtifacts = true;
        $panelAttempt = $this->deliveryQueue()->queue(
            $panelFailure['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-panel-failure-0001',
            'correlation-effect-panel-failure-0001',
        );
        self::assertSame(
            OutboxDispatchOutcome::RetryableFailure,
            $this->deliveryHandler()->handle($this->outboxMessage($panelAttempt->outboxEventId)),
        );
        self::assertSame(['delivery_artifacts'], $panelFailure['doubles']->panelCalls);
        self::assertSame([], $panelFailure['doubles']->sendCalls);
        self::assertSame(ServiceDeliveryEffectState::Prepared->value, DB::table('service_delivery_effects')->value('state'));
        self::assertNull(DB::table('service_delivery_effects')->value('provider_boundary_started_at'));

        $this->truncateTablesForAllConnections();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);

        $rejected = $this->provisionedScenario(
            'effect-permanent-rejection',
            'https://subscription.example.test/permanent-rejection',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::DefinitiveFailure,
                'telegram_permanent_rejection',
            ),
        );
        $this->insertTelegramAccount($rejected['user_id']);
        $rejectedAttempt = $this->deliveryQueue()->queue(
            $rejected['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-permanent-rejection-0001',
            'correlation-effect-permanent-rejection-0001',
        );
        $rejectedMessage = $this->outboxMessage($rejectedAttempt->outboxEventId);
        $handler = $this->deliveryHandler();
        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $handler->handle($rejectedMessage));
        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $handler->handle($rejectedMessage));
        self::assertCount(1, $rejected['doubles']->sendCalls);
        self::assertSame(ServiceDeliveryEffectState::FailedFinal->value, DB::table('service_delivery_effects')->value('state'));
        self::assertSame('telegram_permanent_rejection', DB::table('service_delivery_effects')->value('result_code'));
    }

    public function test_uncertain_and_retry_after_results_quarantine_without_second_send(): void
    {
        $uncertain = $this->provisionedScenario(
            'effect-uncertain',
            'https://subscription.example.test/uncertain',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::UncertainResult,
                'telegram_transport_uncertain',
            ),
        );
        $this->insertTelegramAccount($uncertain['user_id']);
        $uncertainAttempt = $this->deliveryQueue()->queue(
            $uncertain['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-uncertain-0001',
            'correlation-effect-uncertain-0001',
        );
        $uncertainMessage = $this->outboxMessage($uncertainAttempt->outboxEventId);
        $handler = $this->deliveryHandler();

        self::assertSame(OutboxDispatchOutcome::UncertainResult, $handler->handle($uncertainMessage));
        self::assertSame(OutboxDispatchOutcome::UncertainResult, $handler->handle($uncertainMessage));
        self::assertCount(1, $uncertain['doubles']->sendCalls);
        self::assertSame(['delivery_artifacts'], $uncertain['doubles']->panelCalls);
        self::assertSame(ServiceDeliveryEffectState::Uncertain->value, DB::table('service_delivery_effects')->value('state'));

        try {
            $this->resends()->resend(
                $uncertain['service_public_id'],
                $this->resendContext($uncertain['user_id'], 'uncertain-resend-fence'),
            );
            self::fail('An uncertain Service delivery effect must fence a new restricted resend.');
        } catch (DomainException) {
            // Expected.
        }
        self::assertSame(1, DB::table('service_delivery_attempts')->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());
        self::assertSame(0, DB::table('audit_logs')->where('action', ServiceDeliveryResendAudit::ACTION)->count());

        $this->truncateTablesForAllConnections();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);

        $retry = $this->provisionedScenario(
            'effect-retry-after',
            'https://subscription.example.test/retry-after',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::RetryAfter,
                'telegram_retry_after',
                retryAfterSeconds: 60,
            ),
        );
        $this->insertTelegramAccount($retry['user_id']);
        $retryAttempt = $this->deliveryQueue()->queue(
            $retry['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-retry-after-0001',
            'correlation-effect-retry-after-0001',
        );
        $retryMessage = $this->outboxMessage($retryAttempt->outboxEventId);
        $retryHandler = $this->deliveryHandler();

        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $retryHandler->handle($retryMessage));
        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $retryHandler->handle($retryMessage));
        self::assertCount(1, $retry['doubles']->sendCalls);
        self::assertSame(60, (int) DB::table('service_delivery_effects')->value('retry_after_seconds'));

        try {
            $this->deliveryQueue()->queue(
                $retry['service_public_id'],
                ServiceDeliveryPurpose::Resend,
                'request-effect-retry-after-resend-0001',
                'correlation-effect-retry-after-resend-0001',
            );
            self::fail('Provider-directed retry quarantine must block a new Delivery Attempt.');
        } catch (DomainException) {
            // Expected.
        }
    }

    public function test_interrupted_sending_recovers_uncertain_without_sender_call(): void
    {
        $scenario = $this->provisionedScenario(
            'effect-interrupted',
            'https://subscription.example.test/interrupted',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 5003,
            ),
        );
        $this->insertTelegramAccount($scenario['user_id']);
        $attempt = $this->deliveryQueue()->queue(
            $scenario['service_public_id'],
            ServiceDeliveryPurpose::Resend,
            'request-effect-interrupted-0001',
            'correlation-effect-interrupted-0001',
        );
        $executor = $this->app->make(ServiceDeliveryEffectExecutor::class);

        $prepare = new ReflectionMethod($executor, 'prepare');
        /** @var array{effect:object} $context */
        $context = $prepare->invoke($executor, $attempt->attemptPublicId);
        $boundary = new ReflectionMethod($executor, 'enterProviderBoundary');
        $boundary->invoke($executor, $context['effect']);

        self::assertSame(ServiceDeliveryEffectState::Sending->value, DB::table('service_delivery_effects')->value('state'));
        try {
            $this->app->make(ServiceMutationQueueService::class)->queue(
                $scenario['service_public_id'],
                ServiceMutationType::Suspend,
                'request-effect-sending-mutation-0001',
                'correlation-effect-sending-mutation-0001',
            );
            self::fail('A Service mutation must be rejected after the protected Telegram boundary starts.');
        } catch (DomainException) {
            // Expected.
        }
        self::assertSame(0, DB::table('provisioning_operations')
            ->where('service_subscription_id', $scenario['service_id'])
            ->where('operation_type', '<>', 'initial_provision')
            ->count());

        $message = $this->outboxMessage($attempt->outboxEventId);
        $handler = $this->deliveryHandler();
        self::assertSame(OutboxDispatchOutcome::UncertainResult, $handler->handle($message));
        self::assertSame(OutboxDispatchOutcome::UncertainResult, $handler->handle($message));
        self::assertSame([], $scenario['doubles']->sendCalls);
        self::assertSame(ServiceDeliveryEffectState::Uncertain->value, DB::table('service_delivery_effects')->value('state'));
        self::assertSame('interrupted_delivery_effect', DB::table('service_delivery_effects')->value('result_code'));
    }

    public function test_initial_provisioning_outbox_replay_schedules_exactly_one_initial_delivery_attempt(): void
    {
        $scenario = $this->queuedScenario(
            'effect-initial-schedule',
            'https://subscription.example.test/initial-schedule',
            new ProtectedTelegramSendResult(
                ProtectedTelegramSendOutcome::Success,
                'telegram_success',
                messageId: 5004,
            ),
        );
        $message = $this->initialProvisioningMessage($scenario['provisioning_operation_public_id']);
        $handler = $this->app->make(InitialProvisioningOutboxHandler::class);

        self::assertSame(OutboxDispatchOutcome::Success, $handler->handle($message));
        self::assertSame(OutboxDispatchOutcome::Success, $handler->handle($message));
        self::assertSame(1, DB::table('service_delivery_attempts')->where('purpose', ServiceDeliveryPurpose::Initial->value)->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE)->count());
        self::assertSame(1, count(array_filter($scenario['doubles']->panelCalls, static fn (string $call): bool => $call === 'create')));
    }

    public function test_http_sender_performs_one_protected_send_without_parse_mode(): void
    {
        $configuration = $this->telegramConfiguration();
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9090],
            ], 200),
        ]);
        $sender = new HttpProtectedTelegramMessageSender(
            $this->app->make(Factory::class),
            $configuration,
        );

        $result = $sender->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::Success, $result->outcome);
        self::assertSame(9090, $result->messageId);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_ends_with($request->url(), '/sendMessage')
                && ($data['chat_id'] ?? null) === self::TELEGRAM_USER_ID
                && ($data['protect_content'] ?? null) === true
                && ($data['link_preview_options']['is_disabled'] ?? null) === true
                && ! array_key_exists('parse_mode', $data);
        });
    }

    /**
     * @return array{service_id:int,service_public_id:string,user_id:int,doubles:ServiceDeliveryEffectTestDoubles}
     */
    private function provisionedScenario(
        string $suffix,
        string $deliveryLink,
        ProtectedTelegramSendResult $sendResult,
    ): array {
        $queued = $this->queuedScenario($suffix, $deliveryLink, $sendResult);
        $provisioned = $this->app->make(InitialProvisioningExecutor::class)
            ->execute($queued['provisioning_operation_public_id']);
        self::assertSame(ProvisioningState::Succeeded, $provisioned->state);
        $queued['doubles']->resetCalls();

        $service = DB::table('service_subscriptions')->where('id', $queued['service_id'])->first([
            'id', 'public_id', 'user_id', 'remote_service_id', 'provisioned_at', 'lifecycle_state',
        ]);
        self::assertNotNull($service);
        self::assertSame('active', $service->lifecycle_state);
        self::assertNotNull($service->remote_service_id);
        self::assertNotNull($service->provisioned_at);

        return [
            'service_id' => (int) $service->id,
            'service_public_id' => (string) $service->public_id,
            'user_id' => (int) $service->user_id,
            'doubles' => $queued['doubles'],
        ];
    }

    /**
     * @return array{service_id:int,service_public_id:string,user_id:int,provisioning_operation_public_id:string,doubles:ServiceDeliveryEffectTestDoubles}
     */
    private function queuedScenario(
        string $suffix,
        string $deliveryLink,
        ProtectedTelegramSendResult $sendResult,
    ): array {
        $settlement = $this->createPurchaseOrderSettlement('delivery-effect-'.$suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('delivery-effect-order-'.$suffix),
        );
        $queue = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('delivery-effect-queue-'.$suffix),
        );
        $offeringId = (int) DB::table('order_items')->where('order_id', $order->orderId)->value('plan_offering_id');
        $userId = (int) DB::table('orders')->where('id', $order->orderId)->value('user_id');
        $this->makeOfferingOperational($offeringId, $userId, $suffix);

        $doubles = new ServiceDeliveryEffectTestDoubles($deliveryLink, $sendResult);
        $this->app->instance(
            PanelAdapterRegistry::class,
            new PanelAdapterRegistry(
                [$doubles],
                $this->app->make(PanelCredentialPolicy::class),
            ),
        );
        $this->app->instance(TelegramRuntimeConfiguration::class, $this->telegramConfiguration());
        $this->app->instance(ProtectedTelegramMessageSender::class, $doubles);
        $this->app->forgetInstance(InitialProvisioningExecutor::class);
        $this->app->forgetInstance(ServiceDeliveryEffectExecutor::class);
        $this->app->forgetInstance(ServiceDeliveryOutboxHandler::class);
        $this->app->forgetInstance(InitialProvisioningOutboxHandler::class);

        $service = DB::table('service_subscriptions')->where('id', $queue->serviceSubscriptionId)->first([
            'id', 'public_id', 'user_id',
        ]);
        self::assertNotNull($service);

        return [
            'service_id' => (int) $service->id,
            'service_public_id' => (string) $service->public_id,
            'user_id' => (int) $service->user_id,
            'provisioning_operation_public_id' => $queue->provisioningOperationPublicId,
            'doubles' => $doubles,
        ];
    }

    private function deliveryQueue(): ServiceDeliveryAttemptQueueService
    {
        return $this->app->make(ServiceDeliveryAttemptQueueService::class);
    }

    private function resends(): ServiceDeliveryResendService
    {
        return $this->app->make(ServiceDeliveryResendService::class);
    }

    private function resendContext(int $userId, string $suffix): ServiceDeliveryResendContext
    {
        return new ServiceDeliveryResendContext(
            requestKey: 'request-'.$suffix.'-0001',
            correlationId: 'correlation-'.$suffix.'-0001',
            reasonCode: 'user_requested_details',
            reason: 'User requested current Service details.',
            actorUserId: $userId,
        );
    }

    private function deliveryHandler(): ServiceDeliveryOutboxHandler
    {
        $this->app->forgetInstance(ServiceDeliveryEffectExecutor::class);
        $this->app->forgetInstance(ServiceDeliveryOutboxHandler::class);

        return $this->app->make(ServiceDeliveryOutboxHandler::class);
    }

    private function insertTelegramAccount(int $userId): int
    {
        $now = $this->purchaseOrderTimestamp();

        return (int) DB::table('telegram_accounts')->insertGetId([
            'user_id' => $userId,
            'bot_id' => self::BOT_ID,
            'telegram_user_id' => self::TELEGRAM_USER_ID,
            'username' => 'delivery_effect_test',
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function telegramConfiguration(): TelegramRuntimeConfiguration
    {
        return new TelegramRuntimeConfiguration(
            botToken: self::BOT_ID.':abcdefghijklmnopqrstuvwxyzABCDE',
            botId: (string) self::BOT_ID,
            webhookSecret: str_repeat('w', 32),
            webhookUrl: 'https://example.test/api/telegram/webhook',
            maximumBodyBytes: 1_048_576,
            queue: 'telegram-ingress',
            processingLeaseSeconds: 120,
            apiBaseUrl: 'https://api.telegram.org',
            apiTimeoutSeconds: 15,
        );
    }

    private function outboxMessage(string $eventId): OutboxMessage
    {
        $row = DB::table('outbox_messages')->where('id', $eventId)->first([
            'id', 'event_key', 'event_type', 'aggregate_type', 'aggregate_id', 'payload', 'correlation_id',
        ]);
        self::assertNotNull($row);
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);

        return new OutboxMessage(
            (string) $row->id,
            (string) $row->event_key,
            (string) $row->event_type,
            (string) $row->aggregate_type,
            (string) $row->aggregate_id,
            $payload,
            (string) $row->correlation_id,
            1,
        );
    }

    private function initialProvisioningMessage(string $operationPublicId): OutboxMessage
    {
        $row = DB::table('outbox_messages')
            ->where('event_type', 'provisioning.initial.requested')
            ->where('aggregate_id', $operationPublicId)
            ->first(['id']);
        self::assertNotNull($row);

        return $this->outboxMessage((string) $row->id);
    }

    private function makeOfferingOperational(int $offeringId, int $userId, string $suffix): void
    {
        $now = $this->purchaseOrderTimestamp();
        $offering = DB::table('plan_offerings')->where('id', $offeringId)->first([
            'sales_server_id', 'panel_service_target_id',
        ]);
        self::assertNotNull($offering);
        $serverId = (int) $offering->sales_server_id;
        $targetId = (int) $offering->panel_service_target_id;
        $connectionId = (int) DB::table('panel_service_targets')->where('id', $targetId)->value('panel_connection_id');
        $connectionVersion = (int) DB::table('panel_connections')->where('id', $connectionId)->value('version') + 1;
        $capabilityHash = hash('sha256', 'service-delivery-effect-capabilities-'.$suffix);
        $targetEvidenceHash = hash('sha256', 'service-delivery-effect-target-evidence-'.$suffix);

        DB::table('panel_connections')->where('id', $connectionId)->update([
            'encrypted_credentials' => Crypt::encryptString(json_encode(['token' => 'service-delivery-effect-test'], JSON_THROW_ON_ERROR)),
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => '1.0.0',
            'last_capabilities_hash' => $capabilityHash,
            'last_tested_at' => $now,
            'version' => $connectionVersion,
            'updated_at' => $now,
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update([
            'state' => 'active',
            'capability_status' => 'verified',
            'capability_evidence_hash' => $targetEvidenceHash,
            'capability_verified_at' => $now,
            'verified_connection_version' => $connectionVersion,
            'version' => DB::raw('version + 1'),
            'updated_at' => $now,
        ]);
        DB::table('panel_target_capabilities')->where('panel_service_target_id', $targetId)->update([
            'verification_status' => 'verified',
            'evidence_hash' => $targetEvidenceHash,
            'verified_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('sales_servers')->where('id', $serverId)->update([
            'state' => 'active',
            'visibility' => 'listed',
            'version' => DB::raw('version + 1'),
            'updated_at' => $now,
        ]);
        DB::table('plan_offering_protocol_profiles')->where('plan_offering_id', $offeringId)->update([
            'customer_selectable' => false,
            'updated_at' => $now,
        ]);
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'server_selection_mode' => 'system_selects',
            'updated_at' => $now,
        ]);

        $tierId = (int) DB::table('customer_tiers')->where('code', 'normal')->value('id');
        if (! DB::table('customer_profiles')->where('user_id', $userId)->exists()) {
            DB::table('customer_profiles')->insert([
                'user_id' => $userId,
                'current_tier_id' => $tierId,
                'tier_locked' => false,
                'tier_lock_reason_code' => null,
                'phone_verification_status' => 'verified',
                'identity_verification_status' => 'verified',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $ownerId = $this->ownerAdministrator();
        foreach (DB::table('plan_offering_tags')->where('plan_offering_id', $offeringId)->pluck('customer_tag_id') as $tagId) {
            DB::table('customer_tag_assignments')->insert([
                'user_id' => $userId,
                'tag_id' => (int) $tagId,
                'assigned_by_administrator_id' => $ownerId,
                'assigned_at' => $now,
                'removed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('panel_target_capacities')->insert([
            'panel_service_target_id' => $targetId,
            'hard_limit' => 10,
            'held_units' => 0,
            'committed_units' => 0,
            'state' => 'enabled',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(PlanOfferingRoutePolicyService::class)->create(
            $offeringId,
            new PlanOfferingRoutePolicyDefinition([
                new PlanOfferingRouteDefinition(
                    $serverId,
                    $targetId,
                    PlanOfferingRouteType::Primary,
                    0,
                    false,
                    null,
                    null,
                ),
            ]),
            $this->catalogContext($ownerId, 'service-delivery-effect-route-'.$suffix),
        );
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'state' => 'active',
            'visibility' => 'visible',
            'server_selection_mode' => 'system_selects',
            'protocol_selection_mode' => 'system_selects',
            'updated_at' => $now,
        ]);
    }
}
