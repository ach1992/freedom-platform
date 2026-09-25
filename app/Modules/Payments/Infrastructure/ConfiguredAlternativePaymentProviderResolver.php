<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure;

use App\Modules\Payments\Application\Contracts\AlternativePaymentProviderResolver;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionVerificationProvider;
use App\Modules\Payments\CardToCard\Infrastructure\GenericRestBankTransactionVerificationProvider;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardVerificationProvider;
use App\Modules\Payments\GiftCard\Infrastructure\GenericRestGiftCardVerificationProvider;
use App\Modules\Payments\Usdt\Application\Contracts\BlockchainTransactionVerificationProvider;
use App\Modules\Payments\Usdt\Infrastructure\GenericRestBlockchainTransactionVerificationProvider;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use RuntimeException;

final readonly class ConfiguredAlternativePaymentProviderResolver implements AlternativePaymentProviderResolver
{
    public function __construct(private Repository $config) {}

    public function bank(string $providerCode): ?BankTransactionVerificationProvider
    {
        $definition = $this->definition('payments.runtime.c2c_generic_rest_providers_json', $providerCode, 'C2C');
        if ($definition === null) {
            return null;
        }

        return new GenericRestBankTransactionVerificationProvider(
            $providerCode,
            $this->requiredString($definition, 'base_url', 'C2C provider base URL'),
            $this->requiredString($definition, 'pull_path', 'C2C provider pull path'),
            $this->requiredMap($definition, 'field_map', 'C2C provider field map'),
            $this->requiredMap($definition, 'status_map', 'C2C provider status map'),
            $this->requiredStringList($definition, 'allowed_hosts', 'C2C provider host allowlist'),
            $this->optionalString($definition, 'transactions_key', 'transactions'),
            $this->optionalString($definition, 'next_cursor_key', 'next_cursor'),
            $this->optionalString($definition, 'cursor_parameter', 'cursor'),
            $this->optionalString($definition, 'amount_unit', 'IRR'),
            $this->optionalString($definition, 'date_format', DATE_ATOM),
            $this->optionalString($definition, 'provider_timezone', 'UTC'),
            $this->optionalString($definition, 'auth_type', 'none'),
            $this->nullableString($definition, 'credential'),
            $this->optionalString($definition, 'api_key_header', 'X-API-Key'),
            $this->boundedInt($definition, 'timeout_seconds', 8, 1, 30),
            $this->boundedInt($definition, 'max_body_bytes', 262144, 1024, 2097152),
        );
    }

    public function giftCard(string $providerCode): ?GiftCardVerificationProvider
    {
        $definition = $this->definition('payments.runtime.gift_card_generic_rest_providers_json', $providerCode, 'Gift Card');
        if ($definition === null) {
            return null;
        }

        return new GenericRestGiftCardVerificationProvider(
            $providerCode,
            $this->requiredString($definition, 'base_url', 'Gift Card provider base URL'),
            $this->requiredMap($definition, 'operation_paths', 'Gift Card provider operation paths'),
            $this->requiredMap($definition, 'field_map', 'Gift Card provider field map'),
            $this->requiredMap($definition, 'outcome_map', 'Gift Card provider outcome map'),
            $this->requiredMap($definition, 'status_map', 'Gift Card provider status map'),
            $this->requiredStringList($definition, 'allowed_hosts', 'Gift Card provider host allowlist'),
            $this->optionalString($definition, 'date_format', DATE_ATOM),
            $this->optionalString($definition, 'provider_timezone', 'UTC'),
            $this->optionalString($definition, 'auth_type', 'none'),
            $this->nullableString($definition, 'credential'),
            $this->optionalString($definition, 'api_key_header', 'X-API-Key'),
            $this->boundedInt($definition, 'timeout_seconds', 8, 1, 30),
            $this->boundedInt($definition, 'max_body_bytes', 262144, 1024, 2097152),
        );
    }

    public function blockchain(string $providerCode): ?BlockchainTransactionVerificationProvider
    {
        $definition = $this->definition('payments.runtime.usdt_generic_rest_providers_json', $providerCode, 'USDT');
        if ($definition === null) {
            return null;
        }

        return new GenericRestBlockchainTransactionVerificationProvider(
            $providerCode,
            $this->requiredString($definition, 'base_url', 'USDT provider base URL'),
            $this->requiredString($definition, 'lookup_path', 'USDT provider lookup path'),
            $this->requiredString($definition, 'txid_parameter', 'USDT provider TXID parameter'),
            $this->requiredMap($definition, 'field_map', 'USDT provider field map'),
            $this->requiredMap($definition, 'outcome_map', 'USDT provider outcome map'),
            $this->requiredMap($definition, 'status_map', 'USDT provider status map'),
            $this->requiredStringList($definition, 'allowed_hosts', 'USDT provider host allowlist'),
            $this->optionalString($definition, 'date_format', DATE_ATOM),
            $this->optionalString($definition, 'provider_timezone', 'UTC'),
            $this->optionalString($definition, 'auth_type', 'none'),
            $this->nullableString($definition, 'credential'),
            $this->optionalString($definition, 'api_key_header', 'X-API-Key'),
            $this->boundedInt(
                $definition,
                'timeout_seconds',
                (int) $this->config->get('payments.usdt_bep20.generic_rest_timeout_seconds', 10),
                1,
                30,
            ),
            $this->boundedInt(
                $definition,
                'max_body_bytes',
                (int) $this->config->get('payments.usdt_bep20.generic_rest_max_body_bytes', 262144),
                1024,
                2097152,
            ),
        );
    }

    /** @return array<string, mixed>|null */
    private function definition(string $configKey, string $providerCode, string $label): ?array
    {
        if (preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $providerCode) !== 1) {
            throw new RuntimeException($label.' provider code is invalid.');
        }
        $raw = $this->config->get($configKey, '{}');
        if (! is_string($raw) || trim($raw) === '') {
            throw new RuntimeException($label.' provider registry configuration is invalid.');
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($label.' provider registry is malformed JSON.', previous: $exception);
        }
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException($label.' provider registry must be a JSON object keyed by provider code.');
        }
        foreach ($decoded as $code => $definition) {
            if (! is_string($code)
                || preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $code) !== 1
                || ! is_array($definition)
                || array_is_list($definition)) {
                throw new RuntimeException($label.' provider registry contains an invalid definition.');
            }
        }

        $definition = $decoded[$providerCode] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /** @param array<string, mixed> $definition */
    private function requiredString(array $definition, string $key, string $label): string
    {
        $value = $definition[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException($label.' is missing.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $definition */
    private function optionalString(array $definition, string $key, string $default): string
    {
        if (! array_key_exists($key, $definition)) {
            return $default;
        }
        $value = $definition[$key];
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Alternative-payment provider string configuration is invalid.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $definition */
    private function nullableString(array $definition, string $key): ?string
    {
        $value = $definition[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Alternative-payment provider credential configuration is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $definition
     * @return array<string, string>
     */
    private function requiredMap(array $definition, string $key, string $label): array
    {
        $value = $definition[$key] ?? null;
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw new RuntimeException($label.' is missing or invalid.');
        }
        $normalized = [];
        foreach ($value as $mapKey => $mapValue) {
            if (! is_string($mapKey) || $mapKey === '' || ! is_string($mapValue) || $mapValue === '') {
                throw new RuntimeException($label.' contains an invalid entry.');
            }
            $normalized[$mapKey] = $mapValue;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $definition
     * @return list<string>
     */
    private function requiredStringList(array $definition, string $key, string $label): array
    {
        $value = $definition[$key] ?? null;
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new RuntimeException($label.' is missing or invalid.');
        }
        foreach ($value as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new RuntimeException($label.' contains an invalid entry.');
            }
        }

        return array_values(array_map(static fn (string $entry): string => trim($entry), $value));
    }

    /** @param array<string, mixed> $definition */
    private function boundedInt(array $definition, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $definition[$key] ?? $default;
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);
        if ($validated === false) {
            throw new RuntimeException('Alternative-payment provider numeric configuration is invalid.');
        }

        return (int) $validated;
    }
}
