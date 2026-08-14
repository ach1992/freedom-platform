<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Infrastructure;

use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsCreateRequest;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsPaymentResult;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsTransport;
use App\Modules\Payments\NowPayments\Application\Contracts\NowPaymentsTransportException;
use App\Modules\Payments\NowPayments\Application\NowPaymentsDecimal;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final readonly class HttpNowPaymentsTransport implements NowPaymentsTransport
{
    private const CREATE_ENDPOINT = 'https://api.nowpayments.io/v1/payment';

    private const STATUS_ENDPOINT_PREFIX = 'https://api.nowpayments.io/v1/payment/';

    /** @var list<string> */
    private const STATUSES = [
        'waiting',
        'confirming',
        'confirmed',
        'spending',
        'partially_paid',
        'finished',
        'failed',
        'refunded',
        'expired',
    ];

    public function __construct(
        private Factory $http,
        private string $apiKey,
        private int $connectTimeoutSeconds = 5,
        private int $timeoutSeconds = 15,
        private int $maxResponseBytes = 262_144,
    ) {
        if (trim($this->apiKey) === '') {
            throw new DomainException('NOWPayments API key is required.');
        }
        if ($this->connectTimeoutSeconds < 1 || $this->connectTimeoutSeconds > 30
            || $this->timeoutSeconds < 1 || $this->timeoutSeconds > 120
            || $this->maxResponseBytes < 1024 || $this->maxResponseBytes > 1_048_576) {
            throw new DomainException('NOWPayments HTTP configuration is invalid.');
        }
    }

    public function create(NowPaymentsCreateRequest $request): NowPaymentsPaymentResult
    {
        $body = $this->createBody($request);
        try {
            $response = $this->client()
                ->withBody($body, 'application/json')
                ->post(self::CREATE_ENDPOINT);
        } catch (ConnectionException $exception) {
            throw new NowPaymentsTransportException('NOWPayments create request outcome is uncertain.', true, $exception);
        }

        return $this->parseResponse($response, true);
    }

    public function status(string $providerPaymentId): NowPaymentsPaymentResult
    {
        $this->assertProviderPaymentId($providerPaymentId);
        try {
            $response = $this->client()->get(self::STATUS_ENDPOINT_PREFIX.$providerPaymentId);
        } catch (ConnectionException $exception) {
            throw new NowPaymentsTransportException('NOWPayments status lookup is unavailable.', false, $exception);
        }

        return $this->parseResponse($response, false);
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])
            ->withOptions([
                'allow_redirects' => false,
                'verify' => true,
                'on_headers' => function (ResponseInterface $response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > $this->maxResponseBytes) {
                        throw new RuntimeException('NOWPayments response exceeds the configured size limit.');
                    }
                },
            ])
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds);
    }

    private function createBody(NowPaymentsCreateRequest $request): string
    {
        $price = NowPaymentsDecimal::jsonNumber($request->priceAmountUsd);
        $this->assertCurrency($request->payCurrency, 'NOWPayments pay currency');
        $this->assertToken($request->orderId, 'NOWPayments order ID', 4, 128);
        if (strlen($request->orderDescription) < 1 || strlen($request->orderDescription) > 256) {
            throw new DomainException('NOWPayments order description is invalid.');
        }
        $this->assertCallbackUrl($request->ipnCallbackUrl);

        $placeholder = '__NOWPAYMENTS_PRICE_AMOUNT__';
        $encoded = json_encode([
            'price_amount' => $placeholder,
            'price_currency' => 'usd',
            'pay_currency' => strtolower($request->payCurrency),
            'ipn_callback_url' => $request->ipnCallbackUrl,
            'order_id' => $request->orderId,
            'order_description' => $request->orderDescription,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $needle = json_encode($placeholder, JSON_THROW_ON_ERROR);
        $body = str_replace($needle, $price, $encoded, $count);
        if ($count !== 1) {
            throw new RuntimeException('NOWPayments request JSON price encoding failed.');
        }

        return $body;
    }

    private function parseResponse(Response $response, bool $mutation): NowPaymentsPaymentResult
    {
        if ($response->serverError()) {
            throw new NowPaymentsTransportException(
                $mutation ? 'NOWPayments create request outcome is uncertain.' : 'NOWPayments status lookup is unavailable.',
                $mutation,
            );
        }
        if (! $response->successful()) {
            throw new NowPaymentsTransportException('NOWPayments request was rejected.', false);
        }
        $body = $response->body();
        if ($body === '' || strlen($body) > $this->maxResponseBytes) {
            throw new NowPaymentsTransportException(
                $mutation ? 'NOWPayments create response is uncertain.' : 'NOWPayments status response is invalid.',
                $mutation,
            );
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable $exception) {
            throw new NowPaymentsTransportException(
                $mutation ? 'NOWPayments create response is uncertain.' : 'NOWPayments status response is invalid.',
                $mutation,
                $exception,
            );
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new NowPaymentsTransportException('NOWPayments returned an invalid payment payload.', $mutation);
        }

        $paymentId = $this->scalarString($payload['payment_id'] ?? null, 'NOWPayments payment ID', 1, 64);
        $this->assertProviderPaymentId($paymentId);
        $status = $this->scalarString($payload['payment_status'] ?? null, 'NOWPayments payment status', 3, 32);
        if (! in_array($status, self::STATUSES, true)) {
            throw new NowPaymentsTransportException('NOWPayments returned an unknown payment status.', $mutation);
        }
        $priceAmount = $this->rawDecimal($body, $payload, 'price_amount', true, NowPaymentsDecimal::PRICE_PRECISION);
        $priceCurrency = strtoupper($this->scalarString($payload['price_currency'] ?? null, 'NOWPayments price currency', 2, 16));
        $payCurrency = strtolower($this->scalarString($payload['pay_currency'] ?? null, 'NOWPayments pay currency', 2, 32));
        $this->assertCurrency($payCurrency, 'NOWPayments pay currency');
        $orderId = $this->scalarString($payload['order_id'] ?? null, 'NOWPayments order ID', 4, 128);
        $payAmount = $this->rawDecimal($body, $payload, 'pay_amount', false, 18);
        $actuallyPaid = $this->rawDecimal($body, $payload, 'actually_paid', false, 18);
        $payAddress = $this->nullableScalarString($payload['pay_address'] ?? null, 256);

        return new NowPaymentsPaymentResult(
            $paymentId,
            $status,
            $priceAmount,
            $priceCurrency,
            $payAmount,
            $actuallyPaid,
            $payCurrency,
            $payAddress,
            $orderId,
            $this->nullableProviderDate($payload['created_at'] ?? null),
            $this->nullableProviderDate($payload['updated_at'] ?? null),
            hash('sha256', $body),
        );
    }

    /** @param array<string,mixed> $payload */
    private function rawDecimal(
        string $body,
        array $payload,
        string $field,
        bool $required,
        int $precision,
    ): ?string {
        $key = preg_quote($field, '/');
        $pattern = '/"'.$key.'"\s*:\s*(?:"([0-9]+(?:\.[0-9]+)?)"|([0-9]+(?:\.[0-9]+)?))(?=\s*[,}])/';
        $count = preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);
        if ($count === false || $count > 1) {
            throw new RuntimeException('NOWPayments monetary field is ambiguous: '.$field);
        }
        if ($count === 0) {
            if (! $required && (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '')) {
                return null;
            }
            throw new RuntimeException('NOWPayments monetary field is missing or invalid: '.$field);
        }
        $raw = $matches[0][1] !== '' ? $matches[0][1] : $matches[0][2];

        return NowPaymentsDecimal::normalize($raw, $precision);
    }

    private function nullableProviderDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen($value) > 64) {
            throw new RuntimeException('NOWPayments provider timestamp is invalid.');
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new RuntimeException('NOWPayments provider timestamp is invalid.', 0, $exception);
        }
    }

    private function scalarString(mixed $value, string $label, int $minimum, int $maximum): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException($label.' is invalid.');
        }
        $normalized = (string) $value;
        if (strlen($normalized) < $minimum || strlen($normalized) > $maximum) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }

    private function nullableScalarString(mixed $value, int $maximum): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('NOWPayments optional provider string is invalid.');
        }

        return $value;
    }

    private function assertProviderPaymentId(string $value): void
    {
        if (preg_match('/\A[1-9][0-9]{0,63}\z/', $value) !== 1) {
            throw new DomainException('NOWPayments provider payment ID is invalid.');
        }
    }

    private function assertCurrency(string $value, string $label): void
    {
        if (preg_match('/\A[a-zA-Z0-9_-]{2,32}\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum
            || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertCallbackUrl(string $value): void
    {
        if (strlen($value) > 512 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new DomainException('NOWPayments callback URL is invalid.');
        }
        $parts = parse_url($value);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new DomainException('NOWPayments callback URL must be a server-controlled HTTPS URL.');
        }
    }
}
