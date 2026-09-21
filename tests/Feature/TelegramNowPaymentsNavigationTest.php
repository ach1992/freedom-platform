<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseNowPaymentsPayment;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseNowPaymentsReceipt;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOrderReceipt;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodsDecision;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodSelection;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramNowPaymentsNavigationPayment implements TelegramCustomerPurchaseNowPaymentsPayment
{
    public int $claimCalls = 0;

    public int $claimEffects = 0;

    public int $prepareCalls = 0;

    public int $prepareEffects = 0;

    public bool $claimUnavailable = false;

    public int $refreshCalls = 0;

    public int $refreshEffects = 0;

    /** @var array<string,string> */
    private array $claims = [];

    /** @var array<string,TelegramCustomerPurchaseNowPaymentsReceipt> */
    private array $accepted = [];

    /** @var array<string,TelegramCustomerPurchaseNowPaymentsReceipt> */
    private array $refreshed = [];

    public function __construct(
        private readonly string $initialState = 'created',
        private readonly string $refreshState = 'finished',
        private readonly bool $finishedSettlementAvailable = true,
    ) {}

    public function claimForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): string {
        $this->assertAuthority(
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $operationKey,
        );
        $this->claimCalls++;
        if ($this->claimUnavailable) {
            throw new AuthorizationException('Telegram NOWPayments checkout claim is stale.');
        }
        if (isset($this->claims[$operationKey])) {
            return $this->claims[$operationKey];
        }

        $this->claimEffects++;
        $this->claims[$operationKey] = str_pad('01H', 26, '0');

        return $this->claims[$operationKey];
    }

    public function executeClaimForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $paymentIntentPublicId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseNowPaymentsReceipt {
        $this->assertAuthority(
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $operationKey,
        );
        if ($paymentIntentPublicId !== str_pad('01H', 26, '0')) {
            throw new AuthorizationException('Unexpected Telegram NOWPayments PaymentIntent claim.');
        }

        $this->prepareCalls++;
        if (isset($this->accepted[$operationKey])) {
            return $this->copy($this->accepted[$operationKey], true);
        }

        $this->prepareEffects++;
        $created = $this->initialState === 'created';
        $receipt = new TelegramCustomerPurchaseNowPaymentsReceipt(
            str_pad('01R', 26, '0'),
            $paymentIntentPublicId,
            $this->initialState,
            'manual',
            '900000.00000000',
            '1.38888889',
            'usdtbsc',
            $created ? '900001' : null,
            $created ? 'waiting' : null,
            $created ? '1.388888890000000000' : null,
            $created ? '0x'.str_repeat('1', 40) : null,
            null,
            false,
            $this->initialState !== 'created',
        );
        $this->accepted[$operationKey] = $receipt;

        return $receipt;
    }

    public function prepareForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseNowPaymentsReceipt {
        $paymentIntentPublicId = $this->claimForSelf(
            $actorUserId,
            $subjectUserId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $operationKey,
        );

        return $this->executeClaimForSelf(
            $actorUserId,
            $subjectUserId,
            $paymentIntentPublicId,
            $orderPublicId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
            $operationKey,
        );
    }

    public function refreshForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $paymentIntentPublicId,
        string $operationKey,
    ): TelegramCustomerPurchaseNowPaymentsReceipt {
        if ($actorUserId < 1
            || $actorUserId !== $subjectUserId
            || $paymentIntentPublicId !== str_pad('01H', 26, '0')
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new AuthorizationException('Unexpected Telegram NOWPayments refresh authority.');
        }
        $this->refreshCalls++;
        $key = $paymentIntentPublicId.':'.$operationKey;
        if (isset($this->refreshed[$key])) {
            return $this->copy($this->refreshed[$key], true);
        }

        $this->refreshEffects++;
        $finished = $this->refreshState === 'finished';
        $created = $this->refreshState === 'created';
        $receipt = new TelegramCustomerPurchaseNowPaymentsReceipt(
            str_pad('01R', 26, '0'),
            $paymentIntentPublicId,
            $this->refreshState,
            'manual',
            '900000.00000000',
            '1.38888889',
            'usdtbsc',
            $created || $finished ? '900001' : null,
            $finished ? 'finished' : ($created ? 'waiting' : null),
            $created || $finished ? '1.388888890000000000' : null,
            $created || $finished ? '0x'.str_repeat('1', 40) : null,
            $finished && $this->finishedSettlementAvailable ? str_pad('01S', 26, '0') : null,
            false,
            in_array($this->refreshState, ['initiating', 'uncertain', 'manual_review'], true),
        );
        $this->refreshed[$key] = $receipt;

        return $receipt;
    }

    private function assertAuthority(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): void {
        if ($actorUserId !== $subjectUserId
            || $orderPublicId !== str_pad('01N', 26, '0')
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)
            || $decisionPublicId !== str_pad('01P', 26, '0')
            || $decisionConfigurationHash !== str_repeat('b', 64)
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new AuthorizationException('Unexpected Telegram NOWPayments authority.');
        }
    }

    private function copy(TelegramCustomerPurchaseNowPaymentsReceipt $receipt, bool $replayed): TelegramCustomerPurchaseNowPaymentsReceipt
    {
        return new TelegramCustomerPurchaseNowPaymentsReceipt(
            $receipt->authorityPublicId,
            $receipt->paymentIntentPublicId,
            $receipt->state,
            $receipt->rateSource,
            $receipt->rateIrr,
            $receipt->priceAmountUsd,
            $receipt->payCurrency,
            $receipt->providerPaymentId,
            $receipt->providerStatus,
            $receipt->providerPayAmount,
            $receipt->providerPayAddress,
            $receipt->settlementPublicId,
            $replayed,
            $receipt->manualReviewRequired,
        );
    }
}

final readonly class TelegramNowPaymentsNavigationOrder implements TelegramCustomerPurchaseOrder
{
    public function openForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $correlationId,
    ): TelegramCustomerPurchaseOrderReceipt {
        return $this->receipt($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    public function currentForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchaseOrderReceipt {
        if ($orderPublicId !== str_pad('01N', 26, '0')) {
            throw new AuthorizationException('Unexpected Telegram NOWPayments Order.');
        }

        return $this->receipt($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    private function receipt(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchaseOrderReceipt {
        if ($actorUserId !== $subjectUserId
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)) {
            throw new AuthorizationException('Unexpected Telegram NOWPayments Order authority.');
        }

        return new TelegramCustomerPurchaseOrderReceipt(
            str_pad('01N', 26, '0'),
            $quotePublicId,
            $quoteConfigurationHash,
            1_250_000,
            'IRR',
            true,
        );
    }
}

final readonly class TelegramNowPaymentsNavigationPaymentMethods implements TelegramCustomerPurchasePaymentMethods
{
    public function discoverForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionKey,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
        return $this->decision($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    public function currentForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
        if ($decisionPublicId !== str_pad('01P', 26, '0')
            || $decisionConfigurationHash !== str_repeat('b', 64)) {
            throw new AuthorizationException('Unexpected Telegram NOWPayments PAY-001 identity.');
        }

        return $this->decision($actorUserId, $subjectUserId, $quotePublicId, $quoteConfigurationHash);
    }

    public function selectForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $methodCode,
    ): TelegramCustomerPurchasePaymentMethodSelection {
        $this->currentForSelf(
            $actorUserId,
            $subjectUserId,
            $quotePublicId,
            $quoteConfigurationHash,
            $decisionPublicId,
            $decisionConfigurationHash,
        );
        if ($methodCode !== 'nowpayments') {
            throw new AuthorizationException('Unexpected Telegram NOWPayments method.');
        }

        return new TelegramCustomerPurchasePaymentMethodSelection($decisionPublicId, $quotePublicId, $methodCode);
    }

    private function decision(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchasePaymentMethodsDecision {
        if ($actorUserId !== $subjectUserId
            || $quotePublicId !== str_pad('01K', 26, '0')
            || $quoteConfigurationHash !== str_repeat('a', 64)) {
            throw new AuthorizationException('Unexpected Telegram NOWPayments payment-method authority.');
        }

        return new TelegramCustomerPurchasePaymentMethodsDecision(
            str_pad('01P', 26, '0'),
            $quotePublicId,
            $quoteConfigurationHash,
            str_repeat('b', 64),
            ['nowpayments', 'zarinpal'],
            true,
        );
    }
}

/** @requirement BUY-003 IPG-002 PAY-001 PAY-002 PAY-003 ARCH-003 SEC-003 QUA-001 QUA-004 */
final class TelegramNowPaymentsNavigationTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram NOWPayments navigation verification requires MariaDB/MySQL.');
        }
        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
        $this->seed();
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

    public function test_selection_refresh_and_exact_replay_create_one_provider_effect_and_reach_finished_surface(): void
    {
        $payment = new TelegramNowPaymentsNavigationPayment;
        [$processor, $sessionId, $telegramUserId, $accountId] = $this->prepareSelection(
            $payment,
            9970,
            9700,
            'nowpayments_success',
        );

        self::assertSame(1, $payment->prepareEffects);
        self::assertSame(1, $payment->prepareCalls);
        self::assertSame(
            'purchase_nowpayments_payment',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertStringContainsString('0x'.str_repeat('1', 40), $this->latestConfidentialPresentation());

        $refresh = $this->callbackToken('navigation.purchase.nowpayments.refresh', $accountId);
        $this->accept($this->callbackPayload(9710, $telegramUserId, 'nowpayments_success', 'fa', $refresh));
        $processor->process('123456789', 9710);

        self::assertSame(1, $payment->refreshEffects);
        self::assertSame(1, $payment->refreshCalls);
        self::assertSame(
            'purchase_nowpayments_finished',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertStringContainsString(str_pad('01S', 26, '0'), $this->latestConfidentialPresentation());

        $this->accept($this->callbackPayload(9710, $telegramUserId, 'nowpayments_success', 'fa', $refresh));
        $processor->process('123456789', 9710);
        self::assertSame(1, $payment->refreshEffects);
        self::assertSame(1, $payment->refreshCalls);
        self::assertSame(1, DB::table('processed_telegram_updates')->where('update_id', 9710)->count());
    }

    public function test_interrupted_preparing_with_persisted_claim_replays_provider_authority_without_fresh_checkout_claim(): void
    {
        $payment = new TelegramNowPaymentsNavigationPayment;
        $this->app->instance(TelegramCustomerPurchaseNowPaymentsPayment::class, $payment);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, new TelegramNowPaymentsNavigationOrder);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, new TelegramNowPaymentsNavigationPaymentMethods);
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $telegramUserId = 9990;
        $username = 'nowpayments_interrupted';
        $this->accept($this->payload(9900, $telegramUserId, $username, 'fa', '/start'));
        $processor->process('123456789', 9900);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $home = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->first(['id', 'public_id', 'version']);
        self::assertNotNull($home);

        $state = [
            'page' => 1,
            'offering_selection' => str_repeat('c', 40),
            'quote_public_id' => str_pad('01K', 26, '0'),
            'quote_configuration_hash' => str_repeat('a', 64),
            'payment_decision_public_id' => str_pad('01P', 26, '0'),
            'payment_decision_configuration_hash' => str_repeat('b', 64),
            'order_public_id' => str_pad('01N', 26, '0'),
            'payment_method_code' => 'nowpayments',
            'cancel_locked' => true,
            'expiry_locked' => true,
        ];
        $operationKey = hash('sha256', 'telegram-nowpayments-order:'.$state['order_public_id']);
        $paymentIntentPublicId = $payment->claimForSelf(
            (int) $account->user_id,
            (int) $account->user_id,
            $state['order_public_id'],
            $state['quote_public_id'],
            $state['quote_configuration_hash'],
            $state['payment_decision_public_id'],
            $state['payment_decision_configuration_hash'],
            $operationKey,
        );
        $accepted = $payment->executeClaimForSelf(
            (int) $account->user_id,
            (int) $account->user_id,
            $paymentIntentPublicId,
            $state['order_public_id'],
            $state['quote_public_id'],
            $state['quote_configuration_hash'],
            $state['payment_decision_public_id'],
            $state['payment_decision_configuration_hash'],
            $operationKey,
        );
        self::assertSame('created', $accepted->state);
        self::assertSame(1, $payment->claimCalls);
        self::assertSame(1, $payment->claimEffects);
        self::assertSame(1, $payment->prepareCalls);
        self::assertSame(1, $payment->prepareEffects);

        $state['nowpayments_payment_intent_public_id'] = $paymentIntentPublicId;
        $this->app->make(TelegramInteractionSessionService::class)->transition(
            (string) $home->public_id,
            (int) $home->version,
            'purchase_nowpayments_preparing',
            $state,
            'telegram-nowpayments-interrupted-preparing',
        );

        $payment->claimUnavailable = true;
        $this->accept($this->payload(9910, $telegramUserId, $username, 'fa', '/menu'));
        $processor->process('123456789', 9910);

        self::assertSame(1, $payment->claimCalls);
        self::assertSame(1, $payment->claimEffects);
        self::assertSame(2, $payment->prepareCalls);
        self::assertSame(1, $payment->prepareEffects);
        self::assertSame(
            'purchase_nowpayments_payment',
            DB::table('telegram_interaction_sessions')->where('id', (int) $home->id)->value('state'),
        );
        $payload = json_decode(
            (string) DB::table('telegram_interaction_sessions')->where('id', (int) $home->id)->value('payload'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame($paymentIntentPublicId, $payload['nowpayments_payment_intent_public_id'] ?? null);
        self::assertSame('900001', $payload['nowpayments_provider_payment_id'] ?? null);
    }

    public function test_live_uncertain_authority_cannot_be_abandoned_by_navigation_cancel_or_restart_commands(): void
    {
        $payment = new TelegramNowPaymentsNavigationPayment('uncertain', 'uncertain');
        [$processor, $sessionId, $telegramUserId] = $this->prepareSelection(
            $payment,
            9980,
            9800,
            'nowpayments_uncertain',
        );

        self::assertSame(
            'purchase_nowpayments_pending',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame(1, $payment->prepareEffects);
        self::assertStringContainsString('پرداخت دوم', $this->latestConfidentialPresentation());

        $payload = json_decode(
            (string) DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('payload'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertTrue($payload['cancel_locked'] ?? false);
        self::assertTrue($payload['expiry_locked'] ?? false);

        $this->accept($this->payload(9810, $telegramUserId, 'nowpayments_uncertain', 'fa', 'status?'));
        $processor->process('123456789', 9810);
        self::assertSame(1, $payment->prepareEffects);
        self::assertSame(0, $payment->refreshEffects);

        foreach ([
            9811 => '/back',
            9812 => '/start',
            9813 => '/menu',
        ] as $updateId => $command) {
            $this->accept($this->payload($updateId, $telegramUserId, 'nowpayments_uncertain', 'fa', $command));
            $processor->process('123456789', $updateId);
            self::assertSame(
                'purchase_nowpayments_pending',
                DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
            );
            self::assertSame('active', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('status'));
            self::assertSame(1, $payment->prepareEffects);
            self::assertSame(1, $payment->refreshEffects);
        }

        $this->accept($this->payload(9814, $telegramUserId, 'nowpayments_uncertain', 'fa', '/cancel'));
        $processor->process('123456789', 9814);
        self::assertSame(
            'purchase_nowpayments_pending',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame('active', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('status'));
        self::assertSame(1, $payment->prepareEffects);
        self::assertSame(1, $payment->refreshEffects);
        self::assertSame(3, $payment->refreshCalls);
    }

    public function test_finished_provider_without_settlement_remains_locked_across_navigation_cancel_replay_and_expiry(): void
    {
        $payment = new TelegramNowPaymentsNavigationPayment('created', 'finished', false);
        [$processor, $sessionId, $telegramUserId, $accountId] = $this->prepareSelection(
            $payment,
            9995,
            9850,
            'nowpayments_finished_unsettled',
        );

        $refresh = $this->callbackToken('navigation.purchase.nowpayments.refresh', $accountId);
        $refreshPayload = $this->callbackPayload(
            9860,
            $telegramUserId,
            'nowpayments_finished_unsettled',
            'fa',
            $refresh,
        );
        $this->accept($refreshPayload);
        $processor->process('123456789', 9860);

        self::assertSame(
            'purchase_nowpayments_pending',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame('active', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('status'));
        $payload = json_decode(
            (string) DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('payload'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('finished', $payload['nowpayments_state'] ?? null);
        self::assertNull($payload['nowpayments_settlement_public_id'] ?? null);
        self::assertTrue($payload['cancel_locked'] ?? false);
        self::assertTrue($payload['expiry_locked'] ?? false);
        self::assertStringContainsString('پرداخت دوم', $this->latestConfidentialPresentation());

        $this->accept($refreshPayload);
        $processor->process('123456789', 9860);
        self::assertSame(1, $payment->refreshEffects);

        foreach ([
            9861 => '/back',
            9862 => '/start',
            9863 => '/menu',
        ] as $updateId => $command) {
            $this->accept($this->payload(
                $updateId,
                $telegramUserId,
                'nowpayments_finished_unsettled',
                'fa',
                $command,
            ));
            $processor->process('123456789', $updateId);
            self::assertSame(
                'purchase_nowpayments_pending',
                DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
            );
            self::assertSame('active', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('status'));
        }

        $this->accept($this->payload(
            9864,
            $telegramUserId,
            'nowpayments_finished_unsettled',
            'fa',
            '/cancel',
        ));
        $processor->process('123456789', 9864);
        self::assertSame(
            'purchase_nowpayments_pending',
            DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('state'),
        );
        self::assertSame('active', DB::table('telegram_interaction_sessions')->where('id', $sessionId)->value('status'));

        DB::table('telegram_interaction_sessions')
            ->where('id', $sessionId)
            ->update(['expires_at' => '2000-01-01 00:00:00']);
        $active = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount($accountId);
        self::assertNotNull($active);
        self::assertSame('purchase_nowpayments_pending', $active->state);
        self::assertSame('active', $active->status->value);
        self::assertTrue($active->payload['cancel_locked'] ?? false);
        self::assertTrue($active->payload['expiry_locked'] ?? false);
    }

    /**
     * @return array{TelegramUpdateProcessor,int,int,int}
     */
    private function prepareSelection(
        TelegramNowPaymentsNavigationPayment $payment,
        int $telegramUserId,
        int $baseUpdateId,
        string $username,
    ): array {
        $this->app->instance(TelegramCustomerPurchaseNowPaymentsPayment::class, $payment);
        $this->app->instance(TelegramCustomerPurchaseOrder::class, new TelegramNowPaymentsNavigationOrder);
        $this->app->instance(TelegramCustomerPurchasePaymentMethods::class, new TelegramNowPaymentsNavigationPaymentMethods);
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload($baseUpdateId, $telegramUserId, $username, 'fa', '/start'));
        $processor->process('123456789', $baseUpdateId);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id']);
        self::assertNotNull($account);
        $accountId = (int) $account->id;
        $home = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->first(['id', 'public_id', 'version']);
        self::assertNotNull($home);

        $state = [
            'page' => 1,
            'offering_selection' => str_repeat('c', 40),
            'quote_public_id' => str_pad('01K', 26, '0'),
            'quote_configuration_hash' => str_repeat('a', 64),
            'payment_decision_public_id' => str_pad('01P', 26, '0'),
            'payment_decision_configuration_hash' => str_repeat('b', 64),
            'order_public_id' => str_pad('01N', 26, '0'),
        ];
        $methods = $this->app->make(TelegramInteractionSessionService::class)->transition(
            (string) $home->public_id,
            (int) $home->version,
            'purchase_payment_methods',
            $state,
            'telegram-nowpayments-test-methods:'.hash('sha256', $username),
        );
        $callback = $this->app->make(TelegramInteractionCallbackService::class)->issue(
            $methods->publicId,
            $methods->version,
            'navigation.purchase.payment_method.select',
            ['method_code' => 'nowpayments'],
            'telegram-nowpayments-test-select:'.hash('sha256', $username),
        );
        $token = $this->app->make(StringEncrypter::class)->decryptString((string) DB::table('telegram_interaction_callbacks')
            ->where('public_id', $callback->publicId)
            ->value('token_ciphertext'));
        $this->accept($this->callbackPayload($baseUpdateId + 1, $telegramUserId, $username, 'fa', $token));
        $processor->process('123456789', $baseUpdateId + 1);

        return [$processor, (int) $home->id, $telegramUserId, $accountId];
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
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'username' => $username, 'language_code' => $languageCode],
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
                'from' => ['id' => $telegramUserId, 'is_bot' => false, 'username' => $username, 'language_code' => $languageCode],
                'message' => [
                    'message_id' => $updateId,
                    'date' => 1_700_000_000,
                    'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                ],
                'data' => $token,
            ],
        ];
    }

    private function callbackToken(string $action, int $telegramAccountId): string
    {
        $sessionId = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('id');
        self::assertIsNumeric($sessionId);
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($callback);

        return $this->app->make(StringEncrypter::class)
            ->decryptString((string) $callback->token_ciphertext);
    }

    private function latestConfidentialPresentation(): string
    {
        $operationPublicId = DB::table('telegram_delivery_operations')->orderByDesc('id')->value('public_id');
        self::assertIsString($operationPublicId);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
    }
}
