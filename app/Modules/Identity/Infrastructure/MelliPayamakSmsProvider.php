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

final readonly class MelliPayamakSmsProvider implements SmsProvider
{
    private const ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';

    public function __construct(
        private Factory $http,
        private MelliPayamakSmsConfiguration $configuration,
        private SmsOtpMessageRenderer $renderer,
    ) {}

    public function code(): string
    {
        return 'melli_payamak';
    }

    public function sendOtp(SmsOtpMessage $message): SmsDeliveryResult
    {
        try {
            $text = $this->renderer->render($message);
        } catch (Throwable) {
            return SmsDeliveryResult::definitiveFailure('sms_template_invalid');
        }

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->timeout($this->configuration->timeoutSeconds)
                ->connectTimeout(min(5, $this->configuration->timeoutSeconds))
                ->post(self::ENDPOINT, [
                    'username' => $this->configuration->username,
                    'password' => $this->configuration->password,
                    'to' => $message->destination->national(),
                    'from' => $this->configuration->sender,
                    'text' => $text,
                    'isflash' => false,
                ]);
        } catch (ConnectionException) {
            return SmsDeliveryResult::uncertain('melli_transport_error');
        } catch (Throwable) {
            return SmsDeliveryResult::uncertain('melli_transport_error');
        }

        return $this->normalize($response);
    }

    private function normalize(Response $response): SmsDeliveryResult
    {
        $httpStatus = $response->status();

        if ($httpStatus === 429) {
            return SmsDeliveryResult::definitiveFailure('melli_rate_limited');
        }

        if (in_array($httpStatus, [408, 409, 425], true) || $httpStatus >= 500) {
            return SmsDeliveryResult::uncertain('melli_provider_unavailable');
        }

        if (! $response->successful()) {
            return SmsDeliveryResult::definitiveFailure('melli_http_'.$httpStatus);
        }

        $returnValue = $this->extractReturnValue($response->body());

        if ($returnValue === null) {
            return SmsDeliveryResult::uncertain('melli_malformed_response');
        }

        if (preg_match('/\A[1-9][0-9]*\z/', $returnValue) === 1) {
            return SmsDeliveryResult::accepted($returnValue);
        }

        if (preg_match('/\A-?[0-9]+\z/', $returnValue) === 1) {
            return SmsDeliveryResult::definitiveFailure('melli_rejected_'.ltrim($returnValue, '-'));
        }

        return SmsDeliveryResult::uncertain('melli_malformed_response');
    }

    private function extractReturnValue(string $body): ?string
    {
        $candidate = trim($body);

        if ($candidate === '') {
            return null;
        }

        if (preg_match('/\A-?[0-9]+\z/', $candidate) === 1) {
            return $candidate;
        }

        try {
            $decoded = json_decode($candidate, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (is_int($decoded) || is_string($decoded)) {
            $value = trim((string) $decoded);

            return preg_match('/\A-?[0-9]+\z/', $value) === 1 ? $value : null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['Value', 'value', 'ReturnValue', 'return_value', 'SendSimpleSMS2Result'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_int($value) || is_string($value)) {
                $value = trim((string) $value);

                if (preg_match('/\A-?[0-9]+\z/', $value) === 1) {
                    return $value;
                }
            }
        }

        return null;
    }
}
