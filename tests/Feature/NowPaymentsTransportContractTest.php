<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsCreateRequest;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsTransportException;
use App\Modules\Payments\NowPayments\Application\NowPaymentsDecimal;
use App\Modules\Payments\NowPayments\Application\NowPaymentsIpnVerifier;
use App\Modules\Payments\NowPayments\Infrastructure\HttpNowPaymentsTransport;
use DomainException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** @requirement IPG-002 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
final class NowPaymentsTransportContractTest extends TestCase
{
    public function test_shared_rate_proxy_math_rounds_up_without_float(): void
    {
        self::assertSame('11.11111112', NowPaymentsDecimal::irrToUsdProxy(10_000_000, '900000'));
        self::assertSame('1.00000000', NowPaymentsDecimal::irrToUsdProxy(900_000, '900000'));
    }

    public function test_create_uses_fixed_official_endpoint_api_key_and_unquoted_decimal_json_number(): void
    {
        $responseBody = '{"payment_id":900001,"payment_status":"waiting","pay_address":"0x1111111111111111111111111111111111111111","price_amount":11.11111112,"price_currency":"usd","pay_amount":"12.500000000000000000","pay_currency":"usdtbsc","order_id":"payment-intent:01J00000000000000000000000","created_at":"2026-08-14T06:30:00Z","updated_at":"2026-08-14T06:30:00Z"}';
        Http::fake([
            'https://api.nowpayments.io/v1/payment' => Http::response($responseBody, 201, ['Content-Type' => 'application/json']),
        ]);
        $transport = new HttpNowPaymentsTransport($this->app->make(Factory::class), 'test-api-key');
        $request = new NowPaymentsCreateRequest(
            '11.11111112',
            'usdtbsc',
            'payment-intent:01J00000000000000000000000',
            'Freedom purchase test',
            'https://payments.example.test/api/payments/nowpayments/ipn',
        );

        $result = $transport->create($request);
        self::assertSame('900001', $result->providerPaymentId);
        self::assertSame('11.11111112', $result->priceAmount);
        self::assertSame('12.500000000000000000', $result->payAmount);
        self::assertSame('USD', $result->priceCurrency);
        self::assertSame('usdtbsc', $result->payCurrency);

        Http::assertSent(static function ($sent): bool {
            $body = $sent->body();

            return $sent->url() === 'https://api.nowpayments.io/v1/payment'
                && $sent->hasHeader('x-api-key', 'test-api-key')
                && str_contains($body, '"price_amount":11.11111112')
                && ! str_contains($body, '"price_amount":"11.11111112"')
                && str_contains($body, '"price_currency":"usd"')
                && str_contains($body, '"pay_currency":"usdtbsc"');
        });
    }

    public function test_create_server_error_is_treated_as_uncertain_and_never_as_safe_retry_signal(): void
    {
        Http::fake([
            'https://api.nowpayments.io/v1/payment' => Http::response(['message' => 'temporary failure'], 503),
        ]);
        $transport = new HttpNowPaymentsTransport($this->app->make(Factory::class), 'test-api-key');

        try {
            $transport->create(new NowPaymentsCreateRequest(
                '11.11111112',
                'usdtbsc',
                'payment-intent:01J00000000000000000000000',
                'Freedom purchase test',
                'https://payments.example.test/api/payments/nowpayments/ipn',
            ));
            self::fail('Expected uncertain NOWPayments create failure.');
        } catch (NowPaymentsTransportException $exception) {
            self::assertTrue($exception->uncertain);
        }
    }

    public function test_status_uses_fixed_payment_lookup_endpoint(): void
    {
        Http::fake([
            'https://api.nowpayments.io/v1/payment/900001' => Http::response([
                'payment_id' => '900001',
                'payment_status' => 'finished',
                'pay_address' => '0x1111111111111111111111111111111111111111',
                'price_amount' => '11.11111112',
                'price_currency' => 'usd',
                'pay_amount' => '12.500000000000000000',
                'actually_paid' => '12.500000000000000000',
                'pay_currency' => 'usdtbsc',
                'order_id' => 'payment-intent:01J00000000000000000000000',
                'created_at' => '2026-08-14T06:30:00Z',
                'updated_at' => '2026-08-14T06:31:00Z',
            ]),
        ]);
        $transport = new HttpNowPaymentsTransport($this->app->make(Factory::class), 'test-api-key');

        $result = $transport->status('900001');
        self::assertSame('finished', $result->paymentStatus);
        self::assertSame('12.500000000000000000', $result->actuallyPaid);
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://api.nowpayments.io/v1/payment/900001');
    }

    public function test_ipn_signature_uses_sorted_json_hmac_sha512_and_rejects_tampering(): void
    {
        $secret = 'nowpayments-test-secret';
        $rawBody = '{"payment_status":"finished","order_id":"payment-intent:01J00000000000000000000000","payment_id":900001}';
        $canonical = '{"order_id":"payment-intent:01J00000000000000000000000","payment_id":900001,"payment_status":"finished"}';
        $signature = hash_hmac('sha512', $canonical, $secret);
        $verifier = new NowPaymentsIpnVerifier($secret);

        $payload = $verifier->verify($rawBody, $signature);
        self::assertSame('900001', $verifier->paymentId($payload));

        $this->expectException(DomainException::class);
        $verifier->verify(str_replace('finished', 'failed', $rawBody), $signature);
    }
}
