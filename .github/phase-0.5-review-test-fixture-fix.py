from pathlib import Path

path = Path('tests/Feature/NowPaymentsPaymentServiceTest.php')
text = path.read_text()

import_old = "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Illuminate\\Support\\Facades\\DB;"
import_new = "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Illuminate\\Support\\Facades\\DB;\nuse Illuminate\\Support\\Str;"
if text.count(import_old) != 1:
    raise SystemExit(f'Expected one NowPayments test import anchor, found {text.count(import_old)}.')
text = text.replace(import_old, import_new, 1)

start_marker = "    public function test_stale_initiating_create_without_provider_id_fails_closed_without_recreating_provider_payment(): void\n"
end_marker = "    public function test_finished_with_underpayment_never_captures(): void\n"
start = text.find(start_marker)
end = text.find(end_marker, start + len(start_marker))
if start < 0 or end < 0:
    raise SystemExit('Could not locate stale-create recovery test boundaries.')

method = r'''    public function test_stale_initiating_create_without_provider_id_fails_closed_without_recreating_provider_payment(): void
    {
        $service = $this->service();

        $templateIntentPublicId = $this->purchaseIntent('stale-create-template', 10_000_000);
        $template = $service->create(
            $templateIntentPublicId,
            'nowpayments.create.stale-template.000001',
            $this->correlation('create-stale-template'),
        );
        $templateRow = DB::table('nowpayments_payment_authorities')->where('id', $template->authorityId)->first();
        self::assertNotNull($templateRow);
        $baselineCreateCalls = $this->transport->createCalls;

        $intentPublicId = $this->purchaseIntent('stale-create', 10_000_000);
        $intent = DB::table('payment_intents')->where('public_id', $intentPublicId)->first();
        self::assertNotNull($intent);
        $requestKey = 'nowpayments.create.stale-create.000001';

        $staleAuthority = (array) $templateRow;
        unset($staleAuthority['id']);
        $staleAuthority['public_id'] = (string) Str::ulid();
        $staleAuthority['request_key'] = $requestKey;
        $staleAuthority['payment_intent_id'] = (int) $intent->id;
        $staleAuthority['order_id'] = 'payment-intent:'.$intentPublicId;
        $staleAuthority['state'] = NowPaymentsAuthorityState::Initiating->value;
        $staleAuthority['amount_irr'] = (int) $intent->amount_irr;
        $staleAuthority['request_payload_hash'] = hash('sha256', 'stale-create-fixture|'.$intentPublicId.'|'.$requestKey);
        $staleAuthority['provider_payment_id'] = null;
        $staleAuthority['provider_status'] = null;
        $staleAuthority['provider_pay_amount'] = null;
        $staleAuthority['provider_actually_paid'] = null;
        $staleAuthority['provider_pay_address'] = null;
        $staleAuthority['create_response_hash'] = null;
        $staleAuthority['provider_created_at'] = null;
        $staleAuthority['last_status_at'] = null;
        DB::table('nowpayments_payment_authorities')->insert($staleAuthority);

        $tooEarly = $service->create($intentPublicId, $requestKey, $this->correlation('stale-create-too-early'));
        self::assertSame(NowPaymentsAuthorityState::Initiating, $tooEarly->state);
        self::assertSame($baselineCreateCalls, $this->transport->createCalls);
        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));

        $this->clock->value = $this->clock->value->modify('+61 seconds');
        $uncertain = $service->refresh($intentPublicId, $this->correlation('stale-create-recovery'));
        self::assertSame(NowPaymentsAuthorityState::Uncertain, $uncertain->state);
        self::assertNull($uncertain->providerPaymentId);
        self::assertSame($baselineCreateCalls, $this->transport->createCalls);
        self::assertSame(0, $this->transport->statusCalls);
        self::assertSame('pending_manual_review', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('nowpayments_reconciliation_findings')
            ->where('nowpayments_payment_authority_id', $uncertain->authorityId)
            ->where('code', 'create_outcome_unknown_without_provider_id')
            ->where('severity', 'high')
            ->count());

        $replay = $service->create($intentPublicId, $requestKey, $this->correlation('stale-create-replay'));
        self::assertSame(NowPaymentsAuthorityState::Uncertain, $replay->state);
        self::assertSame($baselineCreateCalls, $this->transport->createCalls);
        self::assertSame(1, DB::table('nowpayments_reconciliation_findings')
            ->where('nowpayments_payment_authority_id', $uncertain->authorityId)
            ->where('code', 'create_outcome_unknown_without_provider_id')
            ->count());
    }

'''
text = text[:start] + method + text[end:]
path.write_text(text)