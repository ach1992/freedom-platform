<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Modules\Telegram\Domain\TelegramBroadcastMessageMode;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * @phpstan-type CampaignRow object{
 *     id:int|string,
 *     public_id:string,
 *     state:string,
 *     state_version:int|string,
 *     current_message_version:int|string,
 *     current_audience_version:int|string,
 *     recipient_count:int|string,
 *     audience_materialized_at:?string,
 *     scheduled_at:?string,
 *     started_at:?string,
 *     completed_at:?string,
 *     cancelled_at:?string
 * }
 */
final readonly class TelegramBroadcastCampaignService
{
    public const PERMISSION = 'telegram.broadcasts.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administratorUsers,
        private AdministratorPermissionAuthorizer $administrators,
        private TelegramDeliveryRuntime $runtime,
        private TelegramBroadcastAudienceMaterializer $audiences,
        private Clock $clock,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administratorUsers->allowsUser($actorUserId, self::PERMISSION);
    }

    /** @requirement COM-002 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
    public function createDraft(
        int $actorUserId,
        TelegramBroadcastMessageDefinition $message,
        TelegramBroadcastAudienceDefinition $audience,
        #[SensitiveParameter] string $requestKey,
    ): TelegramBroadcastCampaignReceipt {
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
        $botId = $this->botId();
        $this->assertSourceBoundToActor($actorUserId, $botId, $message);
        $requestHash = $this->requestHash($requestKey);
        $messageHash = $message->contentHash();
        $audienceHash = $audience->hash();
        $connection = $this->database->connection();

        try {
            return $connection->transaction(function (Connection $connection) use (
                $administratorId,
                $botId,
                $requestHash,
                $message,
                $messageHash,
                $audience,
                $audienceHash,
            ): TelegramBroadcastCampaignReceipt {
                $existingId = $connection->table('broadcast_campaigns')
                    ->where('create_request_hash', $requestHash)
                    ->lockForUpdate()
                    ->value('id');
                if (is_int($existingId) || is_string($existingId)) {
                    return $this->replayCreatedCampaign(
                        $connection,
                        (int) $existingId,
                        $administratorId,
                        $botId,
                        $messageHash,
                        $audienceHash,
                    );
                }

                $now = $this->timestamp();
                $publicId = (string) Str::ulid();
                $campaignId = $connection->table('broadcast_campaigns')->insertGetId([
                    'public_id' => $publicId,
                    'create_request_hash' => $requestHash,
                    'actor_administrator_id' => $administratorId,
                    'bot_id' => $botId,
                    'state' => TelegramBroadcastCampaignState::Draft->value,
                    'state_version' => 1,
                    'current_message_version' => 1,
                    'current_audience_version' => 1,
                    'recipient_count' => 0,
                    'correlation_id' => 'tg-broadcast:'.substr(hash('sha256', $publicId), 0, 40),
                    'audience_materialized_at' => null,
                    'scheduled_at' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'cancelled_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $connection->table('broadcast_message_versions')->insert([
                    'public_id' => (string) Str::ulid(),
                    'broadcast_campaign_id' => $campaignId,
                    'version' => 1,
                    'mode' => $message->mode->value,
                    'text' => $message->text,
                    'source_chat_id' => $message->sourceChatId,
                    'source_message_id' => $message->sourceMessageId,
                    'inline_keyboard_snapshot' => $message->inlineKeyboardJson(),
                    'content_hash' => $messageHash,
                    'actor_administrator_id' => $administratorId,
                    'created_at' => $now,
                ]);

                $connection->table('broadcast_audiences')->insert([
                    'broadcast_campaign_id' => $campaignId,
                    'version' => 1,
                    'filter_snapshot' => $audience->json(),
                    'snapshot_hash' => $audienceHash,
                    'estimated_recipient_count' => 0,
                    'created_at' => $now,
                ]);

                return $this->receiptById($connection, $campaignId, false);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $existingId = $connection->table('broadcast_campaigns')
                ->where('create_request_hash', $requestHash)
                ->value('id');
            if (! is_int($existingId) && ! is_string($existingId)) {
                throw $exception;
            }

            return $this->replayCreatedCampaign(
                $connection,
                (int) $existingId,
                $administratorId,
                $botId,
                $messageHash,
                $audienceHash,
            );
        }
    }

    /** @requirement COM-002 ACL-002 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function replaceDraftMessage(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
        TelegramBroadcastMessageDefinition $message,
    ): TelegramBroadcastCampaignReceipt {
        $administratorId = $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
        $this->assertPublicId($campaignPublicId);
        $this->assertStateVersion($expectedStateVersion);
        $botId = $this->botId();
        $this->assertSourceBoundToActor($actorUserId, $botId, $message);
        $messageHash = $message->contentHash();

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $campaignPublicId,
            $expectedStateVersion,
            $administratorId,
            $message,
            $messageHash,
        ): TelegramBroadcastCampaignReceipt {
            $campaign = $this->lockedCampaign($connection, $campaignPublicId);
            $this->assertDraftMutable($campaign);

            $current = $connection->table('broadcast_message_versions')
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->where('version', (int) $campaign->current_message_version)
                ->first(['content_hash']);
            if ($current === null) {
                throw new RuntimeException('Broadcast current message version is missing.');
            }
            if (hash_equals((string) $current->content_hash, $messageHash)) {
                return $this->receiptById($connection, (int) $campaign->id, true);
            }
            $this->assertExpectedStateVersion($campaign, $expectedStateVersion);

            $version = (int) $campaign->current_message_version + 1;
            $now = $this->timestamp();
            $connection->table('broadcast_message_versions')->insert([
                'public_id' => (string) Str::ulid(),
                'broadcast_campaign_id' => (int) $campaign->id,
                'version' => $version,
                'mode' => $message->mode->value,
                'text' => $message->text,
                'source_chat_id' => $message->sourceChatId,
                'source_message_id' => $message->sourceMessageId,
                'inline_keyboard_snapshot' => $message->inlineKeyboardJson(),
                'content_hash' => $messageHash,
                'actor_administrator_id' => $administratorId,
                'created_at' => $now,
            ]);

            $this->updateDraftVersion(
                $connection,
                (int) $campaign->id,
                $expectedStateVersion,
                ['current_message_version' => $version],
                $now,
            );

            return $this->receiptById($connection, (int) $campaign->id, false);
        }, 3);
    }

    /** @requirement COM-002 ACL-002 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function replaceDraftAudience(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
        TelegramBroadcastAudienceDefinition $audience,
    ): TelegramBroadcastCampaignReceipt {
        $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
        $this->assertPublicId($campaignPublicId);
        $this->assertStateVersion($expectedStateVersion);
        $audienceHash = $audience->hash();

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $campaignPublicId,
            $expectedStateVersion,
            $audience,
            $audienceHash,
        ): TelegramBroadcastCampaignReceipt {
            $campaign = $this->lockedCampaign($connection, $campaignPublicId);
            $this->assertDraftMutable($campaign);

            $current = $connection->table('broadcast_audiences')
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->where('version', (int) $campaign->current_audience_version)
                ->first(['snapshot_hash']);
            if ($current === null) {
                throw new RuntimeException('Broadcast current audience version is missing.');
            }
            if (hash_equals((string) $current->snapshot_hash, $audienceHash)) {
                return $this->receiptById($connection, (int) $campaign->id, true);
            }
            $this->assertExpectedStateVersion($campaign, $expectedStateVersion);

            $version = (int) $campaign->current_audience_version + 1;
            $now = $this->timestamp();
            $connection->table('broadcast_audiences')->insert([
                'broadcast_campaign_id' => (int) $campaign->id,
                'version' => $version,
                'filter_snapshot' => $audience->json(),
                'snapshot_hash' => $audienceHash,
                'estimated_recipient_count' => 0,
                'created_at' => $now,
            ]);

            $this->updateDraftVersion(
                $connection,
                (int) $campaign->id,
                $expectedStateVersion,
                ['current_audience_version' => $version],
                $now,
            );

            return $this->receiptById($connection, (int) $campaign->id, false);
        }, 3);
    }

    /** @requirement COM-002 ACL-002 DAT-003 SEC-002 */
    public function estimateAudience(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedAudienceVersion,
    ): int {
        $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);

        return $this->audiences->estimate($campaignPublicId, $expectedAudienceVersion);
    }

    /** @requirement COM-002 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
    public function startNow(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
    ): TelegramBroadcastCampaignReceipt {
        return $this->launch($actorUserId, $campaignPublicId, $expectedStateVersion, null);
    }

    /** @requirement COM-002 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
    public function schedule(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
        DateTimeImmutable $scheduledAt,
    ): TelegramBroadcastCampaignReceipt {
        $scheduledAt = $scheduledAt->setTimezone(new DateTimeZone('UTC'));
        if ($scheduledAt <= $this->clock->now()) {
            throw new DomainException('Broadcast schedule must be in the future.');
        }

        return $this->launch($actorUserId, $campaignPublicId, $expectedStateVersion, $scheduledAt);
    }

    /** @requirement COM-002 ACL-002 DAT-003 SEC-002 QUA-001 */
    public function pause(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
    ): TelegramBroadcastCampaignReceipt {
        return $this->actorTransition(
            $actorUserId,
            $campaignPublicId,
            $expectedStateVersion,
            TelegramBroadcastCampaignState::Active,
            TelegramBroadcastCampaignState::Paused,
        );
    }

    /** @requirement COM-002 ACL-002 DAT-003 SEC-002 QUA-001 */
    public function resume(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
    ): TelegramBroadcastCampaignReceipt {
        return $this->actorTransition(
            $actorUserId,
            $campaignPublicId,
            $expectedStateVersion,
            TelegramBroadcastCampaignState::Paused,
            TelegramBroadcastCampaignState::Active,
        );
    }

    /** @requirement COM-002 ACL-002 DAT-003 SEC-002 QUA-001 */
    public function cancel(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
    ): TelegramBroadcastCampaignReceipt {
        $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
        $this->assertPublicId($campaignPublicId);
        $this->assertStateVersion($expectedStateVersion);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $campaignPublicId,
            $expectedStateVersion,
        ): TelegramBroadcastCampaignReceipt {
            $campaign = $this->lockedCampaign($connection, $campaignPublicId);
            $state = $this->campaignState($campaign);
            if (! in_array($state, [
                TelegramBroadcastCampaignState::Draft,
                TelegramBroadcastCampaignState::Scheduled,
                TelegramBroadcastCampaignState::Active,
                TelegramBroadcastCampaignState::Paused,
            ], true)) {
                throw new DomainException('Broadcast campaign cannot be cancelled from its current state.');
            }
            $this->assertExpectedStateVersion($campaign, $expectedStateVersion);

            $now = $this->timestamp();
            $updated = $connection->table('broadcast_campaigns')
                ->where('id', (int) $campaign->id)
                ->where('state_version', $expectedStateVersion)
                ->update([
                    'state' => TelegramBroadcastCampaignState::Cancelled->value,
                    'state_version' => $expectedStateVersion + 1,
                    'cancelled_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new DomainException('Broadcast campaign changed before cancellation completed.');
            }

            return $this->receiptById($connection, (int) $campaign->id, false);
        }, 3);
    }

    /** @requirement COM-002 ACL-002 DAT-003 SEC-002 */
    public function progress(int $actorUserId, string $campaignPublicId): TelegramBroadcastProgress
    {
        $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
        $this->assertPublicId($campaignPublicId);
        $connection = $this->database->connection();
        $campaign = $connection->table('broadcast_campaigns')
            ->where('public_id', $campaignPublicId)
            ->first(['id', 'public_id', 'state', 'state_version', 'recipient_count']);
        if ($campaign === null) {
            throw new DomainException('Broadcast campaign is unavailable.');
        }

        $counts = [
            'queued' => 0,
            'sending' => 0,
            'sent' => 0,
            'failed_transient' => 0,
            'failed_permanent' => 0,
            'skipped' => 0,
            'uncertain' => 0,
        ];
        foreach ($connection->table('broadcast_recipients')
            ->where('broadcast_campaign_id', (int) $campaign->id)
            ->selectRaw('delivery_state, COUNT(*) AS aggregate_count')
            ->groupBy('delivery_state')
            ->get() as $row) {
            $state = (string) $row->delivery_state;
            if (! array_key_exists($state, $counts)) {
                throw new RuntimeException('Broadcast recipient state is invalid.');
            }
            $counts[$state] = $this->nonNegativeInt($row->aggregate_count, 'Broadcast recipient state count');
        }

        $recipientCount = $this->nonNegativeInt($campaign->recipient_count, 'Broadcast recipient count');
        if (array_sum($counts) !== $recipientCount) {
            throw new RuntimeException('Broadcast recipient progress does not match the materialized audience.');
        }

        return new TelegramBroadcastProgress(
            (string) $campaign->public_id,
            $this->campaignState($campaign),
            $this->positiveInt($campaign->state_version, 'Broadcast state version'),
            $recipientCount,
            $counts['queued'],
            $counts['sending'],
            $counts['sent'],
            $counts['failed_transient'],
            $counts['failed_permanent'],
            $counts['skipped'],
            $counts['uncertain'],
        );
    }

    /** @requirement COM-002 ACL-002 DAT-003 SEC-002 OPS-003 */
    public function activateDueCampaigns(int $limit = 25): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new DomainException('Broadcast activation limit must be between 1 and 100.');
        }

        $now = $this->timestamp();
        $rows = $this->database->connection()->table('broadcast_campaigns')
            ->where('state', TelegramBroadcastCampaignState::Scheduled->value)
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'actor_administrator_id']);

        $activated = 0;
        foreach ($rows as $row) {
            try {
                $this->administrators->authorize(
                    $this->positiveInt($row->actor_administrator_id, 'Broadcast creator administrator ID'),
                    self::PERMISSION,
                );
            } catch (AuthorizationException) {
                continue;
            }

            $activated += $this->database->connection()->transaction(function (Connection $connection) use (
                $row,
                $now,
            ): int {
                $campaign = $connection->table('broadcast_campaigns')
                    ->where('id', (int) $row->id)
                    ->lockForUpdate()
                    ->first([
                        'id',
                        'state',
                        'state_version',
                        'current_message_version',
                        'scheduled_at',
                        'audience_materialized_at',
                    ]);
                if ($campaign === null
                    || (string) $campaign->state !== TelegramBroadcastCampaignState::Scheduled->value
                    || $campaign->scheduled_at === null
                    || (string) $campaign->scheduled_at > $now
                    || $campaign->audience_materialized_at === null
                ) {
                    return 0;
                }
                $this->assertSuccessfulOwnerTest(
                    $connection,
                    (int) $campaign->id,
                    (int) $campaign->current_message_version,
                );

                $version = $this->positiveInt($campaign->state_version, 'Broadcast state version');
                $updated = $connection->table('broadcast_campaigns')
                    ->where('id', (int) $campaign->id)
                    ->where('state', TelegramBroadcastCampaignState::Scheduled->value)
                    ->where('state_version', $version)
                    ->update([
                        'state' => TelegramBroadcastCampaignState::Active->value,
                        'state_version' => $version + 1,
                        'started_at' => $now,
                        'updated_at' => $now,
                    ]);

                return $updated === 1 ? 1 : 0;
            }, 3);
        }

        return $activated;
    }

    /** @requirement COM-002 DAT-003 OPS-003 */
    public function completeIfFinished(string $campaignPublicId): bool
    {
        $this->assertPublicId($campaignPublicId);

        return $this->database->connection()->transaction(function (Connection $connection) use ($campaignPublicId): bool {
            $campaign = $this->lockedCampaign($connection, $campaignPublicId);
            if ((string) $campaign->state !== TelegramBroadcastCampaignState::Active->value) {
                return false;
            }

            $unfinished = $connection->table('broadcast_recipients')
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->whereIn('delivery_state', ['queued', 'sending'])
                ->exists();
            if ($unfinished) {
                return false;
            }

            $version = $this->positiveInt($campaign->state_version, 'Broadcast state version');
            $now = $this->timestamp();
            $updated = $connection->table('broadcast_campaigns')
                ->where('id', (int) $campaign->id)
                ->where('state', TelegramBroadcastCampaignState::Active->value)
                ->where('state_version', $version)
                ->update([
                    'state' => TelegramBroadcastCampaignState::Completed->value,
                    'state_version' => $version + 1,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            return $updated === 1;
        }, 3);
    }

    private function launch(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
        ?DateTimeImmutable $scheduledAt,
    ): TelegramBroadcastCampaignReceipt {
        $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
        $this->assertPublicId($campaignPublicId);
        $this->assertStateVersion($expectedStateVersion);

        $preflight = $this->database->connection()->table('broadcast_campaigns')
            ->where('public_id', $campaignPublicId)
            ->first(['id', 'state', 'state_version', 'current_message_version', 'current_audience_version']);
        if ($preflight === null
            || (string) $preflight->state !== TelegramBroadcastCampaignState::Draft->value
            || (int) $preflight->state_version !== $expectedStateVersion
        ) {
            throw new DomainException('Broadcast campaign changed before launch.');
        }
        $this->assertSuccessfulOwnerTest(
            $this->database->connection(),
            (int) $preflight->id,
            (int) $preflight->current_message_version,
        );

        $this->audiences->materialize(
            $campaignPublicId,
            (int) $preflight->current_audience_version,
            $expectedStateVersion,
        );

        $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
        $nowInstant = $this->clock->now();
        if ($scheduledAt !== null && $scheduledAt <= $nowInstant) {
            throw new DomainException('Broadcast schedule elapsed before launch completed.');
        }
        $now = $nowInstant->format('Y-m-d H:i:s.u');

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $campaignPublicId,
            $expectedStateVersion,
            $scheduledAt,
            $now,
        ): TelegramBroadcastCampaignReceipt {
            $campaign = $this->lockedCampaign($connection, $campaignPublicId);
            if ((string) $campaign->state !== TelegramBroadcastCampaignState::Draft->value
                || $campaign->audience_materialized_at === null
            ) {
                throw new DomainException('Broadcast campaign is not launchable.');
            }
            $this->assertExpectedStateVersion($campaign, $expectedStateVersion);
            $this->assertSuccessfulOwnerTest(
                $connection,
                (int) $campaign->id,
                (int) $campaign->current_message_version,
            );

            $state = $scheduledAt === null
                ? TelegramBroadcastCampaignState::Active
                : TelegramBroadcastCampaignState::Scheduled;
            $values = [
                'state' => $state->value,
                'state_version' => $expectedStateVersion + 1,
                'scheduled_at' => $scheduledAt?->format('Y-m-d H:i:s.u'),
                'started_at' => $scheduledAt === null ? $now : null,
                'updated_at' => $now,
            ];

            $updated = $connection->table('broadcast_campaigns')
                ->where('id', (int) $campaign->id)
                ->where('state', TelegramBroadcastCampaignState::Draft->value)
                ->where('state_version', $expectedStateVersion)
                ->update($values);
            if ($updated !== 1) {
                throw new DomainException('Broadcast campaign changed before launch completed.');
            }

            return $this->receiptById($connection, (int) $campaign->id, false);
        }, 3);
    }

    private function actorTransition(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
        TelegramBroadcastCampaignState $from,
        TelegramBroadcastCampaignState $to,
    ): TelegramBroadcastCampaignReceipt {
        $this->administratorUsers->authorizeUser($actorUserId, self::PERMISSION);
        $this->assertPublicId($campaignPublicId);
        $this->assertStateVersion($expectedStateVersion);

        if (! $from->canTransitionTo($to)) {
            throw new RuntimeException('Broadcast transition definition is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $campaignPublicId,
            $expectedStateVersion,
            $from,
            $to,
        ): TelegramBroadcastCampaignReceipt {
            $campaign = $this->lockedCampaign($connection, $campaignPublicId);
            if ($this->campaignState($campaign) !== $from) {
                throw new DomainException('Broadcast campaign is not in the expected state.');
            }
            $this->assertExpectedStateVersion($campaign, $expectedStateVersion);

            $now = $this->timestamp();
            $updated = $connection->table('broadcast_campaigns')
                ->where('id', (int) $campaign->id)
                ->where('state', $from->value)
                ->where('state_version', $expectedStateVersion)
                ->update([
                    'state' => $to->value,
                    'state_version' => $expectedStateVersion + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new DomainException('Broadcast campaign changed before its transition completed.');
            }

            return $this->receiptById($connection, (int) $campaign->id, false);
        }, 3);
    }

    private function replayCreatedCampaign(
        Connection $connection,
        int $campaignId,
        int $administratorId,
        string $botId,
        string $messageHash,
        string $audienceHash,
    ): TelegramBroadcastCampaignReceipt {
        $campaign = $connection->table('broadcast_campaigns')
            ->where('id', $campaignId)
            ->first(['actor_administrator_id', 'bot_id']);
        $message = $connection->table('broadcast_message_versions')
            ->where('broadcast_campaign_id', $campaignId)
            ->where('version', 1)
            ->first(['content_hash']);
        $audience = $connection->table('broadcast_audiences')
            ->where('broadcast_campaign_id', $campaignId)
            ->where('version', 1)
            ->first(['snapshot_hash']);
        if ($campaign === null
            || $message === null
            || $audience === null
            || (int) $campaign->actor_administrator_id !== $administratorId
            || ! hash_equals((string) $campaign->bot_id, $botId)
            || ! hash_equals((string) $message->content_hash, $messageHash)
            || ! hash_equals((string) $audience->snapshot_hash, $audienceHash)
        ) {
            throw new DomainException('Broadcast create request key was reused with different input.');
        }

        return $this->receiptById($connection, $campaignId, true);
    }

    /**
     * @param  array<string,int>  $values
     */
    private function updateDraftVersion(
        Connection $connection,
        int $campaignId,
        int $expectedStateVersion,
        array $values,
        string $now,
    ): void {
        $values['state_version'] = $expectedStateVersion + 1;
        $values['updated_at'] = $now;
        $updated = $connection->table('broadcast_campaigns')
            ->where('id', $campaignId)
            ->where('state', TelegramBroadcastCampaignState::Draft->value)
            ->whereNull('audience_materialized_at')
            ->where('state_version', $expectedStateVersion)
            ->update($values);
        if ($updated !== 1) {
            throw new DomainException('Broadcast draft changed before version update completed.');
        }
    }

    private function assertSuccessfulOwnerTest(Connection $connection, int $campaignId, int $messageVersion): void
    {
        $exists = $connection->table('broadcast_campaign_tests as test')
            ->join('broadcast_message_versions as message', 'message.id', '=', 'test.broadcast_message_version_id')
            ->join('administrators as owner', 'owner.id', '=', 'test.owner_administrator_id')
            ->where('test.broadcast_campaign_id', $campaignId)
            ->where('message.broadcast_campaign_id', $campaignId)
            ->where('message.version', $messageVersion)
            ->where('test.state', 'succeeded')
            ->where('owner.is_owner', true)
            ->where('owner.status', 'active')
            ->exists();
        if (! $exists) {
            throw new DomainException('Broadcast requires a successful current-version Owner test before launch.');
        }
    }

    /** @param CampaignRow $campaign */
    private function assertDraftMutable(object $campaign): void
    {
        if ((string) $campaign->state !== TelegramBroadcastCampaignState::Draft->value
            || $campaign->audience_materialized_at !== null
        ) {
            throw new DomainException('Broadcast draft is no longer mutable.');
        }
    }

    /** @return CampaignRow */
    private function lockedCampaign(Connection $connection, string $publicId): object
    {
        /** @var CampaignRow|null $campaign */
        $campaign = $connection->table('broadcast_campaigns')
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first([
                'id',
                'public_id',
                'state',
                'state_version',
                'current_message_version',
                'current_audience_version',
                'recipient_count',
                'audience_materialized_at',
                'scheduled_at',
                'started_at',
                'completed_at',
                'cancelled_at',
            ]);
        if ($campaign === null) {
            throw new DomainException('Broadcast campaign is unavailable.');
        }

        return $campaign;
    }

    private function receiptById(Connection $connection, int $campaignId, bool $replayed): TelegramBroadcastCampaignReceipt
    {
        $row = $connection->table('broadcast_campaigns as campaign')
            ->join('broadcast_message_versions as message', function ($join): void {
                $join->on('message.broadcast_campaign_id', '=', 'campaign.id')
                    ->on('message.version', '=', 'campaign.current_message_version');
            })
            ->join('broadcast_audiences as audience', function ($join): void {
                $join->on('audience.broadcast_campaign_id', '=', 'campaign.id')
                    ->on('audience.version', '=', 'campaign.current_audience_version');
            })
            ->where('campaign.id', $campaignId)
            ->first([
                'campaign.public_id',
                'campaign.state',
                'campaign.state_version',
                'campaign.current_message_version',
                'campaign.current_audience_version',
                'campaign.recipient_count',
                'campaign.scheduled_at',
                'message.mode',
                'audience.estimated_recipient_count',
            ]);
        if ($row === null) {
            throw new RuntimeException('Broadcast campaign receipt cannot be reconstructed.');
        }

        $mode = TelegramBroadcastMessageMode::tryFrom((string) $row->mode);
        if ($mode === null) {
            throw new RuntimeException('Broadcast message mode is invalid.');
        }

        return new TelegramBroadcastCampaignReceipt(
            (string) $row->public_id,
            $this->campaignState($row),
            $this->positiveInt($row->state_version, 'Broadcast state version'),
            $mode,
            $this->positiveInt($row->current_message_version, 'Broadcast message version'),
            $this->positiveInt($row->current_audience_version, 'Broadcast audience version'),
            $this->nonNegativeInt($row->estimated_recipient_count, 'Broadcast estimated recipient count'),
            $this->nonNegativeInt($row->recipient_count, 'Broadcast recipient count'),
            $row->scheduled_at === null
                ? null
                : new DateTimeImmutable((string) $row->scheduled_at, new DateTimeZone('UTC')),
            $replayed,
        );
    }

    private function campaignState(object $row): TelegramBroadcastCampaignState
    {
        /** @var object{state:string} $row */
        $state = TelegramBroadcastCampaignState::tryFrom((string) $row->state);
        if ($state === null) {
            throw new RuntimeException('Broadcast campaign state is invalid.');
        }

        return $state;
    }

    /** @param CampaignRow $campaign */
    private function assertExpectedStateVersion(object $campaign, int $expectedStateVersion): void
    {
        if ((int) $campaign->state_version !== $expectedStateVersion) {
            throw new DomainException('Broadcast campaign state version is stale.');
        }
    }

    private function assertSourceBoundToActor(
        int $actorUserId,
        string $botId,
        TelegramBroadcastMessageDefinition $message,
    ): void {
        if ($message->sourceChatId === null) {
            return;
        }

        $telegramUserId = $this->database->connection()->table('telegram_accounts')
            ->where('user_id', $actorUserId)
            ->where('bot_id', $botId)
            ->where('is_bot', false)
            ->value('telegram_user_id');
        if ((! is_int($telegramUserId) && ! is_string($telegramUserId))
            || (int) $telegramUserId !== $message->sourceChatId
        ) {
            throw new DomainException('Broadcast source message must belong to the authorized administrator chat.');
        }
    }

    private function botId(): string
    {
        $botId = $this->runtime->botId();
        if (preg_match('/\A[1-9][0-9]{5,19}\z/', $botId) !== 1) {
            throw new RuntimeException('Broadcast Telegram bot ID is invalid.');
        }

        return $botId;
    }

    private function requestHash(string $requestKey): string
    {
        if ($requestKey === ''
            || strlen($requestKey) > 512
            || ! mb_check_encoding($requestKey, 'UTF-8')
            || str_contains($requestKey, "\0")
        ) {
            throw new DomainException('Broadcast request key is invalid.');
        }

        return hash('sha256', 'telegram-broadcast-create-v1|'.$requestKey);
    }

    private function assertPublicId(string $publicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new DomainException('Broadcast campaign public ID is invalid.');
        }
    }

    private function assertStateVersion(int $version): void
    {
        if ($version < 1) {
            throw new DomainException('Broadcast campaign state version is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $validated;
    }

    private function nonNegativeInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($validated === false) {
            throw new RuntimeException($label.' must be a non-negative integer.');
        }

        return $validated;
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && preg_match('/duplicate|unique/i', $exception->getMessage()) === 1;
    }
}
