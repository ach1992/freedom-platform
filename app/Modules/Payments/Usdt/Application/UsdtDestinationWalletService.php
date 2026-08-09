<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Shared\Application\Clock;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type UsdtDestinationWalletRow object{
 *     id:int|string,
 *     wallet_code:string,
 *     version:int|string,
 *     network:string,
 *     address:string,
 *     enabled:int|bool|string,
 *     request_payload_hash:string,
 *     configuration_snapshot_hash:string
 * }
 */
final readonly class UsdtDestinationWalletService
{
    public const NETWORK = 'BEP20';
    public const MANAGE_PERMISSION = 'payments.usdt.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private Clock $clock,
    ) {}

    /** @requirement USDT-001 ACL-001 SEC-002 DAT-003 */
    public function configure(
        string $mutationKey,
        int $administratorId,
        string $walletCode,
        string $address,
        bool $enabled,
        string $reason,
        string $correlationId,
    ): UsdtDestinationWalletReceipt {
        $this->authorizer->authorize($administratorId, self::MANAGE_PERMISSION);
        $this->assertMutationKey($mutationKey);
        $this->assertWalletCode($walletCode);
        $this->assertAddress($address);
        if ($reason === '' || strlen($reason) > 255 || preg_match('/\A[a-f0-9]{64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('USDT destination configuration metadata is invalid.');
        }

        $requestHash = hash('sha256', json_encode([
            'address' => strtolower($address),
            'enabled' => $enabled,
            'reason' => $reason,
            'wallet_code' => $walletCode,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $mutationKey, $administratorId, $walletCode, $address, $enabled, $reason, $correlationId, $requestHash): UsdtDestinationWalletReceipt {
            /** @var UsdtDestinationWalletRow|null $existing */
            $existing = $connection->table('usdt_destination_wallet_versions')->where('mutation_key', $mutationKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((string) $existing->request_payload_hash !== $requestHash) {
                    throw new RuntimeException('USDT destination mutation key conflict.');
                }

                return $this->receipt($existing, true);
            }

            /** @var object{version:int|string}|null $latest */
            $latest = $connection->table('usdt_destination_wallet_versions')->where('wallet_code', $walletCode)->orderByDesc('version')->lockForUpdate()->first(['version']);
            $version = $latest === null ? 1 : ((int) $latest->version + 1);
            $snapshot = json_encode([
                'address' => strtolower($address),
                'enabled' => $enabled,
                'formula_version' => 'usdt-destination-v1',
                'network' => self::NETWORK,
                'version' => $version,
                'wallet_code' => $walletCode,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $snapshot);
            $createdAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

            $id = (int) $connection->table('usdt_destination_wallet_versions')->insertGetId([
                'wallet_code' => $walletCode,
                'version' => $version,
                'network' => self::NETWORK,
                'address' => strtolower($address),
                'enabled' => $enabled,
                'mutation_key' => $mutationKey,
                'request_payload_hash' => $requestHash,
                'configuration_snapshot' => $snapshot,
                'configuration_snapshot_hash' => $hash,
                'changed_by_administrator_id' => $administratorId,
                'change_reason' => $reason,
                'correlation_id' => $correlationId,
                'created_at' => $createdAt,
            ]);

            /** @var UsdtDestinationWalletRow|null $row */
            $row = $connection->table('usdt_destination_wallet_versions')->where('id', $id)->first();
            if ($row === null) {
                throw new RuntimeException('USDT destination configuration could not be loaded.');
            }

            return $this->receipt($row, false);
        });
    }

    public function current(string $walletCode): UsdtDestinationWalletReceipt
    {
        $this->assertWalletCode($walletCode);
        /** @var UsdtDestinationWalletRow|null $row */
        $row = $this->database->connection()->table('usdt_destination_wallet_versions')
            ->where('wallet_code', $walletCode)
            ->orderByDesc('version')
            ->first();
        if ($row === null || ! (bool) $row->enabled) {
            throw new RuntimeException('USDT destination wallet is unavailable.');
        }

        return $this->receipt($row, false);
    }

    private function assertMutationKey(string $mutationKey): void
    {
        if (strlen($mutationKey) < 8 || strlen($mutationKey) > 128 || preg_match('/\A[a-zA-Z0-9._:-]+\z/', $mutationKey) !== 1) {
            throw new InvalidArgumentException('USDT destination mutation key is invalid.');
        }
    }

    private function assertWalletCode(string $walletCode): void
    {
        if (preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/', $walletCode) !== 1) {
            throw new InvalidArgumentException('USDT destination wallet code is invalid.');
        }
    }

    private function assertAddress(string $address): void
    {
        if (preg_match('/\A0x[a-fA-F0-9]{40}\z/', $address) !== 1) {
            throw new InvalidArgumentException('USDT BEP20 destination address is invalid.');
        }
    }

    /** @param UsdtDestinationWalletRow $row */
    private function receipt(object $row, bool $replayed): UsdtDestinationWalletReceipt
    {
        return new UsdtDestinationWalletReceipt(
            (int) $row->id,
            (string) $row->wallet_code,
            (int) $row->version,
            (string) $row->network,
            (string) $row->address,
            (bool) $row->enabled,
            (string) $row->configuration_snapshot_hash,
            $replayed,
        );
    }
}
