<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\Contracts\TelegramRuntime;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramRequiredChannelService
{
    private const TARGET_TYPE = 'telegram_required_channel';

    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private TelegramMembershipLookup $membershipLookup,
        private TelegramRuntime $runtime,
        private TelegramConfigurationMutationExecutor $executor,
        private TelegramConfigurationMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement ONB-003 CHN-001 ACL-002 SEC-001 SEC-003 DAT-003 */
    public function create(
        TelegramRequiredChannelDefinition $definition,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $this->assertJoinSecretNotInContext($definition, $context);
        $joinHash = hash('sha256', $definition->joinUrl);
        $payloadHash = $this->payloadHash($this->definitionSafePayload($definition, $joinHash));

        return $this->executor->execute(
            'telegram.required_channel.create',
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use ($definition, $context, $joinHash, $payloadHash): TelegramConfigurationMutationReceipt {
                $now = $this->timestamp();
                $id = (int) $connection->table('required_channels')->insertGetId([
                    'channel_key' => $definition->channelKey,
                    'telegram_chat_id' => $definition->telegramChatId,
                    'chat_type' => $definition->chatType,
                    'visibility' => $definition->visibility,
                    'display_title' => $definition->displayTitle,
                    'join_url_ciphertext' => $this->encrypter->encryptString($definition->joinUrl),
                    'join_url_hash' => $joinHash,
                    'sort_order' => $definition->sortOrder,
                    'state' => 'draft',
                    'version' => 1,
                    'verified_bot_id' => null,
                    'verification_result_code' => null,
                    'verified_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $after = $this->safeStateFromDefinition($id, $definition, $joinHash, 'draft', 1, null, null, null, $payloadHash);

                return $this->audit->record($connection, 'telegram.required_channel.create', self::TARGET_TYPE, $id, $context, [], $after, true);
            },
        );
    }

    public function update(
        int $channelId,
        int $expectedVersion,
        TelegramRequiredChannelDefinition $definition,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $this->assertPositiveIdentity($channelId, $expectedVersion);
        $this->assertJoinSecretNotInContext($definition, $context);
        $joinHash = hash('sha256', $definition->joinUrl);
        $payloadHash = $this->payloadHash([
            'channel_id' => $channelId,
            'expected_version' => $expectedVersion,
            ...$this->definitionSafePayload($definition, $joinHash),
        ]);

        return $this->executor->execute(
            'telegram.required_channel.update',
            self::TARGET_TYPE,
            $channelId,
            $payloadHash,
            $context,
            function (Connection $connection) use ($channelId, $expectedVersion, $definition, $context, $joinHash, $payloadHash): TelegramConfigurationMutationReceipt {
                $row = $this->lockedRow($connection, $channelId);
                $this->assertVersion($row, $expectedVersion);
                $this->assertStoredJoinSecretNotInContext($row, $context);
                if ($row->state === 'active') {
                    throw new DomainException('Active Telegram required channel must be disabled before editing.');
                }

                $before = $this->safeState($row, $payloadHash);
                $candidate = $this->safeStateFromDefinition(
                    $channelId,
                    $definition,
                    $joinHash,
                    $row->state,
                    (int) $row->version,
                    null,
                    null,
                    null,
                    $payloadHash,
                );
                $beforeComparable = $before;
                $candidateComparable = $candidate;
                unset($beforeComparable['request_payload_hash'], $beforeComparable['verified_bot_id'], $beforeComparable['verification_result_code'], $beforeComparable['verified_at']);
                unset($candidateComparable['request_payload_hash'], $candidateComparable['verified_bot_id'], $candidateComparable['verification_result_code'], $candidateComparable['verified_at']);

                if ($beforeComparable === $candidateComparable
                    && $row->verified_bot_id === null
                    && $row->verification_result_code === null
                    && $row->verified_at === null
                ) {
                    return $this->audit->record($connection, 'telegram.required_channel.update', self::TARGET_TYPE, $channelId, $context, $before, $before, false);
                }

                $newVersion = $expectedVersion + 1;
                $connection->table('required_channels')->where('id', $channelId)->update([
                    'channel_key' => $definition->channelKey,
                    'telegram_chat_id' => $definition->telegramChatId,
                    'chat_type' => $definition->chatType,
                    'visibility' => $definition->visibility,
                    'display_title' => $definition->displayTitle,
                    'join_url_ciphertext' => $this->encrypter->encryptString($definition->joinUrl),
                    'join_url_hash' => $joinHash,
                    'sort_order' => $definition->sortOrder,
                    'version' => $newVersion,
                    'verified_bot_id' => null,
                    'verification_result_code' => null,
                    'verified_at' => null,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $this->safeStateFromDefinition($channelId, $definition, $joinHash, $row->state, $newVersion, null, null, null, $payloadHash);

                return $this->audit->record($connection, 'telegram.required_channel.update', self::TARGET_TYPE, $channelId, $context, $before, $after, true);
            },
        );
    }

    public function activate(
        int $channelId,
        int $expectedVersion,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $this->assertPositiveIdentity($channelId, $expectedVersion);
        $payloadHash = $this->payloadHash([
            'channel_id' => $channelId,
            'expected_version' => $expectedVersion,
        ]);
        $replayed = $this->executor->replayIfExists(
            'telegram.required_channel.activate',
            self::TARGET_TYPE,
            $channelId,
            $payloadHash,
            $context,
        );
        if ($replayed !== null) {
            return $replayed;
        }

        /** @var object{id:int|string,telegram_chat_id:int|string,version:int|string,state:string,join_url_ciphertext:string,join_url_hash:string}|null $preflight */
        $preflight = $this->database->connection()->table('required_channels')->where('id', $channelId)->first([
            'id',
            'telegram_chat_id',
            'version',
            'state',
            'join_url_ciphertext',
            'join_url_hash',
        ]);
        if ($preflight === null) {
            throw new RuntimeException('Telegram required channel does not exist.');
        }
        if ((int) $preflight->version !== $expectedVersion) {
            throw new RuntimeException('Telegram required-channel version conflict.');
        }
        if ($preflight->state === 'active') {
            throw new DomainException('Telegram required channel is already active.');
        }
        $this->assertStoredJoinSecretNotInContext($preflight, $context);

        $botId = $this->configuredBotId();
        $chatId = (int) $preflight->telegram_chat_id;
        $evidence = $this->membershipLookup->lookup($chatId, $botId);
        if ($evidence->evidence !== TelegramMembershipEvidence::Member
            || ! in_array($evidence->resultCode, ['telegram_membership_creator', 'telegram_membership_administrator'], true)
        ) {
            throw new DomainException('Telegram bot administrator membership could not be verified.');
        }

        $verifiedAt = $this->timestamp();

        return $this->executor->execute(
            'telegram.required_channel.activate',
            self::TARGET_TYPE,
            $channelId,
            $payloadHash,
            $context,
            function (Connection $connection) use ($channelId, $expectedVersion, $chatId, $botId, $evidence, $verifiedAt, $context, $payloadHash): TelegramConfigurationMutationReceipt {
                $row = $this->lockedRow($connection, $channelId);
                $this->assertVersion($row, $expectedVersion);
                if ((int) $row->telegram_chat_id !== $chatId || $row->state === 'active') {
                    throw new RuntimeException('Telegram required channel changed after capability verification.');
                }

                $before = $this->safeState($row, $payloadHash);
                $newVersion = $expectedVersion + 1;
                $connection->table('required_channels')->where('id', $channelId)->update([
                    'state' => 'active',
                    'version' => $newVersion,
                    'verified_bot_id' => $botId,
                    'verification_result_code' => $evidence->resultCode,
                    'verified_at' => $verifiedAt,
                    'updated_at' => $verifiedAt,
                ]);
                $after = $before;
                $after['state'] = 'active';
                $after['version'] = $newVersion;
                $after['verified_bot_id'] = $botId;
                $after['verification_result_code'] = $evidence->resultCode;
                $after['verified_at'] = $verifiedAt;

                return $this->audit->record($connection, 'telegram.required_channel.activate', self::TARGET_TYPE, $channelId, $context, $before, $after, true);
            },
        );
    }

    public function disable(
        int $channelId,
        int $expectedVersion,
        TelegramConfigurationChangeContext $context,
    ): TelegramConfigurationMutationReceipt {
        $this->assertPositiveIdentity($channelId, $expectedVersion);
        $payloadHash = $this->payloadHash(['channel_id' => $channelId, 'expected_version' => $expectedVersion]);

        return $this->executor->execute(
            'telegram.required_channel.disable',
            self::TARGET_TYPE,
            $channelId,
            $payloadHash,
            $context,
            function (Connection $connection) use ($channelId, $expectedVersion, $context, $payloadHash): TelegramConfigurationMutationReceipt {
                $row = $this->lockedRow($connection, $channelId);
                $this->assertVersion($row, $expectedVersion);
                $this->assertStoredJoinSecretNotInContext($row, $context);
                $before = $this->safeState($row, $payloadHash);
                if ($row->state === 'disabled') {
                    return $this->audit->record($connection, 'telegram.required_channel.disable', self::TARGET_TYPE, $channelId, $context, $before, $before, false);
                }

                $newVersion = $expectedVersion + 1;
                $connection->table('required_channels')->where('id', $channelId)->update([
                    'state' => 'disabled',
                    'version' => $newVersion,
                    'verified_bot_id' => null,
                    'verification_result_code' => null,
                    'verified_at' => null,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $before;
                $after['state'] = 'disabled';
                $after['version'] = $newVersion;
                $after['verified_bot_id'] = null;
                $after['verification_result_code'] = null;
                $after['verified_at'] = null;

                return $this->audit->record($connection, 'telegram.required_channel.disable', self::TARGET_TYPE, $channelId, $context, $before, $after, true);
            },
        );
    }

    /** @return array<string, bool|int|string|null> */
    public function findSafe(int $channelId): array
    {
        if ($channelId < 1) {
            throw new RuntimeException('Telegram required-channel ID must be positive.');
        }
        $row = $this->database->connection()->table('required_channels')->where('id', $channelId)->first();
        if ($row === null) {
            throw new RuntimeException('Telegram required channel does not exist.');
        }
        /** @var object{id:int|string,channel_key:string,telegram_chat_id:int|string,chat_type:string,visibility:string,display_title:string,join_url_ciphertext:string,join_url_hash:string,sort_order:int|string,state:string,version:int|string,verified_bot_id:int|string|null,verification_result_code:string|null,verified_at:string|null} $row */

        return $this->safeState($row, null);
    }

    /** @return list<array<string, bool|int|string|null>> */
    public function listSafe(): array
    {
        $rows = $this->database->connection()->table('required_channels')->orderBy('sort_order')->orderBy('id')->get();
        $safe = [];
        foreach ($rows as $row) {
            /** @var object{id:int|string,channel_key:string,telegram_chat_id:int|string,chat_type:string,visibility:string,display_title:string,join_url_ciphertext:string,join_url_hash:string,sort_order:int|string,state:string,version:int|string,verified_bot_id:int|string|null,verification_result_code:string|null,verified_at:string|null} $row */
            $safe[] = $this->safeState($row, null);
        }

        return $safe;
    }

    /**
     * @param  object{join_url_ciphertext:string,join_url_hash:string}  $row
     */
    private function assertStoredJoinSecretNotInContext(
        object $row,
        TelegramConfigurationChangeContext $context,
    ): void {
        try {
            $joinUrl = $this->encrypter->decryptString($row->join_url_ciphertext);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Telegram required-channel join URL cannot be decrypted.', previous: $exception);
        }

        if (! hash_equals($row->join_url_hash, hash('sha256', $joinUrl))) {
            throw new RuntimeException('Telegram required-channel join URL integrity check failed.');
        }

        $this->assertJoinSecretTextNotInContext($joinUrl, $context);
    }

    private function assertJoinSecretNotInContext(
        TelegramRequiredChannelDefinition $definition,
        TelegramConfigurationChangeContext $context,
    ): void {
        $this->assertJoinSecretTextNotInContext($definition->joinUrl, $context);
    }

    private function assertJoinSecretTextNotInContext(
        string $joinUrl,
        TelegramConfigurationChangeContext $context,
    ): void {
        $secrets = [$joinUrl];
        $path = (string) parse_url($joinUrl, PHP_URL_PATH);
        if (str_starts_with($path, '/+') || str_starts_with($path, '/joinchat/')) {
            $token = str_starts_with($path, '/joinchat/') ? substr($path, 10) : ltrim($path, '/+');
            if ($token !== '') {
                $secrets[] = $token;
            }
        }

        $contextValues = [
            $context->requestFingerprint,
            $context->correlationId,
            $context->reasonCode,
            $context->reason ?? '',
        ];
        foreach ($secrets as $secret) {
            foreach ($contextValues as $value) {
                if ($secret !== '' && str_contains($value, $secret)) {
                    throw new InvalidArgumentException('Telegram join-link secret must not appear in audit context.');
                }
            }
        }
    }

    private function configuredBotId(): int
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/', $this->runtime->botId()) !== 1) {
            throw new RuntimeException('Telegram bot identity is unavailable for membership verification.');
        }
        $botId = (int) $this->runtime->botId();
        if ($botId < 1) {
            throw new RuntimeException('Telegram bot identity is unavailable for membership verification.');
        }

        return $botId;
    }

    private function assertPositiveIdentity(int $channelId, int $expectedVersion): void
    {
        if ($channelId < 1 || $expectedVersion < 1) {
            throw new RuntimeException('Telegram required-channel ID and version must be positive.');
        }
    }

    /** @param object{id:int|string,channel_key:string,telegram_chat_id:int|string,chat_type:string,visibility:string,display_title:string,join_url_ciphertext:string,join_url_hash:string,sort_order:int|string,state:string,version:int|string,verified_bot_id:int|string|null,verification_result_code:string|null,verified_at:string|null} $row */
    private function assertVersion(object $row, int $expectedVersion): void
    {
        if ((int) $row->version !== $expectedVersion) {
            throw new RuntimeException('Telegram required-channel version conflict.');
        }
    }

    /** @return object{id:int|string,channel_key:string,telegram_chat_id:int|string,chat_type:string,visibility:string,display_title:string,join_url_ciphertext:string,join_url_hash:string,sort_order:int|string,state:string,version:int|string,verified_bot_id:int|string|null,verification_result_code:string|null,verified_at:string|null} */
    private function lockedRow(Connection $connection, int $channelId): object
    {
        $row = $connection->table('required_channels')->where('id', $channelId)->lockForUpdate()->first();
        if ($row === null) {
            throw new RuntimeException('Telegram required channel does not exist.');
        }
        /** @var object{id:int|string,channel_key:string,telegram_chat_id:int|string,chat_type:string,visibility:string,display_title:string,join_url_ciphertext:string,join_url_hash:string,sort_order:int|string,state:string,version:int|string,verified_bot_id:int|string|null,verification_result_code:string|null,verified_at:string|null} $row */

        return $row;
    }

    /** @return array<string, bool|int|string> */
    private function definitionSafePayload(TelegramRequiredChannelDefinition $definition, string $joinHash): array
    {
        return [
            'channel_key' => $definition->channelKey,
            'telegram_chat_id' => $definition->telegramChatId,
            'chat_type' => $definition->chatType,
            'visibility' => $definition->visibility,
            'display_title' => $definition->displayTitle,
            'join_url_hash' => $joinHash,
            'join_url_present' => true,
            'sort_order' => $definition->sortOrder,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function safeStateFromDefinition(
        int $id,
        TelegramRequiredChannelDefinition $definition,
        string $joinHash,
        string $state,
        int $version,
        ?int $verifiedBotId,
        ?string $verificationResultCode,
        ?string $verifiedAt,
        ?string $payloadHash,
    ): array {
        return [
            'id' => $id,
            ...$this->definitionSafePayload($definition, $joinHash),
            'state' => $state,
            'version' => $version,
            'verified_bot_id' => $verifiedBotId,
            'verification_result_code' => $verificationResultCode,
            'verified_at' => $verifiedAt,
            'request_payload_hash' => $payloadHash,
        ];
    }

    /**
     * @param  object{id:int|string,channel_key:string,telegram_chat_id:int|string,chat_type:string,visibility:string,display_title:string,join_url_ciphertext:string,join_url_hash:string,sort_order:int|string,state:string,version:int|string,verified_bot_id:int|string|null,verification_result_code:string|null,verified_at:string|null}  $row
     * @return array<string, bool|int|string|null>
     */
    private function safeState(object $row, ?string $payloadHash): array
    {
        return [
            'id' => (int) $row->id,
            'channel_key' => (string) $row->channel_key,
            'telegram_chat_id' => (int) $row->telegram_chat_id,
            'chat_type' => (string) $row->chat_type,
            'visibility' => (string) $row->visibility,
            'display_title' => (string) $row->display_title,
            'join_url_hash' => (string) $row->join_url_hash,
            'join_url_present' => (string) $row->join_url_ciphertext !== '',
            'sort_order' => (int) $row->sort_order,
            'state' => (string) $row->state,
            'version' => (int) $row->version,
            'verified_bot_id' => $row->verified_bot_id === null ? null : (int) $row->verified_bot_id,
            'verification_result_code' => $row->verification_result_code === null ? null : (string) $row->verification_result_code,
            'verified_at' => $row->verified_at === null ? null : (string) $row->verified_at,
            'request_payload_hash' => $payloadHash,
        ];
    }

    /** @param array<string, bool|int|string|null> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
