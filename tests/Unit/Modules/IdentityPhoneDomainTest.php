<?php

declare(strict_types=1);

namespace Tests\Unit\Modules;

use App\Modules\Identity\Application\Contracts\SmsDeliveryAttemptRecorder;
use App\Modules\Identity\Application\FallbackSmsDispatcher;
use App\Modules\Identity\Application\SmsDeliveryAttempt;
use App\Modules\Identity\Application\SmsDeliveryResult;
use App\Modules\Identity\Application\SmsOtpMessage;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\PhoneVerificationMethod;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use App\Modules\Identity\Domain\SmsDeliveryStatus;
use App\Modules\Identity\Infrastructure\FakeSmsProvider;
use App\Modules\Identity\Infrastructure\HmacPhoneLookupHasher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement ONB-004 ONB-005 SEC-003 DAT-003 INT-002 */
final class IdentityPhoneDomainTest extends TestCase
{
    public function test_iranian_mobile_number_normalizes_supported_formats_and_digit_sets(): void
    {
        foreach ([
            '09123456789',
            '989123456789',
            '+989123456789',
            '۰۹۱۲۳۴۵۶۷۸۹',
            '٠٩١٢٣٤٥٦٧٨٩',
            '۰۹۱۲ ۳۴۵-۶۷۸۹',
            '+98 (912) 345-6789',
        ] as $input) {
            $number = IranianMobileNumber::fromString($input);
            $this->assertSame('+989123456789', $number->e164());
            $this->assertSame('09123456789', $number->national());
            $this->assertSame('+9891****6789', $number->masked());
        }
    }

    public function test_iranian_mobile_number_rejects_invalid_structures(): void
    {
        foreach (['', '9123456789', '00989123456789', '+981212345678', '0912345678', '091234567890', '09123x56789'] as $input) {
            try {
                IranianMobileNumber::fromString($input);
                $this->fail('Invalid Iranian mobile number was accepted: '.$input);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_phone_verification_policy_distinguishes_methods_and_combined_satisfaction(): void
    {
        $this->assertTrue(PhoneVerificationPolicy::TelegramContactOnly->accepts(PhoneVerificationMethod::TelegramContact));
        $this->assertFalse(PhoneVerificationPolicy::TelegramContactOnly->accepts(PhoneVerificationMethod::SmsOtp));
        $this->assertTrue(PhoneVerificationPolicy::Either->isSatisfied(true, false));
        $this->assertTrue(PhoneVerificationPolicy::Either->isSatisfied(false, true));
        $this->assertFalse(PhoneVerificationPolicy::Both->isSatisfied(true, false));
        $this->assertTrue(PhoneVerificationPolicy::Both->isSatisfied(true, true));
        $this->assertTrue(PhoneVerificationPolicy::None->isSatisfied(false, false));
    }

    public function test_phone_lookup_hash_is_keyed_versioned_and_deterministic(): void
    {
        $number = IranianMobileNumber::fromString('09123456789');
        $first = new HmacPhoneLookupHasher(str_repeat('a', 32), 3);
        $second = new HmacPhoneLookupHasher(str_repeat('b', 32), 4);

        $this->assertSame($first->hash($number)->value, $first->hash($number)->value);
        $this->assertNotSame($first->hash($number)->value, $second->hash($number)->value);
        $this->assertSame(3, $first->hash($number)->keyVersion);
        $this->assertSame(64, strlen($first->hashOpaque('opaque-test-value')));
    }

    public function test_sms_fallback_occurs_only_after_definitive_failure(): void
    {
        $message = new SmsOtpMessage(
            IranianMobileNumber::fromString('09123456789'),
            '123456',
            'phone_verification',
            'phone-verification:unit:0001',
        );

        $acceptedRecorder = new CollectingSmsAttemptRecorder;
        $acceptedPrimary = new FakeSmsProvider('primary', [SmsDeliveryResult::accepted('primary-id')]);
        $acceptedFallback = new FakeSmsProvider('fallback');
        $accepted = (new FallbackSmsDispatcher($acceptedPrimary, $acceptedFallback, $acceptedRecorder))->dispatch($message);
        $this->assertCount(1, $accepted->attempts);
        $this->assertSame(0, $acceptedFallback->sentCount());

        $uncertainRecorder = new CollectingSmsAttemptRecorder;
        $uncertainPrimary = new FakeSmsProvider('primary', [SmsDeliveryResult::uncertain('timeout')]);
        $uncertainFallback = new FakeSmsProvider('fallback');
        $uncertain = (new FallbackSmsDispatcher($uncertainPrimary, $uncertainFallback, $uncertainRecorder))->dispatch($message);
        $this->assertCount(1, $uncertain->attempts);
        $this->assertSame(SmsDeliveryStatus::Uncertain, $uncertain->finalAttempt()->result->status);
        $this->assertSame(0, $uncertainFallback->sentCount());

        $failureRecorder = new CollectingSmsAttemptRecorder;
        $failedPrimary = new FakeSmsProvider('primary', [SmsDeliveryResult::definitiveFailure('rejected')]);
        $successfulFallback = new FakeSmsProvider('fallback', [SmsDeliveryResult::accepted('fallback-id')]);
        $fallback = (new FallbackSmsDispatcher($failedPrimary, $successfulFallback, $failureRecorder))->dispatch($message);
        $this->assertCount(2, $fallback->attempts);
        $this->assertSame(SmsDeliveryStatus::Accepted, $fallback->finalAttempt()->result->status);
        $this->assertSame(1, $successfulFallback->sentCount());
        $this->assertCount(2, $failureRecorder->attempts);
    }
}

final class CollectingSmsAttemptRecorder implements SmsDeliveryAttemptRecorder
{
    /** @var list<array{message: SmsOtpMessage, attempt: SmsDeliveryAttempt, sequence: int}> */
    public array $attempts = [];

    public function record(SmsOtpMessage $message, SmsDeliveryAttempt $attempt, int $sequence): void
    {
        $this->attempts[] = compact('message', 'attempt', 'sequence');
    }
}
