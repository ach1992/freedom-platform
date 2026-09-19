<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Modules\Telegram\Domain\TelegramBroadcastLifecycleAction;
use App\Modules\Telegram\Domain\TelegramBroadcastMessageMode;
use App\Modules\Telegram\Domain\TelegramBroadcastSourceKind;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

final readonly class TelegramBroadcastLifecycleService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private Clock $clock,
        private TelegramDeliveryRuntime $runtime,
    ) {}

    /** @requirement COM-003 ACL-002 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
    public function reviseContent(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
        TelegramBroadcastMessageDefinition $next,
    ): TelegramBroadcastLifecycleRevisionReceipt {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramBroadcastCampaignService::PERMISSION,
        );
        $this->assertPublicId($campaignPublicId);
        $this->assertStateVersion($expectedStateVersion);
        $nextHash = $next->contentHash();

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $administratorId,
            $campaignPublicId,
            $expectedStateVersion,
            $next,
            $nextHash,
        ): TelegramBroadcastLifecycleRevisionReceipt {
            /** @var object{id:int|string,state:string,state_version:int|string,current_message_version:int|string}|null $campaign */
            $campaign = $connection->table('broadcast_campaigns')
                ->where('public_id', $campaignPublicId)
                ->where('bot_id', $this->runtime->botId())
                ->lockForUpdate()
                ->first([
                    'id',
                    'state',
                    'state_version',
                    'current_message_version',
                ]);
            if ($campaign === null) {
                throw new DomainException('Broadcast campaign is unavailable.');
            }
            if ((string) $campaign->state !== TelegramBroadcastCampaignState::Completed->value) {
                throw new DomainException('Broadcast content may be revised only after campaign completion.');
            }
            if ((int) $campaign->state_version !== $expectedStateVersion) {
                throw new DomainException('Broadcast campaign state version is stale.');
            }
            $this->assertNoPendingLifecycleActions($connection, (int) $campaign->id);

            /** @var object{
             *     id:int|string,
             *     version:int|string,
             *     mode:string,
             *     source_kind:?string,
             *     text:?string,
             *     caption_override:?string,
             *     source_chat_id:int|string|null,
             *     source_message_id:int|string|null,
             *     inline_keyboard_snapshot:?string,
             *     content_hash:string
             * }|null $current
             */
            $current = $connection->table('broadcast_message_versions')
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->where('version', (int) $campaign->current_message_version)
                ->lockForUpdate()
                ->first([
                    'id',
                    'version',
                    'mode',
                    'source_kind',
                    'text',
                    'caption_override',
                    'source_chat_id',
                    'source_message_id',
                    'inline_keyboard_snapshot',
                    'content_hash',
                ]);
            if ($current === null) {
                throw new RuntimeException('Broadcast current message version is unavailable.');
            }
            if (hash_equals($current->content_hash, $nextHash)) {
                return new TelegramBroadcastLifecycleRevisionReceipt(
                    $campaignPublicId,
                    (int) $campaign->state_version,
                    (int) $campaign->current_message_version,
                    true,
                );
            }

            $this->assertCompatibleRevision($current, $next);
            $version = (int) $campaign->current_message_version + 1;
            $now = $this->timestamp();
            $connection->table('broadcast_message_versions')->insert([
                'public_id' => (string) Str::ulid(),
                'broadcast_campaign_id' => (int) $campaign->id,
                'version' => $version,
                'mode' => $next->mode->value,
                'source_kind' => $next->sourceKind?->value,
                'text' => $next->text,
                'caption_override' => $next->captionOverride,
                'source_chat_id' => $next->sourceChatId,
                'source_message_id' => $next->sourceMessageId,
                'inline_keyboard_snapshot' => $next->inlineKeyboardJson(),
                'content_hash' => $nextHash,
                'actor_administrator_id' => $administratorId,
                'created_at' => $now,
            ]);

            $updated = $connection->table('broadcast_campaigns')
                ->where('id', (int) $campaign->id)
                ->where('state', TelegramBroadcastCampaignState::Completed->value)
                ->where('state_version', $expectedStateVersion)
                ->update([
                    'current_message_version' => $version,
                    'state_version' => $expectedStateVersion + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new DomainException('Broadcast campaign changed before lifecycle revision completed.');
            }

            return new TelegramBroadcastLifecycleRevisionReceipt(
                $campaignPublicId,
                $expectedStateVersion + 1,
                $version,
                false,
            );
        }, 3);
    }

    /**
     * Empty recipient selection means every currently delivered, non-deleted recipient.
     *
     * @param  list<string>  $recipientPublicIds
     *
     * @requirement COM-003 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004
     */
    public function queueAction(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
        TelegramBroadcastLifecycleAction $action,
        #[SensitiveParameter] string $requestKey,
        array $recipientPublicIds = [],
    ): TelegramBroadcastLifecycleBatchReceipt {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramBroadcastCampaignService::PERMISSION,
        );
        $this->assertPublicId($campaignPublicId);
        $this->assertStateVersion($expectedStateVersion);
        $recipientPublicIds = $this->canonicalRecipientIds($recipientPublicIds);
        $requestHash = $this->requestHash($requestKey);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $administratorId,
            $campaignPublicId,
            $expectedStateVersion,
            $action,
            $recipientPublicIds,
            $requestHash,
        ): TelegramBroadcastLifecycleBatchReceipt {
            /** @var object{id:int|string,state:string,state_version:int|string,current_message_version:int|string}|null $campaign */
            $campaign = $connection->table('broadcast_campaigns')
                ->where('public_id', $campaignPublicId)
                ->where('bot_id', $this->runtime->botId())
                ->lockForUpdate()
                ->first([
                    'id',
                    'state',
                    'state_version',
                    'current_message_version',
                ]);
            if ($campaign === null) {
                throw new DomainException('Broadcast campaign is unavailable.');
            }
            if ((string) $campaign->state !== TelegramBroadcastCampaignState::Completed->value) {
                throw new DomainException('Broadcast lifecycle actions may be queued only after campaign completion.');
            }
            if ((int) $campaign->state_version !== $expectedStateVersion) {
                throw new DomainException('Broadcast campaign state version is stale.');
            }
            $this->assertNoPendingLifecycleActions($connection, (int) $campaign->id);
            $this->assertProviderRetryWindowElapsed(
                $connection,
                (int) $campaign->id,
                $recipientPublicIds,
            );

            /** @var object{
             *     id:int|string,
             *     version:int|string,
             *     mode:string,
             *     source_kind:?string,
             *     text:?string,
             *     caption_override:?string,
             *     source_chat_id:int|string|null,
             *     source_message_id:int|string|null,
             *     inline_keyboard_snapshot:?string
             * }|null $message
             */
            $message = $connection->table('broadcast_message_versions')
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->where('version', (int) $campaign->current_message_version)
                ->first([
                    'id',
                    'version',
                    'mode',
                    'source_kind',
                    'text',
                    'caption_override',
                    'source_chat_id',
                    'source_message_id',
                    'inline_keyboard_snapshot',
                ]);
            if ($message === null) {
                throw new RuntimeException('Broadcast lifecycle message version is unavailable.');
            }

            $this->assertSuccessfulOwnerTest(
                $connection,
                (int) $campaign->id,
                (int) $message->version,
            );
            $this->assertActionCompatible($message, $action);

            $query = $connection->table('broadcast_recipients')
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->where('delivery_state', 'sent')
                ->whereNotNull('telegram_message_id')
                ->where('lifecycle_state', '<>', 'deleted');
            if ($recipientPublicIds !== []) {
                $query->whereIn('public_id', $recipientPublicIds);
            }

            /** @var Collection<int,object{id:int|string,public_id:string,telegram_message_id:int|string}> $recipients */
            $recipients = $query
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'public_id', 'telegram_message_id']);
            if ($recipients->isEmpty()) {
                throw new DomainException('Broadcast lifecycle action has no eligible delivered recipients.');
            }
            if ($recipientPublicIds !== [] && $recipients->count() !== count($recipientPublicIds)) {
                throw new DomainException('One or more selected broadcast recipients are not lifecycle-eligible.');
            }

            $now = $this->timestamp();
            $groupPublicId = (string) Str::ulid();
            $rows = [];
            foreach ($recipients as $recipient) {
                $rows[] = [
                    'public_id' => (string) Str::ulid(),
                    'broadcast_recipient_id' => (int) $recipient->id,
                    'broadcast_message_version_id' => (int) $message->id,
                    'action' => $action->value,
                    'operation_group_public_id' => $groupPublicId,
                    'request_key_hash' => hash(
                        'sha256',
                        'telegram-broadcast-lifecycle-v1|'.$action->value.'|'.$requestHash.'|'.$recipient->public_id,
                    ),
                    'state' => 'prepared',
                    'delivery_operation_public_id' => null,
                    'telegram_message_id' => (int) $recipient->telegram_message_id,
                    'result_code' => null,
                    'provider_boundary_started_at' => null,
                    'provider_boundary_finished_at' => null,
                    'requested_by_administrator_id' => $administratorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                $connection->table('broadcast_recipient_messages')->insert($chunk);
            }

            $updated = $connection->table('broadcast_campaigns')
                ->where('id', (int) $campaign->id)
                ->where('state', TelegramBroadcastCampaignState::Completed->value)
                ->where('state_version', $expectedStateVersion)
                ->update([
                    'state_version' => $expectedStateVersion + 1,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast lifecycle queue transition was lost.');
            }

            return new TelegramBroadcastLifecycleBatchReceipt(
                $campaignPublicId,
                $groupPublicId,
                $action,
                $recipients->count(),
                $expectedStateVersion + 1,
            );
        }, 3);
    }

    /** @requirement COM-003 ACL-002 DAT-003 OPS-003 */
    public function progress(
        int $actorUserId,
        string $campaignPublicId,
        string $groupPublicId,
    ): TelegramBroadcastLifecycleProgress {
        $this->administrators->authorizeUser(
            $actorUserId,
            TelegramBroadcastCampaignService::PERMISSION,
        );
        $this->assertPublicId($campaignPublicId);
        $this->assertPublicId($groupPublicId);

        /** @var Collection<int,object{action:string,state:string,aggregate_count:int|string}> $rows */
        $rows = $this->database->connection()->table('broadcast_recipient_messages as operation')
            ->join('broadcast_recipients as recipient', 'recipient.id', '=', 'operation.broadcast_recipient_id')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'recipient.broadcast_campaign_id')
            ->where('campaign.public_id', $campaignPublicId)
            ->where('campaign.bot_id', $this->runtime->botId())
            ->where('operation.operation_group_public_id', $groupPublicId)
            ->selectRaw('operation.action, operation.state, COUNT(*) AS aggregate_count')
            ->groupBy('operation.action', 'operation.state')
            ->get();
        if ($rows->isEmpty()) {
            throw new DomainException('Broadcast lifecycle operation group is unavailable.');
        }

        $counts = [
            'prepared' => 0,
            'queued' => 0,
            'sending' => 0,
            'succeeded' => 0,
            'retryable' => 0,
            'failed' => 0,
            'uncertain' => 0,
            'skipped' => 0,
        ];
        $action = null;
        foreach ($rows as $row) {
            $rowAction = TelegramBroadcastLifecycleAction::tryFrom($row->action)
                ?? throw new RuntimeException('Broadcast lifecycle progress action is invalid.');
            if ($action !== null && $action !== $rowAction) {
                throw new RuntimeException('Broadcast lifecycle group contains multiple action types.');
            }
            $action = $rowAction;

            if (! array_key_exists($row->state, $counts)) {
                throw new RuntimeException('Broadcast lifecycle progress state is invalid.');
            }
            $count = filter_var(
                $row->aggregate_count,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]],
            );
            if ($count === false) {
                throw new RuntimeException('Broadcast lifecycle progress count is invalid.');
            }
            $counts[$row->state] = $count;
        }
        if ($action === null) {
            throw new RuntimeException('Broadcast lifecycle progress action is unavailable.');
        }

        return new TelegramBroadcastLifecycleProgress(
            $campaignPublicId,
            $groupPublicId,
            $action,
            array_sum($counts),
            $counts['prepared'],
            $counts['queued'],
            $counts['sending'],
            $counts['succeeded'],
            $counts['retryable'],
            $counts['failed'],
            $counts['uncertain'],
            $counts['skipped'],
        );
    }

    /** @param object{mode:string,source_kind:?string,source_chat_id:int|string|null,source_message_id:int|string|null} $current */
    private function assertCompatibleRevision(
        object $current,
        TelegramBroadcastMessageDefinition $next,
    ): void {
        $currentMode = TelegramBroadcastMessageMode::tryFrom($current->mode)
            ?? throw new RuntimeException('Broadcast current message mode is invalid.');

        if ($currentMode === TelegramBroadcastMessageMode::Forward) {
            throw new DomainException('Forwarded broadcast content cannot be revised after delivery.');
        }
        if ($currentMode === TelegramBroadcastMessageMode::NewText) {
            if ($next->mode !== TelegramBroadcastMessageMode::NewText) {
                throw new DomainException('Text broadcasts must remain text during lifecycle revision.');
            }

            return;
        }

        $currentKind = $current->source_kind === null
            ? null
            : TelegramBroadcastSourceKind::tryFrom($current->source_kind);
        if ($currentKind === null) {
            throw new RuntimeException('Broadcast copy source kind is invalid.');
        }

        if ($currentKind === TelegramBroadcastSourceKind::Text
            && $next->mode === TelegramBroadcastMessageMode::NewText
        ) {
            return;
        }
        if ($next->mode !== TelegramBroadcastMessageMode::Copy
            || $next->sourceKind !== $currentKind
            || $next->sourceChatId !== (int) $current->source_chat_id
            || $next->sourceMessageId !== (int) $current->source_message_id
        ) {
            throw new DomainException('Copied broadcast lifecycle revision must preserve its source identity and type.');
        }
    }

    /**
     * @param  object{mode:string,source_kind:?string,caption_override:?string}  $message
     */
    private function assertActionCompatible(object $message, TelegramBroadcastLifecycleAction $action): void
    {
        $mode = TelegramBroadcastMessageMode::tryFrom($message->mode)
            ?? throw new RuntimeException('Broadcast lifecycle message mode is invalid.');

        if ($action === TelegramBroadcastLifecycleAction::Edit) {
            if ($mode === TelegramBroadcastMessageMode::NewText) {
                return;
            }
            $kind = $message->source_kind === null
                ? null
                : TelegramBroadcastSourceKind::tryFrom($message->source_kind);
            if ($mode === TelegramBroadcastMessageMode::Copy
                && $kind?->supportsCaption() === true
                && $message->caption_override !== null
            ) {
                return;
            }

            throw new DomainException('Current broadcast content does not support delivered-message editing.');
        }

        if ($action === TelegramBroadcastLifecycleAction::Buttons
            && $mode === TelegramBroadcastMessageMode::Forward
        ) {
            throw new DomainException('Forwarded broadcast messages do not support authored button mutation.');
        }
    }

    /** @param list<string> $recipientPublicIds */
    private function assertProviderRetryWindowElapsed(
        Connection $connection,
        int $campaignId,
        array $recipientPublicIds,
    ): void {
        $now = $this->timestamp();
        $query = $connection->table('broadcast_recipient_messages as operation')
            ->join('broadcast_recipients as recipient', 'recipient.id', '=', 'operation.broadcast_recipient_id')
            ->where('recipient.broadcast_campaign_id', $campaignId)
            ->whereIn('operation.action', ['edit', 'buttons', 'pin', 'unpin', 'delete'])
            ->where('operation.state', 'retryable')
            ->whereNotNull('operation.retry_not_before')
            ->where('operation.retry_not_before', '>', $now);
        if ($recipientPublicIds !== []) {
            $query->whereIn('recipient.public_id', $recipientPublicIds);
        }

        if ($query->exists()) {
            throw new DomainException('Broadcast lifecycle provider retry window has not elapsed.');
        }
    }

    private function assertNoPendingLifecycleActions(Connection $connection, int $campaignId): void
    {
        $pending = $connection->table('broadcast_recipient_messages as operation')
            ->join('broadcast_recipients as recipient', 'recipient.id', '=', 'operation.broadcast_recipient_id')
            ->where('recipient.broadcast_campaign_id', $campaignId)
            ->whereIn('operation.action', ['edit', 'buttons', 'pin', 'unpin', 'delete'])
            ->whereIn('operation.state', ['prepared', 'queued', 'sending'])
            ->exists();
        if ($pending) {
            throw new DomainException('Broadcast campaign already has a lifecycle mutation in progress.');
        }
    }

    private function assertSuccessfulOwnerTest(
        Connection $connection,
        int $campaignId,
        int $messageVersion,
    ): void {
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
            throw new DomainException('Broadcast lifecycle action requires a successful current-version Owner test.');
        }
    }

    /** @param list<string> $recipientPublicIds
     * @return list<string>
     */
    private function canonicalRecipientIds(array $recipientPublicIds): array
    {
        if (count($recipientPublicIds) > 1000) {
            throw new DomainException('Broadcast lifecycle recipient selection exceeds 1000 recipients.');
        }
        foreach ($recipientPublicIds as $publicId) {
            $this->assertPublicId($publicId);
        }
        if (count(array_unique($recipientPublicIds)) !== count($recipientPublicIds)) {
            throw new DomainException('Broadcast lifecycle recipient IDs must be unique.');
        }

        sort($recipientPublicIds, SORT_STRING);

        return $recipientPublicIds;
    }

    private function requestHash(string $requestKey): string
    {
        if ($requestKey === ''
            || strlen($requestKey) > 512
            || ! mb_check_encoding($requestKey, 'UTF-8')
            || str_contains($requestKey, "\0")
        ) {
            throw new DomainException('Broadcast lifecycle request key is invalid.');
        }

        return hash('sha256', $requestKey);
    }

    private function assertPublicId(string $publicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new DomainException('Broadcast public ID is invalid.');
        }
    }

    private function assertStateVersion(int $stateVersion): void
    {
        if ($stateVersion < 1) {
            throw new DomainException('Broadcast campaign state version is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
