<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use RuntimeException;

/**
 * @phpstan-type MethodVersionRow object{id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,display_priority:int|string,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,allow_degraded_health:int|bool|string,configuration_snapshot:string,configuration_hash:string,method_public_id:string,method_code:string,kind:string,provider_code:string|null}
 * @phpstan-type RuleVersionRow object{id:int|string,payment_eligibility_rule_id:int|string,payment_method_id:int|string,mutation_payload_hash:string,version:int|string,state:string,effect:string,priority:int|string,is_override:int|bool|string,account_type:string|null,tier_code:string|null,identity_status:string|null,customer_tag_id:int|string|null,minimum_amount_irr:int|string|null,maximum_amount_irr:int|string|null,action:string|null,plan_offering_id:int|string|null,product_id:int|string|null,sales_server_id:int|string|null,effective_from:string|null,effective_until:string|null,configuration_snapshot:string,configuration_hash:string,rule_public_id:string,rule_code:string,method_code:string}
 * @phpstan-type DecisionRow object{id:int|string,public_id:string,decision_key:string,request_payload_hash:string,user_id:int|string,quote_id:int|string,quote_public_id_snapshot:string,action:string,amount_irr:int|string,currency:string,eligible_count:int|string,configuration_snapshot:string,configuration_snapshot_hash:string}
 * @phpstan-type DecisionItemRow object{payment_method_id:int|string,method_public_id_snapshot:string,method_code_snapshot:string,kind_snapshot:string,provider_code_snapshot:string|null,method_version:int|string,method_configuration_hash:string,display_priority:int|string,eligible:int|bool|string,payment_eligibility_rule_id:int|string|null,rule_public_id_snapshot:string|null,rule_code_snapshot:string|null,rule_version:int|string|null,rule_configuration_hash:string|null}
 */
trait PaymentEligibilityUtilities
{
    private function assertMutationKey(string $key): void
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{8,128}\z/', $key) !== 1) {
            throw new DomainException('Payment eligibility mutation key is invalid.');
        }
    }

    private function assertCode(string $value, string $label): void
    {
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertNullableProviderCode(?string $providerCode): void
    {
        if ($providerCode !== null && preg_match('/\A[a-z0-9_.-]{1,64}\z/', $providerCode) !== 1) {
            throw new DomainException('Payment provider code is invalid.');
        }
    }

    /** @param array<mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    /** @param mixed $value */
    private function json(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function timestamp(): string
    {
        return $this->databaseDate($this->clock->now());
    }

    private function nullableDate(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : $this->databaseDate($value);
    }

    private function databaseDate(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function dateFromDatabase(string $value, string $label): DateTimeImmutable
    {
        $timezone = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, $timezone);
        if ($date === false) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        }
        if ($date === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $date;
    }

    private function positive(int|string $value, string $label): int
    {
        $normalized = (int) $value;
        if ($normalized < 1) {
            throw new RuntimeException($label.' must be positive.');
        }

        return $normalized;
    }

    private function nonNegative(int|string $value, string $label): int
    {
        $normalized = (int) $value;
        if ($normalized < 0) {
            throw new RuntimeException($label.' must be non-negative.');
        }

        return $normalized;
    }
}
