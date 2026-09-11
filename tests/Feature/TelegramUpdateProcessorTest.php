<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Promotions\Application\ReferralAttributionService;
use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Application\TelegramInteractionAction;
use App\Modules\Telegram\Application\TelegramInteractionDispatcher;
use App\Modules\Telegram\Application\TelegramInteractionHandlerRegistry;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramReferralStartAttributionService;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class TelegramUpdateProcessorTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        if (DB::connection()->getDriverName() === 'mysql') {
            $migration = require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php');
            $migration->up();
            (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        }
        Queue::fake();
        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'telegram.webhook_secret' => self::SECRET,
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

    public function test_processing_upserts_identity_profile_and_first_start_attribution_idempotently(): void
    {
        $this->accept($this->payload(3001, 9100, 'initial_name', '/start ref_123'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 3001);
        $processor->process('123456789', 3001);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('telegram_accounts', 1);
        $this->assertDatabaseCount('customer_profiles', 1);
        $this->assertDatabaseCount('telegram_start_attributions', 1);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 3001,
            'state' => 'processed',
            'attempt_count' => 1,
        ]);

        $attribution = DB::table('telegram_start_attributions')->first();
        self::assertNotNull($attribution);
        self::assertStringNotContainsString('ref_123', (string) $attribution->payload_ciphertext);
        self::assertSame(
            'ref_123',
            $this->app->make(StringEncrypter::class)->decryptString((string) $attribution->payload_ciphertext),
        );

        $this->accept($this->payload(3002, 9100, 'renamed_user', 'hello'));
        $processor->process('123456789', 3002);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('telegram_accounts', 1);
        $this->assertDatabaseHas('telegram_accounts', [
            'bot_id' => '123456789',
            'telegram_user_id' => 9100,
            'username' => 'renamed_user',
        ]);
    }

    public function test_first_canonical_start_referral_binds_once_and_later_competing_start_cannot_replace_it(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $referrals = $this->app->make(ReferralAttributionService::class);

        $this->accept($this->payload(3010, 9110, 'referral_inviter_a', 'hello'));
        $processor->process('123456789', 3010);
        $this->accept($this->payload(3011, 9111, 'referral_inviter_b', 'hello'));
        $processor->process('123456789', 3011);
        $inviterA = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9110)->value('user_id');
        $inviterB = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9111)->value('user_id');
        $tokenA = $referrals->identityForUser($inviterA);
        $tokenB = $referrals->identityForUser($inviterB);

        $this->accept($this->payload(3012, 9112, 'referral_referred', '/start '.$tokenA));
        $processor->process('123456789', 3012);
        $processor->process('123456789', 3012);
        $referred = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9112)->value('user_id');

        $relationship = DB::table('referral_relationships')->where('referred_user_id', $referred)->first();
        self::assertNotNull($relationship);
        self::assertSame($inviterA, (int) $relationship->inviter_user_id);
        self::assertSame(1, DB::table('referral_relationships')->where('referred_user_id', $referred)->count());
        self::assertSame(1, DB::table('referral_attribution_events')->where('relationship_id', (int) $relationship->id)->where('event_type', 'bound')->count());
        self::assertSame(0, DB::table('referral_rewards')->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 3012, 'state' => 'processed', 'attempt_count' => 1]);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['user_id' => $referred, 'flow' => 'navigation.home', 'state' => 'home']);

        $stored = DB::table('telegram_start_attributions')->where('user_id', $referred)->first();
        self::assertNotNull($stored);
        self::assertSame(3012, (int) $stored->first_update_id);
        self::assertSame(hash('sha256', $tokenA), (string) $stored->payload_hash);
        self::assertStringNotContainsString($tokenA, (string) $stored->payload_ciphertext);
        self::assertSame(0, DB::table('telegram_interaction_sessions')->where('payload', 'like', '%'.$tokenA.'%')->count());
        self::assertSame(0, DB::table('telegram_delivery_operations')->where('presentation_text', 'like', '%'.$tokenA.'%')->count());

        $this->accept($this->payload(3013, 9112, 'referral_referred', '/start '.$tokenB));
        $processor->process('123456789', 3013);

        $unchanged = DB::table('referral_relationships')->where('referred_user_id', $referred)->first();
        self::assertNotNull($unchanged);
        self::assertSame($inviterA, (int) $unchanged->inviter_user_id);
        self::assertSame(1, DB::table('referral_attribution_events')->where('relationship_id', (int) $unchanged->id)->where('event_type', 'bound')->count());
        self::assertSame(3012, (int) DB::table('telegram_start_attributions')->where('user_id', $referred)->value('first_update_id'));
    }

    public function test_noncanonical_first_start_blocks_later_referral_and_expected_referral_rejections_do_not_trap_navigation(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $referrals = $this->app->make(ReferralAttributionService::class);

        $this->accept($this->payload(3020, 9120, 'referral_inviter', 'hello'));
        $processor->process('123456789', 3020);
        $inviter = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9120)->value('user_id');
        $token = $referrals->identityForUser($inviter);

        $this->accept($this->payload(3021, 9121, 'referral_campaign', '/start campaign_1'));
        $processor->process('123456789', 3021);
        $campaignUser = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9121)->value('user_id');
        self::assertSame(0, DB::table('referral_relationships')->where('referred_user_id', $campaignUser)->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 3021, 'state' => 'processed']);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['user_id' => $campaignUser, 'flow' => 'navigation.home']);

        $this->accept($this->payload(3022, 9121, 'referral_campaign', '/start '.$token));
        $processor->process('123456789', 3022);
        self::assertSame(0, DB::table('referral_relationships')->where('referred_user_id', $campaignUser)->count());
        self::assertSame(3021, (int) DB::table('telegram_start_attributions')->where('user_id', $campaignUser)->value('first_update_id'));

        $this->accept($this->payload(3023, 9122, 'referral_self', 'hello'));
        $processor->process('123456789', 3023);
        $selfUser = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9122)->value('user_id');
        $selfToken = $referrals->identityForUser($selfUser);
        $this->accept($this->payload(3024, 9122, 'referral_self', '/start '.$selfToken));
        $processor->process('123456789', 3024);
        self::assertSame(0, DB::table('referral_relationships')->where('referred_user_id', $selfUser)->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 3024, 'state' => 'processed']);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['user_id' => $selfUser, 'flow' => 'navigation.home']);

        $unknownToken = str_repeat('f', 32);
        self::assertNotSame($token, $unknownToken);
        $this->accept($this->payload(3025, 9123, 'referral_unknown', '/start '.$unknownToken));
        $processor->process('123456789', 3025);
        $unknownUser = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9123)->value('user_id');
        self::assertSame(0, DB::table('referral_relationships')->where('referred_user_id', $unknownUser)->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 3025, 'state' => 'processed']);
        $this->assertDatabaseHas('telegram_interaction_sessions', ['user_id' => $unknownUser, 'flow' => 'navigation.home']);
    }

    public function test_referral_binding_replays_after_post_dispatch_failure_without_duplicate_attribution(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Referral update replay verification requires MariaDB/MySQL.');
        }

        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $referrals = $this->app->make(ReferralAttributionService::class);
        $this->accept($this->payload(3030, 9130, 'referral_retry_inviter', 'hello'));
        $processor->process('123456789', 3030);
        $inviter = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9130)->value('user_id');
        $token = $referrals->identityForUser($inviter);
        $this->accept($this->payload(3031, 9131, 'referral_retry_user', '/start '.$token));

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_update_test_fail_processed_3031
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 3031 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-referral-post-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 3031);
                self::fail('The simulated post-dispatch failure must leave referral update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_update_test_fail_processed_3031');
        }

        $referred = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9131)->value('user_id');
        $relationship = DB::table('referral_relationships')->where('referred_user_id', $referred)->first();
        self::assertNotNull($relationship);
        self::assertSame(1, DB::table('referral_attribution_events')->where('relationship_id', (int) $relationship->id)->where('event_type', 'bound')->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 3031, 'state' => 'failed', 'attempt_count' => 1]);

        $processor->process('123456789', 3031);

        self::assertSame(1, DB::table('referral_relationships')->where('referred_user_id', $referred)->count());
        self::assertSame(1, DB::table('referral_attribution_events')->where('relationship_id', (int) $relationship->id)->where('event_type', 'bound')->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 3031, 'state' => 'processed', 'attempt_count' => 2]);
    }

    public function test_corrupted_first_start_evidence_fails_before_referral_mutation(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->accept($this->payload(3040, 9140, 'referral_corrupt', '/start campaign_safe'));
        $processor->process('123456789', 3040);
        $userId = (int) DB::table('telegram_accounts')->where('telegram_user_id', 9140)->value('user_id');

        DB::table('telegram_start_attributions')->where('user_id', $userId)->update(['payload_hash' => str_repeat('0', 64)]);

        try {
            $this->app->make(TelegramReferralStartAttributionService::class)->bindFirstStart('123456789', 3040, $userId);
            self::fail('Corrupted first-start evidence must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram referral attribution payload integrity check failed.', $exception->getMessage());
        }
        self::assertSame(0, DB::table('referral_relationships')->where('referred_user_id', $userId)->count());
        self::assertSame(0, DB::table('referral_attribution_events')->count());
    }

    public function test_processed_update_routes_one_restart_safe_interaction_transition_without_duplicate_replay(): void
    {
        $this->accept($this->payload(3050, 9150, 'interaction_user', 'hello'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 3050);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', 9150)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $sessions = $this->app->make(TelegramInteractionSessionService::class);
        $session = $sessions->start(
            (int) $account->id,
            'customer.processor_test',
            'awaiting_input',
            [],
            'processor-test-session-start',
        );

        $handler = new class($sessions) implements TelegramInteractionHandler
        {
            public int $calls = 0;

            public function __construct(private readonly TelegramInteractionSessionService $sessions) {}

            public function flow(): string
            {
                return 'customer.processor_test';
            }

            public function handle(TelegramInteractionAction $action): void
            {
                $this->calls++;
                $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    'processed',
                    [],
                    $action->requestKey,
                );
            }
        };
        $this->app->instance(TelegramInteractionHandlerRegistry::class, new TelegramInteractionHandlerRegistry([$handler]));
        $this->app->forgetInstance(TelegramInteractionDispatcher::class);
        $this->app->forgetInstance(TelegramUpdateProcessor::class);

        $this->accept($this->payload(3051, 9150, 'interaction_user', 'next'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 3051);
        $processor->process('123456789', 3051);

        self::assertSame(1, $handler->calls);
        $active = $sessions->activeForAccount((int) $account->id);
        self::assertNotNull($active);
        self::assertSame($session->publicId, $active->publicId);
        self::assertSame('processed', $active->state);
        self::assertSame(2, $active->version);
        self::assertSame(2, DB::table('telegram_interaction_transitions')->count());
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 3051,
            'state' => 'processed',
            'attempt_count' => 1,
        ]);
    }

    public function test_failed_message_and_back_retries_remain_bound_to_the_original_session(): void
    {
        $this->accept($this->payload(3060, 9160, 'retry_user', 'hello'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 3060);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', 9160)->first(['id']);
        self::assertNotNull($account);
        $sessions = $this->app->make(TelegramInteractionSessionService::class);
        $handler = new class($sessions) implements TelegramInteractionHandler
        {
            /** @var list<TelegramInteractionAction> */
            public array $actions = [];

            public bool $throwAfterMutation = false;

            public string $mutation = 'complete';

            public function __construct(private readonly TelegramInteractionSessionService $sessions) {}

            public function flow(): string
            {
                return 'customer.processor_retry';
            }

            public function handle(TelegramInteractionAction $action): void
            {
                $this->actions[] = $action;
                if ($this->mutation === 'transition') {
                    $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        'advanced_by_message',
                        ['phase' => 'advanced'],
                        $action->requestKey,
                    );
                } else {
                    $this->sessions->complete(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        $action->requestKey,
                    );
                }

                if ($this->throwAfterMutation) {
                    $this->throwAfterMutation = false;
                    throw new RuntimeException('simulated-after-interaction-mutation');
                }
            }
        };
        $this->app->instance(TelegramInteractionHandlerRegistry::class, new TelegramInteractionHandlerRegistry([$handler]));
        $this->app->forgetInstance(TelegramInteractionDispatcher::class);
        $this->app->forgetInstance(TelegramUpdateProcessor::class);

        foreach ([
            ['update_id' => 3061, 'text' => 'next', 'kind' => 'message'],
            ['update_id' => 3062, 'text' => '/back', 'kind' => 'back'],
        ] as $case) {
            $original = $sessions->start(
                (int) $account->id,
                'customer.processor_retry',
                'original_'.$case['kind'],
                ['source' => $case['kind']],
                'processor-retry-start-'.$case['kind'],
            );

            $this->accept($this->payload($case['update_id'], 9160, 'retry_user', $case['text']));
            $handler->mutation = $case['kind'] === 'message' ? 'transition' : 'complete';
            $handler->throwAfterMutation = true;
            try {
                $this->app->make(TelegramUpdateProcessor::class)->process('123456789', $case['update_id']);
                self::fail('The simulated post-terminal crash must keep the durable update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }

            $this->assertDatabaseHas('processed_telegram_updates', [
                'update_id' => $case['update_id'],
                'state' => 'failed',
                'attempt_count' => 1,
            ]);
            if ($case['kind'] === 'message') {
                $advanced = $sessions->activeForAccount((int) $account->id);
                self::assertNotNull($advanced);
                self::assertSame($original->publicId, $advanced->publicId);
                self::assertSame(2, $advanced->version);
                self::assertSame('advanced_by_message', $advanced->state);
                $sessions->complete($original->publicId, 2, 'processor-retry-message-free-account');
            }
            $binding = DB::table('telegram_interaction_update_bindings')
                ->where('bot_id', '123456789')
                ->where('update_id', $case['update_id'])
                ->first();
            self::assertNotNull($binding);
            self::assertSame($case['kind'], $binding->kind);
            self::assertSame(1, (int) $binding->session_version);
            self::assertSame(
                (int) DB::table('telegram_interaction_sessions')->where('public_id', $original->publicId)->value('id'),
                (int) $binding->telegram_interaction_session_id,
            );

            $later = $sessions->start(
                (int) $account->id,
                'customer.processor_retry',
                'later_'.$case['kind'],
                ['source' => 'later'],
                'processor-retry-later-'.$case['kind'],
            );

            $this->app->make(TelegramUpdateProcessor::class)->process('123456789', $case['update_id']);

            $first = $handler->actions[count($handler->actions) - 2];
            $recovered = $handler->actions[count($handler->actions) - 1];
            self::assertSame($original->publicId, $first->sessionPublicId);
            self::assertSame($original->publicId, $recovered->sessionPublicId);
            self::assertSame(1, $recovered->sessionVersion);
            self::assertSame('original_'.$case['kind'], $recovered->sessionState);
            self::assertSame(['source' => $case['kind']], $recovered->sessionPayload);
            self::assertTrue($recovered->replayed);

            $stillLater = $sessions->activeForAccount((int) $account->id);
            self::assertNotNull($stillLater);
            self::assertSame($later->publicId, $stillLater->publicId);
            self::assertSame(1, $stillLater->version);
            $this->assertDatabaseHas('processed_telegram_updates', [
                'update_id' => $case['update_id'],
                'state' => 'processed',
                'attempt_count' => 2,
            ]);

            try {
                DB::table('telegram_interaction_update_bindings')
                    ->where('bot_id', '123456789')
                    ->where('update_id', $case['update_id'])
                    ->update(['kind' => 'cancel']);
                self::fail('Durable Telegram interaction update bindings must be immutable.');
            } catch (QueryException) {
                // Expected.
            }

            $sessions->complete($later->publicId, 1, 'processor-retry-cleanup-'.$case['kind']);
        }
    }

    public function test_cancel_without_a_session_is_durably_bound_as_a_noop_before_a_later_session_exists(): void
    {
        $this->accept($this->payload(3070, 9170, 'cancel_retry_user', 'hello'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $processor->process('123456789', 3070);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', 9170)->first(['id']);
        self::assertNotNull($account);

        $this->accept($this->payload(3071, 9170, 'cancel_retry_user', '/cancel'));
        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_update_test_fail_processed_3071
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 3071 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 3071);
                self::fail('The simulated post-dispatch failure must leave the no-op cancel update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_update_test_fail_processed_3071');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_update_test_fail_processed_3031');
        }

        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 3071,
            'state' => 'failed',
            'attempt_count' => 1,
        ]);
        $binding = DB::table('telegram_interaction_update_bindings')
            ->where('bot_id', '123456789')
            ->where('update_id', 3071)
            ->first();
        self::assertNotNull($binding);
        self::assertSame('cancel', $binding->kind);
        self::assertNull($binding->telegram_interaction_session_id);
        self::assertNull($binding->session_version);

        $forgedBinding = get_object_vars($binding);
        unset($forgedBinding['id']);
        $forgedBinding['update_id'] = 3072;
        $forgedBinding['request_hash'] = str_repeat('f', 64);
        try {
            DB::table('telegram_interaction_update_bindings')->insert($forgedBinding);
            self::fail('Direct SQL must not forge Telegram interaction update bindings.');
        } catch (QueryException) {
            // Expected.
        }
        try {
            DB::table('telegram_interaction_update_bindings')->where('id', $binding->id)->delete();
            self::fail('Durable Telegram interaction update bindings must be non-deletable.');
        } catch (QueryException) {
            // Expected.
        }

        $sessions = $this->app->make(TelegramInteractionSessionService::class);
        $later = $sessions->start(
            (int) $account->id,
            'customer.cancel_retry',
            'safe_later_session',
            [],
            'cancel-retry-later-session',
        );

        $processor->process('123456789', 3071);

        $active = $sessions->activeForAccount((int) $account->id);
        self::assertNotNull($active);
        self::assertSame($later->publicId, $active->publicId);
        self::assertSame(1, $active->version);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 3071,
            'state' => 'processed',
            'attempt_count' => 2,
        ]);
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')
            ->where('bot_id', '123456789')
            ->where('update_id', 3071)
            ->count());
    }

    public function test_unknown_update_is_processed_without_creating_identity(): void
    {
        $this->accept(['update_id' => 4001, 'poll' => ['id' => 'poll-id']]);
        $this->app->make(TelegramUpdateProcessor::class)->process('123456789', 4001);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 4001,
            'state' => 'processed',
        ]);
    }

    public function test_corrupt_encrypted_payload_fails_closed_with_sanitized_metadata(): void
    {
        $this->accept($this->payload(5001, 9200, 'corrupt_user', 'hello'));
        DB::table('processed_telegram_updates')
            ->where('update_id', 5001)
            ->update(['payload_ciphertext' => 'not-valid-ciphertext']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram update processing failed.');

        try {
            $this->app->make(TelegramUpdateProcessor::class)->process('123456789', 5001);
        } finally {
            $this->assertDatabaseHas('processed_telegram_updates', [
                'update_id' => 5001,
                'state' => 'failed',
                'attempt_count' => 1,
            ]);
            $row = DB::table('processed_telegram_updates')->where('update_id', 5001)->first();
            self::assertNotNull($row);
            self::assertNotNull($row->last_error_class);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $row->last_error_code);
        }
    }

    /** @param array<string, mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function payload(int $updateId, int $telegramUserId, string $username, string $text): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'date' => 1_700_000_000,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => $username,
                    'language_code' => 'fa',
                ],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }
}
