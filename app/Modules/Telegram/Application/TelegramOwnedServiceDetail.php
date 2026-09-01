<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramOwnedServiceDetail
{
    /** @param list<TelegramOwnedServiceAction> $allowedActions */
    public function __construct(
        public string $publicId,
        public string $lifecycleState,
        public string $planNameFa,
        public ?string $planNameEn,
        public string $serverNameFa,
        public ?string $serverNameEn,
        public ?string $provisionedAt,
        public string $syncState,
        public ?string $remoteDisposition,
        public ?string $remoteStatus,
        public ?int $dataLimitBytes,
        public ?int $usedBytes,
        public ?string $expiresAt,
        public ?string $observedAt,
        public array $allowedActions = [],
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new InvalidArgumentException('Telegram owned Service detail public identifier is invalid.');
        }
        if (! in_array($lifecycleState, ['active', 'suspended', 'retired'], true)) {
            throw new InvalidArgumentException('Telegram owned Service detail lifecycle state is invalid.');
        }
        if (! in_array($syncState, ['none', 'current', 'cached', 'stale'], true)) {
            throw new InvalidArgumentException('Telegram owned Service synchronization state is invalid.');
        }
        if ($remoteDisposition !== null && ! in_array($remoteDisposition, ['present', 'missing', 'unavailable', 'identity_mismatch'], true)) {
            throw new InvalidArgumentException('Telegram owned Service remote disposition is invalid.');
        }
        if ($remoteStatus !== null && ! in_array($remoteStatus, ['active', 'suspended', 'expired', 'disabled', 'unknown'], true)) {
            throw new InvalidArgumentException('Telegram owned Service remote status is invalid.');
        }
        if ($dataLimitBytes !== null && $dataLimitBytes < 0) {
            throw new InvalidArgumentException('Telegram owned Service data limit is invalid.');
        }
        if ($usedBytes !== null && $usedBytes < 0) {
            throw new InvalidArgumentException('Telegram owned Service usage is invalid.');
        }
        foreach ([$planNameFa, $serverNameFa] as $requiredLabel) {
            if ($requiredLabel === '' || ! mb_check_encoding($requiredLabel, 'UTF-8')) {
                throw new InvalidArgumentException('Telegram owned Service detail label is invalid.');
            }
        }
        if (! in_array($syncState, ['current', 'cached'], true)
            && ($remoteDisposition !== null || $remoteStatus !== null || $dataLimitBytes !== null || $usedBytes !== null || $expiresAt !== null)) {
            throw new InvalidArgumentException('Telegram owned Service stale or absent synchronization must not expose remote facts.');
        }
        if ($syncState === 'current' && $remoteDisposition !== 'present'
            && ($remoteStatus !== null || $dataLimitBytes !== null || $usedBytes !== null || $expiresAt !== null)) {
            throw new InvalidArgumentException('Telegram owned Service non-present remote evidence must not expose remote facts.');
        }
        if ($syncState === 'cached'
            && ($remoteDisposition !== 'unavailable' || $remoteStatus === null || $observedAt === null)) {
            throw new InvalidArgumentException('Telegram owned Service cached synchronization evidence is invalid.');
        }
        foreach ([$planNameEn, $serverNameEn] as $optionalLabel) {
            if ($optionalLabel !== null && ($optionalLabel === '' || ! mb_check_encoding($optionalLabel, 'UTF-8'))) {
                throw new InvalidArgumentException('Telegram owned Service optional detail label is invalid.');
            }
        }

        $this->assertAllowedActions($allowedActions);
        if (($lifecycleState === 'retired' || $provisionedAt === null) && $allowedActions !== []) {
            throw new InvalidArgumentException('Telegram owned Service unavailable lifecycle must not expose allowed actions.');
        }
    }

    public function remainingBytes(): ?int
    {
        if ($this->dataLimitBytes === null || $this->usedBytes === null) {
            return null;
        }

        return max(0, $this->dataLimitBytes - $this->usedBytes);
    }

    /** @param list<TelegramOwnedServiceAction> $allowedActions */
    private function assertAllowedActions(array $allowedActions): void
    {
        if (! array_is_list($allowedActions)) {
            throw new InvalidArgumentException('Telegram owned Service allowed actions must be a list.');
        }

        $positions = [];
        foreach (TelegramOwnedServiceAction::ordered() as $position => $action) {
            $positions[$action->value] = $position;
        }

        $lastPosition = -1;
        $seen = [];
        foreach ($allowedActions as $action) {
            if (! $action instanceof TelegramOwnedServiceAction) {
                throw new InvalidArgumentException('Telegram owned Service allowed action is invalid.');
            }
            $position = $positions[$action->value] ?? null;
            if (! is_int($position) || isset($seen[$action->value]) || $position <= $lastPosition) {
                throw new InvalidArgumentException('Telegram owned Service allowed action order is invalid.');
            }
            $seen[$action->value] = true;
            $lastPosition = $position;
        }
    }
}