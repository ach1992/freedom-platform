<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

final readonly class TelegramBroadcastRetryService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private Clock $clock,
    ) {}

    /**
     * Retry only recipients with definitive failed states. Uncertain recipients
     * are intentionally excluded because a previous provider effect may exist.
     *
     * @param  list<string>  $recipientPublicIds  Empty means all failed recipients.
     *
     * @requirement COM-003 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004
     */
    public function retryFailed(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
        #[SensitiveParameter] string $requestKey,
        array $recipientPublicIds = [],
    ): int {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramBroadcastCampaignService::PERMISSION,
        );
        $this->assertPublicId($campaignPublicId);
        if ($expectedStateVersion < 1) {
            throw new DomainException('Broadcast campaign state version is invalid.');
        }
        $requestHash = $this->requestHash($requestKey);
        $recipientPublicIds = $this->canonicalRecipientIds($recipientPublicIds);

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $campaignPublicId,
                $expectedStateVersion,
                $administratorId,
                $requestHash,
                $recipientPublicIds,
            ): int {
                /** @var object{id:int|string,state:string,state_version:int|string,current_message_version:int|string,completed_at:?string}|null $campaign */
                $campaign = $connection->table('broadcast_campaigns')
                    ->where('public_id', $campaignPublicId)
                    ->lockForUpdate()
                    ->first([
                        'id',
                        'state',
                        'state_version',
                        'current_message_version',
                        'completed_at',
                    ]);
                if ($campaign === null) {
                    throw new DomainException('Broadcast campaign is unavailable.');
                }
                if ((int) $campaign->state_version !== $expectedStateVersion) {
                    throw new DomainException('Broadcast campaign state version is stale.');
                }
                if ((string) $campaign->state !== TelegramBroadcastCampaignState::Completed->value) {
                    throw new DomainException('Failed broadcast recipients may be retried only after campaign completion.');
                }

                $this->assertSuccessfulOwnerTest(
                    $connection,
                    (int) $campaign->id,
                    (int) $campaign->current_message_version,
                );

                $pendingLifecycle = $connection->table('broadcast_recipient_messages as operation')
                    ->join('broadcast_recipients as recipient', 'recipient.id', '=', 'operation.broadcast_recipient_id')
                    ->where('recipient.broadcast_campaign_id', (int) $campaign->id)
                    ->whereIn('operation.action', ['edit', 'buttons', 'pin', 'unpin', 'delete'])
                    ->whereIn('operation.state', ['prepared', 'queued', 'sending'])
                    ->exists();
                if ($pendingLifecycle) {
                    throw new DomainException('Broadcast failed-recipient retry must wait for the current lifecycle mutation to finish.');
                }

                $query = $connection->table('broadcast_recipients')
                    ->where('broadcast_campaign_id', (int) $campaign->id)
                    ->whereIn('delivery_state', ['failed_transient', 'failed_permanent']);
                if ($recipientPublicIds !== []) {
                    $query->whereIn('public_id', $recipientPublicIds);
                }

                /** @var Collection<int,object{id:int|string,public_id:string,delivery_state:string}> $recipients */
                $recipients = $query
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'public_id', 'delivery_state']);

                if ($recipients->isEmpty()) {
                    throw new DomainException('Broadcast campaign has no definitively failed recipients to retry.');
                }
                if ($recipientPublicIds !== [] && $recipients->count() !== count($recipientPublicIds)) {
                    throw new DomainException('One or more selected broadcast recipients are not retryable.');
                }

                $messageVersionId = $connection->table('broadcast_message_versions')
                    ->where('broadcast_campaign_id', (int) $campaign->id)
                    ->where('version', (int) $campaign->current_message_version)
                    ->value('id');
                if (! is_int($messageVersionId) && ! is_string($messageVersionId)) {
                    throw new RuntimeException('Broadcast retry message version is unavailable.');
                }

                $now = $this->timestamp();
                $messageRows = [];
                $recipientIds = [];
                foreach ($recipients as $recipient) {
                    $recipientIds[] = (int) $recipient->id;
                    $messageRows[] = [
                        'public_id' => (string) Str::ulid(),
                        'broadcast_recipient_id' => (int) $recipient->id,
                        'broadcast_message_version_id' => (int) $messageVersionId,
                        'action' => 'retry',
                        'request_key_hash' => hash(
                            'sha256',
                            'telegram-broadcast-retry-v1|'.$requestHash.'|'.$recipient->public_id,
                        ),
                        'state' => 'prepared',
                        'delivery_operation_public_id' => null,
                        'telegram_message_id' => null,
                        'result_code' => null,
                        'provider_boundary_started_at' => null,
                        'provider_boundary_finished_at' => null,
                        'requested_by_administrator_id' => $administratorId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($messageRows, 500) as $chunk) {
                    $connection->table('broadcast_recipient_messages')->insert($chunk);
                }

                $updatedRecipients = $connection->table('broadcast_recipients')
                    ->whereIn('id', $recipientIds)
                    ->whereIn('delivery_state', ['failed_transient', 'failed_permanent'])
                    ->update([
                        'delivery_state' => 'queued',
                        'delivery_operation_public_id' => null,
                        'telegram_message_id' => null,
                        'failure_code' => null,
                        'sent_at' => null,
                        'claim_token_hash' => null,
                        'claim_expires_at' => null,
                        'updated_at' => $now,
                    ]);
                if ($updatedRecipients !== count($recipientIds)) {
                    throw new RuntimeException('Broadcast retry recipient state changed concurrently.');
                }

                $updatedCampaign = $connection->table('broadcast_campaigns')
                    ->where('id', (int) $campaign->id)
                    ->where('state', TelegramBroadcastCampaignState::Completed->value)
                    ->where('state_version', $expectedStateVersion)
                    ->update([
                        'state' => TelegramBroadcastCampaignState::Active->value,
                        'state_version' => $expectedStateVersion + 1,
                        'completed_at' => null,
                        'updated_at' => $now,
                    ]);
                if ($updatedCampaign !== 1) {
                    throw new RuntimeException('Broadcast retry campaign transition was lost.');
                }

                return count($recipientIds);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            throw new DomainException(
                'Broadcast retry request key has already been used for one or more recipients.',
                0,
                $exception,
            );
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
            throw new DomainException('Broadcast retry requires a successful current-version Owner test.');
        }
    }

    /** @param list<string> $recipientPublicIds
     * @return list<string>
     */
    private function canonicalRecipientIds(array $recipientPublicIds): array
    {
        if (count($recipientPublicIds) > 1000) {
            throw new DomainException('Broadcast retry selection exceeds 1000 recipients.');
        }

        foreach ($recipientPublicIds as $publicId) {
            $this->assertPublicId($publicId);
        }
        if (count(array_unique($recipientPublicIds)) !== count($recipientPublicIds)) {
            throw new DomainException('Broadcast retry recipient IDs must be unique.');
        }

        sort($recipientPublicIds, SORT_STRING);

        return $recipientPublicIds;
    }

    private function assertPublicId(string $publicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new DomainException('Broadcast public ID is invalid.');
        }
    }

    private function requestHash(string $requestKey): string
    {
        if ($requestKey === ''
            || strlen($requestKey) > 512
            || ! mb_check_encoding($requestKey, 'UTF-8')
            || str_contains($requestKey, "\0")
        ) {
            throw new DomainException('Broadcast retry request key is invalid.');
        }

        return hash('sha256', $requestKey);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && preg_match('/duplicate|unique/i', $exception->getMessage()) === 1;
    }
}
