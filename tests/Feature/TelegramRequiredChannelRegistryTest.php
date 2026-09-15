<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramConfigurationChangeContext;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use App\Modules\Telegram\Application\TelegramRequiredChannelDefinition;
use App\Modules\Telegram\Application\TelegramRequiredChannelService;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\TelegramMembershipAccessFoundationSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ONB-003 CHN-001 ACL-001 ACL-002 SEC-001 SEC-003 DAT-003 QUA-001 */
final class TelegramRequiredChannelRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(TelegramMembershipAccessFoundationSeeder::class);
    }

    public function test_create_encrypts_join_link_and_audit_replay_never_exposes_secret(): void
    {
        $ownerId = $this->administrator(true);
        $secretJoinUrl = 'https://t.me/+PrivateInviteHash_987654321';
        $service = $this->service($this->lookup(fn (): TelegramMembershipLookupResult => $this->adminEvidence()));
        $definition = $this->definition(joinUrl: $secretJoinUrl, visibility: 'private');
        $context = $this->context($ownerId, 'telegram-channel-create-0001');

        $created = $service->create($definition, $context);
        $replayed = $service->create($definition, $context);

        self::assertTrue($created->changed);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->targetId, $replayed->targetId);
        self::assertSame(1, DB::table('required_channels')->count());

        $row = DB::table('required_channels')->where('id', $created->targetId)->first();
        self::assertNotNull($row);
        self::assertNotSame($secretJoinUrl, (string) $row->join_url_ciphertext);
        self::assertStringNotContainsString($secretJoinUrl, (string) $row->join_url_ciphertext);
        self::assertSame(hash('sha256', $secretJoinUrl), (string) $row->join_url_hash);
        self::assertSame(
            $secretJoinUrl,
            $this->app->make(StringEncrypter::class)->decryptString((string) $row->join_url_ciphertext),
        );

        $audit = DB::table('audit_logs')->where('action', 'telegram.required_channel.create')->first();
        self::assertNotNull($audit);
        $auditText = json_encode([$audit->before_safe_data, $audit->after_safe_data, $audit->reason], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secretJoinUrl, $auditText);
        self::assertStringNotContainsString((string) $row->join_url_ciphertext, $auditText);
        self::assertStringContainsString(hash('sha256', $secretJoinUrl), $auditText);

        $safe = $service->findSafe($created->targetId);
        self::assertArrayNotHasKey('join_url_ciphertext', $safe);
        self::assertArrayNotHasKey('join_url', $safe);
        self::assertSame(hash('sha256', $secretJoinUrl), $safe['join_url_hash']);

        try {
            $service->create(
                $this->definition(key: 'different-channel', chatId: -1005555555555, joinUrl: 'https://t.me/+DifferentInviteHash_12345', visibility: 'private'),
                $context,
            );
            self::fail('Reused create fingerprint with different payload must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram configuration mutation fingerprint conflict.', $exception->getMessage());
        }

        $leakingContext = new TelegramConfigurationChangeContext(
            'telegram-channel-secret-context',
            'correlation-secret-context-0001',
            'telegram_membership_configuration',
            'Do not persist '.$secretJoinUrl,
            $ownerId,
        );
        try {
            $service->create(
                $this->definition(key: 'secret-context-channel', chatId: -1006666666666, joinUrl: $secretJoinUrl, visibility: 'private'),
                $leakingContext,
            );
            self::fail('Private join URL must not enter audit context.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Telegram join-link secret must not appear in audit context.', $exception->getMessage());
            self::assertSame(0, DB::table('audit_logs')->where('request_fingerprint', 'telegram-channel-secret-context')->count());
        }
    }

    public function test_stored_private_join_link_cannot_leak_through_update_activate_or_disable_context(): void
    {
        $ownerId = $this->administrator(true);
        $storedSecret = 'https://t.me/+StoredPrivateInvite_987654321';
        $providerCalls = 0;
        $service = $this->service($this->lookup(function () use (&$providerCalls): TelegramMembershipLookupResult {
            $providerCalls++;

            return $this->adminEvidence();
        }));
        $created = $service->create(
            $this->definition(joinUrl: $storedSecret, visibility: 'private'),
            $this->context($ownerId, 'telegram-channel-secret-create01'),
        );

        $leakingUpdate = new TelegramConfigurationChangeContext(
            'telegram-channel-secret-update01',
            'correlation-secret-update-0001',
            'telegram_membership_configuration',
            'Replace '.$storedSecret,
            $ownerId,
        );
        try {
            $service->update(
                $created->targetId,
                1,
                $this->definition(joinUrl: 'https://t.me/+ReplacementPrivateInvite_12345', visibility: 'private'),
                $leakingUpdate,
            );
            self::fail('Stored private join URL must not enter update audit context.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Telegram join-link secret must not appear in audit context.', $exception->getMessage());
            self::assertSame(1, (int) DB::table('required_channels')->where('id', $created->targetId)->value('version'));
            self::assertSame(0, DB::table('audit_logs')->where('request_fingerprint', 'telegram-channel-secret-update01')->count());
        }

        $leakingActivate = new TelegramConfigurationChangeContext(
            'telegram-channel-secret-active01',
            'correlation-secret-active-0001',
            'telegram_membership_configuration',
            'Activate using StoredPrivateInvite_987654321',
            $ownerId,
        );
        try {
            $service->activate($created->targetId, 1, $leakingActivate);
            self::fail('Stored private invite token must not enter activation audit context.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Telegram join-link secret must not appear in audit context.', $exception->getMessage());
            self::assertSame(0, $providerCalls);
            self::assertSame('draft', DB::table('required_channels')->where('id', $created->targetId)->value('state'));
        }

        $activated = $service->activate(
            $created->targetId,
            1,
            $this->context($ownerId, 'telegram-channel-secret-active02'),
        );
        self::assertSame('active', $activated->after['state']);
        self::assertSame(1, $providerCalls);

        $leakingDisable = new TelegramConfigurationChangeContext(
            'telegram-channel-secret-disable1',
            'correlation-secret-disable-0001',
            'telegram_membership_configuration',
            'Disable '.$storedSecret,
            $ownerId,
        );
        try {
            $service->disable($created->targetId, 2, $leakingDisable);
            self::fail('Stored private join URL must not enter disable audit context.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Telegram join-link secret must not appear in audit context.', $exception->getMessage());
            self::assertSame('active', DB::table('required_channels')->where('id', $created->targetId)->value('state'));
            self::assertSame(0, DB::table('audit_logs')->where('request_fingerprint', 'telegram-channel-secret-disable1')->count());
        }
    }

    public function test_update_version_fingerprint_and_active_identity_changes_fail_closed(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->service($this->lookup(fn (): TelegramMembershipLookupResult => $this->adminEvidence()));
        $created = $service->create($this->definition(), $this->context($ownerId, 'telegram-channel-create-0002'));

        $updated = $service->update(
            $created->targetId,
            1,
            $this->definition(title: 'Updated title', sortOrder: 20),
            $this->context($ownerId, 'telegram-channel-update-0001'),
        );
        self::assertSame(2, $updated->after['version']);
        self::assertSame('Updated title', $updated->after['display_title']);

        $this->expectRuntimeFailure(
            fn () => $service->update(
                $created->targetId,
                1,
                $this->definition(title: 'Stale title'),
                $this->context($ownerId, 'telegram-channel-stale-00001'),
            ),
            'Telegram required-channel version conflict.',
        );

        $replayContext = $this->context($ownerId, 'telegram-channel-update-replay');
        $service->update($created->targetId, 2, $this->definition(title: 'Version three'), $replayContext);
        try {
            $service->update($created->targetId, 2, $this->definition(title: 'Different payload'), $replayContext);
            self::fail('Expected Telegram configuration fingerprint conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram configuration mutation fingerprint conflict.', $exception->getMessage());
        }

        $active = $service->activate($created->targetId, 3, $this->context($ownerId, 'telegram-channel-activate-01'));
        self::assertSame('active', $active->after['state']);
        self::assertSame(4, $active->after['version']);

        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                DB::table('required_channels')->where('id', $created->targetId)->update([
                    'state' => 'disabled',
                    'telegram_chat_id' => -1008888888888,
                    'version' => 5,
                    'verified_bot_id' => null,
                    'verification_result_code' => null,
                    'verified_at' => null,
                    'updated_at' => now('UTC'),
                ]);
                self::fail('MariaDB must prevent retargeting while disabling an active registry entry.');
            } catch (QueryException) {
                self::assertSame('active', DB::table('required_channels')->where('id', $created->targetId)->value('state'));
                self::assertSame(-1001234567890, (int) DB::table('required_channels')->where('id', $created->targetId)->value('telegram_chat_id'));
            }
        }

        try {
            $service->update(
                $created->targetId,
                4,
                $this->definition(chatId: -1009999999999),
                $this->context($ownerId, 'telegram-channel-active-edit01'),
            );
            self::fail('Active channel identity must not be edited.');
        } catch (DomainException) {
            self::assertSame(-1001234567890, (int) DB::table('required_channels')->where('id', $created->targetId)->value('telegram_chat_id'));
        }

        $disabled = $service->disable($created->targetId, 4, $this->context($ownerId, 'telegram-channel-disable-001'));
        self::assertSame('disabled', $disabled->after['state']);
        self::assertNull($disabled->after['verified_bot_id']);
        self::assertNull(DB::table('required_channels')->where('id', $created->targetId)->value('verified_at'));

        $retargeted = $service->update(
            $created->targetId,
            5,
            $this->definition(chatId: -1009999999999),
            $this->context($ownerId, 'telegram-channel-retarget-001'),
        );
        self::assertSame(6, $retargeted->after['version']);
        self::assertSame(-1009999999999, $retargeted->after['telegram_chat_id']);
    }

    public function test_activation_requires_bot_administrator_evidence_and_runs_lookup_before_transaction(): void
    {
        $ownerId = $this->administrator(true);
        $transactionLevels = [];
        $lookupCalls = 0;
        $baselineTransactionLevel = DB::connection()->transactionLevel();
        $service = $this->service($this->lookup(function (int $chatId, int $userId) use (&$transactionLevels, &$lookupCalls): TelegramMembershipLookupResult {
            $lookupCalls++;
            $transactionLevels[] = DB::connection()->transactionLevel();
            self::assertSame(-1001234567890, $chatId);
            self::assertSame(123456, $userId);

            return $this->adminEvidence();
        }));
        $created = $service->create($this->definition(), $this->context($ownerId, 'telegram-channel-create-0003'));

        $activationContext = $this->context($ownerId, 'telegram-channel-activate-02');
        $activated = $service->activate($created->targetId, 1, $activationContext);
        $replayedActivation = $service->activate($created->targetId, 1, $activationContext);

        self::assertSame([$baselineTransactionLevel], $transactionLevels);
        self::assertSame(1, $lookupCalls);
        self::assertTrue($replayedActivation->replayed);
        self::assertSame($activated->targetId, $replayedActivation->targetId);
        self::assertSame('active', $activated->after['state']);
        self::assertSame(123456, $activated->after['verified_bot_id']);
        self::assertSame('telegram_membership_administrator', $activated->after['verification_result_code']);
        self::assertNotNull($activated->after['verified_at']);

        foreach ([
            new TelegramMembershipLookupResult(TelegramMembershipEvidence::Member, 'telegram_membership_member'),
            new TelegramMembershipLookupResult(TelegramMembershipEvidence::NotMember, 'telegram_membership_left'),
            new TelegramMembershipLookupResult(TelegramMembershipEvidence::Unavailable, 'telegram_membership_http_unavailable'),
        ] as $index => $evidence) {
            $candidate = $this->service($this->lookup(fn (): TelegramMembershipLookupResult => $evidence));
            $draft = $candidate->create(
                $this->definition(key: 'channel-'.$index, chatId: -1002000000000 - $index),
                $this->context($ownerId, 'telegram-channel-evidence-create-'.$index),
            );
            try {
                $candidate->activate($draft->targetId, 1, $this->context($ownerId, 'telegram-channel-evidence-active-'.$index));
                self::fail('Non-administrator membership evidence must not activate a channel.');
            } catch (DomainException) {
                self::assertSame('draft', DB::table('required_channels')->where('id', $draft->targetId)->value('state'));
            }
        }
    }

    public function test_concurrent_row_drift_after_external_verification_prevents_stale_activation(): void
    {
        $ownerId = $this->administrator(true);
        $initial = $this->service($this->lookup(fn (): TelegramMembershipLookupResult => $this->adminEvidence()));
        $created = $initial->create($this->definition(), $this->context($ownerId, 'telegram-channel-create-0004'));

        $baselineTransactionLevel = DB::connection()->transactionLevel();
        $service = $this->service($this->lookup(function () use ($created, $baselineTransactionLevel): TelegramMembershipLookupResult {
            self::assertSame($baselineTransactionLevel, DB::connection()->transactionLevel());
            DB::table('required_channels')->where('id', $created->targetId)->update([
                'display_title' => 'Concurrent edit',
                'version' => 2,
                'updated_at' => now('UTC'),
            ]);

            return $this->adminEvidence();
        }));

        $this->expectRuntimeFailure(
            fn () => $service->activate($created->targetId, 1, $this->context($ownerId, 'telegram-channel-stale-active1')),
            'Telegram required-channel version conflict.',
        );
        self::assertSame('draft', DB::table('required_channels')->where('id', $created->targetId)->value('state'));
        self::assertNull(DB::table('required_channels')->where('id', $created->targetId)->value('verified_at'));
    }

    public function test_permission_seed_and_execution_time_authorization_are_bounded_to_sales_content(): void
    {
        $ownerId = $this->administrator(true);
        $salesId = $this->administrator();
        $unauthorizedId = $this->administrator();
        $roleId = (int) DB::table('roles')->where('code', 'sales_content')->value('id');
        $now = now('UTC');
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $salesId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => $ownerId,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(1, DB::table('permissions')->where('code', 'telegram.membership.manage')->count());
        $service = $this->service($this->lookup(fn (): TelegramMembershipLookupResult => $this->adminEvidence()));
        $created = $service->create(
            $this->definition(key: 'sales-content-channel', chatId: -1003333333333),
            $this->context($salesId, 'telegram-channel-sales-create1'),
        );
        self::assertGreaterThan(0, $created->targetId);

        try {
            $service->create(
                $this->definition(key: 'unauthorized-channel', chatId: -1004444444444),
                $this->context($unauthorizedId, 'telegram-channel-unauth-create'),
            );
            self::fail('Expected Telegram membership management authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('required_channels')->where('channel_key', 'unauthorized-channel')->count());
        }
    }

    public function test_activation_authorization_blocks_provider_access_and_rechecks_after_external_lookup(): void
    {
        $ownerId = $this->administrator(true);
        $unauthorizedId = $this->administrator();
        $providerCalls = 0;
        $service = $this->service($this->lookup(function () use (&$providerCalls): TelegramMembershipLookupResult {
            $providerCalls++;

            return $this->adminEvidence();
        }));
        $created = $service->create($this->definition(), $this->context($ownerId, 'telegram-channel-create-0005'));

        try {
            $service->activate($created->targetId, 1, $this->context($unauthorizedId, 'telegram-channel-unauth-active'));
            self::fail('Unauthorized administrator must not reach provider lookup.');
        } catch (AuthorizationException) {
            self::assertSame(0, $providerCalls);
            self::assertSame('draft', DB::table('required_channels')->where('id', $created->targetId)->value('state'));
        }

        $salesId = $this->administrator();
        $roleId = (int) DB::table('roles')->where('code', 'sales_content')->value('id');
        $now = now('UTC');
        $assignmentId = (int) DB::table('administrator_role_assignments')->insertGetId([
            'administrator_id' => $salesId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => $ownerId,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = $this->service($this->lookup(function () use ($assignmentId, &$providerCalls): TelegramMembershipLookupResult {
            $providerCalls++;
            DB::table('administrator_role_assignments')->where('id', $assignmentId)->update([
                'revoked_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return $this->adminEvidence();
        }));

        try {
            $service->activate($created->targetId, 1, $this->context($salesId, 'telegram-channel-revoke-active'));
            self::fail('Authorization revoked after lookup must prevent activation.');
        } catch (AuthorizationException) {
            self::assertSame(1, $providerCalls);
            self::assertSame('draft', DB::table('required_channels')->where('id', $created->targetId)->value('state'));
            self::assertNull(DB::table('required_channels')->where('id', $created->targetId)->value('verified_at'));
        }
    }

    public function test_definition_and_database_constraints_reject_unsafe_registry_shapes(): void
    {
        $invalidDefinitions = [
            fn () => $this->definition(chatId: 123),
            fn () => $this->definition(joinUrl: 'http://t.me/example_name'),
            fn () => $this->definition(joinUrl: 'https://evil.example/example_name'),
            fn () => $this->definition(joinUrl: 'https://t.me/+private_hash', visibility: 'public'),
            fn () => $this->definition(joinUrl: 'https://t.me/example_name', visibility: 'private'),
            fn () => $this->definition(joinUrl: 'https://user@t.me/example_name'),
            fn () => $this->definition(joinUrl: 'https://t.me/example_name?x=1'),
        ];
        $rejected = 0;
        foreach ($invalidDefinitions as $invalid) {
            try {
                $invalid();
                self::fail('Expected required-channel definition rejection.');
            } catch (\InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(count($invalidDefinitions), $rejected);

        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('MariaDB direct constraint verification requires mysql driver.');
        }

        $ownerId = $this->administrator(true);
        $service = $this->service($this->lookup(fn (): TelegramMembershipLookupResult => $this->adminEvidence()));
        $draft = $service->create(
            $this->definition(key: 'direct-version-guard', chatId: -1009990000001),
            $this->context($ownerId, 'telegram-channel-version-create1'),
        );
        try {
            DB::table('required_channels')->where('id', $draft->targetId)->update([
                'display_title' => 'Direct stale edit',
                'updated_at' => now('UTC'),
            ]);
            self::fail('MariaDB must require an exact version increment for registry updates.');
        } catch (QueryException) {
            self::assertSame(1, (int) DB::table('required_channels')->where('id', $draft->targetId)->value('version'));
            self::assertSame('Main channel', DB::table('required_channels')->where('id', $draft->targetId)->value('display_title'));
        }

        $now = now('UTC');
        try {
            DB::table('required_channels')->insert([
                'channel_key' => 'direct-invalid',
                'telegram_chat_id' => 100,
                'chat_type' => 'channel',
                'visibility' => 'public',
                'display_title' => 'Invalid',
                'join_url_ciphertext' => str_repeat('x', 64),
                'join_url_hash' => str_repeat('a', 64),
                'sort_order' => 0,
                'state' => 'active',
                'version' => 1,
                'verified_bot_id' => null,
                'verification_result_code' => null,
                'verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('MariaDB must reject invalid active channel rows.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('required_channels')->where('channel_key', 'direct-invalid')->count());
        }

        try {
            DB::table('required_channels')->insert([
                'channel_key' => 'direct-active-unverified',
                'telegram_chat_id' => -1007777777777,
                'chat_type' => 'channel',
                'visibility' => 'public',
                'display_title' => 'Invalid active proof',
                'join_url_ciphertext' => str_repeat('x', 64),
                'join_url_hash' => str_repeat('b', 64),
                'sort_order' => 0,
                'state' => 'active',
                'version' => 1,
                'verified_bot_id' => null,
                'verification_result_code' => null,
                'verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::fail('MariaDB must reject active registry rows without capability proof.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('required_channels')->where('channel_key', 'direct-active-unverified')->count());
        }
    }

    private function service(TelegramMembershipLookup $lookup): TelegramRequiredChannelService
    {
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $this->app->instance(TelegramRuntimeConfiguration::class, new TelegramRuntimeConfiguration(
            botToken: '123456:abcdefghijklmnopqrstuvwxyzABCDE',
            botId: '123456',
            webhookSecret: str_repeat('w', 32),
            webhookUrl: 'https://example.test/api/telegram/webhook',
            maximumBodyBytes: 1_048_576,
            queue: 'telegram-ingress',
            processingLeaseSeconds: 120,
            apiBaseUrl: 'https://api.telegram.org',
            apiTimeoutSeconds: 15,
        ));
        $this->app->forgetInstance(TelegramRequiredChannelService::class);

        return $this->app->make(TelegramRequiredChannelService::class);
    }

    /** @param callable(int, int): TelegramMembershipLookupResult $callback */
    private function lookup(callable $callback): TelegramMembershipLookup
    {
        return new readonly class($callback) implements TelegramMembershipLookup
        {
            /** @param callable(int, int): TelegramMembershipLookupResult $callback */
            public function __construct(private mixed $callback) {}

            public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
            {
                return ($this->callback)($chatId, $telegramUserId);
            }
        };
    }

    private function adminEvidence(): TelegramMembershipLookupResult
    {
        return new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_administrator',
        );
    }

    private function definition(
        string $key = 'required-main-channel',
        int $chatId = -1001234567890,
        string $chatType = 'channel',
        string $visibility = 'public',
        string $title = 'Main channel',
        string $joinUrl = 'https://t.me/example_channel',
        int $sortOrder = 10,
    ): TelegramRequiredChannelDefinition {
        return new TelegramRequiredChannelDefinition($key, $chatId, $chatType, $visibility, $title, $joinUrl, $sortOrder);
    }

    private function administrator(bool $owner = false): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(int $administratorId, string $fingerprint): TelegramConfigurationChangeContext
    {
        return new TelegramConfigurationChangeContext(
            $fingerprint,
            'correlation-'.substr(hash('sha256', $fingerprint), 0, 24),
            'telegram_membership_configuration',
            'Telegram membership registry test change.',
            $administratorId,
        );
    }

    private function expectRuntimeFailure(callable $operation, string $expectedMessage): void
    {
        try {
            $operation();
            self::fail('Expected Telegram required-channel runtime failure.');
        } catch (RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }
    }
}
