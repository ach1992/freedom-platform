<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait QuoteServiceSupport
{
    private function offeringConfigurationHash(Connection $connection, int $offeringId, int $version): string
    {
        /** @var string|null $hash */
        $hash = $connection->table('plan_offering_histories')
            ->where('plan_offering_id', $offeringId)
            ->where('version', $version)
            ->lockForUpdate()
            ->value('to_configuration_hash');
        if ($hash === null || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new RuntimeException('Plan offering configuration snapshot is unavailable.');
        }

        return $hash;
    }

    private function requestPayloadHash(
        int $userId,
        int $planOfferingId,
        QuotePricingInput $pricing,
        DateTimeImmutable $expiresAt,
        ?QuoteAgentPricingContext $agentPricingContext,
    ): string {
        $payload = [
            'user_id' => $userId,
            'plan_offering_id' => $planOfferingId,
            'override_source' => $pricing->overrideSource->value,
            'override_reference_code' => $pricing->overrideReferenceCode,
            'override_price_irr' => $pricing->overridePriceIrr,
            'discount_reference_code' => $pricing->discountReferenceCode,
            'discount_irr' => $pricing->discountIrr,
            'expires_at' => $this->databaseDateTime($expiresAt),
        ];
        if ($agentPricingContext !== null) {
            $payload['agent_pricing_actor_user_id'] = $agentPricingContext->actorUserId;
            $payload['agent_pricing_action'] = $agentPricingContext->action->value;
            $payload['agent_pricing_mode'] = 'authoritative';
        }

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new DomainException($label.' must be positive.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertUlid(string $value, string $label): void
    {
        if (strlen($value) !== 26 || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveDatabaseInt(int|string $value, string $label): int
    {
        $integer = $this->databaseInt($value, $label);
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeDatabaseInt(int|string $value, string $label): int
    {
        $integer = $this->databaseInt($value, $label);
        if ($integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function databaseInt(int|string $value, string $label): int
    {
        if (is_string($value) && preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $value;
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function databaseDateTimeFromString(string $value, string $label): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $parsed;
    }
}
