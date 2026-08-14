<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use stdClass;

final readonly class UsdtManualRateSettingService
{
    private const PERMISSION = 'payments.usdt.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private ConfigRepository $config,
        private Clock $clock,
    ) {}

    /** @requirement USDT-002 IPG-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function current(): ?UsdtManualRateSettingReceipt
    {
        $row = $this->database->connection()->table('usdt_manual_rate_versions')
            ->orderByDesc('id')
            ->first(['id', 'rate_irr', 'created_by_administrator_id', 'created_at']);

        if ($row !== null) {
            return new UsdtManualRateSettingReceipt(
                $this->positiveInt($row->id, 'USDT manual rate version'),
                UsdtDecimal::rate((string) $row->rate_irr),
                'managed',
                $this->positiveInt($row->created_by_administrator_id, 'USDT manual rate administrator ID'),
                $this->storedDateTime((string) $row->created_at),
            );
        }

        $bootstrap = $this->config->get('usdt.rate.manual_irr');
        if (! is_string($bootstrap) || trim($bootstrap) === '') {
            return null;
        }

        return new UsdtManualRateSettingReceipt(
            null,
            $this->validatedRate($bootstrap),
            'bootstrap',
            null,
            null,
        );
    }

    /** @requirement USDT-002 IPG-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function set(
        int $administratorId,
        string $rateIrr,
        string $requestKey,
        string $correlationId,
    ): UsdtManualRateSettingReceipt {
        if ($administratorId < 1) {
            throw new DomainException('Administrator ID must be positive.');
        }
        $this->assertToken($requestKey, 'USDT manual rate request key', 8, 128);
        $this->assertToken($correlationId, 'USDT manual rate correlation ID', 8, 64);
        $normalized = $this->validatedRate($rateIrr);
        $this->authorizer->authorize($administratorId, self::PERMISSION);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $administratorId,
            $normalized,
            $requestKey,
            $correlationId,
        ): UsdtManualRateSettingReceipt {
            $existingRequest = $connection->table('usdt_manual_rate_versions')
                ->where('request_key', $requestKey)
                ->lockForUpdate()
                ->first(['id', 'rate_irr', 'created_by_administrator_id', 'created_at']);
            if ($existingRequest !== null) {
                if ((int) $existingRequest->created_by_administrator_id !== $administratorId
                    || bccomp(UsdtDecimal::rate((string) $existingRequest->rate_irr), $normalized, 8) !== 0) {
                    throw new RuntimeException('USDT manual rate request key replay conflicts with persisted settings.');
                }

                return $this->receipt($existingRequest, true);
            }

            $previous = $connection->table('usdt_manual_rate_versions')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'rate_irr', 'created_by_administrator_id', 'created_at']);

            $createdAt = $this->timestamp();
            $version = (int) $connection->table('usdt_manual_rate_versions')->insertGetId([
                'request_key' => $requestKey,
                'rate_irr' => $normalized,
                'created_by_administrator_id' => $administratorId,
                'correlation_id' => $correlationId,
                'created_at' => $createdAt,
            ]);
            if ($version < 1) {
                throw new RuntimeException('USDT manual rate setting persistence failed.');
            }

            $connection->table('audit_logs')->insert([
                'actor_type' => 'administrator',
                'actor_id' => (string) $administratorId,
                'action' => 'payments.usdt.manual_rate.updated',
                'target_type' => 'usdt_manual_rate_setting',
                'target_id' => (string) $version,
                'before_safe_data' => $previous === null ? null : json_encode([
                    'version' => (int) $previous->id,
                    'rate_irr' => UsdtDecimal::rate((string) $previous->rate_irr),
                ], JSON_THROW_ON_ERROR),
                'after_safe_data' => json_encode([
                    'version' => $version,
                    'rate_irr' => $normalized,
                ], JSON_THROW_ON_ERROR),
                'reason_code' => 'manual_rate_setting_changed',
                'reason' => null,
                'correlation_id' => $correlationId,
                'request_fingerprint' => hash('sha256', $requestKey."\0".$normalized),
                'created_at' => $createdAt,
            ]);

            $created = $connection->table('usdt_manual_rate_versions')
                ->where('id', $version)
                ->first(['id', 'rate_irr', 'created_by_administrator_id', 'created_at']);
            if ($created === null) {
                throw new RuntimeException('USDT manual rate setting disappeared after persistence.');
            }

            return $this->receipt($created, false);
        });
    }

    /** @return numeric-string */
    private function validatedRate(string $rateIrr): string
    {
        $normalized = UsdtDecimal::rate(trim($rateIrr));
        $minimum = $this->config->get('usdt.rate.min_irr');
        $maximum = $this->config->get('usdt.rate.max_irr');
        if (! is_string($minimum) || ! is_string($maximum)) {
            throw new RuntimeException('USDT manual rate bounds are not configured.');
        }
        $min = UsdtDecimal::rate($minimum);
        $max = UsdtDecimal::rate($maximum);
        if (bccomp($normalized, $min, 8) < 0 || bccomp($normalized, $max, 8) > 0) {
            throw new DomainException('USDT manual rate is outside the configured sanity bounds.');
        }

        return $normalized;
    }

    private function receipt(stdClass $row, bool $replayed): UsdtManualRateSettingReceipt
    {
        return new UsdtManualRateSettingReceipt(
            $this->positiveInt($row->id, 'USDT manual rate version'),
            UsdtDecimal::rate((string) $row->rate_irr),
            'managed',
            $this->positiveInt($row->created_by_administrator_id, 'USDT manual rate administrator ID'),
            $this->storedDateTime((string) $row->created_at),
            $replayed,
        );
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new RuntimeException('Stored USDT manual rate timestamp is invalid.');
        }

        return $parsed;
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
