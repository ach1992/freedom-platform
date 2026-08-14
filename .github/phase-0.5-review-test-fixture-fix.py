from pathlib import Path

path = Path('tests/Feature/NowPaymentsPaymentServiceTest.php')
text = path.read_text()

import_old = "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Illuminate\\Support\\Facades\\DB;"
import_new = "use Illuminate\\Database\\QueryException;\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Illuminate\\Support\\Facades\\DB;"
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
        $intentPublicId = $this->purchaseIntent('stale-create', 10_000_000);
        $service = $this->service();
        $requestKey = 'nowpayments.create.stale-create.000001';

        DB::unprepared(<<<'SQL'
CREATE TRIGGER nowpayments_test_fail_accept_bu
BEFORE UPDATE ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    IF OLD.provider_payment_id IS NULL AND NEW.provider_payment_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test simulated local accept failure';
    END IF;
END
SQL);

        try {
            try {
                $service->create($intentPublicId, $requestKey, $this->correlation('create-stale-create'));
                self::fail('Expected simulated local acceptance failure after provider create.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('test simulated local accept failure', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS nowpayments_test_fail_accept_bu');
        }

        self::assertSame(1, $this->transport->createCalls);
        $authority = DB::table('nowpayments_payment_authorities')
            ->where('payment_intent_id', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('id'))
            ->first();
        self::assertNotNull($authority);
        self::assertSame(NowPaymentsAuthorityState::Initiating->value, $authority->state);
        self::assertNull($authority->provider_payment_id);
        self::assertNotNull($authority->create_attempted_at);

        $tooEarly = $service->create($intentPublicId, $requestKey, $this->correlation('stale-create-too-early'));
        self::assertSame(NowPaymentsAuthorityState::Initiating, $tooEarly->state);
        self::assertSame(1, $this->transport->createCalls);
        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));

        $this->clock->value = $this->clock->value->modify('+61 seconds');
        $uncertain = $service->refresh($intentPublicId, $this->correlation('stale-create-recovery'));
        self::assertSame(NowPaymentsAuthorityState::Uncertain, $uncertain->state);
        self::assertNull($uncertain->providerPaymentId);
        self::assertSame(1, $this->transport->createCalls);
        self::assertSame(0, $this->transport->statusCalls);
        self::assertSame('pending_manual_review', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(1, DB::table('nowpayments_reconciliation_findings')
            ->where('code', 'create_outcome_unknown_without_provider_id')
            ->where('severity', 'high')
            ->count());

        $replay = $service->create($intentPublicId, $requestKey, $this->correlation('stale-create-replay'));
        self::assertSame(NowPaymentsAuthorityState::Uncertain, $replay->state);
        self::assertSame(1, $this->transport->createCalls);
        self::assertSame(1, DB::table('nowpayments_reconciliation_findings')
            ->where('code', 'create_outcome_unknown_without_provider_id')
            ->count());
    }

'''
text = text[:start] + method + text[end:]
path.write_text(text)
