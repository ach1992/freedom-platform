<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Telegram\Application\Contracts\TelegramSupportOwnedOrderProjection;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionRejected;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramSupportBusinessReferenceListItem;
use App\Modules\Telegram\Application\TelegramSupportBusinessReferencePage;
use App\Modules\Telegram\Application\TelegramSupportBusinessReferenceResolution;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramSupportStaleOrderProjection implements TelegramSupportOwnedOrderProjection
{
    public const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const PUBLIC_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public int $resolveCalls = 0;

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramSupportBusinessReferencePage
    {
        if ($actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Test projection is self-only.');
        }

        return new TelegramSupportBusinessReferencePage([
            new TelegramSupportBusinessReferenceListItem(
                self::TOKEN,
                self::PUBLIC_ID,
                100_000,
                'IRR',
            ),
        ], 1, 1, 1);
    }

    public function resolveForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramSupportBusinessReferenceResolution
    {
        if ($actorUserId !== $subjectUserId || ! hash_equals(self::TOKEN, $selectionToken)) {
            throw new AuthorizationException('Test projection selection is unavailable.');
        }

        $this->resolveCalls++;
        if ($this->resolveCalls > 1) {
            throw new AuthorizationException('Test projection became stale before submit.');
        }

        return new TelegramSupportBusinessReferenceResolution(999_999, self::PUBLIC_ID);
    }
}

/** @requirement SUP-001 SUP-002 CHN-001 CNT-001 CNT-002 SEC-002 DAT-003 QUA-001 QUA-004 */
final class TelegramSupportBusinessReferenceNavigationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Support business-reference navigation verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
        $this->seed();
        $this->bootPurchaseOrderClock();
        Queue::fake();
        config([
            'support.rate_limits.ticket_creation.max_attempts' => 10,
            'support.rate_limits.ticket_creation.window_seconds' => 600,
            'support.rate_limits.prefix' => 'test:telegram-support-business-reference:'.bin2hex(random_bytes(8)).':',
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

    public function test_customer_can_link_one_owned_order_payment_or_service_without_persisting_raw_ids_in_interaction_state(): void
    {
        $telegramUserId = 9890;
        $otherTelegramUserId = 9891;
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(8800, $otherTelegramUserId, 'support_reference_other', 'fa', '/start'));
        $processor->process('123456789', 8800);

        $this->accept($this->payload(8810, $telegramUserId, 'support_reference_owner', 'fa', '/start'));
        $processor->process('123456789', 8810);
        $account = $this->account($telegramUserId);
        $references = $this->referenceBundle($account['user_id'], 'telegram-support-navigation');
        self::assertSame($references['user_id'], $account['user_id']);

        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8811, $telegramUserId, 'support_reference_owner', 'fa', $support));
        $processor->process('123456789', 8811);

        $updateId = 8811;
        foreach ([
            ['type' => 'order', 'column' => 'order_id', 'id' => $references['order_id'], 'title' => 'Order reference'],
            ['type' => 'payment_intent', 'column' => 'payment_intent_id', 'id' => $references['payment_intent_id'], 'title' => 'Payment reference'],
            ['type' => 'service', 'column' => 'service_subscription_id', 'id' => $references['service_subscription_id'], 'title' => 'Service reference'],
        ] as $index => $case) {
            $ticketId = $this->createTicketWithReference(
                $processor,
                $account['account_id'],
                $telegramUserId,
                $otherTelegramUserId,
                $updateId,
                $case['type'],
                $case['title'],
                $index === 0,
            );

            $stored = DB::table('support_tickets')->where('id', $ticketId)->first([
                'order_id', 'payment_intent_id', 'service_subscription_id',
            ]);
            self::assertNotNull($stored);
            self::assertSame($case['id'], (int) $stored->{$case['column']});
            foreach (['order_id', 'payment_intent_id', 'service_subscription_id'] as $column) {
                if ($column !== $case['column']) {
                    self::assertNull($stored->{$column});
                }
            }

            if ($index < 2) {
                $back = $this->callbackToken('navigation.back', $account['account_id']);
                $updateId++;
                $this->accept($this->callbackPayload($updateId, $telegramUserId, 'support_reference_owner', 'fa', $back));
                $processor->process('123456789', $updateId);
                self::assertSame('support_home', $this->supportSession($account['account_id'])['state']);
            }
        }
    }

    public function test_final_submit_re_resolves_selection_and_fails_closed_when_reference_becomes_stale(): void
    {
        $projection = new TelegramSupportStaleOrderProjection;
        $this->app->instance(TelegramSupportOwnedOrderProjection::class, $projection);
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9892;

        $this->accept($this->payload(8920, $telegramUserId, 'support_reference_stale', 'fa', '/start'));
        $processor->process('123456789', 8920);
        $account = $this->account($telegramUserId);
        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8921, $telegramUserId, 'support_reference_stale', 'fa', $support));
        $processor->process('123456789', 8921);
        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8922, $telegramUserId, 'support_reference_stale', 'fa', $create));
        $processor->process('123456789', 8922);
        $category = $this->callbackToken(
            'navigation.support.category',
            $account['account_id'],
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8923, $telegramUserId, 'support_reference_stale', 'fa', $category));
        $processor->process('123456789', 8923);
        $orderType = $this->callbackToken(
            'navigation.support.reference.type',
            $account['account_id'],
            json_encode(['reference_type' => 'order'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8924, $telegramUserId, 'support_reference_stale', 'fa', $orderType));
        $processor->process('123456789', 8924);
        [$selection, $payload] = $this->latestSelectionCallback($account['account_id']);
        self::assertSame(TelegramSupportStaleOrderProjection::TOKEN, $payload['selection_token']);
        $this->accept($this->callbackPayload(8925, $telegramUserId, 'support_reference_stale', 'fa', $selection));
        $processor->process('123456789', 8925);
        self::assertSame(1, $projection->resolveCalls);

        $this->accept($this->payload(8926, $telegramUserId, 'support_reference_stale', 'fa', 'Stale context'));
        $processor->process('123456789', 8926);
        $this->accept($this->payload(8927, $telegramUserId, 'support_reference_stale', 'fa', 'This must fail before ticket creation.'));
        $processor->process('123456789', 8927);

        self::assertSame(2, $projection->resolveCalls);
        self::assertSame(0, DB::table('support_tickets')->where('requester_user_id', $account['user_id'])->count());
        self::assertSame('support_create_reference_type', $this->supportSession($account['account_id'])['state']);
        self::assertStringContainsString('دیگر در دسترس', $this->latestConfidentialPresentation($telegramUserId));
    }

    public function test_business_reference_prompt_has_english_file_backed_fallback(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $telegramUserId = 9893;

        $this->accept($this->payload(8930, $telegramUserId, 'support_reference_en', 'en', '/start'));
        $processor->process('123456789', 8930);
        $account = $this->account($telegramUserId);
        $support = $this->callbackToken('navigation.support', $account['account_id']);
        $this->accept($this->callbackPayload(8931, $telegramUserId, 'support_reference_en', 'en', $support));
        $processor->process('123456789', 8931);
        $create = $this->callbackToken('navigation.support.create', $account['account_id']);
        $this->accept($this->callbackPayload(8932, $telegramUserId, 'support_reference_en', 'en', $create));
        $processor->process('123456789', 8932);
        $category = $this->callbackToken(
            'navigation.support.category',
            $account['account_id'],
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8933, $telegramUserId, 'support_reference_en', 'en', $category));
        $processor->process('123456789', 8933);

        self::assertSame('support_create_reference_type', $this->supportSession($account['account_id'])['state']);
        self::assertStringContainsString('Optionally link one related Order', $this->latestConfidentialPresentation($telegramUserId));

        $orderType = $this->callbackToken(
            'navigation.support.reference.type',
            $account['account_id'],
            json_encode(['reference_type' => 'order'], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8934, $telegramUserId, 'support_reference_en', 'en', $orderType));
        $processor->process('123456789', 8934);

        self::assertSame('support_create_reference_list', $this->supportSession($account['account_id'])['state']);
        self::assertStringContainsString('No owned Order records are available', $this->latestConfidentialPresentation($telegramUserId));
    }

    private function createTicketWithReference(
        TelegramUpdateProcessor $processor,
        int $accountId,
        int $telegramUserId,
        int $otherTelegramUserId,
        int &$updateId,
        string $referenceType,
        string $title,
        bool $exerciseBackAndCrossActor,
    ): int {
        $create = $this->callbackToken('navigation.support.create', $accountId);
        $updateId++;
        $this->accept($this->callbackPayload($updateId, $telegramUserId, 'support_reference_owner', 'fa', $create));
        $processor->process('123456789', $updateId);

        $category = $this->callbackToken(
            'navigation.support.category',
            $accountId,
            json_encode(['category' => 'other'], JSON_THROW_ON_ERROR),
        );
        $updateId++;
        $this->accept($this->callbackPayload($updateId, $telegramUserId, 'support_reference_owner', 'fa', $category));
        $processor->process('123456789', $updateId);
        self::assertSame('support_create_reference_type', $this->supportSession($accountId)['state']);
        if ($exerciseBackAndCrossActor) {
            self::assertStringContainsString('در صورت تمایل', $this->latestConfidentialPresentation($telegramUserId));
        }

        $referenceTypeCallback = $this->callbackToken(
            'navigation.support.reference.type',
            $accountId,
            json_encode(['reference_type' => $referenceType], JSON_THROW_ON_ERROR),
        );
        $updateId++;
        $this->accept($this->callbackPayload($updateId, $telegramUserId, 'support_reference_owner', 'fa', $referenceTypeCallback));
        $processor->process('123456789', $updateId);
        self::assertSame('support_create_reference_list', $this->supportSession($accountId)['state']);

        if ($exerciseBackAndCrossActor) {
            $back = $this->callbackToken('navigation.back', $accountId);
            $updateId++;
            $this->accept($this->callbackPayload($updateId, $telegramUserId, 'support_reference_owner', 'fa', $back));
            $processor->process('123456789', $updateId);
            self::assertSame('support_create_reference_type', $this->supportSession($accountId)['state']);

            $referenceTypeCallback = $this->callbackToken(
                'navigation.support.reference.type',
                $accountId,
                json_encode(['reference_type' => $referenceType], JSON_THROW_ON_ERROR),
            );
            $updateId++;
            $this->accept($this->callbackPayload($updateId, $telegramUserId, 'support_reference_owner', 'fa', $referenceTypeCallback));
            $processor->process('123456789', $updateId);
            self::assertSame('support_create_reference_list', $this->supportSession($accountId)['state']);
        }

        [$selection, $selectionPayload] = $this->latestSelectionCallback($accountId);
        self::assertSame(['reference_type', 'selection_token'], array_keys($selectionPayload));
        self::assertSame($referenceType, $selectionPayload['reference_type']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', (string) $selectionPayload['selection_token']);
        self::assertArrayNotHasKey('order_id', $selectionPayload);
        self::assertArrayNotHasKey('payment_intent_id', $selectionPayload);
        self::assertArrayNotHasKey('service_subscription_id', $selectionPayload);

        if ($exerciseBackAndCrossActor) {
            try {
                $this->app->make(TelegramInteractionCallbackService::class)->accept(
                    '123456789',
                    $otherTelegramUserId,
                    $selection,
                    $updateId + 100,
                );
                self::fail('A business-reference callback must remain bound to the issuing Telegram actor.');
            } catch (TelegramInteractionRejected) {
                // Expected.
            }
        }

        $updateId++;
        $this->accept($this->callbackPayload($updateId, $telegramUserId, 'support_reference_owner', 'fa', $selection));
        $processor->process('123456789', $updateId);
        self::assertSame('support_create_title', $this->supportSession($accountId)['state']);
        $active = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount($accountId);
        self::assertNotNull($active);
        self::assertSame($referenceType, $active->payload['reference_type'] ?? null);
        self::assertSame($selectionPayload['selection_token'], $active->payload['selection_token'] ?? null);
        self::assertArrayNotHasKey('order_id', $active->payload);
        self::assertArrayNotHasKey('payment_intent_id', $active->payload);
        self::assertArrayNotHasKey('service_subscription_id', $active->payload);

        $updateId++;
        $this->accept($this->payload($updateId, $telegramUserId, 'support_reference_owner', 'fa', $title));
        $processor->process('123456789', $updateId);
        self::assertSame('support_create_description', $this->supportSession($accountId)['state']);

        $updateId++;
        $this->accept($this->payload($updateId, $telegramUserId, 'support_reference_owner', 'fa', 'Business context description.'));
        $processor->process('123456789', $updateId);
        self::assertSame('support_ticket', $this->supportSession($accountId)['state']);
        $ticketId = DB::table('support_tickets')
            ->where('requester_user_id', $this->account($telegramUserId)['user_id'])
            ->where('title', $title)
            ->value('id');
        self::assertNotNull($ticketId);
        $count = DB::table('support_tickets')->where('id', $ticketId)->count();
        $processor->process('123456789', $updateId);
        self::assertSame($count, DB::table('support_tickets')->where('id', $ticketId)->count());

        return (int) $ticketId;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function latestSelectionCallback(int $accountId): array
    {
        $row = DB::table('telegram_interaction_callbacks')
            ->where('telegram_account_id', $accountId)
            ->where('action', 'navigation.support.reference.select')
            ->orderByDesc('id')
            ->first(['action_payload', 'token_ciphertext']);
        self::assertNotNull($row);
        $payload = json_decode((string) $row->action_payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return [
            $this->app->make(StringEncrypter::class)->decryptString((string) $row->token_ciphertext),
            $payload,
        ];
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

    /**
     * @return array{
     *     user_id:int,
     *     order_id:int,
     *     payment_intent_id:int,
     *     service_subscription_id:int
     * }
     */
    private function referenceBundle(int $userId, string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix, $userId);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );
        $queued = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('queue-'.$suffix),
        );
        $paymentIntentId = DB::table('payment_intents')->where('public_id', $settlement->intentPublicId)->value('id');
        self::assertNotNull($paymentIntentId);

        return [
            'user_id' => $settlement->userId,
            'order_id' => $order->orderId,
            'payment_intent_id' => (int) $paymentIntentId,
            'service_subscription_id' => $queued->serviceSubscriptionId,
        ];
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
