<?php

declare(strict_types=1);

namespace App\Modules\Payments\Zarinpal\Infrastructure;

use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalInquiryResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalRequestResult;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalTransport;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalUnverifiedCandidate;
use App\Modules\Payments\Zarinpal\Application\Contracts\ZarinpalVerifyResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

final readonly class HttpZarinpalTransport implements ZarinpalTransport
{
    private const REQUEST_URL = 'https://payment.zarinpal.com/pg/v4/payment/request.json';

    private const VERIFY_URL = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';

    private const INQUIRY_URL = 'https://payment.zarinpal.com/pg/v4/payment/inquiry.json';

    private const UNVERIFIED_URL = 'https://payment.zarinpal.com/pg/v4/payment/unVerified.json';

    public function __construct(private Factory $http) {}

    public function request(
        string $merchantId,
        int $amountIrr,
        string $callbackUrl,
        string $description,
        string $orderId,
    ): ZarinpalRequestResult {
        $response = $this->post(self::REQUEST_URL, [
            'merchant_id' => $merchantId,
            'amount' => $amountIrr,
            'currency' => 'IRR',
            'description' => $description,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'order_id' => $orderId,
                'auto_verify' => false,
            ],
        ]);
        if ($response === null || $response->serverError()) {
            return ZarinpalRequestResult::uncertain();
        }

        $payload = $this->json($response);
        if ($payload === null) {
            return $response->successful()
                ? ZarinpalRequestResult::uncertain()
                : ZarinpalRequestResult::rejected();
        }
        $code = $this->providerCode($payload);
        $authority = data_get($payload, 'data.authority');
        if ($code === 100 && is_string($authority) && $this->validAuthority($authority)) {
            return ZarinpalRequestResult::accepted($authority, $code);
        }

        return ZarinpalRequestResult::rejected($code);
    }

    public function verify(string $merchantId, int $amountIrr, string $authority): ZarinpalVerifyResult
    {
        $response = $this->post(self::VERIFY_URL, [
            'merchant_id' => $merchantId,
            'amount' => $amountIrr,
            'authority' => $authority,
        ]);
        if ($response === null || $response->serverError()) {
            return ZarinpalVerifyResult::uncertain();
        }

        $payload = $this->json($response);
        if ($payload === null) {
            return $response->successful()
                ? ZarinpalVerifyResult::uncertain()
                : ZarinpalVerifyResult::rejected();
        }
        $code = $this->providerCode($payload);
        $refId = data_get($payload, 'data.ref_id');
        if (in_array($code, [100, 101], true)
            && (is_int($refId) || (is_string($refId) && preg_match('/\A[0-9]{1,32}\z/', $refId) === 1))) {
            return ZarinpalVerifyResult::verified((string) $refId, $code);
        }

        return ZarinpalVerifyResult::rejected($code);
    }

    public function inquiry(string $merchantId, string $authority): ZarinpalInquiryResult
    {
        $response = $this->post(self::INQUIRY_URL, [
            'merchant_id' => $merchantId,
            'authority' => $authority,
        ]);
        if ($response === null || ! $response->successful()) {
            return ZarinpalInquiryResult::unavailable();
        }

        $payload = $this->json($response);
        if ($payload === null) {
            return ZarinpalInquiryResult::unavailable();
        }
        $status = data_get($payload, 'data.status');
        $code = $this->providerCode($payload);
        if (! is_string($status)) {
            return ZarinpalInquiryResult::unavailable($code);
        }

        return ZarinpalInquiryResult::available($status, $code ?? 100);
    }

    public function unverified(string $merchantId): array
    {
        $response = $this->post(self::UNVERIFIED_URL, ['merchant_id' => $merchantId]);
        if ($response === null || ! $response->successful()) {
            return [];
        }

        $payload = $this->json($response);
        $rows = $payload === null ? null : data_get($payload, 'data.authorities');
        if (! is_array($rows)) {
            return [];
        }

        $candidates = [];
        foreach (array_slice($rows, 0, 100) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $authority = $row['authority'] ?? null;
            $amount = $row['amount'] ?? null;
            $callbackUrl = $row['callback_url'] ?? null;
            $providerDate = $row['date'] ?? null;
            if (! is_string($authority)
                || ! $this->validAuthority($authority)
                || filter_var($amount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || ! is_string($callbackUrl)
                || strlen($callbackUrl) > 2048
                || ! is_string($providerDate)
                || strlen($providerDate) > 64) {
                continue;
            }
            $candidates[] = new ZarinpalUnverifiedCandidate(
                $authority,
                (int) $amount,
                $callbackUrl,
                $providerDate,
            );
        }

        return $candidates;
    }

    private function post(string $url, array $payload): ?Response
    {
        try {
            return $this->http
                ->acceptJson()
                ->asJson()
                ->connectTimeout($this->positiveConfigInt('services.zarinpal.connect_timeout_seconds', 5))
                ->timeout($this->positiveConfigInt('services.zarinpal.timeout_seconds', 15))
                ->post($url, $payload);
        } catch (ConnectionException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function json(Response $response): ?array
    {
        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /** @param array<string, mixed> $payload */
    private function providerCode(array $payload): ?int
    {
        $value = data_get($payload, 'data.code', data_get($payload, 'errors.code'));
        $code = filter_var($value, FILTER_VALIDATE_INT);

        return $code === false ? null : $code;
    }

    private function validAuthority(string $authority): bool
    {
        return strlen($authority) <= 64 && preg_match('/\AA[A-Za-z0-9]{20,63}\z/', $authority) === 1;
    }

    private function positiveConfigInt(string $key, int $default): int
    {
        $value = filter_var(config($key, $default), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);

        return $value === false ? $default : $value;
    }
}
