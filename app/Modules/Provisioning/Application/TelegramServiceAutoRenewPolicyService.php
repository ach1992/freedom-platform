<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Provisioning\Domain\AutoRenewPriceChangeMode;
use App\Modules\Telegram\Application\Contracts\TelegramServiceAutoRenewPolicyManager;
use App\Modules\Telegram\Application\TelegramServiceAutoRenewPolicyResult;
use App\Modules\Telegram\Application\TelegramServiceAutoRenewPolicySnapshot;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/** @requirement SVC-007 ACL-002 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
final readonly class TelegramServiceAutoRenewPolicyService implements TelegramServiceAutoRenewPolicyManager
{
    private const PERMISSION = 'catalog.manage';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private ServiceAutoRenewPolicyService $policies,
    ) {}

    public function availableFor(int $actorUserId): bool
    {
        return $this->administrators->allowsUser($actorUserId, self::PERMISSION);
    }

    public function snapshotForUser(int $actorUserId, string $offeringCode): TelegramServiceAutoRenewPolicySnapshot
    {
        $this->administratorId($actorUserId);
        $offering = $this->offering($offeringCode);
        $policy = $this->database->connection()->table('plan_offering_auto_renew_policies')
            ->where('plan_offering_id', (int) $offering->id)
            ->first(['price_change_mode', 'absolute_increase_limit_irr', 'percentage_increase_limit_bps', 'version']);

        return new TelegramServiceAutoRenewPolicySnapshot(
            $offeringCode,
            $policy === null ? null : (string) $policy->price_change_mode,
            $policy?->absolute_increase_limit_irr === null ? null : (int) $policy->absolute_increase_limit_irr,
            $policy?->percentage_increase_limit_bps === null ? null : (int) $policy->percentage_increase_limit_bps,
            $policy === null ? null : (int) $policy->version,
        );
    }

    public function configureForUser(
        int $actorUserId,
        string $offeringCode,
        string $mode,
        ?int $absoluteIncreaseLimitIrr,
        ?int $percentageIncreaseLimitBps,
        string $requestKey,
        string $correlationId,
    ): TelegramServiceAutoRenewPolicyResult {
        $administratorId = $this->administratorId($actorUserId);
        $offering = $this->offering($offeringCode);
        $policyMode = AutoRenewPriceChangeMode::tryFrom($mode)
            ?? throw new DomainException('Telegram auto-renew policy mode is invalid.');

        $receipt = $this->policies->configure(
            $requestKey,
            $administratorId,
            (int) $offering->id,
            $policyMode,
            $absoluteIncreaseLimitIrr,
            $percentageIncreaseLimitBps,
            'telegram_admin_auto_renew',
            'Administrator configured the Service auto-renew price-change policy through Telegram.',
            $correlationId,
        );

        return new TelegramServiceAutoRenewPolicyResult(
            $offeringCode,
            $receipt->mode->value,
            $receipt->absoluteIncreaseLimitIrr,
            $receipt->percentageIncreaseLimitBps,
            $receipt->version,
            $receipt->replayed,
        );
    }

    private function administratorId(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        return $this->administrators->authorizeUser($actorUserId, self::PERMISSION);
    }

    /** @return object{id:int|string} */
    private function offering(string $offeringCode): object
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{1,63}\z/', $offeringCode) !== 1) {
            throw new DomainException('Plan Offering code is invalid.');
        }
        /** @var object{id:int|string,state:string,auto_renew_allowed:int|bool|string}|null $offering */
        $offering = $this->database->connection()->table('plan_offerings')
            ->where('code', $offeringCode)
            ->first(['id', 'state', 'auto_renew_allowed']);
        if ($offering === null || $offering->state !== 'active' || ! (bool) $offering->auto_renew_allowed) {
            throw new DomainException('Auto-renew policy requires an active auto-renew-enabled offering.');
        }

        return $offering;
    }
}
