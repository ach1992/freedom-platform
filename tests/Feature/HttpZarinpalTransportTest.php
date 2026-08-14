<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Zarinpal\Infrastructure\HttpZarinpalTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** @requirement IPG-001 INT-001 INT-002 SEC-002 QUA-001 */
final class HttpZarinpalTransportTest extends TestCase
{
    private const MERCHANT = '00000000-0000-0000-0000-000000000000';

    public function test_request_uses_fixed_official_endpoint_irr_and_disables_provider_auto_verify(): void
    {
        $authority = 'A'.str_repeat('1', 35);
        Http::fake([
            'https://payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => ['code' => 100, 'authority' => $authority, 'fee_type' => 'Merchant', 'fee' => 0],
                'errors' => [],
            ], 200),
        ]);

        $result = $this->app->make(HttpZarinpalTransport::class)->request(
            self::MERCHANT,
            1_000_000,
            'https://example.test/payments/zarinpal/callback',
            'Freedom purchase 01H00000000000000000000000',
            '01H00000000000000000000000',
        );

        self::assertTrue($result->accepted);
        self::assertFalse($result->uncertain);
        self::assertSame($authority, $result->authority);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://payment.zarinpal.com/pg/v4/payment/request.json'
                && $request->method() === 'POST'
                && ($data['merchant_id'] ?? null) === self::MERCHANT
                && ($data['amount'] ?? null) === 1_000_000
                && ($data['currency'] ?? null) === 'IRR'
                && ($data['callback_url'] ?? null) === 'https://example.test/payments/zarinpal/callback'
                && data_get($data, 'metadata.order_id') === '01H00000000000000000000000'
                && data_get($data, 'metadata.auto_verify') === false;
        });
    }

    public function test_request_server_error_is_uncertain_instead_of_retryable_rejection(): void
    {
        Http::fake([
            'https://payment.zarinpal.com/pg/v4/payment/request.json' => Http::response(['errors' => ['code' => -9]], 503),
        ]);

        $result = $this->app->make(HttpZarinpalTransport::class)->request(
            self::MERCHANT,
            1_000_000,
            'https://example.test/payments/zarinpal/callback',
            'Freedom purchase 01H00000000000000000000000',
            '01H00000000000000000000000',
        );

        self::assertFalse($result->accepted);
        self::assertTrue($result->uncertain);
    }

    public function test_verify_accepts_first_or_already_verified_success_but_requires_ref_id(): void
    {
        Http::fake([
            'https://payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => ['code' => 101, 'ref_id' => 123456789, 'fee_type' => 'Merchant', 'fee' => 0],
                'errors' => [],
            ], 200),
        ]);

        $result = $this->app->make(HttpZarinpalTransport::class)->verify(
            self::MERCHANT,
            1_000_000,
            'A'.str_repeat('1', 35),
        );

        self::assertTrue($result->verified);
        self::assertFalse($result->uncertain);
        self::assertSame(101, $result->providerCode);
        self::assertSame('123456789', $result->refId);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://payment.zarinpal.com/pg/v4/payment/verify.json'
            && $request->method() === 'POST'
            && ($request->data()['amount'] ?? null) === 1_000_000
            && ($request->data()['authority'] ?? null) === 'A'.str_repeat('1', 35));
    }

    public function test_inquiry_and_unverified_are_read_only_reconciliation_inputs(): void
    {
        $authority = 'A'.str_repeat('2', 35);
        Http::fake([
            'https://payment.zarinpal.com/pg/v4/payment/inquiry.json' => Http::response([
                'data' => ['code' => 100, 'status' => 'PAID'],
                'errors' => [],
            ], 200),
            'https://payment.zarinpal.com/pg/v4/payment/unVerified.json' => Http::response([
                'data' => ['authorities' => [[
                    'authority' => $authority,
                    'amount' => 1_000_000,
                    'callback_url' => 'https://example.test/payments/zarinpal/callback',
                    'date' => '2026-08-14 08:01:00',
                ]]],
                'errors' => [],
            ], 200),
        ]);

        $transport = $this->app->make(HttpZarinpalTransport::class);
        $inquiry = $transport->inquiry(self::MERCHANT, $authority);
        $unverified = $transport->unverified(self::MERCHANT);

        self::assertTrue($inquiry->available);
        self::assertSame('PAID', $inquiry->status);
        self::assertCount(1, $unverified);
        self::assertSame($authority, $unverified[0]->authority);
        self::assertSame(1_000_000, $unverified[0]->amount);
    }
}
