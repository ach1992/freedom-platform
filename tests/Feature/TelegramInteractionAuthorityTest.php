<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Application\TelegramInteractionAction;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionDispatcher;
use App\Modules\Telegram\Application\TelegramInteractionHandlerRegistry;
use App\Modules\Telegram\Application\TelegramInteractionRejected;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Modules\Telegram\Domain\TelegramInteractionDispatchStatus;
use App\Modules\Telegram\Domain\TelegramInteractionSessionStatus;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ARCH-003 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 QUA-007 */
final class TelegramInteractionAuthorityTest extends TestCase
{
    use DatabaseTruncation;

    private TelegramInteractionTestClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram interaction authority verification requires MariaDB/MySQL.');
        }

        $migration = require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php');
        $migration->up();

        $this->clock = new TelegramInteractionTestClock(new DateTimeImmutable('2026-08-25T00:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->forgetInteractionServices();
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_session_transitions_are_restart_safe_replay_exact_and_version_fenced(): void
    {
        $account = $this->account('session');
        $sessions = $this->sessions();

        $started = $sessions->start(
            $account['telegram_account_id'],
            'customer.purchase',
            'choose_plan',
            ['page' => 1],
            'session-start-1',
        );
        self::assertSame(TelegramInteractionSessionStatus::Active, $started->status);
        self::assertSame(1, $started->version);
        self::assertFalse($started->replayed);

        $startReplay = $sessions->start(
            $account['telegram_account_id'],
            'customer.purchase',
            'choose_plan',
            ['page' => 1],
            'session-start-1',
        );
        self::assertTrue($startReplay->replayed);
        self::assertSame($started->publicId, $startReplay->publicId);
        self::assertSame(['page' => 1], $startReplay->payload);

        try {
            $sessions->start(
                $account['telegram_account_id'],
                'customer.purchase',
                'different_state',
                ['page' => 1],
                'session-start-1',
            );
            self::fail('A reused session request key must not accept different semantics.');
        } catch (\DomainException) {
            // Expected.
        }

        $transitioned = $sessions->transition(
            $started->publicId,
            1,
            'confirm',
            ['page' => 2],
            'session-transition-1',
        );
        self::assertSame(2, $transitioned->version);
        self::assertSame('confirm', $transitioned->state);

        try {
            $sessions->transition(
                $started->publicId,
                1,
                'forged_stale',
                [],
                'session-transition-stale',
            );
            self::fail('A stale session version must fail closed.');
        } catch (\DomainException) {
            // Expected.
        }

        $this->app->forgetInstance(TelegramInteractionSessionService::class);
        $resumed = $this->sessions()->activeForAccount($account['telegram_account_id']);
        self::assertNotNull($resumed);
        self::assertSame($started->publicId, $resumed->publicId);
        self::assertSame(2, $resumed->version);
        self::assertSame(['page' => 2], $resumed->payload);

        $transitionReplay = $this->sessions()->transition(
            $started->publicId,
            1,
            'confirm',
            ['page' => 2],
            'session-transition-1',
        );
        self::assertTrue($transitionReplay->replayed);
        self::assertSame(2, $transitionReplay->version);
        self::assertSame('confirm', $transitionReplay->state);
        self::assertSame(2, DB::table('telegram_interaction_transitions')->count());

        $this->clock->advance('+31 minutes');
        self::assertNull($this->sessions()->activeForAccount($account['telegram_account_id']));
        self::assertSame('expired', DB::table('telegram_interaction_sessions')->value('status'));
        self::assertSame(3, (int) DB::table('telegram_interaction_sessions')->value('version'));
        self::assertSame(3, DB::table('telegram_interaction_transitions')->count());

        $this->assertInteractionCapabilityCleared();
    }

    public function test_callback_tokens_are_opaque_actor_bound_stale_safe_and_direct_dml_protected(): void
    {
        $owner = $this->account('callback-owner', 123456, 900001);
        $other = $this->account('callback-other', 123456, 900002);
        $session = $this->sessions()->start(
            $owner['telegram_account_id'],
            'customer.purchase',
            'confirm',
            ['cart' => 'safe-cart-reference'],
            'callback-session-start',
        );

        $callbacks = $this->callbacks();
        $issued = $callbacks->issue(
            $session->publicId,
            $session->version,
            'purchase.confirm',
            ['selection' => 'plan-basic'],
            'callback-issue-1',
        );
        self::assertMatchesRegularExpression('/\Ai_[A-Za-z0-9_-]{32}\z/', $issued->token);
        self::assertLessThanOrEqual(64, strlen($issued->token));
        self::assertSame(['cart' => 'safe-cart-reference'], $issued->sessionPayload);

        $row = DB::table('telegram_interaction_callbacks')->where('public_id', $issued->publicId)->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $issued->token), $row->token_hash);
        self::assertStringNotContainsString($issued->token, (string) $row->token_ciphertext);
        self::assertStringNotContainsString('safe-cart-reference', (string) $row->token_ciphertext);

        $issueReplay = $callbacks->issue(
            $session->publicId,
            $session->version,
            'purchase.confirm',
            ['selection' => 'plan-basic'],
            'callback-issue-1',
        );
        self::assertTrue($issueReplay->replayed);
        self::assertSame($issued->token, $issueReplay->token);
        self::assertSame($issued->publicId, $issueReplay->publicId);

        try {
            DB::table('telegram_accounts')->where('id', $owner['telegram_account_id'])->update([
                'user_id' => $other['user_id'],
                'bot_id' => 777777,
                'telegram_user_id' => 999991,
            ]);
            self::fail('An active interaction must prevent direct parent Telegram identity retargeting.');
        } catch (QueryException) {
            // Expected: the interaction authority freezes actor identity while the session is active.
        }
        $identitySnapshot = DB::table('telegram_interaction_sessions')
            ->where('public_id', $session->publicId)
            ->first(['user_id', 'bot_id', 'telegram_user_id']);
        self::assertNotNull($identitySnapshot);
        self::assertSame($owner['user_id'], (int) $identitySnapshot->user_id);
        self::assertSame(123456, (int) $identitySnapshot->bot_id);
        self::assertSame($owner['telegram_user_id'], (int) $identitySnapshot->telegram_user_id);

        foreach ([
            ['123456', $other['telegram_user_id'], $issued->token, 5001],
            ['654321', $owner['telegram_user_id'], $issued->token, 5002],
            ['123456', $owner['telegram_user_id'], 'i_'.str_repeat('A', 32), 5003],
        ] as [$botId, $telegramUserId, $token, $updateId]) {
            try {
                $callbacks->accept((string) $botId, (int) $telegramUserId, (string) $token, (int) $updateId);
                self::fail('Forged or cross-actor callback authority must fail closed.');
            } catch (TelegramInteractionRejected) {
                // Expected.
            }
        }

        $accepted = $callbacks->accept('123456', $owner['telegram_user_id'], $issued->token, 5010);
        self::assertTrue($accepted->accepted);
        self::assertSame($owner['user_id'], $accepted->userId);
        self::assertSame(5010, $accepted->acceptedUpdateId);
        self::assertFalse($accepted->completed);
        self::assertFalse($accepted->replayed);

        $acceptedReplay = $callbacks->accept('123456', $owner['telegram_user_id'], $issued->token, 5010);
        self::assertTrue($acceptedReplay->replayed);
        self::assertSame(5010, $acceptedReplay->acceptedUpdateId);
        self::assertSame($accepted->requestKey, $acceptedReplay->requestKey);

        $callbacks->complete($issued->publicId);
        $completedReplay = $callbacks->accept('123456', $owner['telegram_user_id'], $issued->token, 5011);
        self::assertTrue($completedReplay->completed);
        self::assertTrue($completedReplay->replayed);

        try {
            DB::table('telegram_interaction_sessions')
                ->where('public_id', $session->publicId)
                ->update(['state' => 'forged']);
            self::fail('Direct session retargeting must be rejected by MariaDB authority guards.');
        } catch (QueryException) {
            // Expected.
        }

        try {
            DB::table('telegram_interaction_callbacks')
                ->where('public_id', $issued->publicId)
                ->update(['action' => 'forged.action']);
            self::fail('Direct callback retargeting must be rejected by MariaDB authority guards.');
        } catch (QueryException) {
            // Expected.
        }

        try {
            DB::table('telegram_interaction_transitions')->update(['to_state' => 'forged']);
            self::fail('Interaction transition history must be immutable.');
        } catch (QueryException) {
            // Expected.
        }

        $staleSession = $this->sessions()->start(
            $other['telegram_account_id'],
            'customer.purchase',
            'choose_plan',
            [],
            'stale-session-start',
        );
        $staleCallback = $callbacks->issue(
            $staleSession->publicId,
            1,
            'purchase.next',
            [],
            'stale-callback-issue',
        );
        $this->sessions()->transition($staleSession->publicId, 1, 'confirm', [], 'stale-session-transition');
        try {
            $callbacks->accept('123456', $other['telegram_user_id'], $staleCallback->token, 5020);
            self::fail('A callback bound to an old session version must fail closed.');
        } catch (TelegramInteractionRejected) {
            // Expected.
        }

        $this->assertInteractionCapabilityCleared();
    }

    public function test_sensitive_payload_expired_callback_and_cancel_replay_fail_closed(): void
    {
        $account = $this->account('security-edges', 123456, 905001);

        try {
            $this->sessions()->start(
                $account['telegram_account_id'],
                'customer.security',
                'start',
                ['api_token' => 'must-never-persist'],
                'security-sensitive-payload',
            );
            self::fail('Sensitive interaction payload keys must fail before persistence.');
        } catch (\InvalidArgumentException) {
            // Expected.
        }
        self::assertSame(0, DB::table('telegram_interaction_sessions')->count());

        $session = $this->sessions()->start(
            $account['telegram_account_id'],
            'customer.security',
            'start',
            [],
            'security-session-start',
        );
        $callback = $this->callbacks()->issue(
            $session->publicId,
            1,
            'security.confirm',
            [],
            'security-callback-issue',
            30,
        );

        $this->clock->advance('+31 seconds');
        try {
            $this->callbacks()->accept('123456', $account['telegram_user_id'], $callback->token, 5501);
            self::fail('An expired callback token must fail closed.');
        } catch (TelegramInteractionRejected) {
            // Expected.
        }
        self::assertSame('pending', DB::table('telegram_interaction_callbacks')
            ->where('public_id', $callback->publicId)
            ->value('state'));

        $cancelled = $this->sessions()->cancelActive($account['telegram_account_id'], 'security-cancel');
        self::assertNotNull($cancelled);
        self::assertSame(TelegramInteractionSessionStatus::Cancelled, $cancelled->status);
        self::assertFalse($cancelled->replayed);

        $cancelReplay = $this->sessions()->cancelActive($account['telegram_account_id'], 'security-cancel');
        self::assertNotNull($cancelReplay);
        self::assertTrue($cancelReplay->replayed);
        self::assertSame($cancelled->publicId, $cancelReplay->publicId);
        self::assertSame(2, DB::table('telegram_interaction_transitions')->count());
    }

    public function test_dispatcher_routes_back_cancel_and_prevents_second_inflight_callback_execution(): void
    {
        $account = $this->account('dispatcher', 123456, 910001);
        $session = $this->sessions()->start(
            $account['telegram_account_id'],
            'customer.demo',
            'step_one',
            ['safe' => 'context'],
            'dispatcher-session-start',
        );
        $handler = new RecordingTelegramInteractionHandler('customer.demo');
        $this->bindHandler($handler);
        $dispatcher = $this->dispatcher();

        $message = $dispatcher->dispatch('123456', 6001, $account['user_id'], [
            'message' => ['text' => 'hello'],
        ]);
        self::assertSame(TelegramInteractionDispatchStatus::Handled, $message->status);
        self::assertCount(1, $handler->actions);
        self::assertSame(TelegramInteractionActionKind::Message, $handler->actions[0]->kind);
        self::assertSame('telegram-update:123456:6001:message', $handler->actions[0]->requestKey);
        self::assertSame(['safe' => 'context'], $handler->actions[0]->sessionPayload);

        $back = $dispatcher->dispatch('123456', 6002, $account['user_id'], [
            'message' => ['text' => '/back'],
        ]);
        self::assertSame(TelegramInteractionDispatchStatus::Handled, $back->status);
        self::assertSame(TelegramInteractionActionKind::Back, $handler->actions[1]->kind);

        $cancelled = $dispatcher->dispatch('123456', 6003, $account['user_id'], [
            'message' => ['text' => '/cancel'],
        ]);
        self::assertSame(TelegramInteractionDispatchStatus::Cancelled, $cancelled->status);
        self::assertSame($session->publicId, $cancelled->sessionPublicId);
        self::assertCount(2, $handler->actions);
        self::assertNull($this->sessions()->activeForAccount($account['telegram_account_id']));

        $callbackSession = $this->sessions()->start(
            $account['telegram_account_id'],
            'customer.demo',
            'confirm',
            ['safe' => 'callback-context'],
            'dispatcher-callback-session',
        );
        $callback = $this->callbacks()->issue(
            $callbackSession->publicId,
            1,
            'demo.confirm',
            ['choice' => 'yes'],
            'dispatcher-callback-issue',
        );

        $handler->throwOnce = true;
        try {
            $dispatcher->dispatch('123456', 6010, $account['user_id'], [
                'callback_query' => ['data' => $callback->token],
            ]);
            self::fail('The simulated handler crash must escape so the durable update can retry.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated-handler-crash', $exception->getMessage());
        }
        self::assertCount(3, $handler->actions);
        self::assertSame('accepted', DB::table('telegram_interaction_callbacks')->where('public_id', $callback->publicId)->value('state'));
        $stableRequestKey = $handler->actions[2]->requestKey;

        $differentUpdate = $dispatcher->dispatch('123456', 6011, $account['user_id'], [
            'callback_query' => ['data' => $callback->token],
        ]);
        self::assertSame(TelegramInteractionDispatchStatus::Replayed, $differentUpdate->status);
        self::assertCount(3, $handler->actions, 'A second click must not execute while the accepted update is still unresolved.');

        $recovered = $dispatcher->dispatch('123456', 6010, $account['user_id'], [
            'callback_query' => ['data' => $callback->token],
        ]);
        self::assertSame(TelegramInteractionDispatchStatus::Handled, $recovered->status);
        self::assertCount(4, $handler->actions);
        self::assertSame($stableRequestKey, $handler->actions[3]->requestKey);
        self::assertTrue($handler->actions[3]->replayed);
        self::assertSame(['safe' => 'callback-context'], $handler->actions[3]->sessionPayload);
        self::assertSame('completed', DB::table('telegram_interaction_callbacks')->where('public_id', $callback->publicId)->value('state'));

        $afterCompletion = $dispatcher->dispatch('123456', 6012, $account['user_id'], [
            'callback_query' => ['data' => $callback->token],
        ]);
        self::assertSame(TelegramInteractionDispatchStatus::Replayed, $afterCompletion->status);
        self::assertCount(4, $handler->actions);
    }

    public function test_migration_reentry_detects_complete_authority_without_duplicate_constraints(): void
    {
        $migration = require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php');
        $migration->up();

        self::assertSame(12, DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', 'like', 'telegram_interaction_%')
            ->count());
        self::assertTrue(DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', 'telegram_accounts_interaction_identity_update_guard')
            ->exists());
        self::assertSame(18, DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->whereIn('TABLE_NAME', [
                'telegram_interaction_sessions',
                'telegram_interaction_transitions',
                'telegram_interaction_callbacks',
            ])
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->where('CONSTRAINT_NAME', 'like', 'telegram_interaction_%')
            ->count());
        self::assertSame(1, DB::table('telegram_interaction_authority_capability')->count());
    }

    /** @return array{user_id:int,telegram_account_id:int,telegram_user_id:int} */
    private function account(string $suffix, int $botId = 123456, int $telegramUserId = 900000): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
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
            'bot_id' => $botId,
            'telegram_user_id' => $telegramUserId,
            'username' => 'interaction_'.$suffix,
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

    private function sessions(): TelegramInteractionSessionService
    {
        return $this->app->make(TelegramInteractionSessionService::class);
    }

    private function callbacks(): TelegramInteractionCallbackService
    {
        return $this->app->make(TelegramInteractionCallbackService::class);
    }

    private function dispatcher(): TelegramInteractionDispatcher
    {
        $this->app->forgetInstance(TelegramInteractionDispatcher::class);

        return $this->app->make(TelegramInteractionDispatcher::class);
    }

    private function bindHandler(RecordingTelegramInteractionHandler $handler): void
    {
        $this->app->instance(
            TelegramInteractionHandlerRegistry::class,
            new TelegramInteractionHandlerRegistry([$handler]),
        );
        $this->app->forgetInstance(TelegramInteractionDispatcher::class);
    }

    private function forgetInteractionServices(): void
    {
        foreach ([
            TelegramInteractionSessionService::class,
            TelegramInteractionCallbackService::class,
            TelegramInteractionDispatcher::class,
            TelegramInteractionHandlerRegistry::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    private function assertInteractionCapabilityCleared(): void
    {
        $row = DB::selectOne(<<<'SQL'
SELECT
    @app_telegram_interaction_capability AS capability,
    @app_telegram_interaction_authority AS authority_name,
    @app_telegram_interaction_account_id AS account_id,
    @app_telegram_interaction_session_id AS session_id,
    @app_telegram_interaction_callback_id AS callback_id
SQL);
        self::assertNotNull($row);
        self::assertNull($row->capability);
        self::assertNull($row->authority_name);
        self::assertNull($row->account_id);
        self::assertNull($row->session_id);
        self::assertNull($row->callback_id);
    }
}

final class TelegramInteractionTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }

    public function advance(string $modifier): void
    {
        $this->value = $this->value->modify($modifier);
    }
}

final class RecordingTelegramInteractionHandler implements TelegramInteractionHandler
{
    /** @var list<TelegramInteractionAction> */
    public array $actions = [];

    public bool $throwOnce = false;

    public function __construct(private readonly string $flowName) {}

    public function flow(): string
    {
        return $this->flowName;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        $this->actions[] = $action;
        if ($this->throwOnce) {
            $this->throwOnce = false;
            throw new RuntimeException('simulated-handler-crash');
        }
    }
}
