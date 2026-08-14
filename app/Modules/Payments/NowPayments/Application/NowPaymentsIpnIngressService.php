<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application;

use App\Shared\Application\Clock;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class NowPaymentsIpnIngressService
{
    /** @var list<string> */
    private const KNOWN_STATUSES = [
        'waiting',
        'confirming',
        'confirmed',
        'spending',
        'sending',
        'partially_paid',
        'finished',
        'failed',
        'refunded',
        'expired',
    ];

    public function __construct(
        private DatabaseManager $database,
        private NowPaymentsPaymentService $payments,
        private Clock $clock,
    ) {}

    /** @requirement IPG-002 SEC-002 INT-001 INT-002 QUA-004 */
    public function handle(string $rawBody, string $signature, string $correlationId): NowPaymentsPaymentReceipt
    {
        $this->assertToken($correlationId, 'NOWPayments IPN correlation ID', 8, 64);
        $configuration = $this->configuration();
        $verifier = new NowPaymentsIpnVerifier(
            $configuration['ipn_secret'],
            $configuration['max_ipn_body_bytes'],
        );
        $payload = $verifier->verify($rawBody, $signature);
        $paymentId = $verifier->paymentId($payload);
        $providerStatus = $this->notificationStatus($payload);
        $rawHash = hash('sha256', $rawBody);

        $connection = $this->database->connection();
        $authority = $connection->table('nowpayments_payment_authorities')
            ->where('provider_payment_id', $paymentId)
            ->first(['id', 'payment_intent_id']);
        if ($authority === null) {
            throw new DomainException('NOWPayments IPN payment is not bound to a local payment intent.');
        }

        $authorityId = $this->positiveInt($authority->id, 'NOWPayments authority ID');
        $intentId = $this->positiveInt($authority->payment_intent_id, 'Payment intent ID');
        $intentPublicId = $connection->table('payment_intents')
            ->where('id', $intentId)
            ->value('public_id');
        if (! is_string($intentPublicId)) {
            throw new RuntimeException('NOWPayments payment intent public ID is unavailable.');
        }

        $connection->transaction(function (Connection $transaction) use (
            $authorityId,
            $paymentId,
            $providerStatus,
            $rawHash,
            $correlationId,
        ): void {
            $exists = $transaction->table('nowpayments_payment_authorities')
                ->where('id', $authorityId)
                ->where('provider_payment_id', $paymentId)
                ->lockForUpdate()
                ->exists();
            if (! $exists) {
                throw new RuntimeException('NOWPayments IPN authority changed during ingestion.');
            }

            $eventKey = 'nowpayments:'.$authorityId.':ipn_received:'.substr($rawHash, 0, 32);
            if ($transaction->table('nowpayments_payment_observations')->where('event_key', $eventKey)->exists()) {
                return;
            }

            try {
                $transaction->table('nowpayments_payment_observations')->insert([
                    'nowpayments_payment_authority_id' => $authorityId,
                    'event_key' => $eventKey,
                    'event_type' => 'ipn_received',
                    'provider_payment_id' => $paymentId,
                    'provider_status' => $providerStatus,
                    'response_hash' => $rawHash,
                    'occurred_at' => $this->timestamp(),
                    'correlation_id' => $correlationId,
                    'created_at' => $this->timestamp(),
                ]);
            } catch (QueryException $exception) {
                if ($transaction->table('nowpayments_payment_observations')->where('event_key', $eventKey)->first(['id']) === null) {
                    throw $exception;
                }
            }
        });

        // A valid signature authenticates the notification only. Capture authority is always
        // reconstructed from the provider's server-to-server payment status endpoint.
        return $this->payments->refresh($intentPublicId, $correlationId);
    }

    /** @param array<string,mixed> $payload */
    private function notificationStatus(array $payload): ?string
    {
        $value = $payload['payment_status'] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $normalized = strtolower(trim($value));

        return in_array($normalized, self::KNOWN_STATUSES, true) ? $normalized : null;
    }

    /** @return array{ipn_secret:string,max_ipn_body_bytes:int} */
    private function configuration(): array
    {
        if (! (bool) config('services.nowpayments.enabled', false)) {
            throw new RuntimeException('NOWPayments payment provider is disabled.');
        }
        $secret = config('services.nowpayments.ipn_secret');
        if (! is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('NOWPayments IPN secret configuration is invalid.');
        }
        $maxIpnBodyBytes = filter_var(
            config('services.nowpayments.max_ipn_body_bytes', 262144),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1024, 'max_range' => 1048576]],
        );
        if ($maxIpnBodyBytes === false) {
            throw new RuntimeException('NOWPayments IPN body limit configuration is invalid.');
        }

        return [
            'ipn_secret' => $secret,
            'max_ipn_body_bytes' => $maxIpnBodyBytes,
        ];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function positiveInt(mixed $value, string $label): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new RuntimeException($label.' is invalid.');
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum
            || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }
}
