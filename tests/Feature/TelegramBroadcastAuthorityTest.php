<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramBroadcastLifecycleTransport;
use App\Modules\Telegram\Application\Contracts\TelegramSourceMessageSender;
use App\Modules\Telegram\Application\TelegramBroadcastAudienceDefinition;
use App\Modules\Telegram\Application\TelegramBroadcastCampaignReceipt;
use App\Modules\Telegram\Application\TelegramBroadcastCampaignService;
use App\Modules\Telegram\Application\TelegramBroadcastDeliveryRunner;
use App\Modules\Telegram\Application\TelegramBroadcastLifecycleMutationRequest;
use App\Modules\Telegram\Application\TelegramBroadcastLifecycleRunner;
use App\Modules\Telegram\Application\TelegramBroadcastLifecycleService;
use App\Modules\Telegram\Application\TelegramBroadcastMessageDefinition;
use App\Modules\Telegram\Application\TelegramBroadcastOwnerTestService;
use App\Modules\Telegram\Application\TelegramBroadcastRetryService;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationResult;
use App\Modules\Telegram\Application\TelegramResolvedInlineKeyboardMarkup;
use App\Modules\Telegram\Application\TelegramResolvedSourceMessagePresentation;
use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Modules\Telegram\Domain\TelegramBroadcastLifecycleAction;
use App\Modules\Telegram\Domain\TelegramBroadcastSourceKind;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement COM-002 COM-003 ACL-001 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 */
final class TelegramBroadcastAuthorityTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram broadcast authority verification requires MariaDB/MySQL.');
        }

        $this->seed();
        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'telegram.webhook_secret' => 'telegram_webhook_secret_1234567890_safe',
            'telegram.webhook_path' => 'api/telegram/webhook',
            'telegram.max_body_bytes' => 1_048_576,
            'telegram.queue' => 'critical',
            'telegram.processing_lease_seconds' => 120,
            'telegram.api_base_url' => 'https://api.telegram.org',
            'telegram.api_timeout_seconds' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_create_replay_owner_gate_materialization_and_cancel_are_restart_safe(): void
    {
        $actor = $this->owner(910001);
        $service = $this->app->make(TelegramBroadcastCampaignService::class);
        $message = TelegramBroadcastMessageDefinition::newText('پیام آزمایشی کمپین');
        $audience = new TelegramBroadcastAudienceDefinition;

        $created = $service->createDraft(
            $actor['user_id'],
            $message,
            $audience,
            'broadcast-create-replay',
        );
        $replayed = $service->createDraft(
            $actor['user_id'],
            $message,
            $audience,
            'broadcast-create-replay',
        );

        self::assertFalse($created->replayed);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->publicId, $replayed->publicId);
        self::assertSame(1, DB::table('broadcast_campaigns')->count());
        self::assertSame(1, DB::table('broadcast_message_versions')->count());
        self::assertSame(1, DB::table('broadcast_audiences')->count());

        try {
            $service->createDraft(
                $actor['user_id'],
                TelegramBroadcastMessageDefinition::newText('different input'),
                $audience,
                'broadcast-create-replay',
            );
            self::fail('Conflicting replay input must be rejected.');
        } catch (DomainException) {
            self::assertSame(1, DB::table('broadcast_campaigns')->count());
        }

        self::assertSame(1, $service->estimateAudience(
            $actor['user_id'],
            $created->publicId,
            $created->audienceVersion,
        ));

        try {
            $service->startNow($actor['user_id'], $created->publicId, $created->stateVersion);
            self::fail('Broad delivery must require a successful current-version Owner test.');
        } catch (DomainException) {
            self::assertNull(DB::table('broadcast_campaigns')
                ->where('public_id', $created->publicId)
                ->value('audience_materialized_at'));
        }

        $this->successfulOwnerTest($created->publicId, $actor);
        $started = $service->startNow(
            $actor['user_id'],
            $created->publicId,
            $created->stateVersion,
        );

        self::assertSame(TelegramBroadcastCampaignState::Active, $started->state);
        self::assertSame(1, $started->recipientCount);
        self::assertSame(1, DB::table('broadcast_recipients')
            ->where('broadcast_campaign_id', $this->campaignId($created->publicId))
            ->where('delivery_state', 'queued')
            ->count());

        $cancelled = $service->cancel(
            $actor['user_id'],
            $created->publicId,
            $started->stateVersion,
        );
        self::assertSame(TelegramBroadcastCampaignState::Cancelled, $cancelled->state);
        self::assertSame(1, DB::table('broadcast_recipients')
            ->where('broadcast_campaign_id', $this->campaignId($created->publicId))
            ->where('delivery_state', 'skipped')
            ->where('failure_code', 'broadcast_cancelled_before_effect')
            ->count());
    }

    public function test_empty_audience_completes_without_waiting_for_a_delivery_worker(): void
    {
        $actor = $this->owner(910051);
        $service = $this->app->make(TelegramBroadcastCampaignService::class);
        $audience = new TelegramBroadcastAudienceDefinition(
            manualUserPublicIds: [(string) Str::ulid()],
        );

        $immediate = $service->createDraft(
            $actor['user_id'],
            TelegramBroadcastMessageDefinition::newText('empty immediate audience'),
            $audience,
            'broadcast-empty-immediate',
        );
        $this->successfulOwnerTest($immediate->publicId, $actor);

        $completed = $service->startNow(
            $actor['user_id'],
            $immediate->publicId,
            $immediate->stateVersion,
        );

        self::assertSame(TelegramBroadcastCampaignState::Completed, $completed->state);
        self::assertSame(0, $completed->recipientCount);
        self::assertSame(0, DB::table('broadcast_recipients')
            ->where('broadcast_campaign_id', $this->campaignId($immediate->publicId))
            ->count());
        self::assertNotNull(DB::table('broadcast_campaigns')
            ->where('public_id', $immediate->publicId)
            ->value('completed_at'));

        $scheduled = $service->createDraft(
            $actor['user_id'],
            TelegramBroadcastMessageDefinition::newText('empty scheduled audience'),
            $audience,
            'broadcast-empty-scheduled',
        );
        $this->successfulOwnerTest($scheduled->publicId, $actor);
        $scheduledReceipt = $service->schedule(
            $actor['user_id'],
            $scheduled->publicId,
            $scheduled->stateVersion,
            new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC')),
        );
        self::assertSame(TelegramBroadcastCampaignState::Scheduled, $scheduledReceipt->state);

        DB::table('broadcast_campaigns')
            ->where('public_id', $scheduled->publicId)
            ->update(['scheduled_at' => DB::raw('created_at')]);

        self::assertSame(1, $service->activateDueCampaigns());
        $scheduledCompleted = $service->current($actor['user_id'], $scheduled->publicId);
        self::assertSame(TelegramBroadcastCampaignState::Completed, $scheduledCompleted->state);
        self::assertSame(0, $scheduledCompleted->recipientCount);
        self::assertNotNull(DB::table('broadcast_campaigns')
            ->where('public_id', $scheduled->publicId)
            ->value('started_at'));
        self::assertNotNull(DB::table('broadcast_campaigns')
            ->where('public_id', $scheduled->publicId)
            ->value('completed_at'));
    }

    public function test_campaign_reads_and_scheduled_worker_are_scoped_to_runtime_bot(): void
    {
        $actor = $this->owner(910061);
        $service = $this->app->make(TelegramBroadcastCampaignService::class);
        $audience = new TelegramBroadcastAudienceDefinition(
            manualUserPublicIds: [(string) Str::ulid()],
        );
        $campaign = $service->createDraft(
            $actor['user_id'],
            TelegramBroadcastMessageDefinition::newText('other bot campaign'),
            $audience,
            'broadcast-other-bot',
        );
        $this->successfulOwnerTest($campaign->publicId, $actor);
        $scheduled = $service->schedule(
            $actor['user_id'],
            $campaign->publicId,
            $campaign->stateVersion,
            new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC')),
        );
        self::assertSame(TelegramBroadcastCampaignState::Scheduled, $scheduled->state);

        DB::table('broadcast_campaigns')
            ->where('public_id', $campaign->publicId)
            ->update([
                'bot_id' => '987654321',
                'scheduled_at' => DB::raw('created_at'),
            ]);

        try {
            $service->current($actor['user_id'], $campaign->publicId);
            self::fail('A campaign owned by another Telegram bot must not be readable in this runtime.');
        } catch (DomainException) {
            self::assertSame(0, $service->activateDueCampaigns());
        }

        self::assertSame(
            TelegramBroadcastCampaignState::Scheduled->value,
            DB::table('broadcast_campaigns')
                ->where('public_id', $campaign->publicId)
                ->value('state'),
        );
    }

    public function test_fresh_owner_source_boundary_is_not_misclassified_but_stale_boundary_is_uncertain(): void
    {
        $actor = $this->owner(910071);
        $service = $this->app->make(TelegramBroadcastCampaignService::class);
        $campaign = $service->createDraft(
            $actor['user_id'],
            TelegramBroadcastMessageDefinition::copy(
                $actor['telegram_user_id'],
                71,
                TelegramBroadcastSourceKind::Photo,
            ),
            new TelegramBroadcastAudienceDefinition,
            'broadcast-owner-test-boundary',
        );
        $campaignId = $this->campaignId($campaign->publicId);
        $messageVersionId = DB::table('broadcast_message_versions')
            ->where('broadcast_campaign_id', $campaignId)
            ->where('version', 1)
            ->value('id');
        self::assertIsNumeric($messageVersionId);
        $now = now('UTC');

        DB::table('broadcast_campaign_tests')->insert([
            'public_id' => (string) Str::ulid(),
            'broadcast_campaign_id' => $campaignId,
            'broadcast_message_version_id' => (int) $messageVersionId,
            'owner_administrator_id' => $actor['administrator_id'],
            'telegram_account_id' => $actor['telegram_account_id'],
            'request_key_hash' => hash('sha256', 'fresh-owner-boundary'),
            'state' => 'sending',
            'delivery_operation_public_id' => null,
            'telegram_message_id' => null,
            'result_code' => null,
            'provider_boundary_started_at' => $now,
            'provider_boundary_finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $ownerTests = $this->app->make(TelegramBroadcastOwnerTestService::class);
        $fresh = $ownerTests->reconcileCurrent($actor['user_id'], $campaign->publicId);
        self::assertNotNull($fresh);
        self::assertSame('sending', $fresh->state);

        DB::table('broadcast_campaign_tests')
            ->where('public_id', $fresh->publicId)
            ->update([
                'provider_boundary_started_at' => now('UTC')->subMinutes(10),
                'updated_at' => now('UTC'),
            ]);

        $stale = $ownerTests->reconcileCurrent($actor['user_id'], $campaign->publicId);
        self::assertNotNull($stale);
        self::assertSame('uncertain', $stale->state);
        self::assertSame(
            'broadcast_owner_test_interrupted_source_effect',
            $stale->resultCode,
        );
    }

    public function test_fresh_direct_lifecycle_boundary_waits_for_worker_but_stale_boundary_becomes_uncertain(): void
    {
        $actor = $this->owner(910081);
        $this->telegramUser(910082);
        $campaign = $this->startedCampaign($actor, 'broadcast-lifecycle-boundary');
        $campaignId = $this->campaignId($campaign->publicId);
        $now = now('UTC');

        $recipients = DB::table('broadcast_recipients')
            ->where('broadcast_campaign_id', $campaignId)
            ->orderBy('id')
            ->get(['id']);
        foreach ($recipients as $index => $recipient) {
            DB::table('broadcast_recipients')->where('id', (int) $recipient->id)->update([
                'delivery_state' => 'sent',
                'telegram_message_id' => 8200 + $index,
                'failure_code' => null,
                'sent_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $campaignService = $this->app->make(TelegramBroadcastCampaignService::class);
        self::assertTrue($campaignService->completeIfFinished($campaign->publicId));
        $completed = $campaignService->current($actor['user_id'], $campaign->publicId);

        $lifecycle = $this->app->make(TelegramBroadcastLifecycleService::class);
        $batch = $lifecycle->queueAction(
            $actor['user_id'],
            $campaign->publicId,
            $completed->stateVersion,
            TelegramBroadcastLifecycleAction::Pin,
            'broadcast-lifecycle-boundary-pin',
        );
        $operationId = DB::table('broadcast_recipient_messages')
            ->where('operation_group_public_id', $batch->groupPublicId)
            ->orderBy('id')
            ->value('id');
        self::assertIsNumeric($operationId);

        DB::table('broadcast_recipient_messages')
            ->where('id', (int) $operationId)
            ->update([
                'state' => 'sending',
                'provider_boundary_started_at' => $now,
                'provider_boundary_finished_at' => null,
                'updated_at' => $now,
            ]);

        $runner = $this->app->make(TelegramBroadcastLifecycleRunner::class);
        self::assertSame(0, $runner->reconcile());
        self::assertSame('sending', DB::table('broadcast_recipient_messages')
            ->where('id', (int) $operationId)
            ->value('state'));

        DB::table('broadcast_recipient_messages')
            ->where('id', (int) $operationId)
            ->update([
                'provider_boundary_started_at' => now('UTC')->subMinutes(10),
                'updated_at' => now('UTC'),
            ]);

        self::assertSame(1, $runner->reconcile());
        self::assertSame('uncertain', DB::table('broadcast_recipient_messages')
            ->where('id', (int) $operationId)
            ->value('state'));
        self::assertSame(
            'broadcast_lifecycle_interrupted_after_boundary',
            DB::table('broadcast_recipient_messages')
                ->where('id', (int) $operationId)
                ->value('result_code'),
        );
    }

    public function test_source_message_is_bound_to_authorized_creator_chat(): void
    {
        $actor = $this->owner(910101);
        $other = $this->telegramUser(910102);
        $service = $this->app->make(TelegramBroadcastCampaignService::class);

        try {
            $service->createDraft(
                $actor['user_id'],
                TelegramBroadcastMessageDefinition::copy(
                    $other['telegram_user_id'],
                    42,
                    TelegramBroadcastSourceKind::Photo,
                ),
                new TelegramBroadcastAudienceDefinition,
                'broadcast-source-cross-actor',
            );
            self::fail('Broadcast source message must not cross administrator chat authority.');
        } catch (DomainException) {
            self::assertSame(0, DB::table('broadcast_campaigns')->count());
        }

        $accepted = $service->createDraft(
            $actor['user_id'],
            TelegramBroadcastMessageDefinition::copy(
                $actor['telegram_user_id'],
                43,
                TelegramBroadcastSourceKind::Photo,
                'کپشن اولیه',
            ),
            new TelegramBroadcastAudienceDefinition,
            'broadcast-source-own-chat',
        );

        self::assertSame('copy', DB::table('broadcast_message_versions')
            ->where('broadcast_campaign_id', $this->campaignId($accepted->publicId))
            ->value('mode'));
        self::assertSame($actor['telegram_user_id'], (int) DB::table('broadcast_message_versions')
            ->where('broadcast_campaign_id', $this->campaignId($accepted->publicId))
            ->value('source_chat_id'));
    }

    public function test_source_retry_after_is_persisted_and_blocks_failed_recipient_retry_until_due(): void
    {
        $actor = $this->owner(910181);
        $target = $this->telegramUser(910182);
        $targetPublicId = DB::table('users')->where('id', $target['user_id'])->value('public_id');
        self::assertIsString($targetPublicId);

        $service = $this->app->make(TelegramBroadcastCampaignService::class);
        $created = $service->createDraft(
            $actor['user_id'],
            TelegramBroadcastMessageDefinition::copy(
                $actor['telegram_user_id'],
                81001,
                TelegramBroadcastSourceKind::Text,
            ),
            new TelegramBroadcastAudienceDefinition(manualUserPublicIds: [$targetPublicId]),
            'broadcast-source-retry-window',
        );
        $this->successfulOwnerTest($created->publicId, $actor);
        $started = $service->startNow(
            $actor['user_id'],
            $created->publicId,
            $created->stateVersion,
        );
        self::assertSame(1, $started->recipientCount);

        $this->app->instance(TelegramSourceMessageSender::class, new class implements TelegramSourceMessageSender
        {
            public function send(
                int $recipientChatId,
                TelegramResolvedSourceMessagePresentation $source,
                ?TelegramResolvedInlineKeyboardMarkup $inlineKeyboard = null,
            ): TelegramMutationResult {
                return new TelegramMutationResult(
                    TelegramMutationOutcome::RetryAfter,
                    'telegram_source_message_retry_after',
                    retryAfterSeconds: 120,
                );
            }
        });

        $runner = $this->app->make(TelegramBroadcastDeliveryRunner::class);
        self::assertSame(1, $runner->processBatch(1));

        $recipient = DB::table('broadcast_recipients')
            ->where('broadcast_campaign_id', $this->campaignId($created->publicId))
            ->first(['id', 'delivery_state', 'retry_not_before']);
        self::assertNotNull($recipient);
        self::assertSame('failed_transient', $recipient->delivery_state);
        self::assertIsString($recipient->retry_not_before);
        self::assertGreaterThan(now('UTC')->format('Y-m-d H:i:s.u'), $recipient->retry_not_before);
        self::assertSame(
            TelegramBroadcastCampaignState::Completed,
            $service->current($actor['user_id'], $created->publicId)->state,
        );

        $retry = $this->app->make(TelegramBroadcastRetryService::class);
        $completed = $service->current($actor['user_id'], $created->publicId);
        try {
            $retry->retryFailed(
                $actor['user_id'],
                $created->publicId,
                $completed->stateVersion,
                'broadcast-source-retry-too-early',
            );
            self::fail('Provider-directed retry window must block an early broadcast retry.');
        } catch (DomainException) {
            self::assertSame('failed_transient', DB::table('broadcast_recipients')
                ->where('id', (int) $recipient->id)
                ->value('delivery_state'));
        }

        DB::table('broadcast_recipients')
            ->where('id', (int) $recipient->id)
            ->update(['retry_not_before' => null]);

        self::assertSame(1, $retry->retryFailed(
            $actor['user_id'],
            $created->publicId,
            $completed->stateVersion,
            'broadcast-source-retry-after-window',
        ));
    }

    public function test_lifecycle_retry_after_blocks_new_mutation_for_same_recipient_until_due(): void
    {
        $actor = $this->owner(910191);
        $this->telegramUser(910192);
        $campaign = $this->startedCampaign($actor, 'broadcast-lifecycle-retry-window');
        $campaignId = $this->campaignId($campaign->publicId);
        $now = now('UTC');

        $recipients = DB::table('broadcast_recipients')
            ->where('broadcast_campaign_id', $campaignId)
            ->orderBy('id')
            ->get(['id', 'public_id']);
        self::assertNotEmpty($recipients);
        foreach ($recipients as $index => $recipient) {
            DB::table('broadcast_recipients')->where('id', (int) $recipient->id)->update([
                'delivery_state' => 'sent',
                'telegram_message_id' => 8300 + $index,
                'failure_code' => null,
                'retry_not_before' => null,
                'sent_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $service = $this->app->make(TelegramBroadcastCampaignService::class);
        self::assertTrue($service->completeIfFinished($campaign->publicId));
        $completed = $service->current($actor['user_id'], $campaign->publicId);
        $recipientPublicId = (string) $recipients[0]->public_id;

        $lifecycle = $this->app->make(TelegramBroadcastLifecycleService::class);
        $batch = $lifecycle->queueAction(
            $actor['user_id'],
            $campaign->publicId,
            $completed->stateVersion,
            TelegramBroadcastLifecycleAction::Pin,
            'broadcast-lifecycle-retry-window-pin',
            [$recipientPublicId],
        );

        $this->app->instance(
            TelegramBroadcastLifecycleTransport::class,
            new class implements TelegramBroadcastLifecycleTransport
            {
                public function mutate(
                    TelegramBroadcastLifecycleMutationRequest $request,
                ): TelegramMutationResult {
                    return new TelegramMutationResult(
                        TelegramMutationOutcome::RetryAfter,
                        'tg_broadcast_lifecycle_retry_after',
                        retryAfterSeconds: 90,
                    );
                }
            },
        );

        $runner = $this->app->make(TelegramBroadcastLifecycleRunner::class);
        self::assertSame(1, $runner->processBatch(1));

        $operation = DB::table('broadcast_recipient_messages')
            ->where('operation_group_public_id', $batch->groupPublicId)
            ->first(['id', 'state', 'retry_not_before']);
        self::assertNotNull($operation);
        self::assertSame('retryable', $operation->state);
        self::assertIsString($operation->retry_not_before);
        self::assertGreaterThan(now('UTC')->format('Y-m-d H:i:s.u'), $operation->retry_not_before);

        try {
            $lifecycle->queueAction(
                $actor['user_id'],
                $campaign->publicId,
                $batch->stateVersion,
                TelegramBroadcastLifecycleAction::Unpin,
                'broadcast-lifecycle-retry-window-unpin-too-early',
                [$recipientPublicId],
            );
            self::fail('Provider-directed lifecycle retry window must block a new mutation.');
        } catch (DomainException) {
            self::assertSame('retryable', DB::table('broadcast_recipient_messages')
                ->where('id', (int) $operation->id)
                ->value('state'));
        }

        DB::table('broadcast_recipient_messages')
            ->where('id', (int) $operation->id)
            ->update(['retry_not_before' => null]);

        $next = $lifecycle->queueAction(
            $actor['user_id'],
            $campaign->publicId,
            $batch->stateVersion,
            TelegramBroadcastLifecycleAction::Unpin,
            'broadcast-lifecycle-retry-window-unpin-due',
            [$recipientPublicId],
        );
        self::assertSame(1, $next->recipientCount);
    }

    public function test_retry_never_requeues_uncertain_recipient_and_reopens_only_definitive_failure(): void
    {
        $actor = $this->owner(910201);
        $first = $this->telegramUser(910202);
        $second = $this->telegramUser(910203);
        $campaign = $this->startedCampaign($actor, 'broadcast-retry-campaign');
        $campaignId = $this->campaignId($campaign->publicId);

        $recipients = DB::table('broadcast_recipients')
            ->where('broadcast_campaign_id', $campaignId)
            ->orderBy('id')
            ->get(['id', 'public_id']);
        self::assertCount(3, $recipients);

        $now = now('UTC');
        DB::table('broadcast_recipients')->where('id', (int) $recipients[0]->id)->update([
            'delivery_state' => 'sent',
            'telegram_message_id' => 7001,
            'failure_code' => null,
            'sent_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('broadcast_recipients')->where('id', (int) $recipients[1]->id)->update([
            'delivery_state' => 'failed_transient',
            'telegram_message_id' => null,
            'failure_code' => 'telegram_delivery_retryable',
            'sent_at' => null,
            'updated_at' => $now,
        ]);
        DB::table('broadcast_recipients')->where('id', (int) $recipients[2]->id)->update([
            'delivery_state' => 'uncertain',
            'telegram_message_id' => null,
            'failure_code' => 'telegram_delivery_uncertain',
            'sent_at' => null,
            'updated_at' => $now,
        ]);

        $service = $this->app->make(TelegramBroadcastCampaignService::class);
        self::assertTrue($service->completeIfFinished($campaign->publicId));
        $completed = $service->current($actor['user_id'], $campaign->publicId);
        self::assertSame(TelegramBroadcastCampaignState::Completed, $completed->state);

        $retry = $this->app->make(TelegramBroadcastRetryService::class);
        try {
            $retry->retryFailed(
                $actor['user_id'],
                $campaign->publicId,
                $completed->stateVersion,
                'broadcast-retry-mixed-selection',
                [(string) $recipients[1]->public_id, (string) $recipients[2]->public_id],
            );
            self::fail('Uncertain recipient must never enter automatic failed-recipient retry.');
        } catch (DomainException) {
            self::assertSame('uncertain', DB::table('broadcast_recipients')
                ->where('id', (int) $recipients[2]->id)
                ->value('delivery_state'));
        }

        self::assertSame(1, $retry->retryFailed(
            $actor['user_id'],
            $campaign->publicId,
            $completed->stateVersion,
            'broadcast-retry-definitive-only',
        ));
        self::assertSame('queued', DB::table('broadcast_recipients')
            ->where('id', (int) $recipients[1]->id)
            ->value('delivery_state'));
        self::assertSame('uncertain', DB::table('broadcast_recipients')
            ->where('id', (int) $recipients[2]->id)
            ->value('delivery_state'));
        self::assertSame(TelegramBroadcastCampaignState::Active, $service
            ->current($actor['user_id'], $campaign->publicId)
            ->state);

        unset($first, $second);
    }

    public function test_lifecycle_revision_requires_new_owner_test_and_groups_exact_recipient_operations(): void
    {
        $actor = $this->owner(910301);
        $this->telegramUser(910302);
        $campaign = $this->startedCampaign($actor, 'broadcast-lifecycle-campaign');
        $campaignId = $this->campaignId($campaign->publicId);
        $now = now('UTC');

        $recipients = DB::table('broadcast_recipients')
            ->where('broadcast_campaign_id', $campaignId)
            ->orderBy('id')
            ->get(['id']);
        foreach ($recipients as $index => $recipient) {
            DB::table('broadcast_recipients')->where('id', (int) $recipient->id)->update([
                'delivery_state' => 'sent',
                'telegram_message_id' => 8100 + $index,
                'failure_code' => null,
                'sent_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $campaignService = $this->app->make(TelegramBroadcastCampaignService::class);
        self::assertTrue($campaignService->completeIfFinished($campaign->publicId));
        $completed = $campaignService->current($actor['user_id'], $campaign->publicId);

        $lifecycle = $this->app->make(TelegramBroadcastLifecycleService::class);
        $revision = $lifecycle->reviseContent(
            $actor['user_id'],
            $campaign->publicId,
            $completed->stateVersion,
            TelegramBroadcastMessageDefinition::newText('نسخه ویرایش‌شده'),
        );
        self::assertSame(2, $revision->messageVersion);

        try {
            $lifecycle->queueAction(
                $actor['user_id'],
                $campaign->publicId,
                $revision->stateVersion,
                TelegramBroadcastLifecycleAction::Edit,
                'broadcast-lifecycle-before-owner-test',
            );
            self::fail('Lifecycle mutation must require a successful test of the revised message version.');
        } catch (DomainException) {
            self::assertSame(0, DB::table('broadcast_recipient_messages')
                ->where('action', 'edit')
                ->count());
        }

        $this->successfulOwnerTest($campaign->publicId, $actor, 2);
        $batch = $lifecycle->queueAction(
            $actor['user_id'],
            $campaign->publicId,
            $revision->stateVersion,
            TelegramBroadcastLifecycleAction::Edit,
            'broadcast-lifecycle-edit-batch',
        );

        self::assertSame(count($recipients), $batch->recipientCount);
        self::assertSame(count($recipients), DB::table('broadcast_recipient_messages')
            ->where('operation_group_public_id', $batch->groupPublicId)
            ->where('action', 'edit')
            ->where('state', 'prepared')
            ->count());

        $progress = $lifecycle->progress(
            $actor['user_id'],
            $campaign->publicId,
            $batch->groupPublicId,
        );
        self::assertSame($batch->recipientCount, $progress->prepared);
        self::assertSame(0, $progress->finishedCount());

        try {
            $lifecycle->queueAction(
                $actor['user_id'],
                $campaign->publicId,
                $batch->stateVersion,
                TelegramBroadcastLifecycleAction::Delete,
                'broadcast-lifecycle-overlap',
            );
            self::fail('Overlapping lifecycle batches must be rejected.');
        } catch (DomainException) {
            self::assertSame($batch->recipientCount, DB::table('broadcast_recipient_messages')
                ->where('operation_group_public_id', $batch->groupPublicId)
                ->count());
        }
    }

    /**
     * @return array{user_id:int,administrator_id:int,telegram_account_id:int,telegram_user_id:int}
     */
    private function owner(int $telegramUserId): array
    {
        $identity = $this->telegramUser($telegramUserId);
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $identity['user_id'],
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            ...$identity,
            'administrator_id' => $administratorId,
        ];
    }

    /** @return array{user_id:int,telegram_account_id:int,telegram_user_id:int} */
    private function telegramUser(int $telegramUserId): array
    {
        $now = now('UTC');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $telegramAccountId = (int) DB::table('telegram_accounts')->insertGetId([
            'user_id' => $userId,
            'bot_id' => 123456789,
            'telegram_user_id' => $telegramUserId,
            'username' => 'broadcast_'.$telegramUserId,
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'user_id' => $userId,
            'telegram_account_id' => $telegramAccountId,
            'telegram_user_id' => $telegramUserId,
        ];
    }

    private function startedCampaign(array $actor, string $requestKey): TelegramBroadcastCampaignReceipt
    {
        $service = $this->app->make(TelegramBroadcastCampaignService::class);
        $created = $service->createDraft(
            $actor['user_id'],
            TelegramBroadcastMessageDefinition::newText('متن کمپین'),
            new TelegramBroadcastAudienceDefinition,
            $requestKey,
        );
        $this->successfulOwnerTest($created->publicId, $actor);

        return $service->startNow(
            $actor['user_id'],
            $created->publicId,
            $created->stateVersion,
        );
    }

    private function successfulOwnerTest(string $campaignPublicId, array $owner, int $messageVersion = 1): void
    {
        $campaignId = $this->campaignId($campaignPublicId);
        $messageVersionId = DB::table('broadcast_message_versions')
            ->where('broadcast_campaign_id', $campaignId)
            ->where('version', $messageVersion)
            ->value('id');
        self::assertIsNumeric($messageVersionId);
        $now = now('UTC');

        DB::table('broadcast_campaign_tests')->insert([
            'public_id' => (string) Str::ulid(),
            'broadcast_campaign_id' => $campaignId,
            'broadcast_message_version_id' => (int) $messageVersionId,
            'owner_administrator_id' => $owner['administrator_id'],
            'telegram_account_id' => $owner['telegram_account_id'],
            'request_key_hash' => hash('sha256', $campaignPublicId.'|owner-test|'.$messageVersion),
            'state' => 'succeeded',
            'delivery_operation_public_id' => null,
            'telegram_message_id' => 9900 + $messageVersion,
            'result_code' => 'telegram_test_success',
            'provider_boundary_started_at' => null,
            'provider_boundary_finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function campaignId(string $publicId): int
    {
        $id = DB::table('broadcast_campaigns')->where('public_id', $publicId)->value('id');
        self::assertIsNumeric($id);

        return (int) $id;
    }
}
