<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramManagedUsdtRateSettings;
use App\Modules\Telegram\Application\TelegramManagedUsdtRateSnapshot;

final readonly class TelegramManagedUsdtRateSettingsService implements TelegramManagedUsdtRateSettings
{
    private const PERMISSION = 'payments.usdt.manage';

    public function __construct(
        private AdministratorUserPermissionAuthorizer $administrators,
        private UsdtManualRateSettingService $rates,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administrators->allowsUser($actorUserId, self::PERMISSION);
    }

    public function currentFor(int $actorUserId): ?TelegramManagedUsdtRateSnapshot
    {
        $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
        $current = $this->rates->current();

        return $current === null ? null : $this->snapshot($current);
    }

    public function validateFor(int $actorUserId, string $rateIrr): string
    {
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);

        return $this->rates->validateForUpdate($administratorId, $rateIrr);
    }

    public function setFor(
        int $actorUserId,
        string $rateIrr,
        string $requestKey,
        string $correlationId,
    ): TelegramManagedUsdtRateSnapshot {
        $administratorId = $this->administrators->authorizeUser($actorUserId, self::PERMISSION);

        return $this->snapshot($this->rates->set(
            $administratorId,
            $rateIrr,
            $requestKey,
            $correlationId,
        ));
    }

    private function snapshot(UsdtManualRateSettingReceipt $receipt): TelegramManagedUsdtRateSnapshot
    {
        return new TelegramManagedUsdtRateSnapshot(
            $receipt->version,
            $receipt->rateIrr,
            $receipt->source,
            $receipt->replayed,
        );
    }
}
