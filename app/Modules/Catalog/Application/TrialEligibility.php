<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Identity\Domain\PhoneVerificationMethod;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use DomainException;
use Illuminate\Database\Connection;

final class TrialEligibility
{
    public function actor(Connection $connection, int $userId): TrialActorSnapshot
    {
        /** @var object{account_status: string}|null $user */
        $user = $connection->table('users')->where('id', $userId)->lockForUpdate()->first(['account_status']);
        if ($user === null || $user->account_status !== 'active') {
            throw new DomainException('Trial actor is unavailable.');
        }

        /** @var object{tier_code: ?string}|null $profile */
        $profile = $connection->table('customer_profiles as profile')
            ->leftJoin('customer_tiers as tier', 'tier.id', '=', 'profile.current_tier_id')
            ->where('profile.user_id', $userId)
            ->first(['tier.code as tier_code']);
        if ($profile === null) {
            throw new DomainException('Trial requires a customer profile.');
        }

        /** @var list<int|string> $tagRows */
        $tagRows = $connection->table('customer_tag_assignments as assignment')
            ->join('customer_tags as tag', 'tag.id', '=', 'assignment.tag_id')
            ->where('assignment.user_id', $userId)
            ->whereNull('assignment.removed_at')
            ->where('tag.is_active', true)
            ->orderBy('assignment.tag_id')
            ->pluck('assignment.tag_id')
            ->all();
        $tagIds = array_map(static fn (int|string $id): int => (int) $id, $tagRows);

        /** @var object{id: int|string}|null $phone */
        $phone = $connection->table('phone_numbers')
            ->where('active_user_id', $userId)
            ->where('status', 'verified')
            ->lockForUpdate()
            ->first(['id']);
        $phoneNumberId = $phone === null ? null : (int) $phone->id;
        $telegramVerified = false;
        $otpVerified = false;
        if ($phoneNumberId !== null) {
            /** @var list<string> $methods */
            $methods = $connection->table('phone_verification_evidences')
                ->where('phone_number_id', $phoneNumberId)
                ->whereNull('invalidated_at')
                ->pluck('method')
                ->filter(static fn (mixed $method): bool => is_string($method))
                ->values()
                ->all();
            $telegramVerified = in_array(PhoneVerificationMethod::TelegramContact->value, $methods, true);
            $otpVerified = in_array(PhoneVerificationMethod::SmsOtp->value, $methods, true);
        }

        $eligibilityHash = CatalogPayloadHash::make([
            'user_id' => $userId,
            'tier_code' => $profile->tier_code,
            'tag_ids' => $tagIds,
            'phone_number_id' => $phoneNumberId,
            'telegram_contact_verified' => $telegramVerified,
            'sms_otp_verified' => $otpVerified,
        ]);

        return new TrialActorSnapshot(
            $userId,
            $profile->tier_code,
            $tagIds,
            $phoneNumberId,
            $telegramVerified,
            $otpVerified,
            $eligibilityHash,
        );
    }

    public function assertOfferingAudience(string $audience): void
    {
        if (! in_array($audience, ['customers', 'both'], true)) {
            throw new DomainException('Offering audience does not allow customer trials.');
        }
    }

    public function assertOfferingEligibility(
        Connection $connection,
        int $offeringId,
        string $tagMatchMode,
        TrialActorSnapshot $actor,
    ): void {
        /** @var list<int|string> $tiers */
        $tiers = $connection->table('plan_offering_tiers')
            ->where('plan_offering_id', $offeringId)
            ->pluck('tier_code')
            ->all();
        /** @var list<int|string> $tags */
        $tags = $connection->table('plan_offering_tags')
            ->where('plan_offering_id', $offeringId)
            ->pluck('customer_tag_id')
            ->all();

        $this->assertRule(
            array_map(static fn (int|string $value): string => (string) $value, $tiers),
            array_map(static fn (int|string $value): int => (int) $value, $tags),
            $tagMatchMode,
            $actor,
            'Offering',
        );
    }

    public function assertPolicyEligibility(
        Connection $connection,
        int $policyId,
        string $tagMatchMode,
        TrialActorSnapshot $actor,
    ): void {
        /** @var list<int|string> $tiers */
        $tiers = $connection->table('trial_policy_tiers')
            ->where('trial_policy_id', $policyId)
            ->pluck('tier_code')
            ->all();
        /** @var list<int|string> $tags */
        $tags = $connection->table('trial_policy_tags')
            ->where('trial_policy_id', $policyId)
            ->pluck('customer_tag_id')
            ->all();

        $this->assertRule(
            array_map(static fn (int|string $value): string => (string) $value, $tiers),
            array_map(static fn (int|string $value): int => (int) $value, $tags),
            $tagMatchMode,
            $actor,
            'Trial policy',
        );
    }

    public function assertPhonePolicy(string $policyValue, TrialActorSnapshot $actor): void
    {
        $policy = PhoneVerificationPolicy::tryFrom($policyValue)
            ?? throw new DomainException('Stored trial phone-verification policy is invalid.');
        if (! $policy->isSatisfied($actor->telegramContactVerified, $actor->smsOtpVerified)) {
            throw new DomainException('Trial phone-verification requirement is not satisfied.');
        }
    }

    /**
     * @param  list<string>  $tierCodes
     * @param  list<int>  $tagIds
     */
    private function assertRule(
        array $tierCodes,
        array $tagIds,
        string $tagMatchMode,
        TrialActorSnapshot $actor,
        string $label,
    ): void {
        if ($tierCodes !== [] && ($actor->tierCode === null || ! in_array($actor->tierCode, $tierCodes, true))) {
            throw new DomainException($label.' tier eligibility failed.');
        }
        if ($tagIds === []) {
            return;
        }

        $matching = array_intersect($tagIds, $actor->tagIds);
        $eligible = $tagMatchMode === 'any'
            ? $matching !== []
            : count($matching) === count($tagIds);
        if (! $eligible) {
            throw new DomainException($label.' tag eligibility failed.');
        }
    }
}
