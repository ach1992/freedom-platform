<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Support\Domain\SupportTicketState;
use App\Modules\Telegram\Application\ConfidentialTelegramPresentation;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionPayload;
use App\Modules\Telegram\Application\TelegramInteractionRejected;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramSupportMembershipLookup implements TelegramMembershipLookup
{
    public TelegramMembershipEvidence $evidence = TelegramMembershipEvidence::NotMember;

    /** @var list<array{chat_id:int,user_id:int}> */
    public array $calls = [];

    public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
    {
        $this->calls[] = ['chat_id' => $chatId, 'user_id' => $telegramUserId];

        return new TelegramMembershipLookupResult(
            $this->evidence,
            $this->evidence === TelegramMembershipEvidence::Member
                ? 'telegram_membership_member'
                : 'telegram_membership_left',
        );
    }
}

/** @requirement SUP-001 SUP-002 CHN-001 CNT-001 CNT-002 SEC-002 DAT-003 QUA-001 QUA-004 */
final class TelegramSupportNavigationTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Support navigation verification requires MariaDB/MySQL.');
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

    public function test_customer_text_ticket_journey_is_owner_bound_restart_safe_and_replay_single_effect(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9810;
        $otherTelegramUserId = 9811;

        $this->accept($this->payload(8100, $telegramUserId, 'support_customer', 'fa', '/start'));
        $processor->process('123456789', 8100);
        $account = $this->account($telegramUserId);
        $supportToken = $this->callbackToken('navigation.support', $account['account_id']);

        $this->accept($this->payload(8101, $otherTelegramUserId, 'support_other', 'fa', '/start'));
        $processor->process('123456789', 8101);
        $other = $this->account($otherTelegramUserId);
        $this->accept($this->callbackPayload(8102, $otherTelegramUserId, 'support_other', 'fa', $supportToken));
        $processor->process('123456789', 8102);
        self::assertSame('home', (string) DB::table('telegram_interaction_sessions')->where('telegram_account_id', $other['account_id'])->value('state'));
        self::assertSame(0, DB::table('support_tickets')->count());

        $this->accept($this->callbackPayload(8103, $telegramUserId, 'support_customer', 'fa', $supportToken));
        $processor->process('123456789', 8103);
        $session = $this->supportSession($account['account_id']);
        self::assertSame('support_home', $session['state']);
        self::assertSame(2, $session['version']);
        $deliveryCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 8103);
        self::assertSame($deliveryCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
        self::assertSame(2, $this->supportSession($account['account_id'])['version']);

        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8104, $telegramUserId, 'support_customer', 'fa', $create));
        $processor->process('123456789', 8104);
        self::assertSame('support_create_category', $this->supportSession($account['account_id'])['state']);

        $category = $this->callbackToken('navigation.support.category', $account['account_id'], json_encode(['category' => 'other'], JSON_THROW_ON_ERROR));
        $this->accept($this->callbackPayload(8105, $telegramUserId, 'support_customer', 'fa', $category));
        $processor->process('123456789', 8105);
        self::assertSame('support_create_reference_type', $this->supportSession($account['account_id'])['state']);
        $noReference = $this->callbackToken(
            'navigation.support.reference.type',
            $account['account_id'],
            json_encode(['reference_type' => 'none'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8106, $telegramUserId, 'support_customer', 'fa', $noReference));
        $processor->process('123456789', 8106);
        self::assertSame('support_create_title', $this->supportSession($account['account_id'])['state']);

        $this->accept($this->payload(8107, $telegramUserId, 'support_customer', 'fa', 'Connection issue'));
        $processor->process('123456789', 8107);
        self::assertSame('support_create_description', $this->supportSession($account['account_id'])['state']);

        $this->accept($this->payload(8108, $telegramUserId, 'support_customer', 'fa', 'My service cannot connect.'));
        $processor->process('123456789', 8108);
        $ticket = DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->first([
            'id', 'state', 'order_id', 'payment_intent_id', 'service_subscription_id',
        ]);
        self::assertNotNull($ticket);
        self::assertNull($ticket->order_id);
        self::assertNull($ticket->payment_intent_id);
        self::assertNull($ticket->service_subscription_id);
        self::assertSame('support_ticket', $this->supportSession($account['account_id'])['state']);
        self::assertSame(1, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());
        self::assertSame(1, DB::table('support_ticket_messages')->where('ticket_id', (int) $ticket->id)->count());
        $processor->process('123456789', 8108);
        self::assertSame(1, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());
        self::assertSame(1, DB::table('support_ticket_messages')->where('ticket_id', (int) $ticket->id)->count());

        $reply = $this->callbackToken('navigation.support.reply', $account['account_id']);
        $this->accept($this->callbackPayload(8109, $telegramUserId, 'support_customer', 'fa', $reply));
        $processor->process('123456789', 8109);
        self::assertSame('support_reply', $this->supportSession($account['account_id'])['state']);
        $this->accept($this->payload(8110, $telegramUserId, 'support_customer', 'fa', 'Additional detail'));
        $processor->process('123456789', 8110);
        $processor->process('123456789', 8110);
        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', (int) $ticket->id)->count());
        self::assertSame('support_ticket', $this->supportSession($account['account_id'])['state']);

        $close = $this->callbackToken('navigation.support.close', $account['account_id']);
        $this->accept($this->callbackPayload(8111, $telegramUserId, 'support_customer', 'fa', $close));
        $processor->process('123456789', 8111);
        self::assertSame('support_close', $this->supportSession($account['account_id'])['state']);
        $this->accept($this->payload(8112, $telegramUserId, 'support_customer', 'fa', 'Issue solved'));
        $processor->process('123456789', 8112);
        self::assertSame(SupportTicketState::Closed->value, DB::table('support_tickets')->where('id', (int) $ticket->id)->value('state'));
        self::assertNotNull(DB::table('support_tickets')->where('id', (int) $ticket->id)->value('reopen_until'));

        $reopen = $this->callbackToken('navigation.support.reopen', $account['account_id']);
        $this->accept($this->callbackPayload(8113, $telegramUserId, 'support_customer', 'fa', $reopen));
        $processor->process('123456789', 8113);
        self::assertSame(SupportTicketState::AwaitingSupport->value, DB::table('support_tickets')->where('id', (int) $ticket->id)->value('state'));
        self::assertSame('support_ticket', $this->supportSession($account['account_id'])['state']);
    }

    public function test_support_view_and_ticket_creation_honor_configured_membership_rules_with_retry_safe_recovery(): void
    {
        $lookup = new TelegramSupportMembershipLookup;
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9815;

        $this->accept($this->payload(8150, $telegramUserId, 'support_membership', 'fa', '/start'));
        $processor->process('123456789', 8150);
        $account = $this->account($telegramUserId);
        $this->installMembershipRule('support_view', 'support-view-rule', -1005000000015);
        $support = $this->callbackToken('navigation.support', $account['account_id']);

        $this->accept($this->callbackPayload(8151, $telegramUserId, 'support_membership', 'fa', $support));
        try {
            $processor->process('123456789', 8151);
            self::fail('Unsatisfied support_view membership must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        self::assertSame('home', $this->supportSession($account['account_id'])['state']);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8151,
            'state' => 'failed',
            'last_error_class' => AuthorizationException::class,
        ]);

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8151);
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8151,
            'state' => 'processed',
        ]);

        $this->installMembershipRule('ticket_creation', 'ticket-create-rule', -1005000000016);
        $lookup->evidence = TelegramMembershipEvidence::NotMember;
        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8152, $telegramUserId, 'support_membership', 'fa', $create));
        try {
            $processor->process('123456789', 8152);
            self::fail('Unsatisfied ticket_creation membership must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);
        self::assertSame(0, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());

        $lookup->evidence = TelegramMembershipEvidence::Member;
        $processor->process('123456789', 8152);
        self::assertSame('support_create_category', $this->supportSession($account['account_id'])['state']);
        self::assertGreaterThanOrEqual(4, count($lookup->calls));
    }

    public function test_support_queue_reauthorizes_operations_and_internal_note_never_reaches_customer_presentation(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $customerTelegramId = 9820;
        $supportTelegramId = 9821;

        $this->accept($this->payload(8200, $customerTelegramId, 'support_visibility_customer', 'en', '/start'));
        $processor->process('123456789', 8200);
        $customer = $this->account($customerTelegramId);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer['user_id'],
            'other',
            'Private note test',
            'Customer-visible initial body',
            'telegram-support-visibility:create',
        ));

        $this->accept($this->payload(8201, $supportTelegramId, 'support_staff', 'en', '/start'));
        $processor->process('123456789', 8201);
        $staff = $this->account($supportTelegramId);
        $administratorId = $this->grantSupportRole($staff['user_id']);

        $supportHome = $this->callbackToken('navigation.support', $staff['account_id']);
        $this->accept($this->callbackPayload(8202, $supportTelegramId, 'support_staff', 'en', $supportHome));
        $processor->process('123456789', 8202);
        $queue = $this->callbackToken('navigation.support.queue', $staff['account_id']);
        $this->accept($this->callbackPayload(8203, $supportTelegramId, 'support_staff', 'en', $queue));
        $processor->process('123456789', 8203);
        self::assertSame('support_queue', $this->supportSession($staff['account_id'])['state']);

        $detail = $this->callbackToken('navigation.support.queue.ticket', $staff['account_id'], json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR));
        $this->accept($this->callbackPayload(8204, $supportTelegramId, 'support_staff', 'en', $detail));
        $processor->process('123456789', 8204);
        $claim = $this->callbackToken('navigation.support.claim', $staff['account_id']);
        $this->accept($this->callbackPayload(8205, $supportTelegramId, 'support_staff', 'en', $claim));
        $processor->process('123456789', 8205);
        self::assertSame($staff['user_id'], (int) DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));

        $reply = $this->callbackToken('navigation.support.queue.reply', $staff['account_id']);
        $this->accept($this->callbackPayload(8206, $supportTelegramId, 'support_staff', 'en', $reply));
        $processor->process('123456789', 8206);
        self::assertSame('support_queue_reply', $this->supportSession($staff['account_id'])['state']);
        $this->accept($this->payload(8207, $supportTelegramId, 'support_staff', 'en', 'Public support answer'));
        $processor->process('123456789', 8207);
        $publicReply = DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->where('kind', 'support_reply')->first(['body', 'customer_visible']);
        self::assertNotNull($publicReply);
        self::assertSame('Public support answer', (string) $publicReply->body);
        self::assertSame(1, (int) $publicReply->customer_visible);
        self::assertSame(SupportTicketState::AwaitingCustomer->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));

        $priority = $this->callbackToken('navigation.support.queue.priority', $staff['account_id']);
        $this->accept($this->callbackPayload(8208, $supportTelegramId, 'support_staff', 'en', $priority));
        $processor->process('123456789', 8208);
        self::assertSame('support_queue_priority', $this->supportSession($staff['account_id'])['state']);
        $urgent = $this->callbackToken('navigation.support.queue.priority.set', $staff['account_id'], json_encode(['priority' => 'urgent'], JSON_THROW_ON_ERROR));
        $this->accept($this->callbackPayload(8209, $supportTelegramId, 'support_staff', 'en', $urgent));
        $processor->process('123456789', 8209);
        self::assertSame('urgent', DB::table('support_tickets')->where('id', $ticket->id)->value('priority'));
        self::assertSame('support_queue_ticket', $this->supportSession($staff['account_id'])['state']);

        $state = $this->callbackToken('navigation.support.queue.state', $staff['account_id']);
        $this->accept($this->callbackPayload(8210, $supportTelegramId, 'support_staff', 'en', $state));
        $processor->process('123456789', 8210);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_account_id', $staff['account_id'])
            ->where('action', 'navigation.support.queue.state.set')
            ->where('action_payload', json_encode(['state' => 'new'], JSON_THROW_ON_ERROR))
            ->count());
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_account_id', $staff['account_id'])
            ->where('action', 'navigation.support.queue.state.set')
            ->where('action_payload', json_encode(['state' => 'awaiting_customer'], JSON_THROW_ON_ERROR))
            ->count());
        $investigating = $this->callbackToken('navigation.support.queue.state.set', $staff['account_id'], json_encode(['state' => 'investigating'], JSON_THROW_ON_ERROR));
        $this->accept($this->callbackPayload(8211, $supportTelegramId, 'support_staff', 'en', $investigating));
        $processor->process('123456789', 8211);
        self::assertSame(SupportTicketState::Investigating->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));

        $note = $this->callbackToken('navigation.support.queue.note', $staff['account_id']);
        $this->accept($this->callbackPayload(8212, $supportTelegramId, 'support_staff', 'en', $note));
        $processor->process('123456789', 8212);
        $this->accept($this->payload(8213, $supportTelegramId, 'support_staff', 'en', 'SECRET-INTERNAL-NOTE'));
        $processor->process('123456789', 8213);
        $internal = DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->where('kind', 'internal_note')->first(['customer_visible']);
        self::assertNotNull($internal);
        self::assertSame(0, (int) $internal->customer_visible);
        self::assertStringContainsString('SECRET-INTERNAL-NOTE', $this->latestConfidentialPresentation($supportTelegramId));

        $state = $this->callbackToken('navigation.support.queue.state', $staff['account_id']);
        $this->accept($this->callbackPayload(8214, $supportTelegramId, 'support_staff', 'en', $state));
        $processor->process('123456789', 8214);
        $closed = $this->callbackToken('navigation.support.queue.state.set', $staff['account_id'], json_encode(['state' => 'closed'], JSON_THROW_ON_ERROR));
        $this->accept($this->callbackPayload(8215, $supportTelegramId, 'support_staff', 'en', $closed));
        $processor->process('123456789', 8215);
        self::assertSame('support_queue_close', $this->supportSession($staff['account_id'])['state']);
        $this->accept($this->payload(8216, $supportTelegramId, 'support_staff', 'en', 'Closed by support'));
        $processor->process('123456789', 8216);
        self::assertSame(SupportTicketState::Closed->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));
        self::assertSame('Closed by support', DB::table('support_tickets')->where('id', $ticket->id)->value('close_reason'));

        $customerSupport = $this->callbackToken('navigation.support', $customer['account_id']);
        $this->accept($this->callbackPayload(8217, $customerTelegramId, 'support_visibility_customer', 'en', $customerSupport));
        $processor->process('123456789', 8217);
        $customerTicket = $this->callbackToken('navigation.support.ticket', $customer['account_id'], json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR));
        $this->accept($this->callbackPayload(8218, $customerTelegramId, 'support_visibility_customer', 'en', $customerTicket));
        $processor->process('123456789', 8218);
        $customerPresentation = $this->latestConfidentialPresentation($customerTelegramId);
        self::assertStringContainsString('Customer-visible initial body', $customerPresentation);
        self::assertStringContainsString('Public support answer', $customerPresentation);
        self::assertStringNotContainsString('SECRET-INTERNAL-NOTE', $customerPresentation);
        self::assertNotContains('SECRET-INTERNAL-NOTE', array_map(
            static fn ($message): string => $message->body,
            $this->app->make(SupportTicketService::class)->ticketForCustomer($ticket->id, $customer['user_id'])->messages,
        ));

        $reauthTicket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer['user_id'],
            'other',
            'Reauthorization test',
            'Keep this ticket open.',
            'telegram-support-reauth:create',
        ));
        $back = $this->callbackToken('navigation.back', $staff['account_id']);
        $this->accept($this->callbackPayload(8219, $supportTelegramId, 'support_staff', 'en', $back));
        $processor->process('123456789', 8219);
        self::assertSame('support_queue', $this->supportSession($staff['account_id'])['state']);
        $reauthDetail = $this->callbackToken(
            'navigation.support.queue.ticket',
            $staff['account_id'],
            json_encode(['ticket_id' => $reauthTicket->id], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8220, $supportTelegramId, 'support_staff', 'en', $reauthDetail));
        $processor->process('123456789', 8220);
        self::assertSame('support_queue_ticket', $this->supportSession($staff['account_id'])['state']);
        $priority = $this->callbackToken('navigation.support.queue.priority', $staff['account_id']);

        $permissionId = (int) DB::table('permissions')->where('code', SupportTicketSupportService::PERMISSION)->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => 'deny',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $versionBefore = $this->supportSession($staff['account_id'])['version'];
        $this->accept($this->callbackPayload(8221, $supportTelegramId, 'support_staff', 'en', $priority));
        try {
            $processor->process('123456789', 8221);
            self::fail('Revoked support authority must fail the accepted callback at execution time.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        self::assertSame($versionBefore, $this->supportSession($staff['account_id'])['version']);
        self::assertSame('normal', DB::table('support_tickets')->where('id', $reauthTicket->id)->value('priority'));
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8221,
            'state' => 'failed',
            'last_error_class' => AuthorizationException::class,
        ]);
    }

    public function test_canned_support_reply_uses_customer_locale_observes_override_and_reauthorizes_execution(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $customerTelegramId = 9823;
        $supportTelegramId = 9824;

        $this->accept($this->payload(8230, $customerTelegramId, 'support_canned_customer', 'fa', '/start'));
        $processor->process('123456789', 8230);
        $customer = $this->account($customerTelegramId);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer['user_id'],
            'other',
            'Canned response test',
            'Customer-visible initial body',
            'telegram-support-canned:create',
        ));

        $this->accept($this->payload(8231, $supportTelegramId, 'support_canned_staff', 'en', '/start'));
        $processor->process('123456789', 8231);
        $staff = $this->account($supportTelegramId);
        $administratorId = $this->grantSupportRole($staff['user_id']);

        $supportHome = $this->callbackToken('navigation.support', $staff['account_id']);
        $this->accept($this->callbackPayload(8232, $supportTelegramId, 'support_canned_staff', 'en', $supportHome));
        $processor->process('123456789', 8232);
        $queue = $this->callbackToken('navigation.support.queue', $staff['account_id']);
        $this->accept($this->callbackPayload(8233, $supportTelegramId, 'support_canned_staff', 'en', $queue));
        $processor->process('123456789', 8233);
        $detail = $this->callbackToken(
            'navigation.support.queue.ticket',
            $staff['account_id'],
            json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8234, $supportTelegramId, 'support_canned_staff', 'en', $detail));
        $processor->process('123456789', 8234);
        $claim = $this->callbackToken('navigation.support.claim', $staff['account_id']);
        $this->accept($this->callbackPayload(8235, $supportTelegramId, 'support_canned_staff', 'en', $claim));
        $processor->process('123456789', 8235);

        $canned = $this->callbackToken('navigation.support.queue.canned', $staff['account_id']);
        $this->accept($this->callbackPayload(8236, $supportTelegramId, 'support_canned_staff', 'en', $canned));
        $processor->process('123456789', 8236);
        self::assertSame('support_queue_canned', $this->supportSession($staff['account_id'])['state']);
        $operationPublicId = DB::table('telegram_delivery_operations')
            ->where('recipient_chat_id', $supportTelegramId)
            ->orderByDesc('id')
            ->value('public_id');
        self::assertIsString($operationPublicId);
        $keyboardSnapshot = DB::table('telegram_delivery_interactive_presentations')
            ->where('delivery_operation_public_id', $operationPublicId)
            ->value('keyboard_snapshot');
        self::assertIsString($keyboardSnapshot);
        self::assertStringContainsString('Acknowledge receipt', $keyboardSnapshot);
        self::assertStringNotContainsString('تأیید دریافت', $keyboardSnapshot);

        $defaultBody = trans('_mandatory.ticket.canned.acknowledge.body', [], 'fa');
        self::assertIsString($defaultBody);
        $stalePayload = (new TelegramInteractionPayload([
            'template' => 'acknowledge',
            'body_hash' => hash('sha256', $defaultBody),
        ]))->json();
        $staleSend = $this->callbackToken('navigation.support.queue.canned.send', $staff['account_id'], $stalePayload);
        self::assertStringNotContainsString($defaultBody, $stalePayload);

        $overrideBody = 'پاسخ آماده جدید برای مشتری فارسی‌زبان.';
        $now = now('UTC');
        DB::table('localization_overrides')->insert([
            'translation_key' => 'ticket.canned.acknowledge.body',
            'locale' => 'fa',
            'override_value' => $overrideBody,
            'version' => 1,
            'updated_by_administrator_id' => $administratorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->accept($this->callbackPayload(8237, $supportTelegramId, 'support_canned_staff', 'en', $staleSend));
        try {
            $processor->process('123456789', 8237);
            self::fail('A canned-response callback must fail closed after its resolved customer text changes.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        self::assertSame('support_queue_canned', $this->supportSession($staff['account_id'])['state']);
        self::assertSame(0, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->where('kind', 'support_reply')->count());
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8237,
            'state' => 'failed',
            'last_error_class' => AuthorizationException::class,
        ]);

        $back = $this->callbackToken('navigation.back', $staff['account_id']);
        $this->accept($this->callbackPayload(8238, $supportTelegramId, 'support_canned_staff', 'en', $back));
        $processor->process('123456789', 8238);
        self::assertSame('support_queue_ticket', $this->supportSession($staff['account_id'])['state']);
        $canned = $this->callbackToken('navigation.support.queue.canned', $staff['account_id']);
        $this->accept($this->callbackPayload(8239, $supportTelegramId, 'support_canned_staff', 'en', $canned));
        $processor->process('123456789', 8239);

        $freshPayload = (new TelegramInteractionPayload([
            'template' => 'acknowledge',
            'body_hash' => hash('sha256', $overrideBody),
        ]))->json();
        $freshSend = $this->callbackToken('navigation.support.queue.canned.send', $staff['account_id'], $freshPayload);
        self::assertStringNotContainsString($overrideBody, $freshPayload);

        $permissionId = (int) DB::table('permissions')->where('code', SupportTicketSupportService::PERMISSION)->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => 'deny',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->accept($this->callbackPayload(8240, $supportTelegramId, 'support_canned_staff', 'en', $freshSend));
        try {
            $processor->process('123456789', 8240);
            self::fail('Revoked Support authority must fail canned-response execution.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        self::assertSame('support_queue_canned', $this->supportSession($staff['account_id'])['state']);
        self::assertSame(0, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->where('kind', 'support_reply')->count());

        DB::table('administrator_permission_overrides')
            ->where('administrator_id', $administratorId)
            ->where('permission_id', $permissionId)
            ->delete();
        $processor->process('123456789', 8240);
        $processor->process('123456789', 8240);

        $reply = DB::table('support_ticket_messages')
            ->where('ticket_id', $ticket->id)
            ->where('kind', 'support_reply')
            ->get(['body', 'customer_visible']);
        self::assertCount(1, $reply);
        self::assertSame($overrideBody, (string) $reply[0]->body);
        self::assertSame(1, (int) $reply[0]->customer_visible);
        self::assertSame(SupportTicketState::AwaitingCustomer->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));
        self::assertSame('support_queue_ticket', $this->supportSession($staff['account_id'])['state']);
    }

    public function test_customer_ticket_creation_rate_limit_is_processed_without_duplicate_business_effect(): void
    {
        config([
            'support.rate_limits.ticket_creation.max_attempts' => 1,
            'support.rate_limits.ticket_creation.window_seconds' => 600,
        ]);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9826;

        $this->accept($this->payload(8260, $telegramUserId, 'support_rate_create', 'fa', '/start'));
        $processor->process('123456789', 8260);
        $account = $this->account($telegramUserId);
        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8261, $telegramUserId, 'support_rate_create', 'fa', $support));
        $processor->process('123456789', 8261);

        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8262, $telegramUserId, 'support_rate_create', 'fa', $create));
        $processor->process('123456789', 8262);
        $category = $this->callbackToken(
            'navigation.support.category',
            $account['account_id'],
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8263, $telegramUserId, 'support_rate_create', 'fa', $category));
        $processor->process('123456789', 8263);
        $noReference = $this->callbackToken(
            'navigation.support.reference.type',
            $account['account_id'],
            json_encode(['reference_type' => 'none'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8264, $telegramUserId, 'support_rate_create', 'fa', $noReference));
        $processor->process('123456789', 8264);
        $this->accept($this->payload(8265, $telegramUserId, 'support_rate_create', 'fa', 'First rate-limited ticket'));
        $processor->process('123456789', 8265);
        $this->accept($this->payload(8266, $telegramUserId, 'support_rate_create', 'fa', 'First body'));
        $processor->process('123456789', 8266);
        self::assertSame(1, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());

        $back = $this->callbackToken('navigation.back', $account['account_id']);
        $this->accept($this->callbackPayload(8267, $telegramUserId, 'support_rate_create', 'fa', $back));
        $processor->process('123456789', 8267);
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);
        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8268, $telegramUserId, 'support_rate_create', 'fa', $create));
        $processor->process('123456789', 8268);
        $category = $this->callbackToken(
            'navigation.support.category',
            $account['account_id'],
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8269, $telegramUserId, 'support_rate_create', 'fa', $category));
        $processor->process('123456789', 8269);
        $noReference = $this->callbackToken(
            'navigation.support.reference.type',
            $account['account_id'],
            json_encode(['reference_type' => 'none'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8270, $telegramUserId, 'support_rate_create', 'fa', $noReference));
        $processor->process('123456789', 8270);
        $this->accept($this->payload(8271, $telegramUserId, 'support_rate_create', 'fa', 'Second rate-limited ticket'));
        $processor->process('123456789', 8271);
        $this->accept($this->payload(8272, $telegramUserId, 'support_rate_create', 'fa', 'Second body'));
        $processor->process('123456789', 8272);

        self::assertSame(1, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());
        self::assertSame('support_create_description', $this->supportSession($account['account_id'])['state']);
        self::assertStringContainsString('ثانیه', $this->latestConfidentialPresentation($telegramUserId));
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8272,
            'state' => 'processed',
            'last_error_class' => null,
        ]);
        $deliveryCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count();
        $processor->process('123456789', 8272);
        self::assertSame($deliveryCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $telegramUserId)->count());
    }

    public function test_customer_reply_rate_limit_leaves_reply_session_retryable(): void
    {
        config([
            'support.rate_limits.customer_content.max_attempts' => 1,
            'support.rate_limits.customer_content.window_seconds' => 600,
        ]);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9827;

        $this->accept($this->payload(8280, $telegramUserId, 'support_rate_reply', 'en', '/start'));
        $processor->process('123456789', 8280);
        $account = $this->account($telegramUserId);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $account['user_id'],
            'other',
            'Reply rate limit',
            'Initial body',
            'telegram-support-rate-reply:create',
        ));
        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8281, $telegramUserId, 'support_rate_reply', 'en', $support));
        $processor->process('123456789', 8281);
        $ticketButton = $this->callbackToken(
            'navigation.support.ticket',
            $account['account_id'],
            json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8282, $telegramUserId, 'support_rate_reply', 'en', $ticketButton));
        $processor->process('123456789', 8282);

        $reply = $this->callbackToken('navigation.support.reply', $account['account_id']);
        $this->accept($this->callbackPayload(8283, $telegramUserId, 'support_rate_reply', 'en', $reply));
        $processor->process('123456789', 8283);
        $this->accept($this->payload(8284, $telegramUserId, 'support_rate_reply', 'en', 'First reply'));
        $processor->process('123456789', 8284);
        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());

        $reply = $this->callbackToken('navigation.support.reply', $account['account_id']);
        $this->accept($this->callbackPayload(8285, $telegramUserId, 'support_rate_reply', 'en', $reply));
        $processor->process('123456789', 8285);
        $this->accept($this->payload(8286, $telegramUserId, 'support_rate_reply', 'en', 'Second reply'));
        $processor->process('123456789', 8286);

        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame('support_reply', $this->supportSession($account['account_id'])['state']);
        self::assertStringContainsString('Try again in', $this->latestConfidentialPresentation($telegramUserId));
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8286,
            'state' => 'processed',
            'last_error_class' => null,
        ]);
    }

    public function test_long_domain_text_is_bounded_for_telegram_buttons_categories_and_recent_history(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9825;

        $this->accept($this->payload(8250, $telegramUserId, 'support_long_projection', 'en', '/start'));
        $processor->process('123456789', 8250);
        $account = $this->account($telegramUserId);
        DB::table('users')->where('id', $account['user_id'])->update(['locale' => 'en']);
        $tickets = $this->app->make(SupportTicketService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $account['user_id'],
            'other',
            str_repeat('T', 200),
            str_repeat('A', 4000),
            'telegram-support-long:create',
        ));
        $tickets->replyAsCustomer(
            $ticket->id,
            $account['user_id'],
            str_repeat('B', 4000),
            'telegram-support-long:reply-1',
        );
        $tickets->replyAsCustomer(
            $ticket->id,
            $account['user_id'],
            str_repeat('Z', 4000),
            'telegram-support-long:reply-2',
        );
        DB::table('support_ticket_categories')
            ->where('code', 'other')
            ->update(['name_en' => str_repeat('C', 191)]);

        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8251, $telegramUserId, 'support_long_projection', 'en', $support));
        $processor->process('123456789', 8251);
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);

        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8252, $telegramUserId, 'support_long_projection', 'en', $create));
        $processor->process('123456789', 8252);
        self::assertSame('support_create_category', $this->supportSession($account['account_id'])['state']);
        self::assertNotSame('', $this->callbackToken(
            'navigation.support.category',
            $account['account_id'],
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        ));

        $back = $this->callbackToken('navigation.back', $account['account_id']);
        $this->accept($this->callbackPayload(8253, $telegramUserId, 'support_long_projection', 'en', $back));
        $processor->process('123456789', 8253);
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);

        $ticketButton = $this->callbackToken(
            'navigation.support.ticket',
            $account['account_id'],
            json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8254, $telegramUserId, 'support_long_projection', 'en', $ticketButton));
        $processor->process('123456789', 8254);

        $presentation = $this->latestConfidentialPresentation($telegramUserId);
        self::assertLessThanOrEqual(ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS, mb_strlen($presentation));
        self::assertStringContainsString($ticket->trackingNumber, $presentation);
        self::assertStringContainsString(trans('telegram_support.history_older_omitted', [], 'en'), $presentation);
        self::assertStringContainsString(str_repeat('Z', 100), $presentation);
        self::assertStringNotContainsString(str_repeat('A', 100), $presentation);
    }

    public function test_forged_support_queue_callback_is_rejected_before_unprivileged_actor_can_read_queue_data(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $customerTelegramId = 9830;
        $supportTelegramId = 9831;
        $unprivilegedTelegramId = 9832;

        $this->accept($this->payload(8300, $customerTelegramId, 'support_queue_customer', 'fa', '/start'));
        $processor->process('123456789', 8300);
        $customer = $this->account($customerTelegramId);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer['user_id'],
            'other',
            'Confidential queue title',
            'Confidential queue body',
            'telegram-support-forged:create',
        ));

        $this->accept($this->payload(8301, $supportTelegramId, 'support_queue_staff', 'fa', '/start'));
        $processor->process('123456789', 8301);
        $staff = $this->account($supportTelegramId);
        $this->grantSupportRole($staff['user_id']);
        $staffSupport = $this->callbackToken('navigation.support', $staff['account_id']);
        $this->accept($this->callbackPayload(8302, $supportTelegramId, 'support_queue_staff', 'fa', $staffSupport));
        $processor->process('123456789', 8302);
        $staffQueue = $this->callbackToken('navigation.support.queue', $staff['account_id']);

        $this->accept($this->payload(8303, $unprivilegedTelegramId, 'support_queue_unprivileged', 'fa', '/start'));
        $processor->process('123456789', 8303);
        $unprivileged = $this->account($unprivilegedTelegramId);
        $unprivilegedSupport = $this->callbackToken('navigation.support', $unprivileged['account_id']);
        $this->accept($this->callbackPayload(8304, $unprivilegedTelegramId, 'support_queue_unprivileged', 'fa', $unprivilegedSupport));
        $processor->process('123456789', 8304);
        self::assertSame('support_home', $this->supportSession($unprivileged['account_id'])['state']);
        self::assertSame(0, DB::table('telegram_interaction_callbacks')
            ->where('telegram_account_id', $unprivileged['account_id'])
            ->where('action', 'navigation.support.queue')
            ->count());

        $deliveryCount = DB::table('telegram_delivery_operations')
            ->where('recipient_chat_id', $unprivilegedTelegramId)
            ->count();
        $ticketState = (string) DB::table('support_tickets')->where('id', $ticket->id)->value('state');
        $messageCount = DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count();

        try {
            $this->app->make(TelegramInteractionCallbackService::class)->accept(
                '123456789',
                $unprivilegedTelegramId,
                $staffQueue,
                8305,
            );
            self::fail('A support queue callback bound to another Telegram actor must fail closed.');
        } catch (TelegramInteractionRejected) {
            // Expected: callback actor authority rejects before the Support handler can read queue data.
        }
        $this->accept($this->callbackPayload(8305, $unprivilegedTelegramId, 'support_queue_unprivileged', 'fa', $staffQueue));
        $processor->process('123456789', 8305);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8305,
            'state' => 'processed',
            'last_error_class' => null,
        ]);
        self::assertSame('support_home', $this->supportSession($unprivileged['account_id'])['state']);
        self::assertSame($deliveryCount, DB::table('telegram_delivery_operations')
            ->where('recipient_chat_id', $unprivilegedTelegramId)
            ->count());
        self::assertSame($ticketState, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));
        self::assertSame($messageCount, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertStringNotContainsString('Confidential queue title', $this->latestConfidentialPresentation($unprivilegedTelegramId));
        self::assertStringNotContainsString('Confidential queue body', $this->latestConfidentialPresentation($unprivilegedTelegramId));
    }

    public function test_support_back_cancel_and_stale_callback_reuse_fail_closed_without_ticket_effect(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9840;

        $this->accept($this->payload(8400, $telegramUserId, 'support_recovery', 'fa', '/start'));
        $processor->process('123456789', 8400);
        $account = $this->account($telegramUserId);
        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8401, $telegramUserId, 'support_recovery', 'fa', $support));
        $processor->process('123456789', 8401);

        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8402, $telegramUserId, 'support_recovery', 'fa', $create));
        $processor->process('123456789', 8402);
        self::assertSame('support_create_category', $this->supportSession($account['account_id'])['state']);
        $staleCategory = $this->callbackToken(
            'navigation.support.category',
            $account['account_id'],
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        );
        $back = $this->callbackToken('navigation.back', $account['account_id']);
        $this->accept($this->callbackPayload(8403, $telegramUserId, 'support_recovery', 'fa', $back));
        $processor->process('123456789', 8403);
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);
        self::assertSame(0, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());

        try {
            $this->app->make(TelegramInteractionCallbackService::class)->accept(
                '123456789',
                $telegramUserId,
                $staleCategory,
                8404,
            );
            self::fail('A Support category callback from an old session version must fail closed.');
        } catch (TelegramInteractionRejected) {
            // Expected: stale session version is rejected before Support presentation logic runs.
        }
        $this->accept($this->callbackPayload(8404, $telegramUserId, 'support_recovery', 'fa', $staleCategory));
        $processor->process('123456789', 8404);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8404,
            'state' => 'processed',
            'last_error_class' => null,
        ]);
        self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);
        self::assertSame(0, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());

        $freshCreate = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8405, $telegramUserId, 'support_recovery', 'fa', $freshCreate));
        $processor->process('123456789', 8405);
        $category = $this->callbackToken(
            'navigation.support.category',
            $account['account_id'],
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8406, $telegramUserId, 'support_recovery', 'fa', $category));
        $processor->process('123456789', 8406);
        self::assertSame('support_create_reference_type', $this->supportSession($account['account_id'])['state']);

        $this->accept($this->payload(8407, $telegramUserId, 'support_recovery', 'fa', '/cancel'));
        $processor->process('123456789', 8407);
        self::assertSame('cancelled', DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $account['account_id'])
            ->orderByDesc('id')
            ->value('status'));
        self::assertSame(0, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());
        self::assertSame(0, DB::table('support_ticket_messages')->where('actor_user_id', $account['user_id'])->count());
    }

    public function test_support_session_timeout_uses_shared_expiry_authority_without_ticket_effect(): void
    {
        $clock = new TelegramSupportTestClock(new DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->app->instance(Clock::class, $clock);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9850;

        $this->accept($this->payload(8500, $telegramUserId, 'support_timeout', 'fa', '/start'));
        $processor->process('123456789', 8500);
        $account = $this->account($telegramUserId);
        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8501, $telegramUserId, 'support_timeout', 'fa', $support));
        $processor->process('123456789', 8501);
        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8502, $telegramUserId, 'support_timeout', 'fa', $create));
        $processor->process('123456789', 8502);
        $category = $this->callbackToken(
            'navigation.support.category',
            $account['account_id'],
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8503, $telegramUserId, 'support_timeout', 'fa', $category));
        $processor->process('123456789', 8503);
        self::assertSame('support_create_reference_type', $this->supportSession($account['account_id'])['state']);

        $clock->advance('+31 minutes');
        self::assertNull($this->app->make(TelegramInteractionSessionService::class)->activeForAccount($account['account_id']));
        self::assertSame('expired', DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $account['account_id'])
            ->orderByDesc('id')
            ->value('status'));
        self::assertSame(0, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());
        self::assertSame(0, DB::table('support_ticket_messages')->where('actor_user_id', $account['user_id'])->count());
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
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

    private function installMembershipRule(string $action, string $ruleKey, int $chatId): void
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

    private function latestConfidentialPresentation(int $telegramUserId): string
    {
        $operationPublicId = DB::table('telegram_delivery_operations')
            ->where('recipient_chat_id', $telegramUserId)
            ->orderByDesc('id')
            ->value('public_id');
        self::assertIsString($operationPublicId);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
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
final class TelegramSupportTestClock implements Clock
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
