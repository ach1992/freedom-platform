<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Zarinpal\Application\ZarinpalPaymentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement WAL-001 PAY-002 PAY-003 IPG-001 SEC-002 DAT-002 DAT-003 QUA-001 QUA-004 */
final class PaymentTelegramWalletTopUpRuntimeWiringTest extends TestCase
{
    use DatabaseTruncation;
    use TelegramCustomerRuntimeWiringTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTelegramRuntimeWiring();
    }

    public function test_navigation_replays_one_canonical_zarinpal_and_ledger_settlement(): void
    {
        $telegramUserId = 98103;
        [$processor, $accountId, $userId] = $this->openMyAccount(8300, $telegramUserId, 'wallet_topup');

        $topUpToken = $this->telegramRuntimeCallbackToken('navigation.wallet.top_up', $accountId);
        $this->acceptTelegramRuntime($this->telegramRuntimeCallbackPayload(
            8302,
            $telegramUserId,
            'wallet_topup',
            'fa',
            $topUpToken,
        ));
        $processor->process('123456789', 8302);

        $this->acceptTelegramRuntime(
            $this->telegramRuntimePayload(8303, $telegramUserId, 'wallet_topup', 'fa', '250000'),
        );
        $processor->process('123456789', 8303);

        $intent = DB::table('payment_intents')
            ->where('user_id', $userId)
            ->where('purpose', 'wallet_top_up')
            ->first(['id', 'public_id', 'amount_irr', 'provider_code']);
        self::assertNotNull($intent);
        self::assertSame(250_000, (int) $intent->amount_irr);
        self::assertSame('zarinpal', (string) $intent->provider_code);
        self::assertSame(1, DB::table('payment_intents')
            ->where('user_id', $userId)
            ->where('purpose', 'wallet_top_up')
            ->count());
        self::assertSame(1, DB::table('zarinpal_payment_requests')
            ->where('payment_intent_id', (int) $intent->id)
            ->count());
        self::assertSame(1, $this->zarinpal->requestCalls);

        $this->app->make(ZarinpalPaymentService::class)->handleCallback(
            TelegramRuntimeZarinpalTransport::AUTHORITY,
            'OK',
            'telegram-wallet-topup-callback-8303',
        );

        self::assertSame(1, $this->zarinpal->verifyCalls);
        self::assertSame(1, DB::table('wallet_top_up_settlements')
            ->where('payment_intent_id', (int) $intent->id)
            ->count());
        self::assertSame(0, DB::table('purchase_settlements')
            ->where('payment_intent_id', (int) $intent->id)
            ->count());
        self::assertSame(1, DB::table('ledger_transactions')
            ->where('transaction_type', 'wallet_external_top_up')
            ->where('source_type', 'payment_intent')
            ->where('source_id', (string) $intent->public_id)
            ->count());

        $refreshToken = $this->telegramRuntimeCallbackToken(
            'navigation.wallet.top_up.refresh',
            $accountId,
        );
        $this->acceptTelegramRuntime($this->telegramRuntimeCallbackPayload(
            8304,
            $telegramUserId,
            'wallet_topup',
            'fa',
            $refreshToken,
        ));
        $processor->process('123456789', 8304);

        self::assertSame(1, DB::table('payment_intents')
            ->where('user_id', $userId)
            ->where('purpose', 'wallet_top_up')
            ->count());
        self::assertSame(1, DB::table('zarinpal_payment_requests')
            ->where('payment_intent_id', (int) $intent->id)
            ->count());
        self::assertSame(1, DB::table('wallet_top_up_settlements')
            ->where('payment_intent_id', (int) $intent->id)
            ->count());
        self::assertSame(1, DB::table('ledger_transactions')
            ->where('transaction_type', 'wallet_external_top_up')
            ->where('source_id', (string) $intent->public_id)
            ->count());
        self::assertSame(1, $this->zarinpal->requestCalls);
        self::assertSame(1, $this->zarinpal->verifyCalls);
    }
}
