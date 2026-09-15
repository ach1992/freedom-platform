<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use App\Modules\Identity\Application\Contracts\SmsDeliveryAttemptRecorder;
use App\Modules\Identity\Application\FallbackSmsDispatcher;
use App\Modules\Identity\Application\SmsDeliveryAttempt;
use App\Modules\Identity\Application\SmsOtpMessage;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\SmsDeliveryStatus;
use App\Modules\Identity\Infrastructure\FakeSmsProvider;
use App\Modules\Identity\Infrastructure\KavenegarSmsConfiguration;
use App\Modules\Identity\Infrastructure\KavenegarSmsProvider;
use App\Modules\Identity\Infrastructure\LocalizedSmsOtpMessageRenderer;
use App\Modules\Identity\Infrastructure\MelliPayamakSmsConfiguration;
use App\Modules\Identity\Infrastructure\MelliPayamakSmsProvider;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** @requirement ONB-004 SEC-003 INT-002 */
final class SmsProviderHttpContractTest extends TestCase
{
    public function test_localized_renderer_uses_the_message_locale_and_exactly_one_code(): void
    {
        $renderer = $this->renderer();

        self::assertSame('کد تأیید شما: 654321', $renderer->render($this->message('fa')));
        self::assertSame('Your verification code: 654321', $renderer->render($this->message('en')));
    }

    public function test_kavenegar_accepts_a_documented_success_and_sends_a_stable_local_id(): void
    {
        Http::fake([
            'https://api.kavenegar.com/*' => Http::response([
                'return' => ['status' => 200, 'message' => 'accepted'],
                'entries' => [['messageid' => 8792343]],
            ]),
        ]);

        $provider = $this->kavenegar();
        $result = $provider->sendOtp($this->message());

        self::assertSame(SmsDeliveryStatus::Accepted, $result->status);
        self::assertSame('8792343', $result->providerMessageId);
        Http::assertSent(function (Request $request): bool {
            self::assertSame('09123456789', $request['receptor']);
            self::assertSame('کد تأیید شما: 654321', $request['message']);
            self::assertSame('10004346', $request['sender']);
            self::assertMatchesRegularExpression('/\A[0-9]{1,15}\z/', (string) $request['localid']);

            return true;
        });
    }

    public function test_kavenegar_classifies_rejection_rate_limit_malformed_and_uncertain_results(): void
    {
        Http::fakeSequence()
            ->push(['return' => ['status' => 403, 'message' => 'invalid']], 403)
            ->push([], 429)
            ->push(['unexpected' => true], 200)
            ->push([], 503);

        $provider = $this->kavenegar();

        self::assertSame(SmsDeliveryStatus::DefinitiveFailure, $provider->sendOtp($this->message())->status);
        self::assertSame(SmsDeliveryStatus::DefinitiveFailure, $provider->sendOtp($this->message())->status);
        self::assertSame(SmsDeliveryStatus::Uncertain, $provider->sendOtp($this->message())->status);
        self::assertSame(SmsDeliveryStatus::Uncertain, $provider->sendOtp($this->message())->status);
    }

    public function test_kavenegar_transport_failure_is_uncertain_and_does_not_use_fallback(): void
    {
        Http::fake(fn () => Http::failedConnection());
        $fallback = new FakeSmsProvider('fallback_test');
        $dispatcher = new FallbackSmsDispatcher(
            $this->kavenegar(),
            $fallback,
            $this->nullRecorder(),
        );

        $result = $dispatcher->dispatch($this->message());

        self::assertCount(1, $result->attempts);
        self::assertSame(SmsDeliveryStatus::Uncertain, $result->finalAttempt()->result->status);
        self::assertSame(0, $fallback->sentCount());
    }

    public function test_kavenegar_definitive_rejection_allows_fallback(): void
    {
        Http::fake([
            '*' => Http::response(['return' => ['status' => 403, 'message' => 'invalid']], 403),
        ]);
        $fallback = new FakeSmsProvider('fallback_test');
        $dispatcher = new FallbackSmsDispatcher(
            $this->kavenegar(),
            $fallback,
            $this->nullRecorder(),
        );

        $result = $dispatcher->dispatch($this->message());

        self::assertCount(2, $result->attempts);
        self::assertSame(SmsDeliveryStatus::Accepted, $result->finalAttempt()->result->status);
        self::assertSame(1, $fallback->sentCount());
    }

    public function test_melli_payamak_accepts_plain_and_documented_wrapped_success_values(): void
    {
        Http::fakeSequence()
            ->push('123456789', 200)
            ->push(['Value' => '987654321'], 200);

        $provider = $this->melliPayamak();
        $plain = $provider->sendOtp($this->message());
        $wrapped = $provider->sendOtp($this->message());

        self::assertSame(SmsDeliveryStatus::Accepted, $plain->status);
        self::assertSame('123456789', $plain->providerMessageId);
        self::assertSame(SmsDeliveryStatus::Accepted, $wrapped->status);
        self::assertSame('987654321', $wrapped->providerMessageId);
        Http::assertSent(function (Request $request): bool {
            self::assertSame('09123456789', $request['to']);
            self::assertSame('5000123456', $request['from']);
            self::assertSame('کد تأیید شما: 654321', $request['text']);
            self::assertFalse((bool) $request['isflash']);

            return true;
        });
    }

    public function test_melli_payamak_classifies_rejection_rate_limit_malformed_and_uncertain_results(): void
    {
        Http::fakeSequence()
            ->push('-110', 200)
            ->push([], 429)
            ->push('not-a-provider-result', 200)
            ->push([], 503);

        $provider = $this->melliPayamak();

        self::assertSame(SmsDeliveryStatus::DefinitiveFailure, $provider->sendOtp($this->message())->status);
        self::assertSame(SmsDeliveryStatus::DefinitiveFailure, $provider->sendOtp($this->message())->status);
        self::assertSame(SmsDeliveryStatus::Uncertain, $provider->sendOtp($this->message())->status);
        self::assertSame(SmsDeliveryStatus::Uncertain, $provider->sendOtp($this->message())->status);
    }

    public function test_provider_configuration_debug_output_never_contains_credentials(): void
    {
        $melli = MelliPayamakSmsConfiguration::fromArray([
            'username' => 'private-user',
            'password' => 'private-password',
            'sender' => '5000123456',
        ]);
        $kavenegar = KavenegarSmsConfiguration::fromArray([
            'api_key' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456',
            'sender' => '10004346',
        ]);
        $debug = print_r([$melli, $kavenegar], true);

        self::assertStringNotContainsString('private-user', $debug);
        self::assertStringNotContainsString('private-password', $debug);
        self::assertStringNotContainsString('ABCDEFGHIJKLMNOPQRSTUVWXYZ123456', $debug);
        self::assertStringNotContainsString('5000123456', $debug);
        self::assertStringNotContainsString('10004346', $debug);
    }

    private function kavenegar(): KavenegarSmsProvider
    {
        return new KavenegarSmsProvider(
            $this->app->make(Factory::class),
            KavenegarSmsConfiguration::fromArray([
                'api_key' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ123456',
                'sender' => '10004346',
                'timeout_seconds' => 10,
            ]),
            $this->renderer(),
        );
    }

    private function melliPayamak(): MelliPayamakSmsProvider
    {
        return new MelliPayamakSmsProvider(
            $this->app->make(Factory::class),
            MelliPayamakSmsConfiguration::fromArray([
                'username' => 'test-user',
                'password' => 'test-password',
                'sender' => '5000123456',
                'timeout_seconds' => 10,
            ]),
            $this->renderer(),
        );
    }

    private function renderer(): LocalizedSmsOtpMessageRenderer
    {
        return new LocalizedSmsOtpMessageRenderer($this->app->make(Translator::class));
    }

    private function message(string $locale = 'fa'): SmsOtpMessage
    {
        return new SmsOtpMessage(
            IranianMobileNumber::fromString('09123456789'),
            '654321',
            'phone_verification',
            'phone-verification:test:0001',
            locale: $locale,
        );
    }

    private function nullRecorder(): SmsDeliveryAttemptRecorder
    {
        return new class implements SmsDeliveryAttemptRecorder
        {
            public function record(SmsOtpMessage $message, SmsDeliveryAttempt $attempt, int $sequence): void {}
        };
    }
}
