<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\Contracts\PhoneLookupHasher;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement ONB-004 ONB-005 SEC-003 DAT-003 INT-002 QUA-001 QUA-004 */
final class IdentityTelegramPhoneRuntimeWiringTest extends TestCase
{
    use DatabaseTruncation;
    use TelegramCustomerRuntimeWiringTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTelegramRuntimeWiring();
    }

    public function test_contact_ownership_failure_stays_fail_closed_without_session_contact_data(): void
    {
        $telegramUserId = 98101;
        [$processor, $accountId, $userId] = $this->openMyAccount(8100, $telegramUserId, 'identity_contact');

        $this->openPhoneVerification($processor, $accountId, 8102, $telegramUserId, 'identity_contact');
        $contactToken = $this->telegramRuntimeCallbackToken(
            'navigation.phone_verification.contact',
            $accountId,
        );
        $this->acceptTelegramRuntime($this->telegramRuntimeCallbackPayload(
            8103,
            $telegramUserId,
            'identity_contact',
            'fa',
            $contactToken,
        ));
        $processor->process('123456789', 8103);

        $this->acceptTelegramRuntime($this->telegramRuntimeContactPayload(
            8104,
            $telegramUserId,
            'identity_contact',
            '+989121234567',
            $telegramUserId + 99,
        ));
        $processor->process('123456789', 8104);

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->first(['state', 'payload']);
        self::assertNotNull($session);
        self::assertSame('phone_verification_contact', (string) $session->state);
        self::assertSame([], json_decode((string) $session->payload, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('unverified', DB::table('customer_profiles')
            ->where('user_id', $userId)
            ->value('phone_verification_status'));
        self::assertSame(0, DB::table('phone_numbers')->where('user_id', $userId)->count());
    }

    public function test_sms_path_hashes_ingress_ip_and_keeps_phone_and_plaintext_otp_out_of_session_state(): void
    {
        $telegramUserId = 98102;
        [$processor, $accountId, $userId] = $this->openMyAccount(8200, $telegramUserId, 'identity_sms');

        $this->openPhoneVerification($processor, $accountId, 8202, $telegramUserId, 'identity_sms');
        $smsToken = $this->telegramRuntimeCallbackToken('navigation.phone_verification.sms', $accountId);
        $this->acceptTelegramRuntime($this->telegramRuntimeCallbackPayload(
            8203,
            $telegramUserId,
            'identity_sms',
            'fa',
            $smsToken,
        ));
        $processor->process('123456789', 8203);

        $rawIp = '203.0.113.55';
        $rawPhone = '09123456789';
        $this->acceptTelegramRuntime(
            $this->telegramRuntimePayload(8204, $telegramUserId, 'identity_sms', 'fa', $rawPhone),
            $rawIp,
        );
        $processor->process('123456789', 8204);

        self::assertSame(1, $this->sms->calls);
        self::assertSame('+989123456789', $this->sms->lastDestination);
        self::assertIsString($this->sms->lastCode);

        $expectedIpHash = $this->app->make(PhoneLookupHasher::class)->hashOpaque('otp-ip|'.$rawIp);
        self::assertSame(
            $expectedIpHash,
            DB::table('processed_telegram_updates')->where('update_id', 8204)->value('request_ip_hash'),
        );

        $session = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $accountId)
            ->first(['state', 'payload']);
        self::assertNotNull($session);
        self::assertSame('phone_verification_sms_code', (string) $session->state);
        $sessionPayload = json_decode((string) $session->payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($sessionPayload);
        self::assertSame(['challenge_id'], array_keys($sessionPayload));
        self::assertIsString($sessionPayload['challenge_id']);

        $challenge = DB::table('otp_challenges')
            ->where('public_id', $sessionPayload['challenge_id'])
            ->first(['request_ip_hash', 'code_hash']);
        self::assertNotNull($challenge);
        self::assertSame($expectedIpHash, (string) $challenge->request_ip_hash);
        self::assertNotSame($this->sms->lastCode, (string) $challenge->code_hash);

        $serializedSession = (string) $session->payload;
        self::assertStringNotContainsString($rawPhone, $serializedSession);
        self::assertStringNotContainsString('+989123456789', $serializedSession);
        self::assertStringNotContainsString($rawIp, $serializedSession);
        self::assertStringNotContainsString($this->sms->lastCode, $serializedSession);

        $this->acceptTelegramRuntime($this->telegramRuntimePayload(
            8205,
            $telegramUserId,
            'identity_sms',
            'fa',
            $this->sms->lastCode,
        ));
        $processor->process('123456789', 8205);

        self::assertSame('verified', DB::table('customer_profiles')
            ->where('user_id', $userId)
            ->value('phone_verification_status'));
        self::assertSame(1, DB::table('phone_verification_evidences')
            ->where('method', 'sms_otp')
            ->count());
        self::assertSame(
            TelegramNavigationEntryGateway::STATE,
            DB::table('telegram_interaction_sessions')
                ->where('telegram_account_id', $accountId)
                ->value('state'),
        );
    }
}
