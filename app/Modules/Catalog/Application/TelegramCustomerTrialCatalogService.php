<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerTrialCatalog;
use App\Modules\Telegram\Application\TelegramCustomerTrialCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerTrialOffering;
use App\Shared\Application\Clock;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramCustomerTrialCatalogService implements TelegramCustomerTrialCatalog
{
    private const MAXIMUM_PAGE_SIZE = 6;

    public function __construct(
        private DatabaseManager $database,
        private TelegramCustomerPurchaseCatalogService $purchaseCatalog,
        private TrialEligibility $eligibility,
        private Clock $clock,
    ) {}

    /** @requirement CAT-006 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function pageForSelf(
        int $actorUserId,
        int $subjectUserId,
        int $page,
        int $pageSize,
    ): TelegramCustomerTrialCatalogPage {
        $this->assertSelf($actorUserId, $subjectUserId);
        if ($page < 1 || $pageSize < 1 || $pageSize > self::MAXIMUM_PAGE_SIZE) {
            throw new InvalidArgumentException('Telegram Trial catalog page request is invalid.');
        }

        $items = $this->eligibleOfferings($subjectUserId);
        $totalItems = count($items);
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $effectivePage = min($page, $totalPages);
        $pageItems = array_values(array_slice($items, ($effectivePage - 1) * $pageSize, $pageSize));

        return new TelegramCustomerTrialCatalogPage($pageItems, $effectivePage, $totalPages, $totalItems);
    }

    /** @requirement CAT-006 DAT-002 DAT-003 SEC-002 */
    public function offeringForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $selectionToken,
    ): TelegramCustomerTrialOffering {
        $this->assertSelf($actorUserId, $subjectUserId);
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram Trial offering selection is invalid.');
        }

        foreach ($this->eligibleOfferings($subjectUserId) as $offering) {
            if (hash_equals($offering->selectionToken, $selectionToken)) {
                return $offering;
            }
        }

        throw new AuthorizationException('Telegram Trial offering is unavailable for this actor.');
    }

    /** @return list<TelegramCustomerTrialOffering> */
    private function eligibleOfferings(int $userId): array
    {
        $connection = $this->database->connection();
        $this->assertActiveCustomer($connection, $userId);
        $actor = $this->eligibility->readActor($connection, $userId);
        $purchaseOfferings = $this->purchaseCatalog->offeringsForSelf($userId, $userId);
        $businessDate = $this->clock->now()->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
        $items = [];

        foreach ($purchaseOfferings as $purchaseOffering) {
            /** @var object{id:int|string,trial_allowed:bool|int}|null $offering */
            $offering = $connection->table('plan_offerings')
                ->where('code', $purchaseOffering->offeringCode)
                ->where('state', 'active')
                ->where('visibility', 'visible')
                ->first(['id', 'trial_allowed']);
            if ($offering === null || ! (bool) $offering->trial_allowed) {
                continue;
            }
            $offeringId = $this->positiveDatabaseInt($offering->id, 'Trial Offering ID');

            /** @var object{id:int|string,enabled:bool|int,data_bytes:int|string,duration_days:int|string,daily_capacity:int|string,phone_verification_policy:string,membership_required:bool|int,one_per_user:bool|int,one_per_phone:bool|int,tag_match_mode:string}|null $policy */
            $policy = $connection->table('trial_policies')
                ->where('plan_offering_id', $offeringId)
                ->first([
                    'id', 'enabled', 'data_bytes', 'duration_days', 'daily_capacity',
                    'phone_verification_policy', 'membership_required', 'one_per_user',
                    'one_per_phone', 'tag_match_mode',
                ]);
            if ($policy === null || ! (bool) $policy->enabled) {
                continue;
            }
            $policyId = $this->positiveDatabaseInt($policy->id, 'Trial policy ID');

            try {
                $this->eligibility->assertPolicyEligibility(
                    $connection,
                    $policyId,
                    (string) $policy->tag_match_mode,
                    $actor,
                );
                $this->eligibility->assertPhonePolicy((string) $policy->phone_verification_policy, $actor);
            } catch (DomainException) {
                continue;
            }

            if ($this->claimAlreadyConsumed($connection, $policyId, $actor, (bool) $policy->one_per_user, (bool) $policy->one_per_phone)) {
                continue;
            }
            $dailyCapacity = $this->positiveDatabaseInt($policy->daily_capacity, 'Trial daily capacity');
            if ($this->dailyCapacityExhausted($connection, $policyId, $businessDate, $dailyCapacity)) {
                continue;
            }

            $items[] = new TelegramCustomerTrialOffering(
                $this->selectionToken($userId, $purchaseOffering->offeringCode),
                $purchaseOffering->offeringCode,
                $purchaseOffering->categoryNameFa,
                $purchaseOffering->categoryNameEn,
                $purchaseOffering->productNameFa,
                $purchaseOffering->productNameEn,
                $purchaseOffering->variantNameFa,
                $purchaseOffering->variantNameEn,
                $purchaseOffering->serviceModeLabelFa,
                $purchaseOffering->serviceModeLabelEn,
                $this->positiveDatabaseInt($policy->data_bytes, 'Trial data allowance'),
                $this->positiveDatabaseInt($policy->duration_days, 'Trial duration'),
                $this->databaseString($policy->phone_verification_policy, 'Trial phone-verification policy'),
                (bool) $policy->membership_required,
            );
        }

        return $items;
    }

    private function claimAlreadyConsumed(
        Connection $connection,
        int $policyId,
        TrialActorSnapshot $actor,
        bool $onePerUser,
        bool $onePerPhone,
    ): bool {
        if ($onePerUser && $connection->table('trial_reservations')
            ->where('trial_policy_id', $policyId)
            ->where('active_user_id', $actor->userId)
            ->exists()) {
            return true;
        }

        return $onePerPhone
            && $actor->phoneNumberId !== null
            && $connection->table('trial_reservations')
                ->where('trial_policy_id', $policyId)
                ->where('active_phone_number_id', $actor->phoneNumberId)
                ->exists();
    }

    private function dailyCapacityExhausted(
        Connection $connection,
        int $policyId,
        string $businessDate,
        int $dailyCapacity,
    ): bool {
        /** @var object{hard_limit_snapshot:int|string,reserved_count:int|string,committed_count:int|string}|null $counter */
        $counter = $connection->table('trial_daily_capacity_counters')
            ->where('trial_policy_id', $policyId)
            ->where('capacity_date', $businessDate)
            ->first(['hard_limit_snapshot', 'reserved_count', 'committed_count']);
        if ($counter === null) {
            return false;
        }

        $hardLimit = $this->positiveDatabaseInt($counter->hard_limit_snapshot, 'Trial daily capacity snapshot');
        if ($hardLimit !== $dailyCapacity) {
            return true;
        }

        return $this->nonNegativeDatabaseInt($counter->reserved_count, 'Trial daily reserved count')
            + $this->nonNegativeDatabaseInt($counter->committed_count, 'Trial daily committed count') >= $dailyCapacity;
    }

    private function assertActiveCustomer(Connection $connection, int $userId): void
    {
        if ($userId < 1) {
            throw new AuthorizationException('Telegram Trial catalog access denied.');
        }
        /** @var object{account_type:string,account_status:string}|null $user */
        $user = $connection->table('users')->where('id', $userId)->first(['account_type', 'account_status']);
        if ($user === null || $user->account_type !== 'customer' || $user->account_status !== 'active') {
            throw new AuthorizationException('Telegram Trial catalog requires an active customer account.');
        }
    }

    private function selectionToken(int $userId, string $offeringCode): string
    {
        return substr(hash('sha256', "telegram-trial-offering-v1:{$userId}:{$offeringCode}"), 0, 40);
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram Trial catalog self access denied.');
        }
    }

    private function databaseString(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }

    private function nonNegativeDatabaseInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }
}
