<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\SmsOtpMessageRenderer;
use App\Modules\Identity\Application\Contracts\SmsProvider;
use App\Modules\Identity\Application\SmsDeliveryResult;
use App\Modules\Identity\Application\SmsOtpMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

final readonly class KavenegarSmsProvider implements SmsProvider
{
    private const API_BASE_URL = 'https://api.kavenegar.com/v1';

    public function __construct(
        private Factory $http,
        private KavenegarSmsConfiguration $configuration,
        private SmsOtpMessageRenderer $renderer,
    ) {}

    public function code(): string
    {
        return 'kavenegar';
    }

    public function sendOtp(SmsOtpMessage $message): SmsDeliveryResult
    {
        try {
            $text = $this->renderer->render($message);
        } catch (Throwable) {
            return SmsDeliveryResult::definitiveFailure('sms_template_invalid');
        }

        $payload = [
            'receptor' => $message->destination->national(),
            'message' => $text,
            'localid' => $this->localId($message->idempotencyKey),
        ];

        if ($this->configuration->sender !== null) {
            $payload['sender'] = $this->configuration->sender;
        }

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->timeout($this->configuration->timeoutSeconds)
                ->connectTimeout(min(5, $this->configuration->timeoutSeconds))
                ->post(
                    self::API_BASE_URL.'/'.$this->configuration->apiKey.'/sms/send.json',
                    $payload,
                );
        } catch (ConnectionException) {
            return SmsDeliveryResult::uncertain('kavenegar_transport_error');
        } catch (Throwable) {
            return SmsDeliveryResult::uncertain('kavenegar_transport_error');
        }

        return $this->normalize($response);
    }

    private function normalize(Response $response): SmsDeliveryResult
    {
        $httpStatus = $response->status();

        if ($httpStatus === 429) {
            return SmsDeliveryResult::definitiveFailure('kavenegar_rate_limited');
        }

        if (in_array($httpStatus, [408, 409, 425], true) || $httpStatus >= 500) {
            return SmsDeliveryResult::uncertain('kavenegar_provider_unavailable');
        }

        $decoded = $this->decode($response->body());
        $providerStatus = $this->providerStatus($decoded);

        if ($providerStatus === null) {
            return $response->successful()
                ? SmsDeliveryResult::uncertain('kavenegar_malformed_response')
                : SmsDeliveryResult::definitiveFailure('kavenegar_http_'.$httpStatus);
        }

        if ($providerStatus !== 200) {
            if ($providerStatus === 409 || $providerStatus >= 500) {
                return SmsDeliveryResult::uncertain('kavenegar_provider_'.$providerStatus);
            }

            return SmsDeliveryResult::definitiveFailure('kavenegar_rejected_'.$providerStatus);
        }

        if (! $response->successful()) {
            return SmsDeliveryResult::uncertain('kavenegar_http_status_mismatch');
        }

        $messageId = $this->messageId($decoded);

        return $messageId === null
            ? SmsDeliveryResult::uncertain('kavenegar_malformed_response')
            : SmsDeliveryResult::accepted($messageId);
    }

    /** @return array<string, mixed>|null */
    private function decode(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed>|null $decoded */
    private function providerStatus(?array $decoded): ?int
    {
        $return = is_array($decoded['return'] ?? null) ? $decoded['return'] : null;
        $status = $return['status'] ?? null;

        if (is_int($status)) {
            return $status;
        }

        return is_string($status) && preg_match('/\A[0-9]{3}\z/', $status) === 1
            ? (int) $status
            : null;
    }

    /** @param array<string, mixed>|null $decoded */
    private function messageId(?array $decoded): ?string
    {
        $entries = $decoded['entries'] ?? null;

        if (! is_array($entries) || ! isset($entries[0]) || ! is_array($entries[0])) {
            return null;
        }

        $messageId = $entries[0]['messageid'] ?? null;

        if (! is_int($messageId) && ! is_string($messageId)) {
            return null;
        }

        $normalized = trim((string) $messageId);

        return preg_match('/\A[1-9][0-9]*\z/', $normalized) === 1 ? $normalized : null;
    }

    private function localId(string $idempotencyKey): string
    {
        return (string) hexdec(substr(hash('sha256', $idempotencyKey), 0, 12));
    }
}
