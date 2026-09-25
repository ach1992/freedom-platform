<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceAutoRenewManager;
use App\Modules\Telegram\Application\TelegramOwnedServiceAutoRenewPackage;
use App\Modules\Telegram\Application\TelegramOwnedServiceAutoRenewResult;
use App\Modules\Telegram\Application\TelegramOwnedServiceAutoRenewSnapshot;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/** @requirement SVC-007 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
final readonly class TelegramOwnedServiceAutoRenewService implements TelegramOwnedServiceAutoRenewManager
{
    public function __construct(
        private DatabaseManager $database,
        private ServiceAutoRenewConfigurationService $configurations,
    ) {}

    public function snapshotForSelf(int $actorUserId, string $servicePublicId): TelegramOwnedServiceAutoRenewSnapshot
    {
        if ($actorUserId < 1 || ! Str::isUlid($servicePublicId)) {
            throw new DomainException('Telegram Service auto-renew request is invalid.');
        }

        $connection = $this->database->connection();
        /** @var object{id:int|string,user_id:int|string,plan_offering_id:int|string,lifecycle_state:string,provisioned_at:?string,service_target_id:int|string|null,remote_service_id:?string,remote_deleted_at:?string,offering_state:string,auto_renew_allowed:int|bool|string}|null $service */
        $service = $connection->table('service_subscriptions as service')
            ->join('order_items as item', 'item.id', '=', 'service.order_item_id')
            ->join('plan_offerings as offering', 'offering.id', '=', 'item.plan_offering_id')
            ->where('service.public_id', $servicePublicId)
            ->first([
                'service.id', 'service.user_id', 'item.plan_offering_id', 'service.lifecycle_state',
                'service.provisioned_at', 'service.service_target_id', 'service.remote_service_id', 'service.remote_deleted_at',
                'offering.state as offering_state', 'offering.auto_renew_allowed',
            ]);
        if ($service === null || (int) $service->user_id !== $actorUserId
            || ! in_array($service->lifecycle_state, ['active', 'suspended'], true)
            || $service->provisioned_at === null || $service->remote_deleted_at !== null
            || ! is_string($service->remote_service_id) || $service->remote_service_id === ''
            || $service->service_target_id === null || (int) $service->service_target_id < 1
            || $service->offering_state !== 'active' || ! (bool) $service->auto_renew_allowed) {
            throw new DomainException('Service is not eligible for Telegram auto-renew configuration.');
        }

        /** @var object{customer_enabled:int|bool,required_capability_code:?string}|null $renewPolicy */
        $renewPolicy = $connection->table('plan_offering_operations')
            ->where('plan_offering_id', (int) $service->plan_offering_id)
            ->where('operation_code', 'renew')
            ->first(['customer_enabled', 'required_capability_code']);
        if ($renewPolicy === null || ! (bool) $renewPolicy->customer_enabled) {
            throw new DomainException('Service renewal policy is not enabled for this customer.');
        }

        $requiredCapabilities = ['update_expiry'];
        if ($renewPolicy->required_capability_code !== null) {
            $requiredCapabilities[] = (string) $renewPolicy->required_capability_code;
        }
        $verified = $connection->table('panel_target_capabilities')
            ->where('panel_service_target_id', (int) $service->service_target_id)
            ->where('verification_status', 'verified')
            ->whereIn('capability_code', array_values(array_unique($requiredCapabilities)))
            ->pluck('capability_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
        foreach (array_unique($requiredCapabilities) as $capability) {
            if (! in_array($capability, $verified, true)) {
                throw new DomainException('Service target cannot currently support auto-renew.');
            }
        }

        $packages = [];
        foreach ($connection->table('plan_offering_packages')
            ->where('plan_offering_id', (int) $service->plan_offering_id)
            ->where('package_type', 'renewal')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['code', 'name_fa', 'name_en', 'price_irr', 'duration_days']) as $package) {
            if ($package->duration_days === null || (int) $package->price_irr < 1) {
                continue;
            }
            $packages[] = new TelegramOwnedServiceAutoRenewPackage(
                $this->databaseString($package->code ?? null, 'Auto-renew package code'),
                $this->databaseString($package->name_fa ?? null, 'Auto-renew package Persian name'),
                $package->name_en === null ? null : $this->databaseString($package->name_en, 'Auto-renew package English name'),
                (int) $package->price_irr,
                (int) $package->duration_days,
            );
        }
        if ($packages === []) {
            throw new DomainException('Service has no available renewal package.');
        }

        $configured = $connection->table('service_auto_renew_configurations as configuration')
            ->join('plan_offering_packages as package', 'package.id', '=', 'configuration.renewal_package_id')
            ->where('configuration.service_subscription_id', (int) $service->id)
            ->first([
                'configuration.enabled', 'configuration.accepted_price_irr', 'configuration.configuration_version',
                'package.code as package_code',
            ]);
        $configuredCode = $configured === null ? null : $this->databaseString($configured->package_code ?? null, 'Configured renewal package code');
        if ($configuredCode !== null && ! in_array($configuredCode, array_map(static fn (TelegramOwnedServiceAutoRenewPackage $package): string => $package->code, $packages), true)) {
            $configuredCode = null;
        }

        return new TelegramOwnedServiceAutoRenewSnapshot(
            $servicePublicId,
            $configured !== null && (bool) $configured->enabled && $configuredCode !== null,
            $configuredCode,
            $configured === null ? null : (int) $configured->accepted_price_irr,
            $configured === null ? null : (int) $configured->configuration_version,
            $packages,
        );
    }

    public function configureForSelf(
        int $actorUserId,
        string $servicePublicId,
        string $packageCode,
        bool $enabled,
        string $requestKey,
        string $correlationId,
    ): TelegramOwnedServiceAutoRenewResult {
        $snapshot = $this->snapshotForSelf($actorUserId, $servicePublicId);
        if ($snapshot->package($packageCode) === null) {
            throw new DomainException('Selected renewal package is no longer available.');
        }
        if (! $enabled && ! $snapshot->enabled) {
            throw new DomainException('Auto-renew is already disabled for this Service.');
        }
        if (! $enabled && ! hash_equals((string) $snapshot->configuredPackageCode, $packageCode)) {
            throw new DomainException('Disable request must reference the current renewal package.');
        }

        $receipt = $this->configurations->configure(
            $requestKey,
            $actorUserId,
            $servicePublicId,
            $packageCode,
            $enabled,
            $correlationId,
        );

        return new TelegramOwnedServiceAutoRenewResult(
            $receipt->enabled,
            $receipt->packageCode,
            $receipt->acceptedPriceIrr,
            $receipt->configurationVersion,
            $receipt->replayed,
        );
    }

    private function databaseString(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }
}
