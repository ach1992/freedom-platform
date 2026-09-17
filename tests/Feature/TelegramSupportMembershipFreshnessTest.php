<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\SupportTicketState;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class TelegramSupportFreshnessMembershipLookup implements TelegramMembershipLookup
{
    public TelegramMembershipEvidence $evidence = TelegramMembershipEvidence::Member;

    public int $calls = 0;

    /** @var (Closure(int): void)|null */
    public ?Closure $onLookup = null;

    public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
    {
        $this->calls++;
        if ($this->onLookup !== null) {
            ($this->onLookup)($this->calls);
        }

        return new TelegramMembershipLookupResult(
            $this->evidence,
            match ($this->evidence) {
                TelegramMembershipEvidence::Member => 'telegram_membership_member',
                TelegramMembershipEvidence::NotMember => 'telegram_membership_left',
                TelegramMembershipEvidence::Unavailable => 'telegram_membership_unavailable',
            },
        );
    }
}

/** @requirement SUP-001 SUP-002 CHN-001 SEC-002 DAT-003 QUA-001 QUA-004 */
final class TelegramSupportMembershipFreshnessTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Support membership freshness verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
        $this->seed();
        Queue::fake();
        config([
            'support.rate_limits.prefix' => 'test:telegram-support-rate-limit:'.bin2hex(random_bytes(8)).':',
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

    public function test_support_home_inline_back_remains_available_after_membership_loss(): void
    {
        $lookup = new TelegramSupportFreshnessMembershipLookup;
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9859;

        $this->accept($this->payload(8590, $telegramUserId, 'support_back_freshness', 'fa', '/start'));
        $processor->process('123456789', 8590);
        $account = $this->account($telegramUserId);
        $this->installMembershipRule('support_view', 'support-view-back-freshness', -1005000000859);

        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8591, $telegramUserId, 'support_back_freshness', 'fa', $support));
        $processor->process('123456789', 8591);
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);
        $back = $this->callbackToken('navigation.back', $account['account_id']);
        $membershipCalls = $lookup->calls;

        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $this->accept($this->callbackPayload(8592, $telegramUserId, 'support_back_freshness', 'fa', $back));
        $processor->process('123456789', 8592);

        self::assertSame('home', $this->supportSession($account['account_id'])['state']);
        self::assertSame($membershipCalls, $lookup->calls, 'Leaving Support from its home must not require fresh Support membership.');
    }

    public function test_ticket_creation_reauthorizes_before_commit_and_failed_update_retries_exactly_once(): void
    {
        $lookup = new TelegramSupportFreshnessMembershipLookup;
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9860;

        $this->accept($this->payload(8600, $telegramUserId, 'support_create_freshness', 'fa', '/start'));
        $processor->process('123456789', 8600);
        $account = $this->account($telegramUserId);
        $this->installMembershipRule('ticket_creation', 'ticket-create-freshness', -1005000000860);

        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8601, $telegramUserId, 'support_create_freshness', 'fa', $support));
        $processor->process('123456789', 8601);
        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8602, $telegramUserId, 'support_create_freshness', 'fa', $create));
        $processor->process('123456789', 8602);
        $category = $this->callbackToken(
            'navigation.support.category',
            $account['account_id'],
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8603, $telegramUserId, 'support_create_freshness', 'fa', $category));
        $processor->process('123456789', 8603);
        self::assertSame('support_create_reference_type', $this->supportSession($account['account_id'])['state']);
        $noReference = $this->callbackToken(
            'navigation.support.reference.type',
            $account['account_id'],
            json_encode(['reference_type' => 'none'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8604, $telegramUserId, 'support_create_freshness', 'fa', $noReference));
        $processor->process('123456789', 8604);
        $this->accept($this->payload(8605, $telegramUserId, 'support_create_freshness', 'fa', 'Membership freshness'));
        $processor->process('123456789', 8605);
        self::assertSame('support_create_description', $this->supportSession($account['account_id'])['state']);

        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $this->accept($this->payload(8606, $telegramUserId, 'support_create_freshness', 'fa', 'Must not create while membership is stale.'));
        $this->assertMembershipProcessingFails($processor, 8606);

        self::assertSame('support_create_description', $this->supportSession($account['account_id'])['state']);
        self::assertSame(0, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());
        self::assertSame(0, DB::table('support_ticket_messages')->where('actor_user_id', $account['user_id'])->count());
        self::assertSame(0, DB::table('support_ticket_state_histories')->where('actor_user_id', $account['user_id'])->count());

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8606);
        self::assertSame('support_ticket', $this->supportSession($account['account_id'])['state']);
        self::assertSame(1, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());
        self::assertSame(1, DB::table('support_ticket_messages')->where('actor_user_id', $account['user_id'])->count());
        $historyCount = DB::table('support_ticket_state_histories')->where('actor_user_id', $account['user_id'])->count();
        self::assertGreaterThan(0, $historyCount);

        $processor->process('123456789', 8606);
        self::assertSame(1, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());
        self::assertSame(1, DB::table('support_ticket_messages')->where('actor_user_id', $account['user_id'])->count());
        self::assertSame($historyCount, DB::table('support_ticket_state_histories')->where('actor_user_id', $account['user_id'])->count());
    }

    public function test_customer_support_continuations_reauthorize_view_before_detail_reply_close_and_reopen(): void
    {
        $lookup = new TelegramSupportFreshnessMembershipLookup;
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9861;

        $this->accept($this->payload(8610, $telegramUserId, 'support_customer_freshness', 'fa', '/start'));
        $processor->process('123456789', 8610);
        $account = $this->account($telegramUserId);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $account['user_id'],
            'other',
            'Fresh view authorization',
            'Initial customer body',
            'support-membership-freshness:customer-create',
        ));
        $this->installMembershipRule('support_view', 'support-view-customer-freshness', -1005000000861);

        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8611, $telegramUserId, 'support_customer_freshness', 'fa', $support));
        $processor->process('123456789', 8611);
        $ticketButton = $this->callbackToken(
            'navigation.support.ticket',
            $account['account_id'],
            json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
        );

        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $deliveryCount = $this->deliveryCount($telegramUserId);
        $this->accept($this->callbackPayload(8612, $telegramUserId, 'support_customer_freshness', 'fa', $ticketButton));
        $this->assertMembershipProcessingFails($processor, 8612);
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);
        self::assertSame($deliveryCount, $this->deliveryCount($telegramUserId));

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8612);
        self::assertSame('support_ticket', $this->supportSession($account['account_id'])['state']);

        $reply = $this->callbackToken('navigation.support.reply', $account['account_id']);
        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $this->accept($this->callbackPayload(8613, $telegramUserId, 'support_customer_freshness', 'fa', $reply));
        $this->assertMembershipProcessingFails($processor, 8613);
        self::assertSame('support_ticket', $this->supportSession($account['account_id'])['state']);

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8613);
        self::assertSame('support_reply', $this->supportSession($account['account_id'])['state']);
        $messageCount = DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count();

        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $this->accept($this->payload(8614, $telegramUserId, 'support_customer_freshness', 'fa', 'Blocked reply'));
        $this->assertMembershipProcessingFails($processor, 8614);
        self::assertSame($messageCount, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame('support_reply', $this->supportSession($account['account_id'])['state']);

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8614);
        self::assertSame($messageCount + 1, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame('support_ticket', $this->supportSession($account['account_id'])['state']);

        $close = $this->callbackToken('navigation.support.close', $account['account_id']);
        $this->accept($this->callbackPayload(8615, $telegramUserId, 'support_customer_freshness', 'fa', $close));
        $processor->process('123456789', 8615);
        self::assertSame('support_close', $this->supportSession($account['account_id'])['state']);

        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $this->accept($this->payload(8616, $telegramUserId, 'support_customer_freshness', 'fa', 'Blocked close'));
        $this->assertMembershipProcessingFails($processor, 8616);
        self::assertNotSame(SupportTicketState::Closed->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));
        self::assertSame('support_close', $this->supportSession($account['account_id'])['state']);

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8616);
        self::assertSame(SupportTicketState::Closed->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));
        $reopen = $this->callbackToken('navigation.support.reopen', $account['account_id']);

        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $this->accept($this->callbackPayload(8617, $telegramUserId, 'support_customer_freshness', 'fa', $reopen));
        $this->assertMembershipProcessingFails($processor, 8617);
        self::assertSame(SupportTicketState::Closed->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8617);
        self::assertSame(SupportTicketState::AwaitingSupport->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));
    }

    public function test_support_queue_detail_and_mutation_reauthorize_membership_while_permission_remains(): void
    {
        $lookup = new TelegramSupportFreshnessMembershipLookup;
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $customerTelegramId = 9862;
        $supportTelegramId = 9863;

        $this->accept($this->payload(8620, $customerTelegramId, 'support_queue_customer_freshness', 'fa', '/start'));
        $processor->process('123456789', 8620);
        $customer = $this->account($customerTelegramId);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer['user_id'],
            'other',
            'Queue freshness',
            'Queue confidential body',
            'support-membership-freshness:queue-create',
        ));

        $this->accept($this->payload(8621, $supportTelegramId, 'support_queue_staff_freshness', 'fa', '/start'));
        $processor->process('123456789', 8621);
        $staff = $this->account($supportTelegramId);
        $this->grantSupportRole($staff['user_id']);
        $this->installMembershipRule('support_view', 'support-view-staff-freshness', -1005000000863);

        $support = $this->callbackToken('navigation.support', $staff['account_id']);
        $this->accept($this->callbackPayload(8622, $supportTelegramId, 'support_queue_staff_freshness', 'fa', $support));
        $processor->process('123456789', 8622);
        $queue = $this->callbackToken('navigation.support.queue', $staff['account_id']);

        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $deliveryCount = $this->deliveryCount($supportTelegramId);
        $this->accept($this->callbackPayload(8623, $supportTelegramId, 'support_queue_staff_freshness', 'fa', $queue));
        $this->assertMembershipProcessingFails($processor, 8623);
        self::assertSame('support_home', $this->supportSession($staff['account_id'])['state']);
        self::assertSame($deliveryCount, $this->deliveryCount($supportTelegramId));

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8623);
        self::assertSame('support_queue', $this->supportSession($staff['account_id'])['state']);
        $detail = $this->callbackToken(
            'navigation.support.queue.ticket',
            $staff['account_id'],
            json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
        );

        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $deliveryCount = $this->deliveryCount($supportTelegramId);
        $this->accept($this->callbackPayload(8624, $supportTelegramId, 'support_queue_staff_freshness', 'fa', $detail));
        $this->assertMembershipProcessingFails($processor, 8624);
        self::assertSame('support_queue', $this->supportSession($staff['account_id'])['state']);
        self::assertSame($deliveryCount, $this->deliveryCount($supportTelegramId));

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8624);
        self::assertSame('support_queue_ticket', $this->supportSession($staff['account_id'])['state']);
        $claim = $this->callbackToken('navigation.support.claim', $staff['account_id']);

        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $this->accept($this->callbackPayload(8625, $supportTelegramId, 'support_queue_staff_freshness', 'fa', $claim));
        $this->assertMembershipProcessingFails($processor, 8625);
        self::assertNull(DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8625);
        self::assertSame($staff['user_id'], (int) DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));
    }

    public function test_support_view_fails_closed_when_effective_rule_changes_during_current_provider_lookup(): void
    {
        $lookup = new TelegramSupportFreshnessMembershipLookup;
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9864;

        $this->accept($this->payload(8640, $telegramUserId, 'support_config_drift', 'fa', '/start'));
        $processor->process('123456789', 8640);
        $account = $this->account($telegramUserId);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $account['user_id'],
            'other',
            'Configuration drift',
            'Do not render through a changing membership plan',
            'support-membership-freshness:drift-create',
        ));
        $ruleId = $this->installMembershipRule('support_view', 'support-view-config-drift', -1005000000864);

        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8641, $telegramUserId, 'support_config_drift', 'fa', $support));
        $processor->process('123456789', 8641);
        $ticketButton = $this->callbackToken(
            'navigation.support.ticket',
            $account['account_id'],
            json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
        );

        $drifted = false;
        $lookup->onLookup = static function () use (&$drifted, $ruleId): void {
            if ($drifted) {
                return;
            }
            $drifted = true;
            DB::table('channel_membership_rules')->where('id', $ruleId)->update([
                'state' => 'disabled',
                'version' => 3,
                'updated_at' => now('UTC'),
            ]);
        };

        $deliveryCount = $this->deliveryCount($telegramUserId);
        $this->accept($this->callbackPayload(8642, $telegramUserId, 'support_config_drift', 'fa', $ticketButton));
        $this->assertMembershipProcessingFails($processor, 8642);
        self::assertTrue($drifted);
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);
        self::assertSame($deliveryCount, $this->deliveryCount($telegramUserId));

        $lookup->onLookup = null;
        $processor->process('123456789', 8642);
        self::assertSame('support_ticket', $this->supportSession($account['account_id'])['state']);
    }

    public function test_committed_mutation_recovery_requires_current_view_membership_without_duplicate_business_effect(): void
    {
        $lookup = new TelegramSupportFreshnessMembershipLookup;
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9865;

        $this->accept($this->payload(8650, $telegramUserId, 'support_recovery_freshness', 'fa', '/start'));
        $processor->process('123456789', 8650);
        $account = $this->account($telegramUserId);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $account['user_id'],
            'other',
            'Recovery freshness',
            'Initial body',
            'support-membership-freshness:recovery-create',
        ));
        $this->installMembershipRule('support_view', 'support-view-recovery-freshness', -1005000000865);

        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8651, $telegramUserId, 'support_recovery_freshness', 'fa', $support));
        $processor->process('123456789', 8651);
        $ticketButton = $this->callbackToken(
            'navigation.support.ticket',
            $account['account_id'],
            json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8652, $telegramUserId, 'support_recovery_freshness', 'fa', $ticketButton));
        $processor->process('123456789', 8652);
        $reply = $this->callbackToken('navigation.support.reply', $account['account_id']);
        $this->accept($this->callbackPayload(8653, $telegramUserId, 'support_recovery_freshness', 'fa', $reply));
        $processor->process('123456789', 8653);
        self::assertSame('support_reply', $this->supportSession($account['account_id'])['state']);

        $administratorId = $this->administratorFor($account['user_id']);
        $now = now('UTC');
        DB::table('localization_overrides')->insert([
            'translation_key' => 'telegram_support.detail',
            'locale' => 'fa',
            'override_value' => 'خراب :missing_placeholder',
            'version' => 1,
            'updated_by_administrator_id' => $administratorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $messageCount = DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count();
        $this->accept($this->payload(8654, $telegramUserId, 'support_recovery_freshness', 'fa', 'Committed exactly once'));
        $this->assertProcessingFails($processor, 8654);
        self::assertSame($messageCount + 1, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame('support_ticket', $this->supportSession($account['account_id'])['state']);

        DB::table('localization_overrides')
            ->where('translation_key', 'telegram_support.detail')
            ->where('locale', 'fa')
            ->update([
                'override_value' => null,
                'version' => 2,
                'updated_by_administrator_id' => $administratorId,
                'updated_at' => now('UTC'),
            ]);
        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $deliveryCount = $this->deliveryCount($telegramUserId);
        $this->assertMembershipProcessingFails($processor, 8654);
        self::assertSame($messageCount + 1, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame($deliveryCount, $this->deliveryCount($telegramUserId));

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8654);
        self::assertSame($messageCount + 1, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame($deliveryCount + 1, $this->deliveryCount($telegramUserId));
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8654,
            'state' => 'processed',
        ]);
    }

    /** @return array{account_id:int,user_id:int} */
    private function account(int $telegramUserId): array
    {
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);

        return ['account_id' => (int) $account->id, 'user_id' => (int) $account->user_id];
    }

    /** @return array{state:string,version:int} */
    private function supportSession(int $accountId): array
    {
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->first(['state', 'version']);
        self::assertNotNull($session);

        return ['state' => (string) $session->state, 'version' => (int) $session->version];
    }

    private function grantSupportRole(int $userId): int
    {
        $administratorId = $this->administratorFor($userId);
        $now = now('UTC');
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => (int) DB::table('roles')->where('code', 'support')->value('id'),
            'granted_by_administrator_id' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $administratorId;
    }

    private function administratorFor(int $userId): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function installMembershipRule(string $action, string $ruleKey, int $chatId): int
    {
        $now = now('UTC');
        $channelId = (int) DB::table('required_channels')->insertGetId([
            'channel_key' => $ruleKey.'-channel',
            'telegram_chat_id' => $chatId,
            'chat_type' => 'channel',
            'visibility' => 'public',
            'display_title' => $ruleKey,
            'join_url_ciphertext' => str_repeat('c', 64),
            'join_url_hash' => str_repeat('a', 64),
            'sort_order' => 0,
            'state' => 'draft',
            'version' => 1,
            'verified_bot_id' => null,
            'verification_result_code' => null,
            'verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('required_channels')->where('id', $channelId)->update([
            'state' => 'active',
            'version' => 2,
            'verified_bot_id' => 123456789,
            'verification_result_code' => 'telegram_membership_administrator',
            'verified_at' => $now,
            'updated_at' => $now,
        ]);
        $ruleId = (int) DB::table('channel_membership_rules')->insertGetId([
            'rule_key' => $ruleKey,
            'action' => $action,
            'audience' => 'customers',
            'tier_code' => null,
            'customer_tag_id' => null,
            'plan_offering_id' => null,
            'match_mode' => 'all',
            'failure_policy' => 'fail_closed',
            'priority' => 100,
            'effective_from' => null,
            'effective_until' => null,
            'state' => 'draft',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('channel_membership_rule_channels')->insert([
            'channel_membership_rule_id' => $ruleId,
            'required_channel_id' => $channelId,
            'sort_order' => 0,
            'created_at' => $now,
        ]);
        DB::table('channel_membership_rules')->where('id', $ruleId)->update([
            'state' => 'active',
            'version' => 2,
            'updated_at' => $now,
        ]);

        return $ruleId;
    }

    private function callbackToken(string $action, int $accountId, string $expectedActionPayload = '{}'): string
    {
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_account_id', $accountId)
            ->where('action', $action)
            ->where('action_payload', $expectedActionPayload)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($callback, 'Expected Telegram callback was not issued: '.$action.' '.$expectedActionPayload);

        return $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);
    }

    private function deliveryCount(int $telegramUserId): int
    {
        return DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
    }

    private function assertProcessingFails(TelegramUpdateProcessor $processor, int $updateId): void
    {
        try {
            $processor->process('123456789', $updateId);
            self::fail('Telegram Support membership freshness must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
    }

    private function assertMembershipProcessingFails(TelegramUpdateProcessor $processor, int $updateId): void
    {
        $this->assertProcessingFails($processor, $updateId);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => $updateId,
            'state' => 'failed',
            'last_error_class' => AuthorizationException::class,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string,mixed> */
    private function payload(int $updateId, int $telegramUserId, string $username, string $languageCode, string $text): array
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
                    'language_code' => $languageCode,
                ],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function callbackPayload(int $updateId, int $telegramUserId, string $username, string $languageCode, string $token): array
    {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'callback-'.$updateId,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => $username,
                    'language_code' => $languageCode,
                ],
                'message' => [
                    'message_id' => $updateId,
                    'date' => 1_700_000_000,
                    'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                ],
                'data' => $token,
            ],
        ];
    }
}
